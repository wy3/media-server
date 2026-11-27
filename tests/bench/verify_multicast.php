<?php

declare(strict_types=1);

/**
 * 用 PHP 跨平台 MCAST_JOIN_GROUP API 验证组播（Windows 8.4）。
 *
 * MCAST_JOIN_GROUP 接受 ['group' => '239.x.x.x', 'interface' => '0.0.0.0']
 */

$mode = $argv[1] ?? 'bench';
$group = '239.255.43.21';
$port = 12989;

function bench(string $name, int $n, callable $fn): float
{
    $t0 = hrtime(true); $fn($n);
    $ns = (hrtime(true) - $t0) / $n;
    printf("  %-46s %9.1f ns\n", $name, $ns);
    return $ns;
}

if ($mode === 'server') {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_bind($sock, '0.0.0.0', $port);

    $r = @socket_set_option($sock, IPPROTO_IP, MCAST_JOIN_GROUP, [
        'group' => $group,
        'interface' => 0,
    ]);
    if ($r === false) {
        fwrite(STDERR, '加入组播失败: ' . socket_strerror(socket_last_error($sock)) . "\n");
        exit(1);
    }
    echo "加入组播 OK (MCAST_JOIN_GROUP)\n";
    socket_set_nonblock($sock);

    $count = 0;
    $deadline = microtime(true) + 12;
    while (microtime(true) < $deadline && $count < 50) {
        $buf = '';
        $from = ''; $fp = 0;
        if (@socket_recvfrom($sock, $buf, 1024, 0, $from, $fp) > 0) $count++;
        usleep(100);
    }
    echo "收到 $count 包\n";
    exit(0);
}

if ($mode === 'client') {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_TTL, 1);
    socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_LOOP, 1);
    for ($i = 0; $i < 50; $i++) socket_sendto($sock, pack('N', $i), 4, 0, $group, $port);
    echo "发送 50 包\n";
    exit(0);
}

echo "=== MCAST_JOIN_GROUP 组播验证 ===\n";
echo 'MCAST_JOIN_GROUP = ' . MCAST_JOIN_GROUP . "\n\n";
