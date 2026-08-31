<?php

declare(strict_types=1);

namespace MediaServer\Recorder;

use MediaServer\MediaReader\AACPacket;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\PublishStreamInterface;

/**
 * fMP4 实时录制器。
 *
 * 订阅推流 on_frame 事件，将 H264/AAC 样本按片段（关键帧边界）封装为
 * moof+mdat 增量写入分段 .mp4 文件；分段结束时生成同名 .json 索引。
 *
 * 每个分段内部时间戳自 0 起，索引记录分段首个样本的墙钟时间，用于回放换算。
 */
class Mp4Recorder
{
    /** @var resource|null */
    protected $fileHandle = null;

    protected string $segmentFile = '';
    protected string $segmentIndexFile = '';
    protected int $segmentSeq = 0;
    protected int $segmentStartWall = 0;
    protected bool $segmentHasAudio = false;

    /** 当前分段首个样本的原始时间戳，用于归一化 dts */
    protected ?int $firstDts = null;
    protected int $fragmentSeq = 0;
    protected ?int $fragmentStartDts = null;

    protected bool $videoConfigReady = false;
    protected string $avcC = '';
    protected int $videoWidth = 0;
    protected int $videoHeight = 0;

    protected bool $audioConfigReady = false;
    protected string $audioSpecificConfig = '';
    protected int $audioChannels = 2;
    protected int $audioSamplerate = 44100;

    /** @var array<int, array{dts:int,cts:int,data:string,key:bool}> */
    protected array $videoSamples = [];

    /** @var array<int, array{dts:int,cts:int,data:string}> */
    protected array $audioSamples = [];

    protected array $index = [];

    public function __construct(protected PublishStreamInterface $publisher)
    {
    }

    public function onFrame(MediaFrame $frame): void
    {
        switch ($frame->FRAME_TYPE) {
            case MediaFrame::VIDEO_FRAME:
                $this->handleVideoFrame($frame);
                break;
            case MediaFrame::AUDIO_FRAME:
                $this->handleAudioFrame($frame);
                break;
            default:
                break;
        }
    }

    public function onClose(): void
    {
        if ($this->segmentActive()) {
            $this->finalizeFragment();
            $this->finalizeSegment();
        }
        $this->fileHandle = null;
    }

    //--------------------------------
    //		视频
    //--------------------------------

    protected function handleVideoFrame(VideoFrame $frame): void
    {
        $avc = $frame->getAVCPacket();

        if ($avc->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->avcC = $avc->stream->readRaw();
            $set = $avc->getAVCSequenceParameterSet();
            $this->videoWidth = $set->width;
            $this->videoHeight = $set->height;
            $this->videoConfigReady = true;
            return;
        }

        if (!$this->videoConfigReady) {
            return;
        }

        $isKey = $frame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
            || $frame->frameType === VideoFrame::VIDEO_FRAME_TYPE_GENERATED_KEY_FRAME;

        if ($isKey) {
            $now = timestamp();
            if (!$this->segmentActive()) {
                $this->startSegment($now);
            }
            if (!$this->segmentActive()) {
                return; // 目录创建失败等
            }
            if ($this->shouldFinalizeFragment($frame->timestamp)) {
                $this->finalizeFragment();
                if ($now - $this->segmentStartWall >= RecorderManager::$segmentDurationMs) {
                    $this->finalizeSegment();
                    $this->startSegment($now);
                }
            }
            $this->fragmentStartDts = $this->normalizeDts($frame->timestamp);
        } elseif ($this->fragmentStartDts === null) {
            return; // 等待关键帧开启新片段，丢弃片头帧间帧
        }

        $this->videoSamples[] = [
            'dts' => $this->normalizeDts($frame->timestamp),
            'cts' => $avc->compositionTime & 0xFFFFFFFF,
            'data' => $avc->stream->readRaw(),
            'key' => $isKey,
        ];
    }

    //--------------------------------
    //		音频
    //--------------------------------

    protected function handleAudioFrame(AudioFrame $frame): void
    {
        $aac = $frame->getAACPacket();

        if ($aac->aacPacketType === AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->audioSpecificConfig = $aac->stream->readRaw();
            $set = $aac->getAACSequenceParameterSet();
            $this->audioSamplerate = $set->sampleRate;
            $this->audioChannels = $set->channels ?? 1;
            $this->audioConfigReady = true;
            return;
        }

        if (!$this->audioConfigReady || !$this->segmentHasAudio || $this->fragmentStartDts === null) {
            return;
        }

        $this->audioSamples[] = [
            'dts' => $this->normalizeDts($frame->timestamp),
            'cts' => 0,
            'data' => $aac->stream->readRaw(),
        ];
    }

    //--------------------------------
    //		分段 / 分片
    //--------------------------------

    protected function segmentActive(): bool
    {
        return $this->fileHandle !== null && is_resource($this->fileHandle);
    }

    protected function startSegment(int $now): void
    {
        // 等待视频配置就绪；若流含音频则需音频配置就绪后才落盘
        if (!$this->videoConfigReady || ($this->publisher->hasAudio() && !$this->audioConfigReady)) {
            return;
        }

        $dir = RecorderManager::recordDir($this->publisher->getPublishPath());
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            logger()->error('mkdir record dir fail {dir}', ['dir' => $dir]);
            return;
        }

