<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use Workerman\Timer;
use Workerman\Events\EventInterface;
use Workerman\Worker;

/**
 * 多进程帧消费者（shm 刷帧 + control 事件驱动流生命周期）。
 *
 * 分工：
 *   - 流发现：订阅 control 的 notify.publish/unpublish/kick 推送；
 *     冷启动（或重连）时由 notify.hello 快照对齐
 *   - 播放接入：subscribe.req 异步等回复——publishing 立即消费；
 *     awaiting 挂起等 notify.publish（10s 超时关闭并 subscribe.cancel）；
 *     拒绝则关闭该路播放
 *   - 帧数据：固定间隔（默认 50ms）Timer 直接刷各流 shm 段 writeSeq，
 *     连续读取未推送帧并扇出。帧路径零消息传递，帧延迟上界 = 刷帧间隔
 */
class SharedMemoryFrameBusConsumer
{
    /** 等待推流开始的超时秒数（subscribe.req 返回 awaiting 后） */
    public const AWAIT_TIMEOUT = 10.0;

    /** @var array<int, string> key => path */
    private array $paths = [];

    /** @var array<int, SharedMemoryFrameBus> key => segment */
    private array $segments = [];

    /** @var array<int, list<callable>> key => callbacks */
    private array $callbacks = [];

    private bool $registered = false;

    /** @var list<callable> 全局回调（所有流） */
    private array $anyCallbacks = [];

    /** @var array<int, int> key => 已读到的 seq */
    private array $lastReadSeq = [];

    /** @var array<int, bool> key => 序列头是否已喂过 */
    private array $seqFed = [];

    /** @var list<callable> 流结束回调 */
    private array $endCallbacks = [];

    /** @var array<int, bool> key => 流是否已结束（等 publisher 重建） */
    private array $streamEnded = [];

    /** @var array<int, int> key => 上次告警的 seq（覆盖丢失告警去重） */
    private array $gapWarned = [];

    private StreamRegistryClient $registry;

    /** @var array<string, float> path => awaiting 起始时刻（等待推流开始） */
    private array $awaiting = [];

    /** @var array<string, array> path => 流的 shm 布局（control 下发） */
    private array $streamShm = [];

    /** @var callable|null awaiting 超时/被拒时关闭该路播放（worker.php 注入） */
    private $playerCloseHandler = null;

    /**
     * @param int $frameSlots 帧索引槽数
     * @param int $dataKb 单个 GOP 环容量 KB
     * @param float $pollInterval 刷帧间隔秒（0.05 = 帧延迟上界 50ms）
     */
    public function __construct(
        StreamRegistryClient $registry,
        private readonly int $frameSlots = 0,
        private readonly int $dataKb = 0,
        private readonly float $pollInterval = 0.05
    ) {
        $this->registry = $registry;
    }

    /**
     * 内部 registry client（play 进程的统计上报等也走它）。
     */
    public function getRegistry(): StreamRegistryClient
    {
        return $this->registry;
    }

    /**
     * awaiting 超时/被拒时关闭该路所有播放端的回调。
     *
     * @param callable(string $path) $fn
     */
    public function onPlayerClose(callable $fn): void
    {
        $this->playerCloseHandler = $fn;
    }

    /**
     * 启动：刷帧 Timer + control 订阅。必须在 onWorkerStart 中调用。
     */
    public function start(): void
    {
        if ($this->registered) return;

        // 刷帧主循环 + awaiting 超时检查
        Timer::add($this->pollInterval, function () {
            $this->pollFrames();
            $this->checkAwaitTimeout();
        });

        // control 订阅
        $this->registry->onNotify(function (array $ev) {
            $this->handleRegistryEvent($ev);
        });
        $this->registry->onHello(function (array $paths) {
            foreach ($paths as $p) {
                $this->activateStream($p, []);
            }
        });
        $this->registry->connect();
        $this->registered = true;
    }

    /**
     * 注册已发现的流路径（RTMP/HTTP 播放端接入等外部发现渠道）。
     */
    public function registerPath(string $path): void
    {
        $key = SharedMemoryFrameBus::keyForPath($path);
        $this->paths[$key] = $path;
    }

    /**
     * 播放端请求接入一路流（协议 4.2）：
     *   subscribe.req 异步等回复——publishing 立即激活；
     *   awaiting 挂起（10s 超时关闭）；拒绝则关闭该路播放。
     */
    public function subscribeStream(string $path): void
    {
        $this->registry->request('subscribe.req', ['path' => $path], function (?array $reply) use ($path) {
            if ($reply === null) {
                // control 不可用/超时：关闭这路播放（control 是全局依赖，fail-fast）
                $this->closePlayer($path);
                return;
            }
            if (!$reply['ok']) {
                // 被拒绝（被踢/非法）
                $this->closePlayer($path);
                return;
            }
            if ($reply['state'] === 'publishing') {
                $this->streamShm[$path] = $reply['shm'];
                $this->activateStream($path, $this->streamShm[$path]);
                return;
            }
            // awaiting：流还没推，挂起等 notify.publish，10s 超时关闭
            $this->awaiting[$path] = microtime(true);
        });
    }

