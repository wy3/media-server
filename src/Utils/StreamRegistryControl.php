<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use PDO;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 流注册表 + 事件中心（control 进程内运行，协议见 docs/multiprocess/06-registry-protocol.md）。
 *
 * control 是全局唯一权威（单线程事件循环，串行处理）：
 *   - 监听：Worker 实例（Text 协议，行分隔 JSON），由 worker.php 声明后 bind()
 *   - 内存表为权威状态；SQLite 批量落盘（1s Timer），崩溃后恢复
 *   - 连接分类：每条连接握手（hello）时声明角色 ingest / subscribe / admin
 *   - ingest 连接断开 = 进程崩溃：注销其全部流并广播 unpublish（自愈）
 *
 * 全局依赖：control 挂即服务不可用（设计取舍：快速失败，不降级）。
 */
class StreamRegistryControl
{
    /**
     * 心跳过期阈值（秒）。只兜 ingest 进程假死（TCP 未断但完全停摆），
     * 正常崩溃由 handleClose（连接断开）即时自愈。CPU 饱和时 ingest 的
     * 5s 心跳定时器会被挤压延迟，阈值必须远大于可预期的过载延迟。
     */
    public const STALE_AFTER = 60.0;

    private static ?PDO $pdo = null;

    /**
     * 权威注册表：path => ['pid'=>int, 'ts'=>float, 'shm'=>array]
     *
     * @var array<string, array{pid:int, ts:float, shm:array}>
     */
    private static array $streams = [];

    /** @var array<string, array{role:string, pid:int, ts:float, data:array}> 各进程统计 */
    private static array $stats = [];

    /** @var array<int, TcpConnection> 全部已握手连接 */
    private static array $conns = [];

    /** @var array<int, TcpConnection> 订阅端连接（notify 推送对象，含 ingest 的 kick 接收） */
    private static array $notifyTargets = [];

    /** @var bool SQLite 有未落盘变更 */
    private static bool $dirty = false;

    /** @var bool 落盘定时器已启动 */
    private static bool $flushTimerStarted = false;

    private static ?string $dbFile = null;

    public static function setDbFile(string $file): void
    {
        self::$dbFile = $file;
    }

    public static function dbFile(): string
    {
        return self::$dbFile ?? (sys_get_temp_dir() . '/media-server-registry.db');
    }

    /**
     * 绑定 Worker 实例（worker.php 声明 Worker 后调用，挂 onMessage/onClose）。
     */
    public static function bind(Worker $worker): void
    {
        self::restore();
        $worker->onMessage = function (TcpConnection $conn, string $line) {
            $line = trim($line);
            if ($line !== '') {
                self::handleLine($conn, $line);
            }
        };
        $worker->onClose = function (TcpConnection $conn) {
            self::handleClose($conn);
        };
    }

