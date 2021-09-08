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

$worker = new Worker('unix:///tmp/test.sock');
//$worker = new Worker('tcp://127.0.0.1:38888');
$worker->protocol = Frame::class;
$data = pack('@1');
$worker->onConnect = function ($con) use ($data) {
    $con->maxSendBufferSize=200000*200;
    echo "server connection connected.", PHP_EOL;
    for ($i = 0; $i < 200000; $i++) {
        $con->send($data);
    }
};
$worker->onMessage = function ($con, $data) {
    // echo "server msg $data", PHP_EOL;
};
$c = new Worker();
$c->count = 1;
$c->onWorkerStart = function ($w) {

    $tcp = new AsyncTcpConnection('unix:///tmp/test.sock');
    //$tcp = new AsyncTcpConnection('tcp://127.0.0.1:38888');
    $tcp->protocol = Frame::class;
    $tcp->maxPackageSize=200000*1100;
    $recCount = 0;
    $first = 0;
    $last = 0;
    $bufferSize = 0;
    $tcp->onMessage = function ($con, $msg) use (&$recCount, &$first, &$last, &$bufferSize) {

        if ($recCount === 0) {
            $first = microtime(true);
        }
        $bufferSize += strlen($msg);
        $recCount++;
        $last = microtime(true);
    };
    $tcp->onConnect = function ($tcp) use (&$recCount, &$first, &$last, &$bufferSize) {
        Timer::add(1, function () use (&$recCount, &$first, &$last, &$bufferSize) {
            $avg = $recCount / (($last - $first)?:1);
            echo "first: $first last: $last $bufferSize byte $recCount packet avg: $avg p/s.\n";
        });
    };
    $tcp->connect();
};

Worker::runAll();
