<?php

declare(strict_types=1);

namespace MediaServer;

use Evenement\EventEmitter;
use MediaServer\Admin\AdminAuth;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\PushServer\PlayStreamInterface;
use MediaServer\PushServer\PublishStreamInterface;
use MediaServer\Recorder\Mp4Recorder;
use MediaServer\Recorder\RecorderManager;
use MediaServer\Utils\FrameBusInterface;
use MediaServer\Utils\InProcessFrameBus;
use MediaServer\Utils\ShmPublishStream;
use MediaServer\Utils\SharedMemoryFrameBus;
use MediaServer\Utils\SharedMemoryFrameBusPublisher;
use MediaServer\Utils\StreamRegistryClient;

/**
 * 流/播放端状态核心（各进程共享的状态机）。
 *
 * 单进程模式：publish → FrameBus（InProcessFrameBus 直接扇出）
 * 多进程模式：
 *   ingest —— FrameBus = SharedMemoryFrameBusPublisher（写 shm）；
 *             addPublish 前由入口向 control 注册（见 RtmpInvokeHandlerTrait）
 *   play   —— hasPublishStream 走 shm 段探测（ShmPublishStream）；
 *             帧消费由 SharedMemoryFrameBusConsumer 刷帧 → sendToPlayersByPath
 *   admin  —— 无流状态，API 代理见 AdminApiProxy
 */
class MediaServer
{
    /**
     * @var EventEmitter|null
     */
    static protected ?EventEmitter $eventEmitter = null;

    /**
     * 帧分发总线。默认单进程实现，多进程模式由启动脚本替换。
     *
     * @var FrameBusInterface|null
     */
    static protected ?FrameBusInterface $frameBus = null;

    /**
     * 进程角色标记（多进程模式）。
     * ingest=只收推流；play=只做播放；admin=只做管理后台；null=单进程。
     */
    static public ?string $processRole = null;

    // ── 角色与组件装配 ──

    static public function setFrameBus(FrameBusInterface $bus): void
    {
        self::$frameBus = $bus;
    }

    static public function getFrameBus(): FrameBusInterface
    {
        if (self::$frameBus === null) {
            self::$frameBus = new InProcessFrameBus([self::class, 'sendToPlayers']);
        }
        return self::$frameBus;
    }

    /**
     * GOP 缓存是否由帧总线（shm GOP 环）负责。
     * 多进程 ingest 返回 true：RTMP 解析层跳过 PHP 数组 GOP 缓存，
     * 新播放端的 GOP 由 play 进程从 shm 读，ingest 里不再维护。
     */
    static public function frameBusOwnsGopCache(): bool
    {
        return self::getFrameBus() instanceof SharedMemoryFrameBusPublisher;
    }

    /**
     * play 进程：从 shm 段发现流（hasPublishStream 走 shm 探测）。
     */
    static public function enableShmStreamDiscovery(): void
    {
        self::$shmStreamDiscovery = true;
        self::$processRole = 'play';
    }

    /**
     * ingest 进程：只收推流，RTMP play / HTTP play 被拒绝。
     */
    static public function enableIngestRole(): void
    {
        self::$processRole = 'ingest';
    }

    /**
     * admin 进程：管理后台，/api 代理到 control（见 AdminApiProxy）。
     */
    static public function enableAdminProxy(string $registryAddr): void
    {
        AdminApiProxy::enable($registryAddr);
        self::$processRole = 'admin';
    }

    /**
     * 启用多进程帧总线（推流进程调用）：写 shm 段。
     *
     * @param int $frameSlots 帧索引槽数（0=默认 200）
     * @param int $dataKb GOP 环容量 KB（0=默认 2048KB）
     */
    static public function enableSharedMemoryFrameBus(
        int $frameSlots = 0,
        int $dataKb = 0
    ): void {
        self::setFrameBus(new SharedMemoryFrameBusPublisher($frameSlots, $dataKb));
    }

