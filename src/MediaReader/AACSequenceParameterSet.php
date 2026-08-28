<?php

declare(strict_types=1);

namespace MediaServer\MediaReader;


use MediaServer\Utils\BitReader;

class AACSequenceParameterSet extends BitReader
{
    public int $objType = 0;
    public int $sampleIndex = 0;
    public int $sampleRate = 0;
    public ?int $channels = null;
    public int $sbr = -1;
    public int $ps = -1;
    public ?int $extObjectType = null;

    public function __construct(string $data)
    {
        parent::__construct($data);
        $this->readData();
    }

    public function getAACProfileName(): string
    {
        switch ($this->objType) {
            case 1:
                return 'Main';
            case 2:
                if ($this->ps > 0) {
                    return 'HEv2';
                }
                if ($this->sbr > 0) {
                    return 'HE';
                }
                return 'LC';
            case 3:
                return 'SSR';
            case 4:
                return 'LTP';
            case 5:
                return 'SBR';
            default:
                return '';
        }
    }

    public function readData(): void
    {
        $objectType = ($objectType = $this->getBits(5)) === 31 ? ($this->getBits(6) + 32) : $objectType;
        $this->objType = $objectType;
        $sampleRate = ($sampleIndex = $this->getBits(4)) === 0x0f ? $this->getBits(24) : AACPacket::AAC_SAMPLE_RATE[$sampleIndex];
        $this->sampleIndex = $sampleIndex;
        $this->sampleRate = $sampleRate;
        $channelConfig = $this->getBits(4);

        if ($channelConfig < count(AACPacket::AAC_CHANNELS)) {
            $channels = AACPacket::AAC_CHANNELS[$channelConfig];
            $this->channels = $channels;
        }

        $this->sbr = -1;
        $this->ps = -1;
        if ($objectType == 5 || $objectType == 29) {
            if ($objectType == 29) {
                $this->ps = 1;
            }
            $this->extObjectType = 5;
            $this->sbr = 1;
            $this->sampleRate = ($sampleIndex = $this->getBits(4)) === 0x0f ? $this->getBits(24) : AACPacket::AAC_SAMPLE_RATE[$sampleIndex];
            $this->sampleIndex = $sampleIndex;
            $this->objType = ($objectType = $this->getBits(5)) === 31 ? ($this->getBits(6) + 32) : $objectType;
        }


    }


}