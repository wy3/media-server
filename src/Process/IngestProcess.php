<?php

declare(strict_types=1);

/**
 * ingest 进程：推流池。
 *
 * RTMP publish（端口 $rtmpPort）+ HTTP-FLV publish（POST /live/x，端口 $httpPort）
 * → RTMP 解析 → 写 shm 帧段 → 向 control 注册（publish.req 等回复，拒绝则中断推流）。
 * 每 5s 上报统计 + 流租约心跳；收到 notify.kick 断开对应推流。
 */

namespace MediaServer\Process;

use MediaServer\MediaServer;
use MediaServer\Recorder\RecorderManager;
use MediaServer\Utils\StreamRegistryClient;

final class IngestProcess
{
    public static function run(int $rtmpPort, int $httpPort, string $registryAddr): void
    {
        MediaServer::enableSharedMemoryFrameBus(
            (int)(getenv('MP_SHM_FRAMESLOTS') ?: 0),
            (int)(getenv('MP_SHM_DATAKB') ?: 0)
        );
        MediaServer::enableIngestRole();       // 拒绝 RTMP play / HTTP play
        MediaServer::setRegistryClient(new StreamRegistryClient($registryAddr, 'ingest'));
        RecorderManager::$enabled = false;     // 录像由独立池负责

        // RTMP 推流入口
        $worker = new \Workerman\Worker("tcp://0.0.0.0:$rtmpPort");
        $worker->onConnect = function ($connection) {
            new \MediaServer\Rtmp\RtmpStream(
                new \MediaServer\Utils\WMBufferStream($connection)
            );
        };
        $worker->onWorkerStart = function ($w) use ($httpPort) {
            // HTTP-FLV 推流入口（POST /live/x）
            \MediaServer\Admin\AdminAuth::$startTime = timestamp();
            \MediaServer\Http\HttpWMServer::$publicPath = __DIR__ . '/../public';
            $httpServer = new \MediaServer\Http\IngestHttpServer(
                "\\MediaServer\\Http\\ExtHttpProtocol://0.0.0.0:$httpPort"
            );
            $httpServer->listen();

            // 统计上报 + 流租约心跳：每 5s 推给 control
            \Workerman\Timer::add(5, function () {
                $client = MediaServer::$registryClient;
                if ($client === null) return;
                $stats = MediaServer::getServerStats();
                $paths = array_map(
                    fn($s) => $s['publishStreamPath'],
                    MediaServer::listPushStream()
                );
                $client->send('stats.report', [
                    'role' => 'ingest',
                    'pid' => getmypid(),
                    'data' => [
                        'publishCount' => $stats['publishCount'],
                        'memory' => $stats['memory'],
                    ],
                ]);
                $client->send('heartbeat', ['pid' => getmypid(), 'paths' => $paths]);
            });

            // notify.kick：管理踢出，断开对应推流（触发 on_close → publish.end）
            MediaServer::$registryClient?->onNotify(function (array $ev) {
                if (($ev['op'] ?? '') === 'notify.kick') {
                    $path = (string)($ev['path'] ?? '');
                    $pub = MediaServer::getPublishStream($path);
                    if ($pub !== null && method_exists($pub, 'close')) {
                        $pub->close();
                    }
                }
            });
        };

        \Workerman\Worker::runAll();
    }
}
