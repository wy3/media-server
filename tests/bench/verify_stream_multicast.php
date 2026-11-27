<?php

declare(strict_types=1);

/**
 * 验证：stream_socket_server UDP 能否接收组播？
 * 对比 socket_create + socket_recvfrom。
 */

$group = '239.132.246.200';
$port = 13001;
$iface = 0;

$mode = $argv[1] ?? 'stream';

if ($mode === 'receiver_stream') {
    $ctx = stream_context_create(['socket' => ['reuseaddr' => 1]]);
    $s = stream_socket_server("udp://0.0.0.0:$port", $en, $es, STREAM_SERVER_BIND, $ctx);
    $sock = socket_import_stream($s);
    $r = @socket_set_option($sock, IPPROTO_IP, MCAST_JOIN_GROUP, ['group' => $group, 'interface' => $iface]);
    echo "stream join: " . ($r ? 'OK' : 'FAIL') . "\n";
    stream_set_blocking($s, false);
    $got = 0; $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $d = @fread($s, 4096);
        if ($d !== false && $d !== '') $got++;
        usleep(100);
    }
    echo "stream recv: $got\n";
    exit(0);
}

if ($mode === 'receiver_socket') {
    $s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($s, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_bind($s, '0.0.0.0', $port);
    $r = @socket_set_option($s, IPPROTO_IP, MCAST_JOIN_GROUP, ['group' => $group, 'interface' => $iface]);
    echo "socket join: " . ($r ? 'OK' : 'FAIL') . "\n";
    socket_set_nonblock($s);
    $got = 0; $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $b = ''; $f = ''; $p = 0;
        if (@socket_recvfrom($s, $b, 4096, 0, $f, $p) > 0) $got++;
        usleep(100);
    }
    echo "socket recv: $got\n";
    exit(0);
}

if ($mode === 'sender') {
    $ctx = stream_context_create(['socket' => ['multicast_ttl' => 1, 'multicast_loop' => 1]]);
    $s = stream_socket_client("udp://$group:$port", $en, $es, 1, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) { echo "sender create FAIL: $es\n"; exit(1); }
    for ($i = 0; $i < 50; $i++) fwrite($s, pack('N', $i));
    echo "sent 50\n";
    exit(0);
}

echo "usage: stream|socket receiver, sender\n";