    /**
     * 处理一条协议消息。
     */
    protected static function handleLine(TcpConnection $conn, string $line): void
    {
        if (getenv('MP_DEBUG')) {
            logger()->debug("[registry-ctrl] recv: $line");
        }
        $msg = json_decode($line, true);
        if (!is_array($msg) || !isset($msg['op'])) {
            $conn->send(json_encode(['mid' => $msg['mid'] ?? 0, 'ok' => false, 'reason' => 'bad request']) . "\n");
            return;
        }
        $op = (string)$msg['op'];
        $mid = isset($msg['mid']) ? (int)$msg['mid'] : 0;
        $connId = spl_object_id($conn);

        // 握手：未注册连接发 hello 注册角色；发其他消息则按 op 推断角色
        // 自动注册（容忍 client 建连后未握手即发请求的时序）
        if (!isset(self::$conns[$connId])) {
            if ($op === 'hello') {
                $role = (string)($msg['role'] ?? '?');
                self::$conns[$connId] = ['role' => $role, 'pid' => (int)($msg['pid'] ?? 0)];
                // 订阅端 + ingest 都收 notify（ingest 需要接收 notify.kick）
                self::$notifyTargets[$connId] = $conn;
                if ($role === 'subscribe') {
                    // 订阅端冷启动对齐：先推全量快照
                    $conn->send(json_encode(['op' => 'notify.hello', 'paths' => array_keys(self::$streams)]) . "\n");
                }
                $conn->send(json_encode(['mid' => $mid, 'ok' => true]) . "\n");
                return;
            }
            // 未握手先发业务消息：按 op 前缀推断角色自动注册
            $role = str_starts_with($op, 'admin.') ? 'admin'
                : (str_starts_with($op, 'subscribe.') ? 'subscribe' : 'ingest');
            self::$conns[$connId] = ['role' => $role, 'pid' => (int)($msg['pid'] ?? 0)];
            self::$notifyTargets[$connId] = $conn;
            if ($role === 'subscribe') {
                self::$subscribers[$connId] = $conn;
            }
            // 不 return——继续处理本条消息
        }

        switch ($op) {
            // ── ingest ──
            case 'publish.req':
                $path = (string)($msg['path'] ?? '');
                $pid = (int)($msg['pid'] ?? 0);
                $shm = is_array($msg['shm'] ?? null) ? $msg['shm'] : [];
                logger()->info("[registry] publish.req path={path} pid={pid}", ['path' => $path, 'pid' => $pid]);
                $existing = self::$streams[$path] ?? null;
                if ($existing !== null && $existing['pid'] !== $pid
                    && (microtime(true) - $existing['ts']) <= self::STALE_AFTER) {
                    // 重复推流：别处正在推
                    $conn->send(json_encode(['mid' => $mid, 'ok' => false, 'reason' => 'path busy']) . "\n");
                    break;
                }
                self::$streams[$path] = ['pid' => $pid, 'ts' => microtime(true), 'shm' => $shm];
                self::$conns[$connId]['pid'] = $pid;
                self::$dirty = true;
                self::ensureFlushTimer();
                $conn->send(json_encode(['mid' => $mid, 'ok' => true]) . "\n");
                // 群发流开始通知（等待中的播放端开始消费）
                self::pushToSubscribers(['op' => 'notify.publish', 'path' => $path, 'shm' => $shm]);
                break;

            case 'publish.end':
                self::removeStream((string)($msg['path'] ?? ''));
                break;   // fire-and-forget，无回复

            case 'heartbeat':
                $now = microtime(true);
                $pid = (int)($msg['pid'] ?? 0);
                foreach ((array)($msg['paths'] ?? []) as $p) {
                    if (isset(self::$streams[$p]) && self::$streams[$p]['pid'] === $pid) {
                        self::$streams[$p]['ts'] = $now;
                    }
                }
                break;

            case 'stats.report':
                $role = (string)($msg['role'] ?? '?');
                $pid = (int)($msg['pid'] ?? 0);
                self::$stats[$role . ':' . $pid] = [
                    'role' => $role, 'pid' => $pid, 'ts' => microtime(true),
                    'data' => is_array($msg['data'] ?? null) ? $msg['data'] : [],
                ];
                break;

            // ── play/record ──
            case 'subscribe.req':
                $path = (string)($msg['path'] ?? '');
                if (isset(self::$streams[$path])) {
                    $s = self::$streams[$path];
                    // 流存在：立即消费（shm 布局由 control 下发）
                    $conn->send(json_encode([
                        'mid' => $mid, 'ok' => true, 'state' => 'publishing', 'shm' => $s['shm'],
                    ]) . "\n");
                } else {
                    // 流未推：等待 notify.publish（超时由客户端定时器兜底）
                    $conn->send(json_encode(['mid' => $mid, 'ok' => true, 'state' => 'awaiting']) . "\n");
                }
                break;

            case 'subscribe.cancel':
                // 等待超时/关闭：control 侧无需操作（订阅关系按连接维护）
                break;

            // ── admin ──
            case 'admin.list':
                logger()->info('[registry] admin.list streams={n}', ['n' => count(self::$streams)]);
                $conn->send(json_encode(['mid' => $mid, 'ok' => true, 'paths' => array_keys(self::$streams)]) . "\n");
                break;

            case 'admin.stats':
                $conn->send(json_encode(['mid' => $mid, 'ok' => true, 'stats' => self::$stats]) . "\n");
                break;

            case 'admin.kick':
                $path = (string)($msg['path'] ?? '');
                if (!isset(self::$streams[$path])) {
                    $conn->send(json_encode(['mid' => $mid, 'ok' => false, 'reason' => 'not found']) . "\n");
                    break;
                }
                $ownerPid = self::$streams[$path]['pid'];
                // 通知 owner ingest 断开该推流
                self::pushToRole('ingest', $ownerPid, ['op' => 'notify.kick', 'path' => $path]);
                // 通知订阅端流结束（播放端关播放、录像端 finalize）
                self::removeStream($path);
                $conn->send(json_encode(['mid' => $mid, 'ok' => true]) . "\n");
                break;

            default:
                $conn->send(json_encode(['mid' => $mid, 'ok' => false, 'reason' => "unknown op: $op"]) . "\n");
        }
    }

    /**
     * 推送消息给订阅端（play/record）。
     *
     * @param array<string, mixed> $event
     */
    protected static function pushToSubscribers(array $event): void
    {
        $line = json_encode($event) . "\n";
        foreach (self::$notifyTargets as $connId => $conn) {
            if ((self::$conns[$connId]['role'] ?? '') === 'subscribe') {
                $conn->send($line);
            }
        }
    }