    /**
     * 关闭一路流的所有播放端（awaiting 超时/被拒）。
     */
    private function closePlayer(string $path): void
    {
        if ($this->playerCloseHandler !== null) {
            try {
                ($this->playerCloseHandler)($path);
            } catch (\Throwable $e) {
                logger()->warning('[consumer] player close error: {msg}', ['msg' => $e->getMessage()]);
            }
        }
        // 同步状态给 control
        $this->registry->send('subscribe.cancel', ['path' => $path]);
        unset($this->awaiting[$path]);
    }

    /**
     * awaiting 超时检查。
     */
    private function checkAwaitTimeout(): void
    {
        $now = microtime(true);
        foreach ($this->awaiting as $path => $since) {
            if (($now - $since) >= self::AWAIT_TIMEOUT) {
                unset($this->awaiting[$path]);
                logger()->warning('[consumer] {path} await timeout (no publish in {t}s), closing players', ['path' => $path, 't' => self::AWAIT_TIMEOUT]);
                $this->closePlayer($path);
            }
        }
    }

    /**
     * 订阅某路流的帧。
     *
     * @param callable(string $path, string $payload, int $seq, bool $isKey, int $frameType, int $timestamp, int $special) $callback
     */
    public function onFrame(string $path, callable $callback): void
    {
        $key = SharedMemoryFrameBus::keyForPath($path);
        $this->paths[$key] = $path;
        $this->callbacks[$key][] = $callback;
    }

    /**
     * 订阅所有流的帧（发现的流自动进入）。
     *
     * @param callable(string $path, string $payload, int $seq, bool $isKey, int $frameType, int $timestamp, int $special) $callback
     */
    public function onAnyFrame(callable $callback): void
    {
        $this->anyCallbacks[] = $callback;
    }

    /**
     * 注册流结束回调。
     *
     * @param callable(string $path) $callback
     */
    public function onStreamEnd(callable $callback): void
    {
        $this->endCallbacks[] = $callback;
    }

    /**
     * 激活一路流（publish 事件/hello 快照/subscribe.req publishing 回复）。
     *
     * @param array<string, mixed> $shm control 下发的 shm 布局
     */
    private function activateStream(string $path, array $shm = []): void
    {
        if ($shm !== []) $this->streamShm[$path] = $shm;
        $this->registerPath($path);
        $key = SharedMemoryFrameBus::keyForPath($path);
        if (!isset($this->segments[$key])) {
            try {
                $this->segments[$key] = new SharedMemoryFrameBus($path, $this->frameSlots, $this->dataKb, false);
            } catch (\RuntimeException $e) {
                // 段还没建（publish 事件先于首帧）——pollFrames 里重试
            }
        }
    }

    /**
     * 处理 control 推送事件。
     *
     * @param array<string, mixed> $ev
     */
    private function handleRegistryEvent(array $ev): void
    {
        switch ($ev['op'] ?? '') {
            case 'notify.publish':
                $path = (string)($ev['path'] ?? '');
                if ($path === '') break;
                unset($this->awaiting[$path]);   // 等到了（如果在等）
                $shm = is_array($ev['shm'] ?? null) ? $ev['shm'] : [];
                $this->activateStream($path, $shm);
                break;

            case 'notify.unpublish':
                $path = (string)($ev['path'] ?? '');
                if ($path === '') break;
                $key = SharedMemoryFrameBus::keyForPath($path);
                if (isset($this->segments[$key]) || isset($this->lastReadSeq[$key])) {
                    $this->streamEnded[$key] = true;
                    foreach ($this->endCallbacks as $cb) {
                        try {
                            $cb($path);
                        } catch (\Throwable $e) {
                            logger()->warning('[consumer] stream end error: {msg}', ['msg' => $e->getMessage()]);
                        }
                    }
                    unset($this->lastReadSeq[$key], $this->seqFed[$key], $this->gapWarned[$key], $this->segments[$key]);
                }
                break;

            case 'notify.kick':
                // 该路播放被管理端踢出：关闭本地播放端
                $path = (string)($ev['path'] ?? '');
                if ($path !== '') {
                    $this->closePlayer($path);
                }
                break;
        }
    }