        $base = RecorderManager::sanitizePath($this->publisher->getPublishPath())
            . '_' . date('Ymd_His') . '_' . $this->segmentSeq;
        $this->segmentFile = $dir . DIRECTORY_SEPARATOR . $base . '.mp4';
        $this->segmentIndexFile = $dir . DIRECTORY_SEPARATOR . $base . '.json';
        $this->segmentSeq++;
        $this->segmentStartWall = $now;
        $this->segmentHasAudio = $this->audioConfigReady;
        $this->firstDts = null;
        $this->fragmentSeq = 0;
        $this->fragmentStartDts = null;
        $this->videoSamples = [];
        $this->audioSamples = [];

        $handle = @fopen($this->segmentFile, 'wb');
        if ($handle === false) {
            logger()->error('open record file fail {file}', ['file' => $this->segmentFile]);
            $this->fileHandle = null;
            return;
        }
        $this->fileHandle = $handle;

        $moov = Mp4Muxer::moov(
            ['avcC' => $this->avcC, 'width' => $this->videoWidth, 'height' => $this->videoHeight],
            $this->segmentHasAudio
                ? ['esds' => $this->audioSpecificConfig, 'channels' => $this->audioChannels, 'samplerate' => $this->audioSamplerate]
                : []
        );
        fwrite($this->fileHandle, Mp4Muxer::ftyp() . $moov);

        $this->index = [
            'path' => $this->publisher->getPublishPath(),
            'file' => basename($this->segmentFile),
            'start' => $now,
            'end' => $now,
            'duration' => 0,
        ];
    }

    protected function normalizeDts(int $timestamp): int
    {
        if ($this->firstDts === null) {
            $this->firstDts = $timestamp;
        }
        return max(0, $timestamp - $this->firstDts);
    }

    protected function shouldFinalizeFragment(int $currentTimestamp): bool
    {
        if ($this->fragmentStartDts === null || empty($this->videoSamples)) {
            return false;
        }
        $elapsed = $this->normalizeDts($currentTimestamp) - $this->fragmentStartDts;
        return $elapsed >= RecorderManager::$fragmentDurationMs;
    }

    protected function finalizeFragment(): void
    {
        if (empty($this->videoSamples) && empty($this->audioSamples)) {
            return;
        }

        $out = '';
        if ($this->videoSamples) {
            $out .= $this->buildFragment(Mp4Muxer::VIDEO_TRACK_ID, $this->videoSamples);
        }
        if ($this->audioSamples) {
            $out .= $this->buildFragment(Mp4Muxer::AUDIO_TRACK_ID, $this->audioSamples);
        }

        if ($out !== '') {
            $this->writeSegment($out);
        }

        $this->fragmentSeq++;
        $this->videoSamples = [];
        $this->audioSamples = [];
    }

    /**
     * @param array<int, array{dts:int,cts:int,data:string,key?:bool}> $samples
     */
    protected function buildFragment(int $trackId, array $samples): string
    {
        $n = count($samples);
        $moofSamples = [];
        foreach ($samples as $i => $s) {
            $dur = $i + 1 < $n ? $samples[$i + 1]['dts'] - $s['dts'] : 40;
            if ($dur <= 0) {
                $dur = 40;
            }
            $moofSamples[] = [
                'dts' => $s['dts'],
                'dur' => $dur,
                'cts' => (int)$s['cts'],
                'size' => strlen($s['data']),
                'flags' => $trackId === Mp4Muxer::AUDIO_TRACK_ID || !empty($s['key'])
                    ? 0x02000000
                    : 0x01010000,
            ];
        }

        $mdat = '';
        foreach ($samples as $s) {
            $mdat .= $s['data'];
        }

        return Mp4Muxer::buildTrackFragment(
            $this->fragmentSeq,
            $trackId,
            $moofSamples,
            $trackId === Mp4Muxer::AUDIO_TRACK_ID ? Mp4Muxer::AUDIO_TIMESCALE : Mp4Muxer::VIDEO_TIMESCALE,
            $moofSamples[0]['dts']
        ) . Mp4Muxer::mdat($mdat);
    }

    protected function writeSegment(string $data): void
    {
        if (!$this->segmentActive()) {
            return;
        }
        fwrite($this->fileHandle, $data);
        $this->index['end'] = timestamp();
        $this->index['duration'] = $this->index['end'] - $this->index['start'];
    }

    protected function finalizeSegment(): void
    {
        if (!$this->segmentActive()) {
            return;
        }
        fflush($this->fileHandle);
        fclose($this->fileHandle);
        $this->fileHandle = null;

        $this->index['end'] = timestamp();
        $this->index['duration'] = max(0, $this->index['end'] - $this->index['start']);
        $json = json_encode($this->index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            file_put_contents($this->segmentIndexFile, $json);
        }
        logger()->info("recorder segment saved {file}", ['file' => $this->segmentFile]);
    }
}
