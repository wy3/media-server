<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
use MediaServer\Utils\MulticastNotifier;

$iso = ['group' => '239.183.88.77', 'port' => 13021, 'token' => 0x445566];
$extDir = 'D:\php-8.4.25-nts-Win32-vs17-x64\ext';
$phpCmd = '"' . PHP_BINARY . '" -d extension_dir=' . escapeshellarg($extDir) . ' -d extension=sockets';

$mode = $argv[1] ?? 'parent';

if ($mode === 'child_rx') {
    $r = new MulticastNotifier($iso['group'], $iso['port'], $iso['token']);
    $r->initReceiver();
    $got = 0;
    $deadline = microtime(true) + 6;
    while (microtime(true) < $deadline) {
        $got += $r->drain(function ($k, $p) {});
        usleep(500);
    }
    echo "CHILD_RX:$got\n";
    exit(0);
}

if ($mode === 'child_tx') {
    sleep(2);
    $s = new MulticastNotifier($iso['group'], $iso['port'], $iso['token']);
    for ($i = 1; $i <= 50; $i++) { $s->notify($i, '/live/x'); usleep(5000); }
    echo "CHILD_TX:50\n";
    exit(0);
}

// parent：起两个子进程，一个收一个发
$rx = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . ' child_rx 2>&1', 'r');
$tx = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . ' child_tx 2>&1', 'r');
echo trim(stream_get_contents($tx)) . "\n";
echo trim(stream_get_contents($rx)) . "\n";
pclose($tx); pclose($rx);
