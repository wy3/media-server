<?php
/**
 * Date: 2021/9/14
 * Time: 16:57
 */


use MediaServer\Utils\BinaryStream;

require_once __DIR__ . '/vendor/autoload.php';

$b=new BinaryStream("\x01\x02");
echo dechex($b->readInt16LE()),PHP_EOL;
$b->push("\x01\x02");
echo dechex($b->readInt16()),PHP_EOL;
exit;

$worker = new \Workerman\Worker('tcp://0.0.0.0:8888');
$worker->reusePort = false;
$worker->count = 4;
\Workerman\Worker::runAll();
