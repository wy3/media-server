<?php

declare(strict_types=1);

namespace MediaServer;

use MediaServer\Utils\StreamRegistryClient;

/**
 * 管理后台 API 代理（admin 进程用）。
 *
 * admin 进程无本地流状态：注册表/统计类 /api 请求转发到 control
 * （admin.list / admin.stats），login/logout 本地处理（AdminAuth 进程内会话）。
 */
final class AdminApiProxy
{
    /** control 注册表地址 */
    public static string $registryAddr = '';

    /** @var string 探活/连接用 host */
    public static string $host = '127.0.0.1';

    /** @var int 探活/连接用 port */
    public static int $port = 18090;

    public static function enable(string $registryAddr): void
    {
        self::$registryAddr = $registryAddr;
        // 解析 host:port（同步 socket 用）
        $bare = str_replace(['tcp://', 'unix://'], '', $registryAddr);
        if (preg_match('/^(.*):(\d+)$/', $bare, $m)) {
            self::$host = $m[1];
            self::$port = (int)$m[2];
        }
    }

    /**
     * 异步转发 API 请求到 control。回调返回数据（false = 404）。
     *
     * 同步短连接实现（与手动验证一致的方式）：admin 查询低频（人工刷新），
     * 单次 8ms 阻塞可接受；AsyncTcpConnection 版在 Windows event 扩展下
     * 存在连接建立时序问题（send 缓冲不冲刷），故用同步 socket。
     *
     * @param string $name
     * @param array<string, mixed> $args
     * @param callable(mixed) $onData
     */
    public static function proxyAsync(string $name, array $args, callable $onData): void
    {
        $reply = self::syncQuery($name);
        $onData($reply);
    }

    /**
     * 同步查询 control。
     *
     * @return mixed false = 未知 API / control 不可用
     */
    private static function syncQuery(string $name): mixed
    {
        $op = match ($name) {
            'listPushStream' => 'admin.list',
            'getServerStats', 'getPlayStreamCount' => 'admin.stats',
            default => null,
        };
        if ($op === null) {
            return false;
        }
        $sock = @fsockopen(self::$host, self::$port, $errno, $errstr, 2);
        if ($sock === false) {
            // control 不可用：空数据（admin 只读，不影响业务）
            return $name === 'listPushStream' ? [] : [
                'publishCount' => 0, 'playCount' => 0, 'uptime' => 0,
                'memory' => 0, 'memoryPeak' => 0, 'time' => time(),
            ];
        }
        stream_set_timeout($sock, 3);
        fwrite($sock, json_encode(['mid' => 0, 'op' => 'hello', 'role' => 'admin']) . "\n");
        fgets($sock);   // hello ok
        fwrite($sock, json_encode(['mid' => 1, 'op' => $op]) . "\n");
        $line = fgets($sock);
        fclose($sock);
        $reply = $line !== false ? json_decode(trim($line), true) : null;
        if (!$reply['ok']) {
            return $name === 'listPushStream' ? [] : [
                'publishCount' => 0, 'playCount' => 0, 'uptime' => 0,
                'memory' => 0, 'memoryPeak' => 0, 'time' => time(),
            ];
        }
        if ($name === 'listPushStream') {
            return array_map(
                fn($p) => ['publishStreamPath' => $p],
                $reply['paths']
            );
        }
        // 聚合各进程上报的统计
        $total = ['publishCount' => 0, 'playCount' => 0, 'memory' => 0, 'time' => time()];
        foreach ($reply['stats'] as $entry) {
            $d = $entry['data'] ?? [];
            if (($entry['role'] ?? '') === 'ingest') $total['publishCount'] += (int)($d['publishCount'] ?? 0);
            if (($entry['role'] ?? '') === 'play') $total['playCount'] += (int)($d['playCount'] ?? 0);
            $total['memory'] += (int)($d['memory'] ?? 0);
        }
        return $name === 'getPlayStreamCount'
            ? ['count' => $total['playCount']]
            : $total + ['memoryPeak' => 0, 'uptime' => 0];
    }
}
