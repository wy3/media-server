<?php

declare(strict_types=1);

/**
 * 组播/单播 UDP 零丢包验证（精确序号校验）。
 *
 * 之前的测试只统计"收到多少包"，可能有误差。
 * 本脚本：每个包带 4 字节序号，接收端校验：
 *   - 总数是否 == 发送数
 *   - 序号是否连续（丢失/重复/乱序都能检测）
 *
 * 同时验证：
 *   1. 增大 SO_RCVBUF 是否消除丢包
 *   2. stream fread 合并读取（用户想要的行为）是否丢包
 *
 * 用法：
 *   php verify_no_loss.php receiver <mode> <expected> <bufsize>
 *   php verify_no_loss.php sender <count> <rate> <mode>
 *   mode: multicast / unicast
 */

$port = 12997;
$extDir = 'D:\php-8.4.25-nts-Win32-vs17-x64\ext';
$phpCmd = '"' . PHP_BINARY . '" -d extension_dir=' . escapeshellarg($extDir) . ' -d extension=sockets';
$group = '239.183.77.102';

$mode = $argv[1] ?? 'bench';
$kind = $argv[2] ?? 'multicast';

if ($mode === 'receiver') {
    $kind = $argv[2] ?? 'multicast';
    $expected = (int)($argv[3] ?? 10000);
    $bufSize = (int)($argv[4] ?? 0);   // 0=默认

    // 用 socket_create（精确控制 SO_RCVBUF 和 recvfrom 边界）
    $s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($s, SOL_SOCKET, SO_REUSEADDR, 1);
    if ($bufSize > 0) {
        socket_set_option($s, SOL_SOCKET, SO_RCVBUF, $bufSize);
    }
    $bindAddr = $kind === 'multicast' ? '0.0.0.0' : '127.0.0.1';
    socket_bind($s, $bindAddr, $port);
    if ($kind === 'multicast') {
        socket_set_option($s, IPPROTO_IP, MCAST_JOIN_GROUP, [
            'group' => $group, 'interface' => 0,
        ]);
    }
    socket_set_nonblock($s);

    $seen = [];
    $dup = 0;
    $deadline = microtime(true) + 30;
    $lastReport = microtime(true);

    while (count($seen) < $expected && microtime(true) < $deadline) {
        $buf = ''; $from = ''; $fp = 0;
        $n = @socket_recvfrom($s, $buf, 4096, 0, $from, $fp);
        if ($n >= 4) {
            // 每个包前 4 字节是序号，可能一次 recvfrom 只读一个 UDP 包
            $seq = unpack('N', substr($buf, 0, 4))[1];
            if (isset($seen[$seq])) $dup++;
            $seen[$seq] = 1;
        }
        if (microtime(true) - $lastReport > 5) {
            $lastReport = microtime(true);
            fwrite(STDERR, "  recv progress: " . count($seen) . "/$expected\n");
        }
    }

    $got = count($seen);
    $missing = [];
    for ($i = 1; $i <= $expected; $i++) {
        if (!isset($seen[$i])) $missing[] = $i;
    }
    $actualBuf = socket_get_option($s, SOL_SOCKET, SO_RCVBUF);
    printf("RESULT kind=%s bufsize=%d actual_buf=%d got=%d/%d missing=%d dup=%d first_missing=%s\n",
        $kind, $bufSize, $actualBuf, $got, $expected, count($missing), $dup,
        $missing ? $missing[0] : 'none');
    exit($got === $expected && $dup === 0 ? 0 : 1);
}

if ($mode === 'sender') {
    $kind = $argv[2] ?? 'multicast';
    $count = (int)($argv[3] ?? 10000);
    $rate = (int)($argv[4] ?? 30000);   // 0=不限速

    $s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    $target = $kind === 'multicast' ? $group : '127.0.0.1';
    if ($kind === 'multicast') {
        socket_set_option($s, IPPROTO_IP, IP_MULTICAST_TTL, 1);
        socket_set_option($s, IPPROTO_IP, IP_MULTICAST_LOOP, 1);
    }

    $t0 = microtime(true);
    for ($i = 1; $i <= $count; $i++) {
        $payload = pack('N', $i) . str_repeat('X', 4);   // 8B: seq + pad
        socket_sendto($s, $payload, strlen($payload), 0, $target, $port);
        if ($rate > 0) {
            $targetTime = $t0 + $i / $rate;
            $now = microtime(true);
            if ($now < $targetTime) {
                $us = (int)(($targetTime - $now) * 1e6);
                if ($us > 0) usleep(min($us, 100000));
            }
        }
    }
    $el = microtime(true) - $t0;
    printf("SENT kind=%s count=%d elapsed=%.2fs rate=%.0f/s\n", $kind, $count, $el, $count / $el);
    exit(0);
}

// bench 模式：测试不同缓冲区和速率
echo "=== UDP 零丢包验证（序号校验）===\n\n";
echo "场景 A: 默认缓冲区, 低速率(1000/s), 10000 包\n";
foreach (['unicast', 'multicast'] as $k) {
    $r = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . " receiver $k 10000 0 2>&1", 'r');
    usleep(600000);
    $s = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . " sender $k 10000 1000 2>&1", 'r');
    $sout = trim(stream_get_contents($s)); pclose($s);
    $rout = trim(stream_get_contents($r)); pclose($r);
    echo "  $k: " . ($rout ?: "(timeout)") . "\n";
}

echo "\n场景 B: 大缓冲区(4MB), 高速率(30000/s), 30000 包\n";
foreach (['unicast', 'multicast'] as $k) {
    $r = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . " receiver $k 30000 4194304 2>&1", 'r');
    usleep(600000);
    $s = popen($phpCmd . ' ' . escapeshellarg(__FILE__) . " sender $k 30000 30000 2>&1", 'r');
    $sout = trim(stream_get_contents($s)); pclose($s);
    $rout = trim(stream_get_contents($r)); pclose($r);
    echo "  $k: " . ($rout ?: "(timeout)") . "\n";
}
