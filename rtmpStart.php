<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/1
 * Time: 2:37
 */

use MediaServer\FlvStream;
use MediaServer\MediaServer;
use MediaServer\PlayerStream;
use React\EventLoop\Loop;
use React\Stream\ThroughStream;
use RingCentral\Psr7\Response;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';
//\Workerman\Worker::$eventLoopClass=\Workerman\Events\Event::class;
//$worker=new \Workerman\Worker('tcp://0.0.0.0:1935');
//$worker->onWorkerStart=function(\Workerman\Worker $w){
//    echo \Workerman\Worker::$eventLoopClass,PHP_EOL;
//};
//$worker->onConnect=function(Workerman\Connection\ConnectionInterface $connection){
//       logger()->info("connection" . $connection->getRemoteAddress() . " connected . ");
//    $rtmpStream=new \MediaServer\Rtmp\RtmpStream($connection);
//};
//
//\Workerman\Worker::runAll();
//

echo_now_init();

$server = new React\Socket\SocketServer('tcp://0.0.0.0:1935');

$server->on('connection', function (React\Socket\ConnectionInterface $connection) {
    logger()->info("connection" . $connection->getRemoteAddress() . " connected . ");
    $rtmpStream=new \MediaServer\Rtmp\RtmpStream($connection);
});
Loop::addPeriodicTimer(5,function(){
    logger()->info("[memory] memory:".memory_get_usage());
    $playCount=0;
    foreach (MediaServer::$playerStream as $g){
        $playCount+=count($g);
    }
    logger()->info("[media server] publisher:".count(MediaServer::$publishStream)." player:".$playCount);
});
logger()->info("server " . $server->getAddress() . " start . ");
Loop::run();
