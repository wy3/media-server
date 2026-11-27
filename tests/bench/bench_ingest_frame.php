<?php

declare(strict_types=1);

/**
 * ingest 推流路径每帧开销拆解：
 * publish() = payload 序列化 + crc32 + pack 头 + shmop_write×2 + 段头读×3 + notify
 *
 * 对照：单进程推流 = 解析 + GOP 缓存数组追加（无 shm、无 crc、无通知）
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$ext = PHP_OS_FAMILY === 'Windows' ? 'D:\php-8.4.25-nts-Win32-vs17-x64\ext' : '';
if (!extension_loaded('shmop')) {
    echo "需要 -d extension=shmop（php -d extension_dir=... -d extension=shmop）\n";
    exit(1);
}

use MediaServer\Utils\SharedMemoryFrameBus;

$N = 30000;   // ≈ 500路 × 30fps × 2s 的帧量
$videoLen = 7000;   // 4Mbps 30fps 平均视频帧
$audioLen = 440;    // 128kbps 44.1kHz 1024samples

echo "=== ingest 每帧开销拆解 ===\n";

// 1. 纯 crc32 成本
$payload = str_repeat('x', $videoLen);
$t0 = hrtime(true);
for ($i = 0; $i < $N; $i++) {
    $c = crc32($payload);
}
$t1 = hrtime(true);
printf("crc32(7KB) ×%d: %.1f ms  (%.2f µs/帧)\n", $N, ($t1 - $t0) / 1e6, ($t1 - $t0) / 1e3 / $N);

// 2. shmop_write 7KB
$key = 0x7F000001;
$shm = shmop_open($key, 'n', 0644, 4 * 1024 * 1024);
$t0 = hrtime(true);
for ($i = 0; $i < $N; $i++) {
    shmop_write($shm, $payload, ($i * 7168) % (4 * 1024 * 1024 - 8192));
}
$t1 = hrtime(true);
printf("shmop_write(7KB) ×%d: %.1f ms  (%.2f µs/帧)\n", $N, ($t1 - $t0) / 1e6, ($t1 - $t0) / 1e3 / $N);

// 3. shmop_read 段头（8 字节）
$t0 = hrtime(true);
for ($i = 0; $i < $N; $j = ($i++) * 0) {
    shmop_read($shm, 272, 8);
}
$t1 = hrtime(true);
printf("shmop_read(8B) ×%d: %.1f ms  (%.2f µs/帧)\n", $N, ($t1 - $t0) / 1e6, ($t1 - $t0) / 1e3 / $N);

// 4. pack 头 + 写帧索引 + 写 payload + 更新段头（完整 write() 模拟）
$bus = new SharedMemoryFrameBus('/live/bench_' . getmypid(), 200, 2048, true);
$t0 = hrtime(true);
for ($i = 1; $i <= $N; $i++) {
    $bus->write($payload, $i % 60 === 1, 1, $i * 33);
}
$t1 = hrtime(true);
$per = ($t1 - $t0) / 1e3 / $N;
printf("完整 write() 7KB ×%d: %.1f ms  (%.2f µs/帧)\n", $N, ($t1 - $t0) / 1e6, $per);
printf("  → 单进程 500路×30fps 每秒 %d 帧 × %.2fµs = %.1f%% 单核\n", 15000, $per, 15000 * $per / 10000);

// 5. notify（UDP 组播 syscall）
$sock = stream_socket_client('udp://239.199.1.1:59999', $errno, $errstr, 1, STREAM_CLIENT_CONNECT);
$t0 = hrtime(true);
for ($i = 0; $i < $N; $i++) {
    @fwrite($sock, pack('NNC', 12345, $i & 0x7FFFFFFF, 0) . str_pad('', 128, "\0"), 136);
}
$t1 = hrtime(true);
$perNotify = ($t1 - $t0) / 1e3 / $N;
printf("notify(UDP 136B) ×%d: %.1f ms  (%.2f µs/帧)\n", $N, ($t1 - $t0) / 1e6, $perNotify);

// 6. 全路径合计（write + notify，音频帧占一半按 440B 另算）
$t0 = hrtime(true);
for ($i = 1; $i <= $N; $i++) {
    $p = $i % 2 === 0 ? $payload : str_repeat('a', $audioLen);
    $bus->write($p, $i % 60 === 1, $i % 2 === 0 ? 1 : 2, $i * 33);
    @fwrite($sock, pack('NNC', 12345, $i & 0x7FFFFFFF, 0) . str_pad('', 128, "\0"), 136);
}
$t1 = hrtime(true);
$perFull = ($t1 - $t0) / 1e3 / $N;
printf("write+notify 交错 ×%d: %.1f ms  (%.2f µs/帧)\n", $N, ($t1 - $t0) / 1e6, $perFull);
printf("\n500 路 × 45fps(30视频+15音频) = 22500 帧/s → publish 开销 ≈ %.0f%% 单核\n", 22500 * $perFull / 10000);

$bus->destroy();
