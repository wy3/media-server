<?php

declare(strict_types=1);

/**
 * play 进程：播放池。
 *
 * RTMP play（端口 $rtmpPort）+ HTTP-FLV/WS-FLV play（端口 $httpPort）。
 * 播放接入 → subscribe.req 等 control 回复（publishing 立即消费 / awaiting
 * 等推流开始 10s 超时关闭 / 拒绝关闭）；帧数据固定间隔刷 shm 扇出（数据面
 * 零消息传递，帧延迟上界 = 刷帧间隔 50ms）。每 5s 上报统计。
 */

namespace MediaServer\Process;

use MediaServer\MediaServer;
use MediaServer\Recorder\RecorderManager;
use MediaServer\Utils\SharedMemoryFrameBusConsumer;
use MediaServer\Utils\StreamRegistryClient;

final class PlayProcess
{
    public static function run(int $rtmpPort, int $httpPort, string $registryAddr): void
    {
        RecorderManager::$enabled = false;
        MediaServer::enableShmStreamDiscovery();   // shmStreamDiscovery + processRole='play'
        $consumer = new SharedMemoryFrameBusConsumer(new StreamRegistryClient($registryAddr, 'subscribe'));
        MediaServer::$frameBusConsumer = $consumer;

        $consumer->onAnyFrame(function (
            string $path, string $payload, int $seq, bool $isKey, int $frameType, int $timestamp, int $special
        ) {
            // 确保有 shm publisher（RTMP/HTTP 播放端连接时会用到）
            MediaServer::addShmPublishStream($path);
            $frame = match ($frameType) {
                \MediaServer\MediaReader\MediaFrame::VIDEO_FRAME => new \MediaServer\MediaReader\VideoFrame($payload, $timestamp),
                \MediaServer\MediaReader\MediaFrame::AUDIO_FRAME => new \MediaServer\MediaReader\AudioFrame($payload, $timestamp),
                \MediaServer\MediaReader\MediaFrame::META_FRAME => new \MediaServer\MediaReader\MetaDataFrame($payload),
                default => null,
            };
            if ($frame !== null) {
                MediaServer::sendToPlayersByPath($frame, $path);
            }
        });

        $worker = new \Workerman\Worker("tcp://0.0.0.0:$rtmpPort");   // RTMP 播放入口
        $worker->onConnect = function ($connection) {
            new \MediaServer\Rtmp\RtmpStream(
                new \MediaServer\Utils\WMBufferStream($connection)
            );
        };
        $worker->onWorkerStart = function ($w) use ($consumer, $httpPort) {
            $consumer->start();

            // HTTP-FLV / WS-FLV 播放入口
            \MediaServer\Admin\AdminAuth::$startTime = timestamp();
            \MediaServer\Http\HttpWMServer::$publicPath = __DIR__ . '/../public';
            $httpServer = new \MediaServer\Http\PlayHttpServer(
                "\\MediaServer\\Http\\ExtHttpProtocol://127.0.0.1:$httpPort"
            );
            $httpServer->listen();

            // 统计上报：每 5s
            \Workerman\Timer::add(5, function () {
                $client = MediaServer::$registryClient;
                if ($client === null) return;
                $stats = MediaServer::getServerStats();
                $client->send('stats.report', [
                    'role' => 'play',
                    'pid' => getmypid(),
                    'data' => [
                        'playCount' => $stats['playCount'],
                        'memory' => $stats['memory'],
                        'streams' => MediaServer::getPlayStreamCount(),
                    ],
                ]);
            });
        };

        \Workerman\Worker::runAll();
    }
}
