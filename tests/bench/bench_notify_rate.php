<?php

declare(strict_types=1);

/**
 * 组播通知速率基准：单独发送 vs 有接收者时的速率
 */

$extLoaded = extension_loaded('sockets') && extension_loaded('shmop');
if (!$extLoaded) { echo "需要扩展\n"; exit(1); }

$group = '239.199.5.7';
$port = 51345;

// 接收端（同进程内另一个 socket，模拟消费者收通知）
$rx = stream_socket_server("udp://0.0.0.0:$port", $errno, $errstr, STREAM_SERVER_BIND);
stream_set_blocking($rx, false);
$rxSock = socket_import_stream($rx);
socket_set_option($rxSock, IPPROTO_IP, MCAST_JOIN_GROUP, [
    'group' => $group,
    'interface' => PHP_OS_FAMILY === 'Windows' ? 0 : '127.0.0.1',
]);

// 发送端
$ctx = stream_context_create(['socket' => ['multicast_ttl' => 1, 'multicast_loop' => 1, 'so_sndbuf' => 4194304]]);
$tx = stream_socket_client("udp://$group:$port", $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $ctx);
stream_set_blocking($tx, false);

$payload = pack('NNC', 12345, 1, 0) . str_pad('', 128, "\0");
$N = 30000;

function drainRx($rx): int {
    $n = 0;
    while (($d = @fread($rx, 65536)) !== false && $d !== '') { $n++; }
    return $n;
}

echo "=== 场景 1：只发不收（接收缓冲堆积） ===\n";
$t0 = hrtime(true);
for ($i = 0; $i < $N; $i++) {
    @fwrite($tx, $payload, 136);
}
$t1 = hrtime(true);
$per1 = ($t1 - $t0) / 1e3 / $N;
printf("  %d 帧全发: %.2f µs/帧  (%.0f 帧/s)\n", $N, $per1, 1e6 / $per1);

echo "=== 场景 2：边发边收（每 1000 帧清一次接收缓冲） ===\n";
$t0 = hrtime(true);
$sent = 0;
for ($i = 0; $i < $N; $i++) {
    @fwrite($tx, $payload, 136);
    if ($i % 1000 === 999) { drainRx($rx); }
}
$t1 = hrtime(true);
$per2 = ($t1 - $t0) / 1e3 / $N;
printf("  %d 帧全发: %.2f µs/帧  (%.0f 帧/s)\n", $N, $per2, 1e6 / $per2);

echo "=== 场景 3：无接收者（不同端口，纯内核 sendto） ===\n";
$tx2 = stream_socket_client("udp://$group:59998", $e2, $s2, 1, STREAM_CLIENT_CONNECT, $ctx);
stream_set_blocking($tx2, false);
$t0 = hrtime(true);
for ($i = 0; $i < $N; $i++) {
    @fwrite($tx2, $payload, 136);
}
$t1 = hrtime(true);
$per3 = ($t1 - $t0) / 1e3 / $N;
printf("  %d 帧全发: %.2f µs/帧  (%.0f 帧/s)\n", $N, $per3, 1e6 / $per3);

echo "=== 场景 4：收发全交替（每帧收） ===\n";
$t0 = hrtime(true);
for ($i = 0; $i < $N; $i++) {
    @fwrite($tx, $payload, 136);
    drainRx($rx);
}
$t1 = hrtime(true);
$per4 = ($t1 - $t0) / 1e3 / $N;
printf("  %d 帧全发: %.2f µs/帧  (%.0f 帧/s)\n", $N, $per4, 1e6 / $per4);
