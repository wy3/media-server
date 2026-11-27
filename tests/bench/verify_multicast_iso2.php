<?php

declare(strict_types=1);

/**
 * 验证：随机 group + 随机端口 是否能隔离外部程序。
 *
 * 场景：
 *   server 加入 group A + port A
 *   client 发送：正确 group+port（mine）、错误 group（wrong_group）、错误端口（wrong_port）
 *   检查 server 是否只收到 mine
 */

$mode = $argv[1] ?? 'bench';

if ($mode === 'bench') {
    echo "=== 隔离方案对比 ===\n\n";
    echo "[方案1] 固定 group + magic token\n";
    echo "  ✅ 能过滤（校验 magic）\n";
    echo "  ❌ 仍会收到别人的包（要 recv + 丢弃，浪费 syscall）\n";
    echo "  ❌ 别人也能收到你的包（信息泄露，虽然只有 key）\n\n";
    echo "[方案2] 随机 group + 随机端口 + magic token\n";
    echo "  ✅ 外部程序不知道 group/port，不会发过来\n";
    echo "  ✅ 你也不知道别人的，不会误收\n";
    echo "  ✅ 万一撞上，magic token 兜底过滤\n";
    echo "  → 三重隔离，推荐\n\n";
    echo "随机空间：\n";
    echo "  group: 239.0.0.0/8 = 16,777,216 个地址\n";
    echo "  port:  20000~60000 = 40,000 个端口\n";
    echo "  组合:  16,777,216 × 40,000 ≈ 6710 亿\n";
    echo "  token: md5(instance-id) 16 字节，碰撞概率 ~0\n";
    exit(0);
}

$group = $argv[2] ?? '239.255.43.24';
$port = (int)($argv[3] ?? 12991);
$token = $argv[4] ?? 'ISOLATED-TOKEN';

if ($mode === 'server') {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_bind($sock, '0.0.0.0', $port);
    $r = @socket_set_option($sock, IPPROTO_IP, MCAST_JOIN_GROUP, [
        'group' => $group, 'interface' => 0,
    ]);
    if ($r === false) { fwrite(STDERR, "join fail\n"); exit(1); }
    socket_set_nonblock($sock);
    echo "server: group=$group port=$port token=$token\n";

    $mine = 0; $other = 0;
    $magic = md5($token, true);
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        $buf = ''; $from = ''; $fp = 0;
        if (@socket_recvfrom($sock, $buf, 4096, 0, $from, $fp) > 0) {
            if (substr($buf, 0, 16) === $magic) $mine++; else $other++;
        }
        usleep(100);
    }
    echo "server: mine=$mine other=$other\n";
    exit(0);
}

if ($mode === 'client') {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_TTL, 1);
    socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_LOOP, 1);
    $magic = md5($token, true);

    // 正确的
    for ($i = 0; $i < 20; $i++) socket_sendto($sock, $magic . pack('N', $i), 20, 0, $group, $port);
    // 错误 group（其他程序常用地址）
    for ($i = 0; $i < 20; $i++) socket_sendto($sock, $magic . pack('N', 100 + $i), 20, 0, '239.255.255.250', $port);
    // 错误端口
    for ($i = 0; $i < 20; $i++) socket_sendto($sock, $magic . pack('N', 200 + $i), 20, 0, $group, $port + 1);
    echo "client: sent 20 mine + 20 wrong_group + 20 wrong_port\n";
    exit(0);
}
