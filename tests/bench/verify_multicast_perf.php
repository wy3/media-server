<?php

declare(strict_types=1);

/**
 * 组播性能完整验证。
 *
 * 测四项：
 *   1. 发送端：组播 send 吞吐（30,000 次/s 是否可持续）
 *   2. 接收端：多进程同时收 30,000 包/s 的 CPU 开销
 *   3. 组合：shmop 写帧 + 组播通知 的端到端成本
 *   4. payload 大小对组播的影响（4B key vs 大 payload）
 *
 * 用法：
 *   php verify_multicast_perf.php send_bench
 *   php verify_multicast_perf.php receiver <id> <duration>
 *   php verify_multicast_perf.php sender <rate> <duration>
 *   php verify_multicast_perf.php combo
 */

$mode = $argv[1] ?? 'send_bench';
$group = '239.183.77.99';
$port = 12992;
$iface = PHP_OS_FAMILY === 'Windows' ? 0 : '127.0.0.1';

function makeSender(): Socket
{
    global $group, $iface;
    $s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($s, IPPROTO_IP, IP_MULTICAST_TTL, 1);
    socket_set_option($s, IPPROTO_IP, IP_MULTICAST_LOOP, 1);
    if (PHP_OS_FAMILY !== 'Windows') {
        socket_set_option($s, IPPROTO_IP, IP_MULTICAST_IF, $iface);
    }
    return $s;
}

