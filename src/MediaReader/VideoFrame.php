<?php

declare(strict_types=1);

namespace MediaServer\MediaReader;


use MediaServer\Utils\BinaryStream;

class VideoFrame extends BinaryStream implements MediaFrame
{
    public int $FRAME_TYPE = self::VIDEO_FRAME;

    const VIDEO_CODEC_NAME = [
        '',
        'Jpeg',
        'Sorenson-H263',
        'ScreenVideo',
        'On2-VP6',
        'On2-VP6-Alpha',
        'ScreenVideo2',
        'H264',
        '',
        '',
        '',
        '',
        'H265'
    ];


    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const VIDEO_FRAME_TYPE_INTER_FRAME = 2;
    const VIDEO_FRAME_TYPE_DISPOSABLE_INTER_FRAME = 3;
    const VIDEO_FRAME_TYPE_GENERATED_KEY_FRAME = 4;
    const VIDEO_FRAME_TYPE_VIDEO_INFO_FRAME = 5;


    const VIDEO_CODEC_ID_JPEG = 1;
    const VIDEO_CODEC_ID_H263 = 2;
    const VIDEO_CODEC_ID_SCREEN = 3;
    const VIDEO_CODEC_ID_VP6_FLV = 4;
    const VIDEO_CODEC_ID_VP6_FLV_ALPHA = 5;
    const VIDEO_CODEC_ID_SCREEN_V2 = 6;
    const VIDEO_CODEC_ID_AVC = 7;
    const VIDEO_CODEC_ID_HEVC = 12;


    public int $frameType = 0;
    public int $codecId = 0;
    public int $timestamp = 0;

    /** 是否使用 Enhanced RTMP 扩展视频头（首字节最高位 0x80） */
    public bool $isEnhanced = false;

    /** Enhanced RTMP 的 FourCC，如 hvc1 / av01 */
    public string $exVideoFourCC = '';

    /** Enhanced RTMP 首字节低 4 位携带的 PacketType（H.265 用） */
    public int $exPacketType = 0;

    public function __toString(): string
    {
        return $this->dump();
    }


    public function getVideoCodecName(): string
    {
        if ($this->isEnhanced) {
            return match ($this->exVideoFourCC) {
                'hvc1', 'hev1' => 'H265',
                'av01' => 'AV1',
                default => '',
            };
        }
        return self::VIDEO_CODEC_NAME[$this->codecId];
    }

    /**
     * 是否为 HEVC（H.265）视频，兼容 Enhanced RTMP（codecId=0 + hvc1）与旧版 codecId=12。
     *
     * @return bool
     */
    public function isHEVC(): bool
    {
        if ($this->isEnhanced) {
            return $this->exVideoFourCC === 'hvc1' || $this->exVideoFourCC === 'hev1';
        }
        return $this->codecId === self::VIDEO_CODEC_ID_HEVC;
    }


    public function __construct(string $data, int $timestamp = 0)
    {
        parent::__construct($data);

        $this->timestamp = $timestamp;
        $firstByte = $this->readTinyInt();
        $this->isEnhanced = ($firstByte & 0x80) !== 0;
        if ($this->isEnhanced) {
            // Enhanced RTMP：1 + frameType(3bit) + packetType(4bit)，随后 4 字节 FourCC
            $this->frameType = ($firstByte >> 4) & 0x07;
            $this->exPacketType = $firstByte & 0x0F;
            $this->codecId = 0;
            if ($this->has(4)) {
                $this->exVideoFourCC = $this->readRaw(4);
            }
        } else {
            $this->frameType = $firstByte >> 4;
            $this->codecId = $firstByte & 15;
        }
    }


    /**
     * @var AVCPacket
     */
    protected ?AVCPacket $avcPacket = null;

    public function getAVCPacket(): AVCPacket
    {
        if (!$this->avcPacket) {
            $this->avcPacket = new AVCPacket($this);
        }

        return $this->avcPacket;
    }

    /**
     * @var HEVCPacket|null
     */
    protected ?HEVCPacket $hevcPacket = null;

    /**
     * 取 HEVC 分包（仅当本帧为 H.265 时有效）。
     *
     * 与 getAVCPacket 的区别：Enhanced RTMP 的 packetType/cts 位置不同，
     * 且 FourCC 已由构造函数读走，因此不能复用 AVCPacket。
     *
     * @return HEVCPacket
     */
    public function getHEVCPacket(): HEVCPacket
    {
        if (!$this->hevcPacket) {
            $this->hevcPacket = new HEVCPacket($this, $this->isEnhanced ? $this->exPacketType : -1);
        }

        return $this->hevcPacket;
    }

    public function destroy(): void
    {
        $this->avcPacket = null;
        $this->hevcPacket = null;
    }

}