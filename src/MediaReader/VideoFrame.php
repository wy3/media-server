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


    public int $frameType = 0;
    public int $codecId = 0;
    public int $timestamp = 0;

    public function __toString(): string
    {
        return $this->dump();
    }


    public function getVideoCodecName(): string
    {
        return self::VIDEO_CODEC_NAME[$this->codecId];
    }


    public function __construct(string $data, int $timestamp = 0)
    {
        parent::__construct($data);

        $this->timestamp = $timestamp;
        $firstByte = $this->readTinyInt();
        $this->frameType = $firstByte >> 4;
        $this->codecId = $firstByte & 15;
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

    public function destroy(): void
    {
        $this->avcPacket = null;
    }

}