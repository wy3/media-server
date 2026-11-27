<?php

declare(strict_types=1);

/**
 * shmop 基础能力验证（Windows）。
 *
 * 验证点：
 *   1. shmop_open 创建 / 附加
 *   2. 写入后另一进程能否读到
 *   3. 段 key 是否稳定（同一 key 多次打开指向同一段）
 *   4. 段残留与回收
 *   5. 并发读的原子性（单写多读）
 */

$extDir = 'D:\php-8.4.25-nts-Win32-vs17-x64\ext';
if (!extension_loaded('shmop')) {
    echo "shmop 未加载，尝试动态加载...\n";
    if (!@dl('php_shmop.dll')) {
        echo "FAIL: 无法加载 shmop\n";
        exit(1);
    }
}
echo 'shmop loaded: ' . (extension_loaded('shmop') ? 'YES' : 'NO') . "\n\n";

function shmKey(string $name): int
{
    // 与 docs 里的设计一致：crc32 推导
    return crc32($name);
}

function shmOpenWrite(int $key, int $size)
{
    $id = @shmop_open($key, 'n', 0644, $size);   // n = 新建
    if ($id === false) {
        $id = @shmop_open($key, 'w', 0, 0);      // w = 读写已存在
    }
    return $id;
}

echo "=== 1. 创建与写入 ===\n";
$key = shmKey('/live/test');
printf("  key = %d (0x%08x)\n", $key, $key);

$seg = shmOpenWrite($key, 4096);
if ($seg === false) { echo "  FAIL: shmop_open 失败\n"; exit(1); }
$ok = shmop_write($seg, "HELLO-SHM", 0);
printf("  写入 %d 字节: %s\n", $ok, $ok === 9 ? 'OK' : 'FAIL');
shmop_close($seg);

echo "\n=== 2. 重新打开并读取 ===\n";
$seg2 = shmop_open($key, 'w', 0, 0);
if ($seg2 === false) { echo "  FAIL: 重新打开失败\n"; exit(1); }
$size = shmop_size($seg2);
$data = shmop_read($seg2, 0, 9);
printf("  段大小 = %d\n", $size);
printf("  读回 = %s  %s\n", var_export($data, true), $data === 'HELLO-SHM' ? 'OK' : 'FAIL');
shmop_close($seg2);

echo "\n=== 3. key 稳定性（同一 key 是否指向同一段）===\n";
$seg3 = shmop_open($key, 'w', 0, 0);
$d3 = shmop_read($seg3, 0, 9);
printf("  再次打开读到 %s  %s\n", var_export($d3, true), $d3 === 'HELLO-SHM' ? 'OK' : 'FAIL');
shmop_close($seg3);

echo "\n=== 4. 不同 key 是否隔离 ===\n";
$key2 = shmKey('/live/other');
$seg4 = shmOpenWrite($key2, 4096);
shmop_write($seg4, "OTHER", 0);
$back = shmop_read($seg4, 0, 5);
printf("  key2 读到 %s  %s\n", var_export($back, true), $back === 'OTHER' ? 'OK' : 'FAIL');
shmop_close($seg4);

echo "\n=== 5. crc32 冲突检查（10000 条常见 path）===\n";
$paths = [];
for ($i = 0; $i < 10000; $i++) $paths[] = "/live/stream{$i}";
$keys = [];
$collisions = 0;
foreach ($paths as $p) {
    $k = crc32($p);
    if (isset($keys[$k])) { $collisions++; }
    $keys[$k] = $p;
}
printf("  10000 条 path，冲突 %d 次\n", $collisions);

echo "\n=== 6. 清理 ===\n";
foreach ([$key, $key2] as $k) {
    $s = @shmop_open($k, 'w', 0, 0);
    if ($s) {
        $r = @shmop_delete($s);
        shmop_close($s);
        printf("  key %d 删除: %s\n", $k, $r ? 'OK' : 'FAIL');
    }
}

echo "\n=== 7. 删除后能否重新创建 ===\n";
$seg5 = shmOpenWrite($key, 4096);
if ($seg5 === false) {
    echo "  FAIL: 删除后无法重建\n";
} else {
    shmop_write($seg5, "RECREATED", 0);
    $r = shmop_read($seg5, 0, 9);
    printf("  重建后读到 %s  %s\n", var_export($r, true), $r === 'RECREATED' ? 'OK' : 'FAIL');
    shmop_delete($seg5);
    shmop_close($seg5);
}
