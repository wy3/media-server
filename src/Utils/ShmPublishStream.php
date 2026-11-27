<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use Evenement\EventEmitterTrait;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\PublishStreamInterface;

/**
 * 共享内存版的 Publisher。
 *
 * play/record 进程用它替代真实推流对象，
 * 从 shm 段头索引读取 meta/AVC/AAC/HEVC 序列头和 GOP。
 *
 * 序列头帧会随环形缓冲被覆盖，但 RTMP 推流器在每个 GOP 都会重发序列头，
 * 所以索引指向的帧在活跃流中始终有效。
 */
final class ShmPublishStream implements PublishStreamInterface
{
    use EventEmitterTrait;

    public bool $is_on_frame = false;

    /** on_publish_ready 是否已触发（hasCompleteGop 达成时只发一次） */
    public bool $isPublishReadyEmitted = false;

    private SharedMemoryFrameBus $seg;
    private int $gopLastSeq = 0;
    /** 已广播到的实时 seq（进程级，所有播放端共享交接点，避免 DTS 重复） */
    public static array $broadcastSeq = [];
    private ?MetaDataFrame $meta = null;
    private ?VideoFrame $avc = null;
    private ?VideoFrame $hevc = null;
    private ?AudioFrame $aac = null;

    public function __construct(
        private readonly string $path,
        private readonly int $frameSlots = 0,   // 0=SharedMemoryFrameBus 内部默认
        private readonly int $dataKb = 0
    ) {
        $this->seg = new SharedMemoryFrameBus($path, $frameSlots, $dataKb, false);
    }

    public function getPublishPath(): string { return $this->path; }

    public function isMetaData(): bool { return $this->getMetaDataFrame() !== null; }
    public function getMetaDataFrame(): ?MetaDataFrame
    {
        if ($this->meta !== null) return $this->meta;
        $f = $this->seg->readMeta();
        if ($f !== null) {
            $this->meta = new MetaDataFrame($f['payload']);
        }
        return $this->meta;
    }

    public function isAVCSequence(): bool { return $this->getAVCSequenceFrame() !== null; }
    public function getAVCSequenceFrame(): ?VideoFrame
    {
        if ($this->avc !== null) return $this->avc;
        $f = $this->seg->readAvcSequence();
        if ($f !== null) {
            $this->avc = new VideoFrame($f['payload'], $f['timestamp']);
        }
        return $this->avc;
    }

    public function isHEVCSequence(): bool { return $this->getHEVCSequenceFrame() !== null; }
    public function getHEVCSequenceFrame(): ?VideoFrame
    {
        if ($this->hevc !== null) return $this->hevc;
        $f = $this->seg->readHevcSequence();
        if ($f !== null) {
            $this->hevc = new VideoFrame($f['payload'], $f['timestamp']);
        }
        return $this->hevc;
    }

    public function isAACSequence(): bool { return $this->getAACSequenceFrame() !== null; }
    public function getAACSequenceFrame(): ?AudioFrame
    {
        if ($this->aac !== null) return $this->aac;
        $f = $this->seg->readAacSequence();
        if ($f !== null) {
            $this->aac = new AudioFrame($f['payload'], $f['timestamp']);
        }
        return $this->aac;
    }

    public function hasAudio(): bool { return $this->isAACSequence(); }
    public function hasVideo(): bool { return $this->isAVCSequence() || $this->isHEVCSequence(); }

    /**
     * GOP：从最近关键帧到当前帧。
     * 记录最后一个发送的 seq，供实时流从这里继续（避免 DTS 重复）。
     */
    public function getGopCacheQueue(): array
    {
        $keySeq = $this->seg->lastKeySeq();
        $writeSeq = $this->seg->writeSeq();
        if ($keySeq <= 0 || $writeSeq <= 0) return [];

        // 起点取关键帧，但跳过已广播过的部分（其他播放端已由实时流覆盖）
        $broadcast = self::$broadcastSeq[$this->path] ?? 0;
        $start = max($keySeq, $broadcast + 1);
        if ($start > $writeSeq) return [];

        $frames = [];
        $lastSent = 0;
        for ($seq = $start; $seq <= $writeSeq; $seq++) {
            $f = $this->seg->read($seq);
            if ($f === null) continue;
            $frame = match ($f['frameType']) {
                MediaFrame::VIDEO_FRAME => new VideoFrame($f['payload'], $f['timestamp']),
                MediaFrame::AUDIO_FRAME => new AudioFrame($f['payload'], $f['timestamp']),
                MediaFrame::META_FRAME => new MetaDataFrame($f['payload']),
                default => null,
            };
            if ($frame !== null) { $frames[] = $frame; $lastSent = $seq; }
        }
        $this->gopLastSeq = $lastSent ?: $broadcast;
        // 交接点推进到本次回填结束
        if ($lastSent > 0) {
            self::$broadcastSeq[$this->path] = $lastSent;
        }
        return $frames;
    }

    /**
     * GOP 回填发到的 seq（实时流从此继续）。
     */
    public function gopLastSeq(): int { return $this->gopLastSeq; }

    /**
     * 当前 GOP 是否完整可播：有关键帧且其后已有追加帧。
     * play 进程用它判断「探测到段就能播」，不依赖通知时序。
     */
    public function hasCompleteGop(): bool
    {
        $keySeq = $this->seg->lastKeySeq();
        return $keySeq > 0 && $this->seg->writeSeq() > $keySeq;
    }

    public function getPublishStreamInfo(): array
    {
        return [
            'publishStreamPath' => $this->path,
            'writeSeq' => $this->seg->writeSeq(),
            'lastKeySeq' => $this->seg->lastKeySeq(),
        ];
    }
}
