<?php
/**
 * Date: 2021/9/6
 * Time: 14:42
 */


require_once __DIR__ . '/vendor/autoload.php';

use Cassandra\Time;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Events\React\Base;
use Workerman\Events\React\ExtEventLoop;
use Workerman\Events\React\StreamSelectLoop;
use MediaServer\Frame;
use Workerman\Timer;
use Workerman\Worker;

AsyncTcpConnection::$defaultMaxSendBufferSize=1024*1024*1024;
AsyncTcpConnection::$defaultMaxPackageSize=1024*1024*1024;

$channelWorker=new \Channel\Server('unix:///tmp/test.sock');
$worker = new Worker();
$data = pack('@100');
$worker->onWorkerStart=function($w)use($data){

    Channel\Client::connect('unix:///tmp/test.sock');
    Timer::add(2,function()use($w,$data){
        /** @var Worker $w */
            for ($i = 0; $i < 100000; $i++) {
                Channel\Client::publish('broadcast', $data );
            }
    });
};

$c = new Worker();
$c->count = 2;
$c->onWorkerStart = function ($w) {
    Channel\Client::connect('unix:///tmp/test.sock');
    // 订阅broadcast事件，并注册事件回调
    $recCount = 0;
    $first = 0;
    $last = 0;
    Channel\Client::on('broadcast', function($event_data)use (&$recCount, &$first, &$last){
        if ($recCount === 0) {
            $first = microtime(true);
        }
        $recCount++;
        $last = microtime(true);
    });
    Timer::add(1, function () use (&$recCount, &$first, &$last) {
        $avg = $recCount / (($last - $first)?:1);
        echo "first: $first last: $last packet: $recCount  avg: $avg p/s.\n";
    });
};

Worker::runAll();
