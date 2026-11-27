<?php

declare(strict_types=1);

/**
 * 星形广播成本验证。
 *
 * 关键问题：1000 路推流 × 30fps × M 个播放进程 = 多少次通知？
 *
 * 用户推算：1000 × 30 × 10 = 300,000 次/s
 * 本脚本实测这个量级的 UDP send 成本。
 */

function bench(string $name, int $n, callable $fn): float
{
    $t0 = hrtime(true);
    $fn($n);
    $ns = (hrtime(true) - $t0) / $n;
    printf("  %-46s %9.1f ns\n", $name, $ns);
    return $ns;
}

$us = stream_socket_server('udp://127.0.0.1:0', $en, $es, STREAM_SERVER_BIND);
$addr = stream_socket_get_name($us, false);

// 模拟 10 个播放进程（10 个 UDP 端点）
$sockets = [];
for ($i = 0; $i < 10; $i++) {
    $sockets[] = stream_socket_client('udp://' . $addr, $en, $es, 2);
}
foreach ($sockets as $s) stream_set_blocking($s, false);

$payload = pack('N', 12345);

echo "=== 单播 UDP send 成本 ===\n";
$per = bench('1 次 UDP send(4B)', 100000, function (int $n) use ($sockets, $payload) {
    $s = $sockets[0];
    for ($i = 0; $i < $n; $i++) fwrite($s, $payload);
});

echo "\n=== 场景推算：1 帧通知 M 个播放进程 ===\n\n";
foreach ([1, 5, 10] as $m) {
    for ($streams = 100; $streams <= 1000; $streams *= 10) {
        $sendsPerSec = $streams * 30 * $m;
        // 模拟 1 秒的量（用 100000 次外推）
        $cpuMs = $sendsPerSec * $per / 1e6;
        $pct = $cpuMs / 10;  // 1 秒 = 1000ms，单核 100% = 1000ms
        printf("  %4d 路 × 30fps × %2d 播进程 = %7d 次/s → %6.1f ms/s = %5.2f%% 单核\n",
            $streams, $m, $sendsPerSec, $cpuMs, $pct);
    }
    echo "\n";
}

echo "=== 结论 ===\n";
echo "  1000路 × 30fps × 10播放进程 = 300,000 次/s\n";
printf("  单靠 send 就要 %.0f%% 单核，完全不可接受\n", 1000 * 30 * 10 * $per / 1e7);
echo "\n";

echo "=== 修正方案：只通知有订阅的流 ===\n";
echo "  假设 1000 路推流中只有 10% 有播放端（100 路）\n";
echo "  且每路流平均订阅进程数 = 2\n";
$subSends = 100 * 30 * 2;
printf("  → %d 次/s = %.2f%% 单核 ✅\n", $subSends, $subSends * $per / 1e7);
echo "\n";
echo "  但极端情况：1000 路都有播放端，每路 10 个进程订阅\n";
echo "  → 仍然 300,000 次/s，回到原点\n";
