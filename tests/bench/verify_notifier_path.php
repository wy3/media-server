<?php

declare(strict_types=1);

/**
 * 验证 stream fread 读取 UDP 数据报时，合并读取能否按固定长度对齐。
 * 关键：fread 是否会从数据报中间开始（导致 136 字节对齐错乱）。
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
use MediaServer\Utils\MulticastNotifier;

$mode = $argv[1] ?? 'all';
$iso = ['group' => '239.183.99.77', 'port' => 13010, 'token' => 0x12345678];
$extDir = 'D:\php-8.4.25-nts-Win32-vs17-x64\ext';
$phpCmd = '"' . PHP_BINARY . '" -d extension_dir=' . escapeshellarg($extDir)
    . ' -d extension=sockets -d error_reporting=0';

if ($mode === 'receiver') {
    $n = new MulticastNotifier($iso['group'], $iso['port'], $iso['token']);
    $n->initReceiver();
    $got = [];
    $deadline = microtime(true) + 8;
    while (count($got) < 100 && microtime(true) < $deadline) {
        $n->drain(function (int $key, string $path) use (&$got) {
            $got[] = "$key:$path";
        });
        usleep(100);
    }
    sort($got);
    echo 'GOT:' . count($got) . "\n";
    echo 'FIRST:' . ($got[0] ?? 'none') . "\n";
    echo 'LAST:' . ($got[count($got)-1] ?? 'none') . "\n";
    // 检查是否全部有效
    $valid = true;
    foreach ($got as $g) {
        if (!preg_match('/^(\d+):\/live\/test\d+$/', $g)) { $valid = false; break; }
    }
    echo ($valid && count($got) === 100 ? 'PASS' : 'FAIL') . "\n";
    exit(0);
}

if ($mode === 'sender') {
    $n = new MulticastNotifier($iso['group'], $iso['port'], $iso['token']);
    for ($i = 1; $i <= 100; $i++) {
        $n->notify($i, "/live/test$i");
        usleep(1000);
    }
    echo "SENT:100\n";
    exit(0);
}

echo "=== 136B payload 合并读取验证 ===\n\n";
$r = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . ' receiver 2>&1', 'r');
usleep(800000);
$s = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . ' sender 2>&1', 'r');
echo trim(stream_get_contents($s)) . "\n"; pclose($s);
$out = trim(stream_get_contents($r)); pclose($r);
echo str_replace("\n", ' | ', $out) . "\n";
