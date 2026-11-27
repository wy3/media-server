<?php

declare(strict_types=1);

/**
 * 跨进程 shmop 验证（Windows）。
 *
 * 用法：
 *   php verify_shmop_xproc.php parent    # 父进程：创建段、起子进程、校验
 *   php verify_shmop_xproc.php child     # 子进程：附加、读、写回
 */

$mode = $argv[1] ?? 'parent';
$extDir = 'D:\php-8.4.25-nts-Win32-vs17-x64\ext';
if (!extension_loaded('shmop') && !@dl('php_shmop.dll')) {
    fwrite(STDERR, "shmop 加载失败\n");
    exit(1);
}

const SHM_KEY = 0x5EED0001;
const SHM_SIZE = 65536;

if ($mode === 'child') {
    // 子进程：读父进程写入的数据，回写一段确认
    $id = @shmop_open(SHM_KEY, 'w', 0, 0);
    if ($id === false) {
        fwrite(STDERR, "child: 附加失败\n");
        exit(2);
    }
    $data = shmop_read($id, 0, 16);
    fwrite(STDOUT, "child read: " . bin2hex($data) . "\n");

    // 回写确认区（偏移 16）
    shmop_write($id, "CHILD-OK", 16);
    shmop_close($id);
    exit(0);
}

// ---------- parent ----------
echo "=== 跨进程 shmop 验证 ===\n\n";

// 清理可能残留的段
$old = @shmop_open(SHM_KEY, 'w', 0, 0);
if ($old) { @shmop_delete($old); shmop_close($old); }

echo "[1] 父进程创建段\n";
$id = shmop_open(SHM_KEY, 'n', 0644, SHM_SIZE);
if ($id === false) { echo "  FAIL: 创建失败\n"; exit(1); }
$payload = "PARENT-DATA-0001";
shmop_write($id, $payload, 0);
printf("  写入 %s (%d 字节)\n", $payload, strlen($payload));

echo "\n[2] 启动子进程\n";
$php = PHP_BINARY;
$script = __FILE__;
$cmd = '"' . $php . '" -d extension_dir=' . $extDir . ' -d extension=shmop ' . '"' . $script . '" child';
$out = [];
$ret = 0;
exec($cmd . ' 2>&1', $out, $ret);
printf("  退出码 %d\n", $ret);
foreach ($out as $line) echo "  | $line\n";

echo "\n[3] 父进程读子进程回写的数据\n";
$back = shmop_read($id, 16, 8);
printf("  读到 %s  %s\n", var_export($back, true), $back === 'CHILD-OK' ? 'OK' : 'FAIL');

echo "\n[4] 清理\n";
shmop_delete($id);
shmop_close($id);
echo "  已删除\n";

$ok = $ret === 0 && $back === 'CHILD-OK';
echo "\n=== 结果: " . ($ok ? 'PASS' : 'FAIL') . " ===\n";
exit($ok ? 0 : 1);
