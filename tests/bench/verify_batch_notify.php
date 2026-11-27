<?php

declare(strict_types=1);

/**
 * 验证批量通知机制：单进程内模拟 publisher 的 flushNotify + 消费者 drain
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MediaServer\Utils\MulticastNotifier;

if (!extension_loaded('shmop') || !extension_loaded('sockets')) {
    echo "需要 shmop+sockets 扩展\n";
    exit(1);
}

$iso = ['group' => '239.199.7.9', 'port' => 51515, 'token' => 123456789];

// 消费者
$notifierRx = new MulticastNotifier($iso['group'], $iso['port'], $iso['token']);
$rx = $notifierRx->initReceiver();

// 发布者
$notifierTx = new MulticastNotifier($iso['group'], $iso['port'], $iso['token']);
$notifierTx->initSender();

// 模拟批量：5 个流各写 10 帧（pendingNotify 里 5 个 key），flush 应只发 5 个包
$pending = ['/live/a' => 111, '/live/b' => 222, '/live/c' => 333, '/live/d' => 444, '/live/e' => 555];
foreach ($pending as $p => $k) {
    $notifierTx->notify($k, $p);
}
usleep(100000);   // 100ms 等内核送达

$got = [];
$notifierRx->drain(function ($key, $path) use (&$got) {
    $got[$path] = $key;
});

echo "sent=5 streams, received=" . count($got) . "\n";
$ok = count($got) === 5;
foreach ($pending as $p => $k) {
    if (($got[$p] ?? null) !== $k) { echo "  MISMATCH $p\n"; $ok = false; }
}
echo $ok ? "=== PASS ===\n" : "=== FAIL ===\n";
exit($ok ? 0 : 1);
