<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use Workerman\Connection\TcpConnection;
use Workerman\Timer;

/**
 * StreamRegistryControl 客户端（协议见 docs/multiprocess/06-registry-protocol.md）。
 *
 * 基于 Workerman TcpConnection 包装同步建立的 socket：
 *   - request(op, payload, onReply)：异步等待回复（mid 关联，3s 超时回调 null）
 *   - send(op, payload)：发送不等回复（握手前缓存 outbox，握手后自动补发）
 *   - onNotify / onHello：接收 control 推送（notify.publish / unpublish / kick / hello）
 *
 * 连接策略：懒建连。用 fsockopen 同步建立连接（control 在本机，毫秒级），
 * 把已建立的 socket 交给 Workerman TcpConnection 管理读写。
 * 不用 AsyncTcpConnection——php_event 在 Windows 上对
 * STREAM_CLIENT_ASYNC_CONNECT socket 会持续触发虚假就绪事件，空转烧满 1 核。
 * control 不可达时 fsockopen 失败返回 null，调用方按失败处理，下次操作重试。
 * 运行中断线（control 重启）后 onClose 清引用，下次操作重新建连。
 */
class StreamRegistryClient
{
    private string $addr;

    /** @var TcpConnection|null */
    private ?TcpConnection $conn = null;

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
        $this->role = $role;
    }

    /**
     * 建连（懒调用）。fsockopen 同步建立（本地 control 毫秒级），
     * 交给 TcpConnection 管理读写。control 不可达时返回 null，
     * 调用方按失败处理，下次操作重试。
     */
    private function conn(): ?TcpConnection
    {
        if ($this->conn !== null) return $this->conn;
        $this->ensureTimer();

        $remote = str_replace(['tcp://', 'unix://'], '', $this->addr);
        // 拆 host:port（registry 地址恒为 host:port 形式）
        $colon = strrpos($remote, ':');
        if ($colon === false) {
            fwrite(STDERR, "[registry-client] bad addr: {$this->addr}\n");
            return null;
        }
        $host = substr($remote, 0, $colon);
        $port = (int)substr($remote, $colon + 1);
        $timeout = 3.0;
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            fwrite(STDERR, "[registry-client] connect fail $remote: $errstr\n");
            return null;
        }

        $conn = new TcpConnection($socket, $remote);
        $conn->protocol = '\Workerman\Protocols\Text';   // 行分隔 JSON
        $conn->onMessage = function (TcpConnection $c, string $line) {
            $msg = json_decode(trim($line), true);
            if (is_array($msg)) {
                $this->handleMessage($msg);
            }
        };
        $conn->onClose = function (TcpConnection $c) {
            $this->handshaken = false;
            // 未完成请求全部回 null（调用方按超时/失败处理）
            foreach ($this->pending as $cb) {
                $cb(null);
            }
            $this->pending = [];
            $this->pendingTimeout = [];
            // 清引用：下次操作重新建连（control 重启场景）
            $this->conn = null;
        };
        $this->conn = $conn;

        // 建立后立即握手（control 回 ok 后触发 outbox 补发）
        $conn->send(json_encode(['mid' => 0, 'op' => 'hello', 'role' => $this->role, 'pid' => getmypid()]));
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
        $msg = array_merge(['mid' => $mid, 'op' => $op], $payload);
        if (!$this->handshaken) {
            // 未握手时 send 会早于 onConnect 里的 hello 被 flush，导致 control
            // 先收到业务请求、后收到 hello（hello 被判 bad request）。放进 outbox，
            // 等 hello ok 后按序补发（回调已存 pending，靠 mid 匹配回复）。
            $this->outbox[] = $msg;
            return;
        }
        $conn->send(json_encode($msg));
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