    /**
     * 刷帧主循环：只处理仍有订阅者的流。
     *
     * 无播放端且无 per-path 回调的流跳过刷帧（并释放 shm 段），
     * 避免 play 进程在无人观看时空转烧 CPU。
     */
    private function pollFrames(): void
    {
        foreach ($this->paths as $key => $path) {
            // 播放端还在，或有 per-path 回调（record 等）→ 保持刷帧
            $hasPlayers = \MediaServer\MediaServer::getPlayStreams($path) !== [];
            if (!$hasPlayers && !isset($this->callbacks[$key])) {
                // 释放段缓存，等订阅回来再重建
                if (isset($this->segments[$key])) {
                    unset($this->segments[$key], $this->lastReadSeq[$key]);
                }
                continue;
            }
            $seg = $this->segments[$key] ?? null;
            if ($seg === null) {
                try {
                    $this->segments[$key] = new SharedMemoryFrameBus($path, $this->frameSlots, $this->dataKb, false);
                } catch (\RuntimeException $e) {
                    continue;
                }
            }
            $this->dispatch($key, $path);
        }
    }

    /**
     * 追指定流的所有新帧（连续读语义）。
     */
    private function dispatch(int $key, string $path): void
    {
        if ($path === '') return;

        // 流已结束（同 path 再次推流）：publisher 重建段，重置状态
        if ($this->streamEnded[$key] ?? false) {
            $this->streamEnded[$key] = false;
            unset($this->lastReadSeq[$key], $this->seqFed[$key]);
        }

        $seg = $this->segments[$key] ?? null;
        if ($seg === null) return;
        $writeSeq = $seg->writeSeq();

        // 序列头（专用槽）作为流的首批帧喂给回调；缺的后续刷帧补喂
        $seqSlots = [1 => $seg->readMeta(), 2 => $seg->readAvcSequence(), 4 => $seg->readHevcSequence(), 3 => $seg->readAacSequence()];
        foreach ($seqSlots as $specialId => $sf) {
            if ($sf === null) { unset($this->seqFed[$key][$specialId]); continue; }
            if ($this->seqFed[$key][$specialId] ?? false) continue;
            $this->seqFed[$key][$specialId] = true;
            foreach ($this->anyCallbacks as $cb) {
                $cb($path, $sf['payload'], $sf['seq'], $sf['isKey'], $sf['frameType'], $sf['timestamp'], $sf['special']);
            }
            if (isset($this->callbacks[$key])) {
                foreach ($this->callbacks[$key] as $cb) {
                    $cb($path, $sf['payload'], $sf['seq'], $sf['isKey'], $sf['frameType'], $sf['timestamp'], $sf['special']);
                }
            }
        }

        // 首次接入：若播放端已通过 startPlay 做 GOP 回填，从回填结束处继续；
        // 否则从当前实时位置开始（record 进程无播放端，直接实时）
        if (!isset($this->lastReadSeq[$key])) {
            $startFrom = $writeSeq;
            if (\MediaServer\MediaServer::hasPublishStream($path)) {
                $pub = \MediaServer\MediaServer::getPublishStream($path);
                if ($pub instanceof ShmPublishStream && $pub->gopLastSeq() > 0) {
                    $startFrom = $pub->gopLastSeq();
                }
            }
            $this->lastReadSeq[$key] = $startFrom;
        }

        // 连续读取
        while ($this->lastReadSeq[$key] < $writeSeq) {
            $seq = $this->lastReadSeq[$key] + 1;
            $frame = $seg->read($seq);
            $this->lastReadSeq[$key] = $seq;
            if ($frame === null) {
                if (($this->gapWarned[$key] ?? 0) < $seq - 1) {
                    logger()->warning('[consumer] {path} seq={seq} unreadable (overwritten?), resync to next keyframe', ['path' => $path, 'seq' => $seq]);
                    $this->gapWarned[$key] = $seq;
                }
                $this->lastReadSeq[$key] = max($this->lastReadSeq[$key], $seg->lastKeySeq());
                continue;
            }

            try {
                foreach ($this->anyCallbacks as $cb) {
                    $cb($path, $frame['payload'], $frame['seq'], $frame['isKey'], $frame['frameType'], $frame['timestamp'], $frame['special']);
                }
                if (isset($this->callbacks[$key])) {
                    foreach ($this->callbacks[$key] as $cb) {
                        $cb($path, $frame['payload'], $frame['seq'], $frame['isKey'], $frame['frameType'], $frame['timestamp'], $frame['special']);
                    }
                }
            } catch (\Throwable $e) {
                logger()->warning('[consumer] frame dispatch error seq={seq}: {msg} @ {file}:{line}', [
                    'seq' => $seq, 'msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
                ]);
            }
        }
    }

    /**
     * 取消订阅。
     */
    public function offFrame(string $path): void
    {
        $key = SharedMemoryFrameBus::keyForPath($path);
        unset($this->callbacks[$key], $this->segments[$key], $this->paths[$key]);
    }
}