    // ── registry 客户端（ingest 注册/心跳；play/record 订阅） ──

    static public ?StreamRegistryClient $registryClient = null;

    static public function setRegistryClient(StreamRegistryClient $client): void
    {
        self::$registryClient = $client;
    }

    /**
     * 当前进程的帧总线消费者（play/record 进程用；ingest 无）。
     */
    static public ?\MediaServer\Utils\SharedMemoryFrameBusConsumer $frameBusConsumer = null;

    static public function getFrameBusConsumer(): ?\MediaServer\Utils\SharedMemoryFrameBusConsumer
    {
        return self::$frameBusConsumer;
    }

    // ── 事件透传（on_publish_ready 等全局事件） ──

    static function __callStatic(string $name, array $arguments): mixed
    {
        if (!self::$eventEmitter) {
            self::$eventEmitter = new EventEmitter();
        }
        return call_user_func_array([self::$eventEmitter, $name], $arguments);
    }

    /**
     * admin 进程的 /api 异步实现：转发到 control 的 registry 协议。
     * 委托给 AdminApiProxy（被 HttpWMServer 的 /api 分支调用）。
     *
     * @param string $name
     * @param array<string, mixed> $args
     * @param callable(mixed) $onData
     */
    static public function adminApiProxyAsync(string $name, array $args, callable $onData): void
    {
        AdminApiProxy::proxyAsync($name, $args, $onData);
    }

    // ── 管理API（本地状态部分；admin 进程的远程部分见 AdminApiProxy） ──

    static public function callApi(?string $name, array $args = []): array|false|null
    {
        switch ((string)$name) {
            case 'listPushStream':
                return self::listPushStream(...$args);
            case 'listRecordFiles':
                return RecorderManager::listRecordFiles(...$args);
            case 'getPlayStreamCount':
                return self::getPlayStreamCount(...$args);
            case 'getServerStats':
                return self::getServerStats();
            case 'getSettings':
                return self::getSettings();
            case 'login':
                return AdminAuth::login(...$args);
            case 'logout':
                return ['ok' => AdminAuth::logout(...$args)];
            default:
                return false;
        }
    }

    static public function listPushStream(?string $path = null): array
    {
        if ($path) {
            return isset(self::$publishStream[$path]) ? [
                self::$publishStream[$path]->getPublishStreamInfo()
            ] : [];
        }
        return array_map(fn($s) => $s->getPublishStreamInfo(), array_values(self::$publishStream));
    }

    static public function getPlayStreamCount(?string $path = null): array
    {
        if ($path) {
            return ['path' => $path, 'count' => count(self::getPlayStreams($path))];
        }
        $result = [];
        foreach (array_keys(self::$playerStream) as $p) {
            $result[] = ['path' => $p, 'count' => count(self::getPlayStreams($p))];
        }
        return $result;
    }

    static public function getServerStats(): array
    {
        return [
            'publishCount' => count(self::$publishStream),
            'playCount' => array_sum(array_map('count', self::$playerStream)),
            'uptime' => AdminAuth::$startTime > 0 ? max(0, intdiv(timestamp() - AdminAuth::$startTime, 1000)) : 0,
            'memory' => memory_get_usage(),
            'memoryPeak' => memory_get_peak_usage(),
            'time' => time(),
        ];
    }

    static public function getSettings(): array
    {
        return [
            'recorder' => [
                'enabled' => RecorderManager::$enabled,
                'recordPath' => RecorderManager::$recordPath,
                'fragmentDurationMs' => RecorderManager::$fragmentDurationMs,
                'segmentDurationMs' => RecorderManager::$segmentDurationMs,
            ],
            'admin' => [
                'username' => AdminAuth::$username,
            ],
        ];
    }

    // ── 推流状态（ingest 真实 publisher；play 进程为 ShmPublishStream） ──

    /**
     * @var array<string, PublishStreamInterface>
     */
    static public array $publishStream = [];

