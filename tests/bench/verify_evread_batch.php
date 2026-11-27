<?php

declare(strict_types=1);

/**
 * 验证：EV_READ 回调时，socket 缓冲区里是否会积压多个 UDP 包？
 * 一次回调能/read 几个包？
 *
 * 关键问题：
 *   1. UDP 是数据报，一次 recvfrom 只读一个包
 *   2. 如果发送端突发 N 个包，接收端 EV_READ 会触发几次？
 *   3. 一次回调里应该循环读到 EAGAIN 吗？
 *
 * 用法：
 *   php verify_evread_batch.php receiver <mode> <duration>
 *   php verify_evread_batch.php sender <count> <burst>
 */

$port = 12996;
$extDir = 'D:\php-8.4.25-nts-Win32-vs17-x64\ext';
$phpCmd = '"' . PHP_BINARY . '" -d extension_dir=' . escapeshellarg($extDir)
    . ' -d extension=sockets -d extension=event';

use Workerman\Worker;
use Workerman\Events\EventInterface;

$mode = $argv[1] ?? 'bench';

if ($mode === 'receiver') {
    $readMode = $argv[2] ?? 'one';   // one=每次回调读1个, all=读到EAGAIN
    $duration = (int)($argv[3] ?? 10);

    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    Worker::$eventLoopClass = '\Workerman\Events\Event';
    $w = new Worker('tcp://0.0.0.0:0');
    $w->count = 1;
    $w->onWorkerStart = function ($worker) use ($readMode, $duration, $port) {
        // Workerman Event 扩展不支持 PHP8 Socket 对象，改用 stream socket
        $ctx = stream_context_create(['socket' => ['reuseaddr' => 1]]);
        $s = stream_socket_server("udp://127.0.0.1:$port", $en, $es, STREAM_SERVER_BIND, $ctx);
        stream_set_blocking($s, false);

        $GLOBALS['callbacks'] = 0;
        $GLOBALS['packets'] = 0;
        $GLOBALS['maxPerCb'] = 0;

        Worker::getEventLoop()->add($s, EventInterface::EV_READ, function ($fd) use ($s, $readMode) {
            $GLOBALS['callbacks']++;
            $perCb = 0;
            do {
                $buf = @fread($s, 4096);
                $n = strlen((string)$buf);
                if ($n > 0) {
                    $perCb++;
                    $GLOBALS['packets']++;
                }
            } while ($n > 0 && $readMode === 'all');
            if ($perCb > $GLOBALS['maxPerCb']) $GLOBALS['maxPerCb'] = $perCb;
        });

        \Workerman\Timer::add($duration, function () use ($readMode) {
            printf("mode=%s callbacks=%d packets=%d max_per_cb=%d avg=%.2f\n",
                $readMode, $GLOBALS['callbacks'], $GLOBALS['packets'],
                $GLOBALS['maxPerCb'],
                $GLOBALS['callbacks'] ? $GLOBALS['packets'] / $GLOBALS['callbacks'] : 0);
            Worker::stopAll();
        }, [], false);
    };
    Worker::runAll();
    exit(0);
}

if ($mode === 'sender') {
    $count = (int)($argv[2] ?? 1000);
    $burst = (int)($argv[3] ?? 100);   // 每批连续发多少
    $s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    $payload = str_repeat('X', 8);
    $sent = 0;
    while ($sent < $count) {
        $n = min($burst, $count - $sent);
        for ($i = 0; $i < $n; $i++) {
            socket_sendto($s, $payload, 8, 0, '127.0.0.1', $port);
        }
        $sent += $n;
        usleep(20000);   // 每批间隔 20ms，制造突发
    }
    echo "sent $count in bursts of $burst\n";
    exit(0);
}

// bench：对比 one vs all 模式
echo "=== EV_READ 批量读取验证 ===\n\n";
echo "场景：发送端突发 100 包/批，共 1000 包\n\n";

foreach (['one', 'all'] as $readMode) {
    $r = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . " receiver $readMode 8 2>&1", 'r');
    usleep(800000);
    $s = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . " sender 1000 100 2>&1", 'r');
    $sout = trim(stream_get_contents($s)); pclose($s);
    // 等接收端 Timer 结束
    $rout = trim(stream_get_contents($r)); pclose($r);
    echo "  $sout\n";
    echo "  " . ($rout ?: "(no output)") . "\n\n";
}
