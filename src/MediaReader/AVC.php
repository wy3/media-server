<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/12
 * Time: 0:41
 */

namespace MediaServer\MediaReader;

use MediaServer\Utils\BitReader;

class AVC
{
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;
    const AVC_PACKET_TYPE_END_SEQUENCE = 2;

    /**
     * @param $avcPacket
     * @return AVCPacket
     */
    static function packetRead($avcPacket)
    {
        return AVCPacket::create([
            'avcPacketType' => ord($avcPacket[0]), //if codecId == 7 ,0 avc sequence header,1 avc nalus
            'compositionTime' => (ord($avcPacket[1]) << 16) | (ord($avcPacket[2]) << 8) | ord($avcPacket[3]),
            'data' => substr($avcPacket, 4)
        ]);
    }

    /**
     * @param $sequenceHeader
     * @return AVCSequenceParameterSet
     */
    static function readAVCSpecificConfig($sequenceHeader)
    {
        $reader = new AVCSequenceParameterSet($sequenceHeader);
        $reader->readData();
        return $reader;
    }

    static function getAVCProfileName($profile)
    {
        switch ($profile) {
            case 1:
                return 'Main';
            case 2:
                return 'Main 10';
            case 3:
                return 'Main Still Picture';
            case 66:
                return 'Baseline';
            case 77:
                return 'Main';
            case 100:
                return 'High';
            default:
                return '';
        }
    }
}


