<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/**
 * StreamRegistryControl 客户端（协议见 docs/multiprocess/06-registry-protocol.md）。
 *
 * 基于 Workerman AsyncTcpConnection：
 *   - request(op, payload, onReply)：异步等待回复（mid 关联，3s 超时回调 null）
 *   - send(op, payload)：发送不等回复（握手前缓存 outbox，握手后自动补发）
 *   - onNotify / onHello：接收 control 推送（notify.publish / unpublish / kick / hello）
 *
 * 连接策略：懒建连 + 首连前阻塞探活（fsockopen 0.5s）。
 * 不能让 AsyncTcpConnection 对未监听端口自动重连——event 扩展在 Windows 上
 * 反复 async-connect 未监听端口会原生崩溃（Access Violation）。探活失败时
 * 丢弃当次操作（request 3s 超时已有兜底），由调用方下次操作重试。
 * 运行中断线（control 重启）后 onClose 清引用，下次操作重新探活建连。
 */
class StreamRegistryClient
{
    private string $addr;

    /** @var string 探活用地址（去掉协议前缀） */
    private string $probeAddr;

    /** @var AsyncTcpConnection|null */
    private ?AsyncTcpConnection $conn = null;

    /** @var bool 握手（hello→ok）完成 */
    private bool $handshaken = false;

    /** @var int 请求序号（mid） */
    private int $midSeq = 0;

    /** @var array<int, callable> mid => onReply 回调表 */
    private array $pending = [];

    /** @var array<int, float> mid => 超时时刻 */
    private array $pendingTimeout = [];

    /** @var list<callable> 推送回调 */
    private array $notifyHandlers = [];

    /** @var callable|null 收到 hello 快照时的回调（冷启动对齐） */
    private $helloHandler = null;

    /** @var bool 定时器（请求超时扫描）是否已注册 */
    private bool $timerStarted = false;

    /** @var string hello 握手时声明的角色（ingest/subscribe/admin） */
    private string $role;

    public function __construct(string $addr, string $role = 'ingest')
    {
        $this->addr = str_starts_with($addr, 'unix://') || str_starts_with($addr, 'tcp://')
            ? $addr
            : "tcp://{$addr}";
        // fsockopen 探活用裸地址
        $this->probeAddr = str_replace(['tcp://', 'unix://'], '', $this->addr);
        $this->role = $role;
    }

    /**
     * 建连（懒调用）。control 未监听时返回 null（调用方丢弃操作，下轮重试）。
     */
    private function conn(): ?AsyncTcpConnection
    {
        if ($this->conn !== null) return $this->conn;
        $this->ensureTimer();

        // 探活：毫秒级失败，避免 AsyncTcpConnection 对未监听端口自动重连（Windows event 崩溃）
        $probePort = 18090;
        if (preg_match('/:(\d+)$/', $this->probeAddr, $m)) {
            $probePort = (int)$m[1];
            $host = substr($this->probeAddr, 0, -(strlen($m[0])));
        } else {
            $host = $this->probeAddr;
        }
        $probe = @fsockopen($host, $probePort, $errno, $probeErr, 0.5);
        if ($probe === false) {
            fwrite(STDERR, "[registry-client] probe fail host=$host port=$probePort: $probeErr\n");
            return null;
        }
        fclose($probe);
        fwrite(STDERR, "[registry-client] probe ok, building async conn\n");

        $conn = new AsyncTcpConnection($this->addr);
        $conn->protocol = '\Workerman\Protocols\Text';   // 行分隔 JSON
        $conn->onConnect = function (AsyncTcpConnection $c) {
            fwrite(STDERR, "[registry-client] onConnect: sending hello\n");
            // TCP 建立即发 hello（control 回 ok 后触发 outbox 补发）
            $c->send(json_encode(['mid' => 0, 'op' => 'hello', 'role' => $this->role, 'pid' => getmypid()]));
        };
        $conn->onMessage = function (AsyncTcpConnection $c, string $line) {
            fwrite(STDERR, "[registry-client] onMessage: " . trim($line) . "\n");
            $msg = json_decode(trim($line), true);
            if (is_array($msg)) {
                $this->handleMessage($msg);
            }
        };
        $conn->onClose = function (AsyncTcpConnection $c) {
            fwrite(STDERR, "[registry-client] onClose\n");
            $this->handshaken = false;
            // 未完成请求全部回 null（调用方按超时/失败处理）
            foreach ($this->pending as $cb) {
                $cb(null);
            }
            $this->pending = [];
            $this->pendingTimeout = [];
            // 清引用：下次操作重新探活建连（control 重启场景）
            $this->conn = null;
        };
        // 不配置 reconnect：断线由下一次操作触发重新探活
        $conn->connect();
        $this->conn = $conn;
        return $conn;
    }

