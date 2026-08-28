<?php

declare(strict_types=1);

namespace MediaServer\MediaReader;

use MediaServer\Utils\BinaryStream;

class AudioFrame extends BinaryStream implements MediaFrame
{
    public int $FRAME_TYPE = self::AUDIO_FRAME;

    const AUDIO_CODEC_NAME = [
        '',
        'ADPCM',
        'MP3',
        'LinearLE',
        'Nellymoser16',
        'Nellymoser8',
        'Nellymoser',
        'G711A',
        'G711U',
        '',
        'AAC',
        'Speex',
        '',
        'OPUS',
        'MP3-8K',
        'DeviceSpecific',
        'Uncompressed'
    ];

    const AUDIO_SOUND_RATE = [
        5512, 11025, 22050, 44100
    ];


    const SOUND_FORMAT_AAC = 10;

    public int $soundFormat = 0;
    public int $soundRate = 0;
    public int $soundSize = 0;
    public int $soundType = 0;
    public int $timestamp = 0;


    public function __construct(string $data, int $timestamp = 0)
    {
        parent::__construct($data);
        $this->timestamp = $timestamp;
        $firstByte = $this->readTinyInt();
        $this->soundFormat = $firstByte >> 4;
        $this->soundRate = $firstByte >> 2 & 3;
        $this->soundSize = $firstByte >> 1 & 1;
        $this->soundType = $firstByte & 1;

    }

    public function __toString(): string
    {
        return $this->dump();
    }


    public function getAudioCodecName(): string
    {
        return self::AUDIO_CODEC_NAME[$this->soundFormat];
    }

    public function getAudioSamplerate(): int
    {
        $rate = self::AUDIO_SOUND_RATE[$this->soundRate];
        switch ($this->soundFormat) {
            case 4:
                $rate = 16000;
                break;
            case 5:
                $rate = 8000;
                break;
            case 11:
                $rate = 16000;
                break;
            case 14:
                $rate = 8000;
                break;
        }
        return $rate;
    }

    /**
     * @var AACPacket
     */
    protected ?AACPacket $aacPacket = null;

    public function getAACPacket(): AACPacket
    {
        if (!$this->aacPacket) {
            $this->aacPacket = new AACPacket($this);
        }

        return $this->aacPacket;
    }


    public function destroy(): void
    {
        $this->aacPacket = null;
    }

}