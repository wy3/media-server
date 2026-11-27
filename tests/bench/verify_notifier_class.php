<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use MediaServer\Utils\MulticastNotifier;

$mode = $argv[1] ?? 'all';
$extDir = 'D:\php-8.4.25-nts-Win32-vs17-x64\ext';
$phpCmd = '"' . PHP_BINARY . '" -d extension_dir=' . escapeshellarg($extDir)
    . ' -d extension=sockets -d error_reporting=0';

if ($mode === 'receiver') {
    $iso = json_decode(base64_decode($argv[2]), true);
    $n = (int)($argv[3] ?? 20);
    $notifier = new MulticastNotifier($iso['group'], $iso['port'], $iso['token']);
    $sock = $notifier->initReceiver();

    $got = [];
    $deadline = microtime(true) + 10;
    while (count($got) < $n && microtime(true) < $deadline) {
        $notifier->drain(function (int $key, string $path) use (&$got) { $got[] = $key; });
        usleep(1000);
    }
    sort($got);
    $expected = range(1, $n);
    echo 'RECEIVED:' . implode(',', $got) . "\n";
    echo ($got === $expected ? 'PASS' : 'FAIL') . "\n";
    exit($got === $expected ? 0 : 1);
}

if ($mode === 'sender') {
    $iso = json_decode(base64_decode($argv[2]), true);
    $n = (int)($argv[3] ?? 20);
    $notifier = new MulticastNotifier($iso['group'], $iso['port'], $iso['token']);
    for ($i = 1; $i <= $n; $i++) {
        $notifier->notify($i);
        usleep(2000);
    }
    echo "SENT:$n\n";
    exit(0);
}

echo "=== MulticastNotifier 验证 ===\n\n";
$iso = MulticastNotifier::generateIsolation();
printf("隔离参数: group=%s port=%d token=0x%08x\n\n", $iso['group'], $iso['port'], $iso['token']);

$isoArg = base64_encode(json_encode($iso));
foreach ([1, 2] as $id) {
    $r[$id] = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . " receiver $isoArg 20 2>&1", 'r');
}
usleep(800000);
$s = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . " sender $isoArg 20 2>&1", 'r');
echo trim(stream_get_contents($s)) . "\n"; pclose($s);

$ok = true;
foreach ($r as $id => $h) {
    $out = trim(stream_get_contents($h)); pclose($h);
    echo "receiver$id: " . str_replace("\n", ' ', $out) . "\n";
    if (!preg_match('/PASS/', $out)) $ok = false;
}
echo "\n=== " . ($ok ? 'PASS' : 'FAIL') . " ===\n";
exit($ok ? 0 : 1);
