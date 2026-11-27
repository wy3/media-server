<?php

declare(strict_types=1);

/**
 * 单进程内验证完整链路：
 *   MediaServer::enableSharedMemoryFrameBus → publish 帧
 *   → notifier.drain → 重建 MediaFrame → Mp4Recorder → 文件产出
 *
 * 排除 Workerman/多进程干扰，定位 record 无输出的根因。
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/src/functions.php';

use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\Recorder\RecorderManager;
use MediaServer\Recorder\Mp4Recorder;
use MediaServer\Utils\MulticastNotifier;
use MediaServer\Utils\RecordPublisherStub;
use MediaServer\Utils\SharedMemoryFrameBusConsumer;

$root = dirname(__DIR__, 2);
$recordDir = $root . '/tests/tmp/record_debug';
@mkdir(dirname($recordDir), 0777, true);
`rm -rf "$recordDir" 2>/dev/null`;
mkdir($recordDir, 0777, true);

RecorderManager::$enabled = true;
RecorderManager::$recordPath = $recordDir;
RecorderManager::$fragmentDurationMs = 2000;
RecorderManager::$segmentDurationMs = 60000;

$isolation = MulticastNotifier::generateIsolation();
printf("isolation: group=%s port=%d token=0x%08x\n", $isolation['group'], $isolation['port'], $isolation['token']);

$notifier = new MulticastNotifier($isolation['group'], $isolation['port'], $isolation['token']);
$notifier->initSender();

// 消费者（不用 Workerman，手动 drain）
$consumer = new SharedMemoryFrameBusConsumer($notifier);
$recvSock = $notifier->initReceiver();

$path = '/live/debug';
$stub = new RecordPublisherStub($path);
$recorder = new Mp4Recorder($stub);

$received = [];
$consumer->onAnyFrame(function (string $p, string $payload, int $seq, bool $isKey, int $ft, int $ts) use (&$received, $stub, $recorder) {
    $frame = match ($ft) {
        MediaFrame::VIDEO_FRAME => new VideoFrame($payload, $ts),
        MediaFrame::AUDIO_FRAME => new AudioFrame($payload, $ts),
        MediaFrame::META_FRAME => new MetaDataFrame($payload),
        default => null,
    };
    if ($frame === null) return;
    if ($ft === MediaFrame::VIDEO_FRAME) $stub->markVideo();
    if ($ft === MediaFrame::AUDIO_FRAME) $stub->markAudio();
    $received[] = $ft;
    $recorder->onFrame($frame);
});

// 生产者：构造帧并写 shm + notify
// 用真实的 VideoFrame/AudioFrame 二进制格式
$frames = [];
// 序列头: 0x17 0x00 0x00 0x00 0x00 + avcC
$frames[] = new MetaDataFrame(str_repeat('M', 200));
$frames[] = new VideoFrame("\x17\x00\x00\x00\x00" . str_repeat('S', 500), 0);
// 关键帧: 0x17 0x01 + cts + NALU
$frames[] = new VideoFrame("\x17\x01\x00\x00\x00" . str_repeat('K', 2000), 1000);
// 音频
$frames[] = new AudioFrame("\xAF\x01" . str_repeat('A', 400), 1000);

$shmKey = \MediaServer\Utils\SharedMemoryFrameBus::keyForPath($path);
$seg = new \MediaServer\Utils\SharedMemoryFrameBus($path, 64, 262144, true);

foreach ($frames as $frame) {
    $payload = (string)$frame;
    $ft = $frame->FRAME_TYPE;
    $isKey = $frame instanceof VideoFrame && $frame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME;
    $seg->write($payload, $isKey, $ft, $frame->timestamp ?? 0);
    $notifier->notify($shmKey, $path);
    usleep(50000);
}

$ref = new ReflectionMethod($consumer, 'dispatch');
$ref->setAccessible(true);

// 消费所有通知
usleep(300000);
$count = 0;
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline) {
    $n = $notifier->drain(function (int $key, string $p) use ($ref, $consumer) {
        $ref->invoke($consumer, $key, $p);
    });
    if ($n === 0) break;
    $count += $n;
}

echo "\nnotifications drained: $count\n";
echo "received frames: " . count($received) . " " . json_encode($received) . "\n";

// 直接调用 consumer 的 dispatch（通过反射）来验证
$ref = new ReflectionMethod($consumer, 'dispatch');
$ref->setAccessible(true);
foreach ($frames as $i => $frame) {
    $ref->invoke($consumer, $shmKey, $path);
}
echo "after direct dispatch: " . count($received) . " frames\n";

// 强制 finalize 看是否有文件
$ref2 = new ReflectionMethod($recorder, 'finalizeSegment');
$ref2->setAccessible(true);
try { $ref2->invoke($recorder); echo "finalizeSegment called\n"; } catch (Throwable $e) { echo "finalize error: {$e->getMessage()}\n"; }

echo "\n=== 文件 ===\n";
foreach (glob($recordDir . '/*') as $f) echo "  " . basename($f) . " " . filesize($f) . " B\n";
