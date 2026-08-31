<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';


//录像配置
\MediaServer\Recorder\RecorderManager::$enabled = true;
\MediaServer\Recorder\RecorderManager::$recordPath = __DIR__ . '/public/record';
\MediaServer\Recorder\RecorderManager::$fragmentDurationMs = 2000;  //fMP4 分片时长
\MediaServer\Recorder\RecorderManager::$segmentDurationMs = 60000;  //单个 .mp4 文件时长


$rtmpServer = new \Workerman\Worker('tcp://0.0.0.0:1935');
$rtmpServer->onConnect = function (\Workerman\Connection\TcpConnection $connection) {
    logger()->info("connection" . $connection->getRemoteAddress() . " connected . ");
    new \MediaServer\Rtmp\RtmpStream(
        new \MediaServer\Utils\WMBufferStream($connection)
    );
};
$rtmpServer->onWorkerStart = function (\Workerman\Worker $worker) {
    logger()->info("rtmp server " . $worker->getSocketName() . " start . ");
    \MediaServer\Http\HttpWMServer::$publicPath = __DIR__.'/public';
    $httpServer = new \MediaServer\Http\HttpWMServer("\\MediaServer\\Http\\ExtHttpProtocol://127.0.0.1:18080");
    $httpServer->listen();
    logger()->info("http server " . $httpServer->getSocketName() . " start . ");
};

\Workerman\Worker::runAll();
