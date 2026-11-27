<?php

declare(strict_types=1);

/**
 * record 进程：录像池。
 *
 * 订阅 control 事件发现流（notify.publish/unpublish）+ 固定间隔刷 shm
 * → 喂给 Mp4Recorder（fMP4 分片）。unpublish 时 finalize 分片。
 */

namespace MediaServer\Process;

use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;
use MediaServer\Recorder\RecorderManager;
use MediaServer\Utils\RecordPublisherStub;
use MediaServer\Utils\SharedMemoryFrameBusConsumer;
use MediaServer\Utils\StreamRegistryClient;

final class RecordProcess
{
    public static function run(string $recordPath, string $registryAddr): void
    {
        RecorderManager::$enabled = true;
        RecorderManager::$recordPath = $recordPath;
        RecorderManager::$fragmentDurationMs = 2000;
        RecorderManager::$segmentDurationMs = 60000;

        $consumer = new SharedMemoryFrameBusConsumer(new StreamRegistryClient($registryAddr, 'subscribe'));
        MediaServer::$frameBusConsumer = $consumer;
        /** @var array<string, array{recorder:\MediaServer\Recorder\Mp4Recorder, stub:RecordPublisherStub}> $recorders */
        $recorders = [];

        $consumer->onStreamEnd(function (string $path) use (&$recorders) {
            if (isset($recorders[$path])) {
                $recorders[$path]['recorder']->onClose();
                unset($recorders[$path]);
            }
        });

        $consumer->onAnyFrame(function (
            string $path, string $payload, int $seq, bool $isKey, int $frameType, int $timestamp, int $special
        ) use (&$recorders) {
            $frame = match ($frameType) {
                MediaFrame::VIDEO_FRAME => new VideoFrame($payload, $timestamp),
                MediaFrame::AUDIO_FRAME => new AudioFrame($payload, $timestamp),
                MediaFrame::META_FRAME => new MetaDataFrame($payload),
                default => null,
            };
            if ($frame === null) return;

            if (!isset($recorders[$path])) {
                $stub = new RecordPublisherStub($path);
                $recorders[$path] = [
                    'recorder' => new \MediaServer\Recorder\Mp4Recorder($stub),
                    'stub' => $stub,
                ];
            }
            $entry = $recorders[$path];
            if ($frameType === MediaFrame::VIDEO_FRAME) $entry['stub']->markVideo();
            if ($frameType === MediaFrame::AUDIO_FRAME) $entry['stub']->markAudio();
            $entry['recorder']->onFrame($frame);
        });

        $worker = new \Workerman\Worker('tcp://0.0.0.0:0');   // 无监听端口
        $worker->onWorkerStart = function ($w) use ($consumer) {
            $consumer->start();
        };

        \Workerman\Worker::runAll();
    }
}