    /**
     * 推送消息给指定角色的指定进程（按 pid 匹配，如 kick 的 owner ingest）。
     *
     * @param array<string, mixed> $event
     */
    protected static function pushToRole(string $role, int $pid, array $event): void
    {
        $line = json_encode($event) . "\n";
        foreach (self::$conns as $connId => $info) {
            if (($info['role'] ?? '') === $role && (int)($info['pid'] ?? 0) === $pid) {
                self::$notifyTargets[$connId]?->send($line);
            }
        }
    }

    /**
     * 移除流并广播 unpublish 给所有订阅端。
     */
    protected static function removeStream(string $path): void
    {
        if (!isset(self::$streams[$path])) return;
        unset(self::$streams[$path]);
        self::$dirty = true;
        self::pushToSubscribers(['op' => 'notify.unpublish', 'path' => $path]);
    }

    /**
     * 连接断开：ingest 断开 = 进程崩溃，注销其全部流并广播（自愈）。
     */
    protected static function handleClose(TcpConnection $conn): void
    {
        $connId = spl_object_id($conn);
        if (!isset(self::$conns[$connId])) return;
        $info = self::$conns[$connId];
        unset(self::$conns[$connId], self::$notifyTargets[$connId]);

        if (($info['role'] ?? '') === 'ingest') {
            foreach (array_keys($info['paths'] ?? []) as $path) {
                self::removeStream($path);
            }
        }
    }

    /**
     * 清理心跳超时的流（兜底，正常自愈走 handleClose：ingest 连接断开即注销全部流）。
     *
     * 心跳由 ingest 的 5s 定时器上报；CPU 饱和时事件循环过载，心跳会延迟
     * 甚至停发十几秒——但这不代表流死了（推流端还在写帧）。宽阈值只兜
     * 进程假死（TCP 未断但完全停摆）的场景。
     */
    protected static function sweepStale(): void
    {
        $now = microtime(true);
        $stale = [];
        foreach (self::$streams as $path => $info) {
            if (($now - $info['ts']) > self::STALE_AFTER) {
                $stale[] = $path;
            }
        }
        foreach ($stale as $p) {
            logger()->warning('[registry] stale sweep: {path}', ['path' => $p]);
            self::removeStream($p);
        }
    }

    // ── SQLite 持久化（崩溃恢复用，1s 批量落盘；可用 disablePersistence 关闭） ──

    private static bool $persistenceEnabled = true;

    /** 关闭持久化（压测场景：避免上千条流时 1s 全表重写卡住事件循环） */
    public static function disablePersistence(): void
    {
        self::$persistenceEnabled = false;
    }

    protected static function ensureFlushTimer(): void
    {
        if (!self::$persistenceEnabled) return;
        if (self::$flushTimerStarted) return;
        self::$flushTimerStarted = true;
        Timer::add(1.0, function () {
            if (!self::$dirty) return;
            self::$dirty = false;
            try {
                $pdo = self::pdo();
                $pdo->beginTransaction();
                $pdo->exec('DELETE FROM registry');
                $st = $pdo->prepare('INSERT INTO registry (path, pid, shm, ts) VALUES (?, ?, ?, ?)');
                foreach (self::$streams as $path => $info) {
                    $st->execute([$path, $info['pid'], json_encode($info['shm']), $info['ts']]);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                self::$dirty = true;
            }
        });
    }

    protected static function pdo(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;
        $file = self::dbFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("mkdir registry dir fail: {$dir}");
        }
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS registry (
            path TEXT PRIMARY KEY, pid INTEGER NOT NULL, shm TEXT, ts REAL NOT NULL)');
        return self::$pdo = $pdo;
    }

    protected static function restore(): void
    {
        if (!self::$persistenceEnabled) return;
        try {
            $pdo = self::pdo();
            $now = microtime(true);
            $rows = $pdo->query('SELECT path, pid, shm, ts FROM registry')->fetchAll();
            foreach ($rows as $r) {
                if (($now - (float)$r['ts']) <= self::STALE_AFTER) {
                    self::$streams[$r['path']] = [
                        'pid' => (int)$r['pid'],
                        'ts' => (float)$r['ts'],
                        'shm' => json_decode((string)$r['shm'], true) ?: [],
                    ];
                } else {
                    $del = $pdo->prepare('DELETE FROM registry WHERE path = ?');
                    $del->execute([$r['path']]);
                }
            }
        } catch (\Throwable $e) {
            // 恢复失败不阻塞启动（当作空注册表）
        }
    }
}
