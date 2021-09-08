<?php
/**
 * Date: 2021/9/6
 * Time: 14:42
 */


require_once __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Workerman\Events\React\Base;
use Workerman\Events\React\ExtEventLoop;
use Workerman\Events\React\StreamSelectLoop;
use Workerman\Timer;
use Workerman\Worker;
Worker::$eventLoopClass=Workerman\Events\React\StreamSelectLoop::class;

$worker = new Worker();
$worker->onWorkerStart = function ($w) {
    /** @var LoopInterface $loop */
    $loop = Worker::getEventLoop();
    $context = new React\ZMQ\Context($loop);
    $pub = $context->getSocket(ZMQ::SOCKET_PUB);
    $pub->bind('ipc:///tmp/555');
    $pub->on('error', function ($e) {
        var_dump($e->getMessage());
    });
    $data = 'f ' .pack('@1024');
    Timer::add(2, function () use ($pub, $data) {
        $i = microtime(true);
        //echo "publishing $i\n";
        //$pub->send('foo1 ' . $i);
        //$pub->sendmulti(array('foo', $i)); // you don't get this one
        //echo "publishing $i\n";
        echo microtime(true) . " send begin.\n";
        for ($i = 0; $i < 1000000; $i++) {
            $pub->send($data);
        }
        echo microtime(true) . " send end.\n";
        // you get this one in the sub socket
        //$pub->sendmulti(array('bus', $i)); // you don't get this one
    }, [], false);
};
$c = new Worker();
$c->count = 1;
$c->onWorkerStart = function ($w) {
    /** @var LoopInterface $loop */
    $loop = Worker::getEventLoop();
    $context = new React\ZMQ\Context($loop,new ZMQContext(10));
    $sub = $context->getSocket(ZMQ::SOCKET_SUB);
    $sub->connect('ipc:///tmp/555');
    $sub->setSockOpt(ZMQ::SOCKOPT_RCVHWM,100);
    $sub->subscribe('f');
    $recCount = 0;
    $first=0;
    $last=0;
    $sub->on('messages', function ($msg) use (&$recCount,&$first,&$last) {
        if($recCount===0){
            $first=microtime(true);
        }
        $recCount++;
        $last=microtime(true);
    });
    Timer::add(1, function () use (&$recCount,&$first,&$last) {
        if($recCount>0){
            $first=microtime(true);
            echo  "first: $first last: $last reciev $recCount packet.\n";
        }

    });
    $sub->on('error', function ($e) {
        var_dump($e->getMessage());
    });

};

Worker::runAll();
