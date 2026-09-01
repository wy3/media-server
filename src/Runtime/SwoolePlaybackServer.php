<?php

declare(strict_types=1);

namespace MediaServer\Runtime;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server;
use Throwable;

/**
 * Swoole 版指定时间回放（对应 Workerman 版 PlaybackServer 的输出段）。
 *
 * 回放计划构建完全复用 PlaybackServer::buildPlayback（算法不变），
 * 仅把"原始 TCP 直发 + onBufferFull/Drain"重映射为
 * "Http\Response::write()（chunked）+ 服务器级 BufferFull/BufferEmpty"。
 *
 * 注意：父类 handlePlaybackRequest 为 static，子类重定义必须保持 static，
 * 故本类全部驱动方法均使用静态上下文（resumeByServer/abortByServer 已是静态入口）。
 */
class SwoolePlaybackServer extends \MediaServer\Recorder\PlaybackServer
{
    /** @var array<int, array> fd → 活跃回放状态 */
    protected static array $active = [];

    /** @var Server|null 当前服务实例（用于挂接 onBufferEmpty/onClose） */
    protected static ?Server $server = null;

    /** 绑定服务实例（在 start_swoole 初始化时调用） */
    public static function bindServer(Server $server): void
    {
        self::$server = $server;
    }

    /**
     * 入口：把 Swoole HTTP 请求映射为回放。
     *
     * 注意：不能重定义父类 handlePlaybackRequest（父类是 Workerman 版签名，参数类型不兼容，
     * PHP 会因签名不兼容报 Fatal error），故此处用独立方法名 servePlayback，
     * 仅复用父类的 buildPlayback/readRangeData 等静态构建逻辑。
     */
    public static function servePlayback(SwooleRequest $req, SwooleResponse $res, int $fd, string $path): void
    {
        if (strpos($path, '..') !== false || strpos($path, '\\') !== false) {
            $res->status(400);
            $res->end('Bad Request.');
            return;
        }

        $start = (int)($req->get['start'] ?? 0);
        $end = (int)($req->get['end'] ?? 0);
        if ($end <= 0) {
            $end = PHP_INT_MAX;
        }
        if ($end <= $start) {
            $res->status(400);
            $res->end('invalid time range');
            return;
        }

        try {
            $plan = self::buildPlayback($path, $start, $end);
        } catch (Throwable $e) {
            logger()->error('playback error {msg}', ['msg' => $e->getMessage()]);
            $res->status(500);
            $res->end('playback error');
            return;
        }

        if ($plan === null) {
            $res->status(404);
            $res->end('no record');
            return;
        }

        $res->status(200);
        $res->header('Content-Type', 'video/mp4');
        $res->header('Access-Control-Allow-Origin', '*');
        // 不设 Content-Length：走 chunked 传输，正文字节与 Workerman 版一致

        self::$active[$fd] = [
            'res' => $res,
            'parts' => $plan['parts'],
            'n' => count($plan['parts']),
            'i' => 0,
            'ri' => 0,
            'fh' => null,
            'file' => null,
            'buf' => '',
            'pending' => '',
            'full' => false,
            'done' => false,
        ];
        self::flush($fd);
    }

    /**
     * 协作式分时发送：单次 flush 内读盘量受 STREAM_CHUNK 预算约束；
     * write 返回 false（发送缓冲满）时挂起，待 onBufferEmpty 后从断点继续。
     */
    protected static function flush(int $fd): void
    {
        $st = &self::$active[$fd];
        if ($st === null || $st['done'] || $st['full']) {
            return;
        }
        $res = $st['res'];

        try {
            while (true) {
                //先清偿上次的欠账
                if ($st['pending'] !== '') {
                    if ($res->write($st['pending']) === false) {
                        $st['full'] = true;
                        return;
                    }
                    $st['pending'] = '';
                }

                if ($st['i'] >= $st['n']) {
                    self::finish($fd);
                    return;
                }

                $part = $st['parts'][$st['i']];
                if (isset($part['bytes'])) {
                    if ($res->write($part['bytes']) === false) {
                        $st['pending'] = $part['bytes'];
                        $st['full'] = true;
                        return;
                    }
                    $st['i']++;
                    continue;
                }

                $ranges = $part['ranges'];
                $rn = count($ranges);
                while (strlen($st['buf']) < self::STREAM_CHUNK && $st['ri'] < $rn) {
                    $r = $ranges[$st['ri']++];
                    $st['buf'] .= self::readRangeData($st, $r);
                }
                if ($st['buf'] !== '') {
                    if ($res->write($st['buf']) === false) {
                        $st['pending'] = $st['buf'];
                        $st['buf'] = '';
                        $st['full'] = true;
                        return;
                    }
                    $st['buf'] = '';
                }
                if ($st['ri'] >= $rn) {
                    self::closeFh($st);
                    $st['i']++;
                    $st['ri'] = 0;
                }
            }
        } catch (Throwable) {
            //发送异常（客户端断开等）：终止回放，防止状态残留
            self::finish($fd);
        }
    }

    /** 服务器 onBufferEmpty（发送缓冲已排空）→ 恢复发送（静态入口，供 start_swoole 挂接） */
    public static function resumeByServer(Server $server, int $fd): void
    {
        if (!isset(self::$active[$fd])) {
            return;
        }
        self::$active[$fd]['full'] = false;
        self::flush($fd);
    }

    /** 服务器 onClose → 终止回放并清理（静态入口） */
    public static function abortByServer(int $fd): void
    {
        if (!isset(self::$active[$fd])) {
            return;
        }
        $st = &self::$active[$fd];
        $st['done'] = true;
        if (is_resource($st['fh'])) {
            fclose($st['fh']);
        }
        unset(self::$active[$fd]);
    }

    protected static function finish(int $fd): void
    {
        if (!isset(self::$active[$fd])) {
            return;
        }
        $st = &self::$active[$fd];
        self::closeFh($st);
        $res = $st['res'];
        unset(self::$active[$fd]);
        try {
            $res->end();
        } catch (Throwable) {
            // 客户端可能已断开
        }
    }

    protected static function closeFh(array &$st): void
    {
        if (is_resource($st['fh'])) {
            fclose($st['fh']);
        }
        $st['fh'] = null;
        $st['buf'] = '';
    }

    // ------------------------------------------------------------------ 复用父类

    public static function buildPlaybackPlan(string $path, int $start, int $end): ?array
    {
        return self::buildPlayback($path, $start, $end);
    }
}
