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
