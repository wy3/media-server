<?php

declare(strict_types=1);

/**
 * 环形缓冲扇出验证：单写多读，数据完整性 + 轮询开销。
 *
 * 用法：
 *   php verify_framebus.php writer <frames>   # 写 N 帧
 *   php verify_framebus.php reader <id> <frames>  # 读 N 帧并校验
 *   php verify_framebus.php all                # 父进程协调
 *
 * 段布局（与 docs/multiprocess/02-shared-memory.md 一致）：
 *   [0..15]   段头：writeSeq(8) | reserved(8)
 *   [16..]    槽位区：每槽 24 字节头 + payload
 */

$mode = $argv[1] ?? 'all';
$extDir = 'D:\php-8.4.25-nts-Win32-vs17-x64\ext';
if (!extension_loaded('shmop') && !@dl('php_shmop.dll')) {
    fwrite(STDERR, "shmop 加载失败\n");
    exit(1);
}

const SHM_KEY = 0x5EED0002;
const SLOT_COUNT = 64;
const SLOT_PAYLOAD = 65536;          // 64 KB/槽
const SLOT_SIZE = 24 + SLOT_PAYLOAD;  // 头 24 + payload
const SHM_SIZE = 16 + SLOT_COUNT * SLOT_SIZE;

/** 帧内容：可校验的确定性数据 */
function framePayload(int $seq): string
{
    // 前 8 字节放 seq，其余填 seq 派生值
    $body = str_repeat(pack('N', $seq), intdiv(SLOT_PAYLOAD - 8, 4));
    return pack('N2', $seq, crc32($body)) . $body;
}

if ($mode === 'writer') {
    $frames = (int)($argv[2] ?? 1000);
    $id = @shmop_open(SHM_KEY, 'w', 0, 0);
    if ($id === false) { fwrite(STDERR, "writer: 附加失败\n"); exit(2); }

    $t0 = hrtime(true);
    for ($seq = 1; $seq <= $frames; $seq++) {
        $slot = $seq % SLOT_COUNT;
        $off = 16 + $slot * SLOT_SIZE;
        $payload = framePayload($seq);
        // 槽头：seq(8) | len(4) | crc(4) | pad(8)
        $head = pack('J', $seq) . pack('N', strlen($payload)) . pack('N', crc32($payload)) . str_repeat("\0", 8);
        shmop_write($id, $head . $payload, $off);
        // 发布：最后写全局 writeSeq
        shmop_write($id, pack('J', $seq), 0);
        usleep(200);  // 模拟 30fps 附近的写入节奏
    }
    $elapsed = (hrtime(true) - $t0) / 1e9;
    printf("writer: %d 帧, %.3fs, %.0f 帧/s\n", $frames, $elapsed, $frames / $elapsed);
    shmop_close($id);
    exit(0);
}

if ($mode === 'reader') {
    $rid = (int)($argv[2] ?? 0);
    $frames = (int)($argv[3] ?? 1000);
    $id = @shmop_open(SHM_KEY, 'w', 0, 0);
    if ($id === false) { fwrite(STDERR, "reader$rid: 附加失败\n"); exit(2); }

    $lastSeq = 0;
    $got = 0;
    $corrupt = 0;
    $skipped = 0;
    $polls = 0;
    $deadline = microtime(true) + 60;
    $pollNs = 0;

    while ($got < $frames && microtime(true) < $deadline) {
        $p0 = hrtime(true);
        $writeSeq = (int)unpack('J', shmop_read($id, 0, 8))[1];
        $pollNs += hrtime(true) - $p0;
        $polls++;

        while ($lastSeq < $writeSeq && $got < $frames) {
            $next = $lastSeq + 1;
            $slot = $next % SLOT_COUNT;
            $off = 16 + $slot * SLOT_SIZE;
            $raw = shmop_read($id, $off, SLOT_SIZE);

            $h = unpack('Jseq/Nlen/Ncrc', substr($raw, 0, 16));
            if ($h['seq'] !== $next) {
                // 被覆盖了（读太慢）
                $skipped++;
                $lastSeq = $next;
                continue;
            }
            $payload = substr($raw, 24, $h['len']);
            if (crc32($payload) !== $h['crc']) {
                $corrupt++;
            } else {
                $got++;
            }
            $lastSeq = $next;
        }
        usleep(50);
    }

    printf("reader%d: got=%d corrupt=%d skipped=%d polls=%d avg_poll=%.2f ns\n",
        $rid, $got, $corrupt, $skipped, $polls, $polls ? $pollNs / $polls : 0);
    shmop_close($id);
    exit($corrupt === 0 && $got > 0 ? 0 : 1);
}

// ---------- all ----------
echo "=== FrameBus 环形缓冲扇出验证 ===\n\n";
printf("段布局: %d 槽 × (%d + %d) B = %.2f MB\n\n",
    SLOT_COUNT, 24, SLOT_PAYLOAD, SHM_SIZE / 1048576);

$old = @shmop_open(SHM_KEY, 'w', 0, 0);
if ($old) { @shmop_delete($old); shmop_close($old); }

$id = shmop_open(SHM_KEY, 'n', 0644, SHM_SIZE);
if ($id === false) { echo "FAIL: 创建段失败\n"; exit(1); }
shmop_write($id, pack('J', 0), 0);
shmop_close($id);

$php = PHP_BINARY;
$script = __FILE__;
$base = '"' . $php . '" -d extension_dir=' . $extDir . ' -d extension=shmop ' . '"' . $script . '"';
$FRAMES = 2000;

echo "[1] 启动 2 个 reader\n";
$readers = [];
foreach ([1, 2] as $rid) {
    $readers[$rid] = popen($base . ' reader ' . $rid . ' ' . $FRAMES . ' 2>&1', 'r');
}

echo "[2] 启动 writer（{$FRAMES} 帧）\n";
$writer = popen($base . ' writer ' . $FRAMES . ' 2>&1', 'r');

$writerOut = stream_get_contents($writer);
pclose($writer);
echo '  ' . trim($writerOut) . "\n";

$fail = 0;
foreach ($readers as $rid => $h) {
    $line = trim(stream_get_contents($h));
    pclose($h);
    echo '  ' . $line . "\n";
    if (!preg_match('/corrupt=0/', $line)) $fail++;
    if (!preg_match('/got=(\d+)/', $line, $m) || (int)$m[1] === 0) $fail++;
}

$id = @shmop_open(SHM_KEY, 'w', 0, 0);
if ($id) { shmop_delete($id); shmop_close($id); }

echo "\n=== 结果: " . ($fail === 0 ? 'PASS' : "FAIL ($fail)") . " ===\n";
exit($fail === 0 ? 0 : 1);
