<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use MediaServer\Utils\SharedMemoryFrameBus;

$path = '/live/test_' . getmypid();
echo "=== SharedMemoryFrameBus 验证 ===\n\n";

// 1. 生产者创建并写帧（frameSlots=8, GOP 槽 64KB）
echo "[1] 创建段并写帧\n";
$producer = new SharedMemoryFrameBus($path, 8, 64, true);
printf("  创建者=%s frameSlots=%d gopBlocks=%d key=%d\n",
    $producer->createdByMe() ? 'YES' : 'NO',
    $producer->frameSlots(), $producer->gopBlocks(),
    SharedMemoryFrameBus::keyForPath($path));

$ok = 0; $fail = 0;
function check(string $name, bool $cond): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  OK   $name\n"; }
    else { $fail++; echo "  FAIL $name\n"; }
}

check('validate() 通过', $producer->validate());
check('初始 writeSeq=0', $producer->writeSeq() === 0);

$payloads = [];
for ($i = 1; $i <= 20; $i++) {
    $payloads[$i] = str_repeat(chr($i & 0xFF), 100 + $i);
    $seq = $producer->write($payloads[$i], $i % 5 === 0);
    if ($seq !== $i) { echo "  FAIL write seq $i got $seq\n"; $fail++; }
}
check('writeSeq=20', $producer->writeSeq() === 20);
check('lastKeySeq=20', $producer->lastKeySeq() === 20);

// 2. 消费者（独立对象）attach 并读
echo "\n[2] 消费者 attach 并读帧\n";
$consumer = new SharedMemoryFrameBus($path, 8, 64, false);
check('消费者 validate()', $consumer->validate());
check('消费者看到 writeSeq=20', $consumer->writeSeq() === 20);

$allOk = true;
// 单 GOP 布局：每个 I 帧归零数据环，环内只有「最新 GOP 的未覆盖部分」可读。
// payloads 的 I 帧是 seq 5/10/15/20：
//   - seq 17-19 在最新 GOP（seq20 起）未覆盖区，完整可读
//   - seq 16 被 seq20 的 I 帧部分覆盖（CRC 失败 → null）
//   - seq 13-15 属于被 seq15/20 覆盖的旧 GOP（null）
for ($i = 17; $i <= 19; $i++) {
    $frame = $consumer->read($i);
    if ($frame === null || $frame['payload'] !== $payloads[$i]) {
        echo "  FAIL frame[$i] mismatch\n";
        $allOk = false;
    }
}
check('最新 GOP 未覆盖区（seq 17-19）全部读取一致', $allOk);
check('被最新 I 帧部分覆盖的帧 seq=16 返回 null（CRC 失败）', $consumer->read(16) === null);
check('旧 GOP 帧 seq=13 返回 null', $consumer->read(13) === null);
check('更早的已覆盖帧 seq=1 返回 null', $consumer->read(1) === null);

$latest = $consumer->readLatest();
check('readLatest = seq 20', $latest !== null && $latest['seq'] === 20);
check('关键帧 isKey', $consumer->read(20)['isKey'] === true);
check('frameType=1', $consumer->read(20)['frameType'] === 1);

// 3. 覆盖场景：写超过 slotCount
echo "\n[3] 环形覆盖\n";
for ($i = 21; $i <= 30; $i++) {
    $payloads[$i] = str_repeat(chr($i & 0xFF), 150);
    $producer->write($payloads[$i]);
}
check('writeSeq=30', $consumer->writeSeq() === 30);
check('旧帧 seq=1 已被覆盖', $consumer->read(1) === null);
$frame30 = $consumer->read(30);
check('新帧 seq=30 可读', $frame30 !== null && $frame30['payload'] === $payloads[30]);

// 4. 损坏检测
echo "\n[4] CRC 校验\n";
// 直接改 payload 一个字节：seq=30 的 payload 在 GOP 环内，dataOff 从索引读
// （seq 21-30 无 I 帧 → 都在 seq20 I 帧之后的同一个 GOP 里）
$seg = shmop_open(SharedMemoryFrameBus::keyForPath($path), 'w', 0, 0);
$fh = unpack('Nlen/Jseq/Ncrc/Nflags/Jtimestamp/JdataOff', shmop_read($seg, 4096 + 4 * 65536 + (30 % 8) * 40, 40));
shmop_write($seg, 'X', 4096 + 4 * 65536 + 8 * 40 + $fh['dataOff']);
check('CRC 损坏的帧返回 null', $consumer->read(30) === null);
shmop_close($seg);

echo "\n=== 结果: $ok OK / $fail FAIL ===\n";
$producer->destroy();
exit($fail === 0 ? 0 : 1);
