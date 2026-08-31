<?php

declare(strict_types=1);

namespace MediaServer;


use Evenement\EventEmitter;
use MediaServer\Admin\AdminAuth;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\PushServer\PlayStreamInterface;
use MediaServer\PushServer\PublishStreamInterface;
use MediaServer\PushServer\VerifyAuthStreamInterface;
use MediaServer\Recorder\Mp4Recorder;
use MediaServer\Recorder\RecorderManager;


class MediaServer
{

    /**
     * @var EventEmitter|null
     */
    static protected ?EventEmitter $eventEmitter = null;


    static function __callStatic(string $name, array $arguments): mixed
    {
        if (!self::$eventEmitter) {
            self::$eventEmitter = new EventEmitter();
        }
        return call_user_func_array([self::$eventEmitter, $name], $arguments);
    }


    /**
     * @var PublishStreamInterface[]
     */
    static public array $publishStream = [];

    static public function callApi(?string $name, array $args = []): array|false|null
    {
        switch ($name) {
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
        return array_map(function ($stream) {
            return $stream->getPublishStreamInfo();
        }, array_values(self::$publishStream));
    }

    /**
     * 各推流路径对应的播放连接数统计。
     */
    static public function getPlayStreamCount(?string $path = null): array
    {
        if ($path) {
            return [
                'path' => $path,
                'count' => count(self::getPlayStreams($path)),
            ];
        }
        $result = [];
        foreach (array_keys(self::$playerStream) as $p) {
            $result[] = [
                'path' => $p,
                'count' => count(self::getPlayStreams($p)),
            ];
        }
        return $result;
    }

    /**
     * 服务运行状态统计。
     */
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

    /**
     * 当前服务配置（不包含任何敏感信息）。
     */
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

    /**
     * @param string $path
     * @return bool
     */
    static public function hasPublishStream(string $path): bool
    {
        return isset(self::$publishStream[$path]);
    }

    /**
     * @param string $path
     * @return PublishStreamInterface
     */
    static public function getPublishStream(string $path): PublishStreamInterface
    {
        return self::$publishStream[$path];
    }

    /**
     * @param PublishStreamInterface $stream
     */
    static protected function addPublishStream(PublishStreamInterface $stream): void
    {
        $path = $stream->getPublishPath();
        self::$publishStream[$path] = $stream;
    }

    static protected function delPublishStream(string $path): void
    {
        unset(self::$publishStream[$path]);
    }

    /**
     * @var PlayStreamInterface[][]
     */
    static public array $playerStream = [];

    /**
     * @param string $path
     * @return array|PlayStreamInterface[]
     */
    static public function getPlayStreams(string $path): array
    {
        return self::$playerStream[$path] ?? [];
    }


    /**
     * @param string $path
     * @param int $objId
     */
    static protected function delPlayerStream(string $path, int $objId): void
    {
        unset(self::$playerStream[$path][$objId]);
        //一个播放设备都没有
        if (self::hasPublishStream($path) && count(self::getPlayStreams($path)) == 0) {
            $p_stream = self::getPublishStream($path);
            $p_stream->removeListener('on_frame', self::class . '::publisherOnFrame');
            $p_stream->is_on_frame = false;
        }
    }

    /**
     * @param PlayStreamInterface $playerStream
     */
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


    /**
     * @param PublishStreamInterface $publisher
     * @param MediaFrame $frame
     */
    static function publisherOnFrame(MediaFrame $frame, PublishStreamInterface $publisher): void
    {
        foreach (self::getPlayStreams($publisher->getPublishPath()) as $playStream) {
            if (!$playStream->isPlayerIdling()) {
                $playStream->frameSend($frame);
            }
        }
    }


    /**
     * @param PublishStreamInterface $stream
     * @return bool
     */
    static public function addPublish(PublishStreamInterface $stream): bool
    {
        $path = $stream->getPublishPath();
        $stream->is_on_frame = false;

        $stream->on('on_publish_ready', function () use ($path) {
            foreach (self::getPlayStreams($path) as $playStream) {
                if ($playStream->isPlayerIdling()) {
                    $playStream->startPlay();
                }
            }
        });

        if (count(self::getPlayStreams($path)) > 0) {
            $stream->on('on_frame', self::class . '::publisherOnFrame');
            $stream->is_on_frame = true;
        }


        $stream->on('on_close', function () use ($path) {
            foreach (self::getPlayStreams($path) as $playStream) {
                $playStream->playClose();
            }

            self::delPublishStream($path);

        });

        //录像
        if (RecorderManager::isEnabled($path)) {
            $recorder = new Mp4Recorder($stream);
            $stream->on('on_frame', [$recorder, 'onFrame']);
            $stream->on('on_close', [$recorder, 'onClose']);
        }

        self::addPublishStream($stream);

        logger()->info(" add publisher {path}", ['path' => $path]);

        return true;

    }

    /**
     * @param PlayStreamInterface $playerStream
     */
    static public function addPlayer(PlayStreamInterface $playerStream): void
    {

        $objIndex = spl_object_id($playerStream);
        $path = $playerStream->getPlayPath();

        //on close event
        $playerStream->on("on_close", function () use ($path, $objIndex) {
            //echo "play on close", PHP_EOL;
            self::delPlayerStream($path, $objIndex);
        });

        self::addPlayerStream($playerStream);

        //判断当前是否有对应的推流设备
        if (self::hasPublishStream($path)) {
            $playerStream->startPlay();
        }

        logger()->info(" add player {path}", ['path' => $path]);

    }

    /**
     * @param VerifyAuthStreamInterface $stream
     * @return bool
     */
    static public function verifyAuth(VerifyAuthStreamInterface $stream): bool
    {
        return true;
    }


}
