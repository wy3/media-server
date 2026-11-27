<?php

declare(strict_types=1);

/**
 * 单独调试 play worker 的 consumer：EV_READ 注册后是否收到通知。
 * 不经 launcher，直接跑 play worker + 推流。
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/src/functions.php';

use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;
use MediaServer\Recorder\RecorderManager;
use MediaServer\Utils\MulticastNotifier;
use MediaServer\Utils\SharedMemoryFrameBusConsumer;

$isolation = json_decode(base64_decode($argv[2] ?? ''), true);
$rtmpPort = (int)($argv[3] ?? 1936);
$httpPort = (int)($argv[4] ?? 18080);

if (extension_loaded('event')) {
    \Workerman\Worker::$eventLoopClass = '\Workerman\Events\Event';
}

$notifier = new MulticastNotifier($isolation['group'], $isolation['port'], $isolation['token']);
MediaServer::enableShmStreamDiscovery();
RecorderManager::$enabled = false;
$consumer = new SharedMemoryFrameBusConsumer($notifier);
$frames = 0;
$consumer->onAnyFrame(function (
    string $path, string $payload, int $seq, bool $isKey, int $frameType, int $timestamp, int $special
) use (&$frames) {
    $frames++;
    if ($frames <= 5 || $frames % 100 === 0) {
        fwrite(STDERR, "[rx] path=$path type=$frameType seq=$seq frames=$frames\n");
    }
    MediaServer::addShmPublishStream($path);
    $frame = match ($frameType) {
        MediaFrame::VIDEO_FRAME => new VideoFrame($payload, $timestamp),
        MediaFrame::AUDIO_FRAME => new AudioFrame($payload, $timestamp),
        MediaFrame::META_FRAME => new MetaDataFrame($payload),
        default => null,
    };
    if ($frame !== null) MediaServer::sendToPlayersByPath($frame, $path);
});

$worker = new \Workerman\Worker("tcp://0.0.0.0:$rtmpPort");
$worker->onConnect = function ($connection) {
    new \MediaServer\Rtmp\RtmpStream(new \MediaServer\Utils\WMBufferStream($connection));
};
$worker->onWorkerStart = function ($w) use ($consumer, $httpPort, &$frames) {
    $consumer->start();
    fwrite(STDERR, "[play-debug] consumer EV_READ registered\n");
    \MediaServer\Http\HttpWMServer::$publicPath = dirname(__DIR__, 2) . '/public';
    $httpServer = new \MediaServer\Http\HttpWMServer(
        "\\MediaServer\\Http\\ExtHttpProtocol://127.0.0.1:$httpPort"
    );
    $httpServer->listen();
    fwrite(STDERR, "[play-debug] http listening on $httpPort\n");

    \Workerman\Timer::add(3, function () use (&$frames) {
        fwrite(STDERR, "[play-debug] total frames=$frames\n");
    });
};

\Workerman\Worker::runAll();
