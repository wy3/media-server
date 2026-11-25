<?php

/**
 * 冒烟测试：不启动服务、不依赖 ffmpeg，仅验证核心纯逻辑可用。
 *
 * 覆盖：
 *  - Composer 自动加载与 PSR-4 映射
 *  - MP4 box 生成的字节结构（长度前缀、类型标识）
 *  - RTMP 握手 S1/S2 的长度与回显字段
 *  - AVC SPS 的 Exp-Golomb 解析与分辨率/档次识别
 *  - 录像路径安全化与 SQLite 索引 schema 建表
 *
 * 用法：php tests/smoke.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use MediaServer\MediaReader\AVCSequenceParameterSet;
use MediaServer\Recorder\Mp4Muxer;
use MediaServer\Recorder\RecordIndex;
use MediaServer\Recorder\RecorderManager;
use MediaServer\Rtmp\RtmpHandshake;

$passed = 0;
$failed = 0;

/**
 * 断言辅助：条件为真则计入通过，否则记录失败。
 *
 * @param string $name 断言名称
 * @param bool $condition 断言结果
 * @param string $detail 失败时的补充信息
 */
function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

echo "== 自动加载 ==\n";
$classes = [
    MediaServer\Rtmp\RtmpStream::class,
    MediaServer\Flv\FlvPlayStream::class,
    MediaServer\Http\HttpWMServer::class,
    MediaServer\Recorder\Mp4Recorder::class,
    MediaServer\Admin\AdminAuth::class,
];
foreach ($classes as $class) {
    check('class_exists ' . $class, class_exists($class));
}
check('function timestamp()', function_exists('timestamp') && timestamp() > 0);

echo "== MP4 box ==\n";
$ftyp = Mp4Muxer::ftyp();
check('ftyp 长度前缀与实际一致', unpack('N', substr($ftyp, 0, 4))[1] === strlen($ftyp));
check('ftyp 类型标记', substr($ftyp, 4, 4) === 'ftyp');

$payload = 'payload';
$box = Mp4Muxer::box('mdat', $payload);
check('box 长度 = 8 + payload', strlen($box) === 8 + strlen($payload));
check('box 长度前缀与实际一致', unpack('N', substr($box, 0, 4))[1] === strlen($box));
check('box 类型标记', substr($box, 4, 4) === 'mdat');
check('box 载荷完整', substr($box, 8) === $payload);

$moov = Mp4Muxer::moov(
    ['avcC' => str_repeat("\x01", 32), 'width' => 1280, 'height' => 720],
    ['esds' => str_repeat("\x05", 16), 'channels' => 2, 'samplerate' => 44100]
);
check('moov 长度前缀与实际一致', unpack('N', substr($moov, 0, 4))[1] === strlen($moov));
check('moov 含 mvhd', str_contains($moov, 'mvhd'));
check('moov 含视频 trak', str_contains($moov, 'avc1'));
check('moov 含音频 trak', str_contains($moov, 'mp4a'));
check('moov 含 mvex', str_contains($moov, 'mvex'));

$samples = [
    ['dts' => 0, 'dur' => 40, 'cts' => 0, 'size' => 100, 'flags' => 0x02000000],
    ['dts' => 40, 'dur' => 40, 'cts' => 80, 'size' => 50, 'flags' => 0x01010000],
];
$moof = Mp4Muxer::buildTrackFragment(1, 1, $samples, 1000, 0);
check('moof 含 mfhd/traf/trun', str_contains($moof, 'mfhd') && str_contains($moof, 'traf') && str_contains($moof, 'trun'));
check('moof 长度前缀与实际一致', unpack('N', substr($moof, 0, 4))[1] === strlen($moof));
// 有非零 CTS 时必须带上 sample-composition-time-offset（0x000800）
check('存在 CTS 时 trun flags 含 0x800', (unpack('N', substr($moof, strpos($moof, 'trun') + 4, 4))[1] & 0x800) === 0x800);

echo "== RTMP 握手 ==\n";
$c1 = pack('NNa1528', 12345, 0, make_random_str(1528));
$s0s1s2 = RtmpHandshake::handshakeGenerateS0S1S2($c1);
check('S0S1S2 总长 1+1536+1536', strlen($s0s1s2) === 3073);
check('S0 版本号 = 3', ord($s0s1s2[0]) === 3);
// 布局：S0(1) + S1(1536) + S2(1536)
$s2 = substr($s0s1s2, 1 + 1536);
check('S2 长度 1536', strlen($s2) === 1536);
check('S2 回显 C1 时间戳', unpack('N', substr($s2, 0, 4))[1] === 12345);
check('S2 回显 C1 随机数', substr($s2, 8) === substr($c1, 8));

echo "== AVC SPS 解析 ==\n";
// 1280x720 High profile 的最小 avcC：configurationVersion + profile + compat + level + 0xFF + 0xE1 + len + sps
// 这里直接构造一段已知 SPS 字节，校验 Exp-Golomb 读取路径不会崩溃且能取到 profile/level。
$avcC = "\x01\x64\x00\x1f\xff\xe1\x00\x0d" . "\x67\x64\x00\x1f\xac\xd9\x40\x50\x05\xbb\x01\x10\x00";
$sps = null;
try {
    $sps = new AVCSequenceParameterSet(substr($avcC, 8));
    check('SPS 解析无异常', true);
    check('SPS profile = 100 (High)', $sps->profile === 100, 'got ' . $sps->profile);
    check('SPS profile 名称', $sps->getAVCProfileName() === 'High', 'got ' . $sps->getAVCProfileName());
} catch (Throwable $e) {
    check('SPS 解析无异常', false, $e->getMessage());
}

echo "== 录像路径与索引 ==\n";
check('sanitizePath 归一斜杠', RecorderManager::sanitizePath('/live/cam1') === 'live_cam1');
check('sanitizePath 空路径降级', RecorderManager::sanitizePath('//') === 'default');

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms_smoke_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0777, true);
RecorderManager::$recordPath = $tmpRoot;
$dir = RecorderManager::recordDir('/live/cam1');
check('recordDir 落在 recordPath 下', str_starts_with($dir, $tmpRoot));

$appended = RecordIndex::appendSegment('/live/cam1', ['video' => ['width' => 1280]], [
    'file' => 'seg_1.mp4',
    'start' => 1000,
    'end' => 2000,
    'duration' => 1000,
    'size' => 4096,
]);
check('索引写入成功', $appended === true);
$files = RecorderManager::listRecordFiles('/live/cam1');
check('索引可读回 1 条', count($files) === 1, 'got ' . count($files));
check('索引含起始时间', ($files[0]['start'] ?? null) === 1000);
check('索引含文件名', ($files[0]['file'] ?? null) === 'seg_1.mp4');
check('recordings.db 已创建', is_file($tmpRoot . '/recordings.db'));

// 清理临时库（WAL 模式下可能残留 -wal/-shm）
foreach (['', '-wal', '-shm'] as $suffix) {
    @unlink($tmpRoot . '/recordings.db' . $suffix);
}
@rmdir($tmpRoot);

echo "\n";
echo $failed === 0
    ? "ALL {$passed} CHECK(S) PASSED\n"
    : "{$failed} OF " . ($passed + $failed) . " CHECK(S) FAILED\n";
exit($failed === 0 ? 0 : 1);
