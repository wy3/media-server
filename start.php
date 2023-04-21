<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';


$rtmpServer = new \Workerman\Worker('tcp://0.0.0.0:1935');
$rtmpServer->onConnect = function (\Workerman\Connection\TcpConnection $connection) {
    logger()->info("connection" . $connection->getRemoteAddress() . " connected . ");
    new \MediaServer\Rtmp\RtmpStream(
        new \MediaServer\Utils\WMBufferStream($connection)
    );
};
$rtmpServer->onWorkerStart = function ($worker) {
    logger()->info("rtmp server " . $worker->getSocketName() . " start . ");
    $httpServer = new \MediaServer\Http\HttpWMServer("\\MediaServer\\Http\\ExtHttpProtocol://127.0.0.1:18080");
    $httpServer->listen();
    logger()->info("http server " . $httpServer->getSocketName() . " start . ");
};

\Workerman\Worker::runAll();