    /**
     * 发起连接（供 consumer 启动时显式调用，提前建立订阅通道）。
     */
    public function connect(): void
    {
        $this->conn();
    }

    /**
     * 注册推送回调（notify.* 事件）。注册后角色变为 subscribe（冷启动收快照）。
     *
     * @param callable(array<string, mixed>) $fn
     */
    public function onNotify(callable $fn): void
    {
        $this->notifyHandlers[] = $fn;
        $this->role = 'subscribe';
    }

    /**
     * 注册 hello 快照回调（订阅端冷启动对齐）。
     *
     * @param callable(list<string>) $fn
     */
    public function onHello(callable $fn): void
    {
        $this->helloHandler = $fn;
    }

    /**
     * 发送请求并异步等待回复。超时（3s）或 control 不可达时 onReply(null)。
     *
     * @param array<string, mixed> $payload
     * @param callable(array<string, mixed>|null) $onReply
     */
    public function request(string $op, array $payload, callable $onReply): void
    {
        $conn = $this->conn();
        if ($conn === null) {
            $onReply(null);
            return;
        }
        $this->midSeq++;
        $mid = $this->midSeq;
        $this->pending[$mid] = $onReply;
        $this->pendingTimeout[$mid] = microtime(true) + 3.0;
        $conn->send(json_encode(array_merge(['mid' => $mid, 'op' => $op], $payload)));
    }

    /**
     * 发送不等回复（fire-and-forget）。control 不可达时静默丢弃。
     *
     * @param array<string, mixed> $payload
     */
    public function send(string $op, array $payload): void
    {
        $conn = $this->conn();
        if ($conn === null) return;
        // 未握手时排队，握手 ok 后补发
        if (!$this->handshaken) {
            $this->outbox[] = array_merge(['mid' => 0, 'op' => $op], $payload);
            return;
        }
        $conn->send(json_encode(array_merge(['mid' => 0, 'op' => $op], $payload)));
    }

    /** @var list<array<string, mixed>> 握手前缓存的待发消息 */
    private array $outbox = [];

    /**
     * 分发一条消息。
     *
     * @param array<string, mixed> $msg
     */
    private function handleMessage(array $msg): void
    {
        // hello 的 ok 回复（mid=0 且无 op/paths 字段）
        if (($msg['mid'] ?? -1) === 0 && !isset($msg['op']) && !isset($msg['paths'])) {
            $this->handshaken = true;
            $outbox = $this->outbox;
            $this->outbox = [];
            foreach ($outbox as $m) {
                $this->conn?->send(json_encode($m));
            }
            return;
        }

        // request 回复（mid 关联）
        if (isset($msg['mid']) && $msg['mid'] > 0 && isset($this->pending[$msg['mid']])) {
            $mid = (int)$msg['mid'];
            $cb = $this->pending[$mid];
            unset($this->pending[$mid], $this->pendingTimeout[$mid]);
            $cb($msg);
            return;
        }

        // 推送
        $op = (string)($msg['op'] ?? '');
        switch ($op) {
            case 'notify.hello':
                if ($this->helloHandler !== null) {
                    ($this->helloHandler)(array_values(array_filter((array)($msg['paths'] ?? []))));
                }
                break;
            case 'notify.publish':
            case 'notify.unpublish':
            case 'notify.kick':
                foreach ($this->notifyHandlers as $fn) {
                    $fn($msg);
                }
                break;
        }
    }

    /**
     * 500ms 周期扫描：请求超时检测（超时回调 null）。
     */
    private function ensureTimer(): void
    {
        if ($this->timerStarted) return;
        $this->timerStarted = true;
        Timer::add(0.5, function () {
            $now = microtime(true);
            foreach ($this->pendingTimeout as $mid => $deadline) {
                if ($now >= $deadline) {
                    $cb = $this->pending[$mid] ?? null;
                    unset($this->pending[$mid], $this->pendingTimeout[$mid]);
                    if ($cb) $cb(null);
                }
            }
        });
    }
}
