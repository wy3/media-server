<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/1
 * Time: 2:37
 */


require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';


class Main
{
    public function createRtmpServer()
    {
        $rtmpServer =  new \Workerman\Worker('tcp://0.0.0.0:1935');
        $rtmpServer->onConnect = function (\Workerman\Connection\TcpConnection $connection) {
            logger()->info("connection" . $connection->getRemoteAddress() . " connected . ");
            new \MediaServer\Rtmp\RtmpStream(
                new \MediaServer\Utils\WMBufferStream($connection)
            );
        };
        $rtmpServer->listen();
        logger()->info("rtmp server " . $rtmpServer->getSocketName() . " start . ");
    }

    public function createHttpServer()
    {
        $httpServer = new \MediaServer\Http\HttpWMServer("\\MediaServer\\Http\\ExtHttpProtocol://127.0.0.1:18080");
        $httpServer->listen();
        logger()->info("http server " . $httpServer->getSocketName() . " start . ");
    }

    public function run(){
        $globalWorker = new \Workerman\Worker();
        $globalWorker->onWorkerStart= function(){
            $this->createRtmpServer();
            $this->createHttpServer();
        };
    }
}


try {
    (new Main())->run();
    \Workerman\Worker::runAll();
} catch (Throwable $e) {

}