    /**
     * 多进程 play/record 进程：注册 shm 版 publisher。
     * 有完整 GOP 才立即触发 startPlay；不完整时等刷帧轮询补触发。
     */
    static public function addShmPublishStream(string $path): ShmPublishStream
    {
        if (!isset(self::$publishStream[$path])) {
            $pub = new ShmPublishStream($path);
            self::$publishStream[$path] = $pub;
            self::$frameBusConsumer?->registerPath($path);
            if ($pub->hasCompleteGop()) {
                $pub->isPublishReadyEmitted = true;
                $pub->emit('on_publish_ready');
            }
        }
        return self::$publishStream[$path];
    }

    /**
     * 检查等待中的流是否已凑出完整 GOP（play 进程刷帧轮询调用）。
     */
    static public function pollShmPublishReady(): void
    {
        foreach (self::$publishStream as $path => $pub) {
            if ($pub instanceof ShmPublishStream
                && !$pub->isPublishReadyEmitted
                && $pub->hasCompleteGop()) {
                $pub->isPublishReadyEmitted = true;
                $pub->emit('on_publish_ready');
            }
        }
    }

    /**
     * 多进程 play 进程：shm 段存在即认为流存在（探测 + 注册）。
     */
    static public bool $shmStreamDiscovery = false;

    static public function hasPublishStream(string $path): bool
    {
        if (isset(self::$publishStream[$path])) return true;

        if (self::$shmStreamDiscovery) {
            $key = SharedMemoryFrameBus::keyForPath($path);
            $shm = @shmop_open($key, 'w', 0, 0);
            if ($shm !== false) {
                shmop_close($shm);
                self::addShmPublishStream($path);
                return true;
            }
        }
        return false;
    }

    static public function getPublishStream(string $path): PublishStreamInterface
    {
        return self::$publishStream[$path];
    }

    static protected function addPublishStream(PublishStreamInterface $stream): void
    {
        self::$publishStream[$stream->getPublishPath()] = $stream;
    }

    static protected function delPublishStream(string $path): void
    {
        unset(self::$publishStream[$path]);

        // 多进程 ingest：通知消费进程该流已结束（control 侧注销由 on_close 完成）
        $bus = self::getFrameBus();
        if ($bus instanceof SharedMemoryFrameBusPublisher) {
            $bus->notifyStreamEnd($path);
        }
    }

    // ── 播放端状态 ──

    /**
     * @var array<string, array<PlayStreamInterface>>
     */
    static public array $playerStream = [];

    static public function getPlayStreams(string $path): array
    {
        return self::$playerStream[$path] ?? [];
    }

    static protected function delPlayerStream(string $path, int $objId): void
    {
        unset(self::$playerStream[$path][$objId]);
        //一个播放设备都没有（且非多进程 play 模式）时移除帧监听
        if (self::hasPublishStream($path) && count(self::getPlayStreams($path)) == 0
            && !self::getFrameBus() instanceof SharedMemoryFrameBusPublisher) {
            $p_stream = self::getPublishStream($path);
            $p_stream->removeListener('on_frame', self::class . '::publisherOnFrame');
            $p_stream->is_on_frame = false;
        }
    }

    static protected function addPlayerStream(PlayStreamInterface $playerStream): void
    {
        $path = $playerStream->getPlayPath();
        $objIndex = spl_object_id($playerStream);

        if (!isset(self::$playerStream[$path])) {
            self::$playerStream[$path] = [];
        }
        self::$playerStream[$path][$objIndex] = $playerStream;

        if (self::hasPublishStream($path)) {
            $p_stream = self::getPublishStream($path);
            if (!$p_stream->is_on_frame) {
                $p_stream->on('on_frame', self::class . '::publisherOnFrame');
                $p_stream->is_on_frame = true;
            }
        }
    }

    // ── 帧扇出 ──

    /**
     * on_frame 事件入口。委托给 FrameBus，
     * 单进程时直接扇出，多进程时写共享内存。
     */
    static function publisherOnFrame(MediaFrame $frame, PublishStreamInterface $publisher): void
    {
        self::getFrameBus()->publish($frame, $publisher);
    }

