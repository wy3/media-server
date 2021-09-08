<?php
/**
 * Date: 2021/9/6
 * Time: 14:42
 */


require_once __DIR__ . '/vendor/autoload.php';

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

//$worker = new Worker('unix:///tmp/test.sock');
@unlink('/tmp/test.sock');
$w = new Worker();
$w->name='onmsg';
$w->onWorkerStart = function () {
    $worker = new \MediaServer\Channel\Server(
        Worker::getEventLoop(),
        'udg:///tmp/test.sock'
    );
    $recCount = 0;
    $first = 0;
    $last = 0;
    $bufferSize = 0;
    $maxDataSize=0;
    $worker->onMessage = function ($data)use (&$recCount, &$first, &$last, &$bufferSize,&$maxDataSize) {
        //echo "server msg $data", PHP_EOL;
        if ($recCount === 0) {
            $first = microtime(true);
        }
        $bufferSize += strlen($data);
        $recCount++;
        $last = microtime(true);
        $maxDataSize=max(strlen($data),$maxDataSize);
    };
    $worker->listen();
    Timer::add(1, function () use (&$recCount, &$first, &$last, &$bufferSize,&$maxDataSize) {
        $avg = $recCount / (($last - $first) ?: 1);
        //$avgByte=$bufferSize/($recCount ?: 1);
        //echo "first: $first last: $last  getByte: $bufferSize maxPacketSize: $maxDataSize packet: $recCount  avg: $avg p/s.\n";
    });

};

$c = new Worker();
$c->count = 1;
$c->name='send';
$c->onWorkerStart = function ($w) {

    Timer::add(1, function () {
        $udg = new \MediaServer\Channel\Client(Worker::getEventLoop(),'udg:///tmp/test.sock');
        $udg->onceMaxSend=1000;
        $data=pack('@100');
        Timer::add(1, function ()use($udg,$data) {
            $beginSend=microtime(true);
            for($i=0;$i<50000;$i++){
                $udg->send($data);
            }
            $use=microtime(true)-$beginSend;
            echo "now ".microtime(true)." send $i packets use $use s.\n";
        });
        $udg->send(time());
    },[],false);

    return;
    //$tcp = new AsyncTcpConnection('unix:///tmp/test.sock');
    $udp = new \MediaServer\AsyncUdgConnection('udg://'.__DIR__.'/test.sock');
    //$udp->protocol = Frame::class;
    $recCount = 0;
    $first = 0;
    $last = 0;
    $bufferSize = 0;
    $udp->onMessage = function ($udp, $msg) use (&$recCount, &$first, &$last, &$bufferSize) {

        if ($recCount === 0) {
            $first = microtime(true);
        }
        $bufferSize += strlen($msg);
        $recCount++;
        $last = microtime(true);
    };
    $udp->onConnect = function ($udp) use (&$recCount, &$first, &$last, &$bufferSize) {
        echo "udg connected.\n";
        //随便发点消息先
        /** @var \MediaServer\AsyncUdgConnection $udp */
        var_dump($udp->send('hi'));
        Timer::add(1, function () use ($udp, &$recCount, &$first, &$last, &$bufferSize) {
            $avg = $recCount / (($last - $first) ?: 1);
            echo "first: $first last: $last $bufferSize byte $recCount packet avg: $avg p/s.\n";
        });
    };
    $udp->connect();
};

Worker::runAll();
