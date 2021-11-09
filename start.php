<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/1
 * Time: 2:37
 */

use MediaServer\MediaServer;
use React\EventLoop\Loop;
use React\Stream\ThroughStream;
use RingCentral\Psr7\Response;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';


class Main
{
    public function createRtmpServer()
    {
        $rtmpServer = new React\Socket\SocketServer('tcp://0.0.0.0:1935');
        $rtmpServer->on('connection', function (React\Socket\ConnectionInterface $connection) {
            logger()->info("connection" . $connection->getRemoteAddress() . " connected . ");
            new \MediaServer\Rtmp\RtmpStream($connection);
        });
        logger()->info("rtmp server " . $rtmpServer->getAddress() . " start . ");
    }

    public function createHttpServer()
    {
        $httpServer=new \MediaServer\Http\HttpServer();
        $socket = new React\Socket\SocketServer('tcp://0.0.0.0:18080');
        $httpServer()->listen($socket);
        $httpServer()->on('error',function($e){
            var_dump($e->getMessage());
        });

        logger()->info("http server " . $socket->getAddress() . " start . ");

    }

    public function run(){
        $this->createRtmpServer();
        $this->createHttpServer();
    }
}


try {
    (new Main())->run();
} catch (Throwable $e) {

}