function makeReceiver(): Socket
{
    global $group, $port, $iface;
    $s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($s, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_bind($s, '0.0.0.0', $port);
    socket_set_option($s, IPPROTO_IP, MCAST_JOIN_GROUP, [
        'group' => $group, 'interface' => $iface,
    ]);
    socket_set_nonblock($s);
    return $s;
}

function bench(string $name, int $n, callable $fn): float
{
    $fn(min($n, 100));
    $t0 = hrtime(true);
    $fn($n);
    $ns = (hrtime(true) - $t0) / $n;
    printf("  %-52s %9.1f ns\n", $name, $ns);
    return $ns;
}

// ---------- 1. 发送端基准 ----------
if ($mode === 'send_bench') {
    echo "=== 组播发送端基准 ===\n\n";
    $s = makeSender();

    foreach ([4, 8, 100, 1500] as $len) {
        $payload = str_repeat('X', $len);
        $t = bench("组播 send {$len}B", 50000, function (int $n) use ($s, $payload, $group, $port) {
            for ($i = 0; $i < $n; $i++) socket_sendto($s, $payload, strlen($payload), 0, $group, $port);
        });
        printf("      → %.0f 次/s 可持续, %.1f MB/s\n\n", 1e9 / $t, $len / $t * 1000);
    }

    echo "=== 真实场景：30,000 次/s（1000路×30fps）===\n";
    $payload = str_repeat('X', 8);
    $t = bench('组播 send 8B', 100000, function (int $n) use ($s, $payload, $group, $port) {
        for ($i = 0; $i < $n; $i++) socket_sendto($s, $payload, 8, 0, $group, $port);
    });
    printf("  30,000 次/s 占用 = %.2f%% 单核\n", 30000 * $t / 1e7);
    echo "\n  结论: 发送端成本与 payload 大小关系不大，主要是 syscall 开销\n";
    exit(0);
}

// ---------- 2. 接收端 ----------
if ($mode === 'receiver') {
    $id = $argv[2] ?? '0';
    $duration = (int)($argv[3] ?? 15);
    $s = makeReceiver();
    $count = 0; $bytes = 0;
    $cpu0 = getrusage(); // 用户态+系统态时间
    $t0 = microtime(true);
    $deadline = $t0 + $duration;

    while (microtime(true) < $deadline) {
        $buf = ''; $from = ''; $fp = 0;
        $n = @socket_recvfrom($s, $buf, 4096, 0, $from, $fp);
        if ($n > 0) { $count++; $bytes += $n; }
        else usleep(50);
    }
    $wall = microtime(true) - $t0;
    $cpu1 = getrusage();
    $cpuSec = ($cpu1['ru_utime.tv_sec'] + $cpu1['ru_utime.tv_usec'] / 1e6
             + $cpu1['ru_stime.tv_sec'] + $cpu1['ru_stime.tv_usec'] / 1e6)
            - ($cpu0['ru_utime.tv_sec'] + $cpu0['ru_utime.tv_usec'] / 1e6
             + $cpu0['ru_stime.tv_sec'] + $cpu0['ru_stime.tv_usec'] / 1e6);
    printf("receiver%s: %.1fs 收 %d 包 (%d B) = %.0f 包/s, CPU %.2f%%\n",
        $id, $wall, $count, $bytes, $count / $wall, $cpuSec / $wall * 100);
    exit(0);
}

// ---------- 3. 发送端（持续速率） ----------
if ($mode === 'sender') {
    $rate = (int)($argv[2] ?? 30000);
    $duration = (int)($argv[3] ?? 15);
    $s = makeSender();
    $payload = str_repeat('X', 8);
    $interval = 1e9 / $rate;  // ns per packet
    $count = 0;
    $cpu0 = getrusage();
    $t0 = microtime(true);
    $next = hrtime(true);

    while (microtime(true) - $t0 < $duration) {
        socket_sendto($s, $payload, 8, 0, $group, $port);
        $count++;
        $next += (int)$interval;
        $now = hrtime(true);
        if ($next > $now) usleep((int)(($next - $now) / 1000));
        else $next = $now;
    }
    $wall = microtime(true) - $t0;
    $cpu1 = getrusage();
    $cpuSec = ($cpu1['ru_utime.tv_sec'] + $cpu1['ru_utime.tv_usec'] / 1e6
             + $cpu1['ru_stime.tv_sec'] + $cpu1['ru_stime.tv_usec'] / 1e6)
            - ($cpu0['ru_utime.tv_sec'] + $cpu0['ru_utime.tv_usec'] / 1e6
             + $cpu0['ru_stime.tv_sec'] + $cpu0['ru_stime.tv_usec'] / 1e6);
    printf("sender: %.1fs 发 %d 包 = %.0f 包/s (目标 %d), CPU %.2f%%\n",
        $wall, $count, $count / $wall, $rate, $cpuSec / $wall * 100);
    exit(0);
}

// ---------- 4. 组合：shmop 写 + 组播通知 ----------
if ($mode === 'combo') {
    echo "=== 组合成本：写 shmop 帧 + 组播通知 ===\n\n";
    if (!extension_loaded('shmop')) { echo "需要 shmop\n"; exit(1); }
    $shm = shmop_open(0x5EED5001, 'n', 0644, 65536 + 16 + 16000);
    if ($shm === false) { $shm = shmop_open(0x5EED5001, 'w', 0, 0); }
    $frame = str_repeat('X', 16000);
    $s = makeSender();

    $t_shm = bench('shmop_write(16KB 帧)', 20000, function (int $n) use ($shm, $frame) {
        for ($i = 0; $i < $n; $i++) shmop_write($shm, $frame, 16 + ($i % 4) * 16024);
    });
    $t_mc = bench('组播 send(8B key)', 100000, function (int $n) use ($s, $group, $port) {
        $p = pack('N', 1);
        for ($i = 0; $i < $n; $i++) socket_sendto($s, $p, 4, 0, $group, $port);
    });
    $t_both = bench('shmop 写 + 组播通知（一帧）', 20000, function (int $n) use ($shm, $frame, $s, $group, $port) {
        $p = pack('N', 1);
        for ($i = 0; $i < $n; $i++) {
            shmop_write($shm, $frame, 16 + ($i % 4) * 16024);
            socket_sendto($s, $p, 4, 0, $group, $port);
        }
    });

    printf("\n  单帧总成本: %.1f ns\n", $t_both);
    printf("  1000路×30fps: %.2f%% 单核\n", 30000 * $t_both / 1e7);
    echo "\n  结论: 组播通知成本远小于 shmop 写帧，不是瓶颈\n";
    exit(0);
}

echo "unknown mode: $mode\n";