    /**
     * 单进程扇出：同一帧发给本进程所有播放端。
     * 首个播放端组包，其余直接复用其结果（PlayPackCache）。
     */
    static public function sendToPlayers(MediaFrame $frame, PublishStreamInterface $publisher): void
    {
        self::sendToPlayersByPath($frame, $publisher->getPublishPath());
    }

    /**
     * 按 path 扇出（多进程 play 进程用，无需 publisher 对象）。
     */
    static public function sendToPlayersByPath(MediaFrame $frame, string $path): void
    {
        $playPackCache = null;
        foreach (self::getPlayStreams($path) as $playStream) {
            if (!$playStream->isPlayerIdling()) {
                $playPackCache = $playStream->frameSend($frame, $playPackCache);
            }
        }
    }

    // ── 推流接入 / 播放接入 ──

    /**
     * 注册推流（RTMP publish / HTTP-FLV publish 入口）。
     * 跨进程查重已由入口向 control 完成（publish.req）；这里做本进程注册。
     *
     * @return bool false = 本进程已存在同路径推流
     */
    static public function addPublish(PublishStreamInterface $stream): bool
    {
        $path = $stream->getPublishPath();

        if (isset(self::$publishStream[$path])) {
            logger()->warning('Stream {path} already publishing, reject duplicate publisher', ['path' => $path]);
            return false;
        }

        $stream->is_on_frame = false;

        $stream->on('on_publish_ready', function () use ($path) {
            foreach (self::getPlayStreams($path) as $playStream) {
                if ($playStream->isPlayerIdling()) {
                    $playStream->startPlay();
                }
            }
        });

        if (count(self::getPlayStreams($path)) > 0 || self::getFrameBus() instanceof SharedMemoryFrameBusPublisher) {
            $stream->on('on_frame', self::class . '::publisherOnFrame');
            $stream->is_on_frame = true;
        }

        $stream->on('on_close', function () use ($path) {
            foreach (self::getPlayStreams($path) as $playStream) {
                $playStream->playClose();
            }

            self::delPublishStream($path);

            // 推流结束通知（fire-and-forget，control 广播 notify.unpublish）
            self::$registryClient->send('publish.end', ['path' => $path]);

        });

        //录像（单进程模式；多进程下录像由独立 record 进程负责）
        if (RecorderManager::isEnabled($path)) {
            $recorder = new Mp4Recorder($stream);
            $stream->on('on_frame', [$recorder, 'onFrame']);
            $stream->on('on_close', [$recorder, 'onClose']);
        }

        self::addPublishStream($stream);

        logger()->info(' add publisher {path}', ['path' => $path]);

        return true;
    }

    /**
     * 播放端接入。多进程 play 进程先向 control 订阅（协议 4.2）：
     * publishing 立即消费；awaiting 挂起等推流开始（10s 超时关闭）；
     * 拒绝则关闭该路播放。
     */
    static public function addPlayer(PlayStreamInterface $playerStream): void
    {
        $objIndex = spl_object_id($playerStream);
        $path = $playerStream->getPlayPath();

        $playerStream->on('on_close', function () use ($path, $objIndex) {
            self::delPlayerStream($path, $objIndex);
        });

        self::addPlayerStream($playerStream);

        // 多进程 play 进程：向 control 订阅这路流
        if (self::$processRole === 'play' && self::$frameBusConsumer !== null) {
            self::$frameBusConsumer->subscribeStream($path);
            self::$frameBusConsumer->onPlayerClose(function (string $p) {
                foreach (self::getPlayStreams($p) as $ps) {
                    $ps->playClose();
                }
            });
        }

        // 本地已有推流（单进程模式 / play 进程已激活该流）→ 立即开始
        if (self::hasPublishStream($path)) {
            $playerStream->startPlay();
        }

        logger()->info(' add player {path}', ['path' => $path]);
    }

}
