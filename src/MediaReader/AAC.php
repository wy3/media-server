<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/12
 * Time: 19:24
 */

namespace MediaServer\MediaReader;


use MediaServer\Utils\BitReader;

class AAC
{
    const AAC_SAMPLE_RATE = [
        96000, 88200, 64000, 48000,
        44100, 32000, 24000, 22050,
        16000, 12000, 11025, 8000,
        7350, 0, 0, 0
    ];

    const AAC_CHANNELS = [
        0, 1, 2, 3, 4, 5, 6, 8
    ];

    const AAC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AAC_PACKET_TYPE_RAW = 1;

    /**
     * @param $accData
     * @return AACPacket
     */
    static function packetRead($accData)
    {
        return AACPacket::create([
            'aacPacketType' => ord($accData[0]), //0 = AAC sequence header，1 = AAC raw
            'data' => substr($accData, 1)
        ]);
    }

    static function getAACProfileName(AACSequenceParameterSet $set)
    {
        switch ($set->objType) {
            case 1:
                return 'Main';
            case 2:
                if ($set->ps > 0) {
                    return 'HEv2';
                }
                if ($set->sbr > 0) {
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

}

class AACPacket
{
    public $aacPacketType;
    public $data;

    /**
     * @param $args
     * @return AACPacket
     */
    public static function create($args)
    {
        $f = new self();
        foreach ($args as $k => $v) {
            $f->$k = $v;
        }
        return $f;
    }
}


class AACSequenceParameterSet extends BitReader
{
    public $objType;
    public $sampleIndex;
    public $sampleRate;
    public $channels;
    public $sbr;
    public $ps;
    public $extObjectType;

    public function readData()
    {
        $objectType = ($objectType = $this->getBits(5)) === 31 ? ($this->getBits(6) + 32) : $objectType;
        $this->objType = $objectType;
        $sampleRate = ($sampleIndex = $this->getBits(4)) === 0x0f ? $this->getBits(24) : AAC::AAC_SAMPLE_RATE[$sampleIndex];
        $this->sampleIndex = $sampleIndex;
        $this->sampleRate = $sampleRate;
        $channelConfig = $this->getBits(4);

        if ($channelConfig < count(AAC::AAC_CHANNELS)) {
            $channels = AAC::AAC_CHANNELS[$channelConfig];
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
            $this->sampleRate = ($sampleIndex = $this->getBits(4)) === 0x0f ? $this->getBits(24) : AAC::AAC_SAMPLE_RATE[$sampleIndex];
            $this->sampleIndex = $sampleIndex;
            $this->objType = ($objectType = $this->getBits(5)) === 31 ? ($this->getBits(6) + 32) : $objectType;
        }


    }


}