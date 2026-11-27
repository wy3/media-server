<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use Evenement\EventEmitterTrait;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\PublishStreamInterface;

/**
 * 录像进程用的 Publisher 桩。
 *
 * Mp4Recorder 只用 getPublishPath() / hasAudio() / hasVideo()，
 * 多进程录像池里没有真实推流对象，用本桩提供这些信息。
 * hasAudio/hasVideo 根据收到的帧动态判定。
 */
final class RecordPublisherStub implements PublishStreamInterface
{
    use EventEmitterTrait;

    private bool $hasAudio = false;
    private bool $hasVideo = false;

    public function __construct(private readonly string $path)
    {
    }

    public function markAudio(): void { $this->hasAudio = true; }
    public function markVideo(): void { $this->hasVideo = true; }

    public function getPublishPath(): string { return $this->path; }
    public function isMetaData(): bool { return false; }
    public function getMetaDataFrame(): ?MetaDataFrame { return null; }
    public function isAACSequence(): bool { return false; }
    public function getAACSequenceFrame(): ?AudioFrame { return null; }
    public function isAVCSequence(): bool { return false; }
    public function getAVCSequenceFrame(): ?VideoFrame { return null; }
    public function isHEVCSequence(): bool { return false; }
    public function getHEVCSequenceFrame(): ?VideoFrame { return null; }
    public function hasAudio(): bool { return $this->hasAudio; }
    public function hasVideo(): bool { return $this->hasVideo; }
    public function getGopCacheQueue(): array { return []; }
    public function getPublishStreamInfo(): array { return []; }
}
