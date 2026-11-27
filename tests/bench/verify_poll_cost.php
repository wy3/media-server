<?php

declare(strict_types=1);

/**
 * 轮询开销精确测量（每档用独立 key，避免删除后重建失败）。
 */

if (!extension_loaded('shmop') && !@dl('php_shmop.dll')) {
    fwrite(STDERR, "shmop 加载失败\n");
    exit(1);
}

function bench(string $name, int $n, callable $fn): float
{
    $fn(min($n, 100));
    $t0 = hrtime(true);
    $fn($n);
    $ns = (hrtime(true) - $t0) / $n;
    printf("  %-48s %9.1f ns\n", $name, $ns);
    return $ns;
}

$keyBase = 0x5EED1000;
$i = 0;
foreach ([4096, 65536, 1048576, 4194304] as $size) {
    $key = $keyBase + $i++;
    $id = shmop_open($key, 'n', 0644, $size);
    if ($id === false) { echo "size=$size 创建失败，跳过\n"; continue; }
    shmop_write($id, pack('J', 42), 0);

    echo "=== 段大小 {$size} B ===\n";
    bench('shmop_read(8)', 200000, function (int $n) use ($id) {
        for ($i = 0; $i < $n; $i++) $v = shmop_read($id, 0, 8);
    });
    bench('shmop_write(8)', 200000, function (int $n) use ($id) {
        for ($i = 0; $i < $n; $i++) shmop_write($id, pack('J', $i), 0);
    });
    bench('shmop_read(8) + unpack(J)', 200000, function (int $n) use ($id) {
        for ($i = 0; $i < $n; $i++) $v = unpack('J', shmop_read($id, 0, 8))[1];
    });
    $len = min(65536, $size);
    bench("shmop_read({$len})", 20000, function (int $n) use ($id, $len) {
        for ($i = 0; $i < $n; $i++) $v = shmop_read($id, 0, $len);
    });
    echo "\n";
}

echo "=== 对照：纯内存 ===\n";
$buf = str_repeat("\0", 8);
bench('$buf[0]（纯内存）', 1000000, function (int $n) use ($buf) {
    for ($i = 0; $i < $n; $i++) $v = $buf[0];
});
bench('unpack(J, $buf)', 500000, function (int $n) use ($buf) {
    for ($i = 0; $i < $n; $i++) $v = unpack('J', $buf)[1];
});
bench('substr($buf, 0, 8)', 500000, function (int $n) use ($buf) {
    for ($i = 0; $i < $n; $i++) $v = substr($buf, 0, 8);
});
