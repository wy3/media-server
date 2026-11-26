<?php
declare(strict_types=1);

/**
 * 压测用服务端。
 *
 * 与 start.php 的差异：
 *   - 端口可配置（避免与正常实例冲突）
 *   - 录像默认关闭（排除磁盘 IO 干扰）
 *   - 可指定事件循环（对比 select / event）
 *   - 可通过环境变量控制日志输出（压测时关掉，避免写盘拖累）
 *
 * 用法：
 *   php server_bench.php [select|event] [rtmpPort] [httpPort]
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/functions.php';

\MediaServer\Recorder\RecorderManager::$enabled = false;

$loop = $argv[1] ?? 'event';
$rtmpPort = (int)($argv[2] ?? 11935);
$httpPort = (int)($argv[3] ?? 11980);

if ($loop === 'event') {
    \Workerman\Worker::$eventLoopClass = '\Workerman\Events\Event';
} elseif ($loop === 'select') {
    \Workerman\Worker::$eventLoopClass = '\Workerman\Events\Select';
} else {
    fwrite(STDERR, "unknown loop: $loop (use select|event)\n");
    exit(1);
}

$rtmpServer = new \Workerman\Worker("tcp://0.0.0.0:$rtmpPort");
$rtmpServer->onConnect = function (\Workerman\Connection\TcpConnection $connection) {
    new \MediaServer\Rtmp\RtmpStream(
        new \MediaServer\Utils\WMBufferStream($connection)
    );
};
$rtmpServer->onWorkerStart = function (\Workerman\Worker $worker) use ($httpPort) {
    \MediaServer\Admin\AdminAuth::$startTime = timestamp();
    \MediaServer\Http\HttpWMServer::$publicPath = __DIR__ . '/../../../public';
    $httpServer = new \MediaServer\Http\HttpWMServer("\\MediaServer\\Http\\ExtHttpProtocol://127.0.0.1:$httpPort");
    $httpServer->listen();
    echo '[bench] event-loop=', get_class(\Workerman\Worker::$globalEvent),
        ' rtmp=', $worker->getSocketName(),
        ' http=127.0.0.1:', $httpPort, PHP_EOL;
};

\Workerman\Worker::runAll();
