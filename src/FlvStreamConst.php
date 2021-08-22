<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/9
 * Time: 2:35
 */

namespace MediaServer;

require_once __DIR__ . '/SabreAMF/OutputStream.php';
require_once __DIR__ . '/SabreAMF/InputStream.php';

require_once __DIR__ . '/SabreAMF/AMF0/Serializer.php';
require_once __DIR__ . '/SabreAMF/AMF0/Deserializer.php';

use Exception;
use SabreAMF_AMF0_Deserializer;
use SabreAMF_InputStream;
use function ord;

class FlvStreamConst
{

    static function flvHeaderRead($flvHeader)
    {
        $data = unpack("a3signature/Cversion/CtypeFlags/NdataOffset", $flvHeader);
        return $data;
    }

    /**
     * pre tag len 转数字
     * @param $preLen
     * @return mixed
     */
    static function preTagLenRead($preLen)
    {
        $up = unpack('N', $preLen);
        return $up[0];
    }

    static function tagDataRead($tagData)
    {
        return [
            'type' => ord($tagData[0]),
            'dataSize' => strlen($tagData) - 11,
            'timestamp' => (ord($tagData[7]) << 24) | (ord($tagData[4]) << 16) | (ord($tagData[5]) << 8) | ord($tagData[6]),
            'streamId' => (ord($tagData[8]) << 16) | (ord($tagData[9]) << 8) | ord($tagData[10]),
            //'data'=>substr($tagData,11),
            'data' => substr($tagData, 11)
        ];
    }

    /**
     * @param $scriptData
     * @return null[]
     * @throws Exception
     */
    static function scriptFrameDataRead($scriptData)
    {
        static $scriptMetaDataCode = [
            'onMetaData' => ['dataObj']
        ];
        $stream = new SabreAMF_InputStream($scriptData);
        $deserializer = new SabreAMF_AMF0_Deserializer($stream);
        $result = [
            'cmd' => null,
        ];
        if ($cmd = @$deserializer->readAMFData()) {
            $result['cmd'] = $cmd;
            if (isset($scriptMetaDataCode[$cmd])) {
                foreach ($scriptMetaDataCode[$cmd] as $k) {
                    $result[$k] = $deserializer->readAMFData();
                }
            } else {
                logger()->warning('AMF Unknown command {cmd}', $result);
            }
        } else {
            logger()->warning('AMF read data error');
        }
        return $result;
    }

    static function videoFrameDataRead($videoData)
    {
        $firstByte = ord($videoData[0]);
        return [
            'frameType' => $firstByte >> 4,
            'codecId' => $firstByte & 15,
            'data' => substr($videoData, 1),
        ];
    }

    static function avcPacketRead($avcPacket)
    {
        return [
            'avcPacketType' => ord($avcPacket[0]), //if codecId == 7 ,0 avc sequence header,1 avc nalus
            'compositionTime' => (ord($avcPacket[1]) << 16) | (ord($avcPacket[2]) << 8) | ord($avcPacket[3]),
            'data' => substr($avcPacket, 4)
        ];
    }

    static function audioFrameDataRead($audioData)
    {
        $firstByte = ord($audioData[0]);
        return [
            'soundFormat' => $firstByte >> 4,
            'soundRate' => $firstByte >> 2 & 3,
            'soundSize' => $firstByte >> 1 & 1,
            'soundType' => $firstByte & 1,
            'data' => substr($audioData, 1)
        ];
    }

    static function accPacketDataRead($accData)
    {
        return [
            'accPacketType' => ord($accData[0]), //0 = AAC sequence header，1 = AAC raw
            'data' => substr($accData, 1)
        ];
    }


    /**
     *  $analysis = unpack("CtagType/a3tagSize/a3timestamp/CtimestampEx/a3streamId/a{$dataSize}data", $data);
     * $tag = [
     * 'type' => $analysis['tagType'],
     * 'dataSize' => $dataSize,
     * 'timestamp' => ($analysis['timestampEx'] << 24) | (\ord($analysis['timestamp'][0]) << 16) | (\ord($analysis['timestamp'][1]) << 8) | \ord($analysis['timestamp'][2]),
     * 'streamId' => (\ord($analysis['streamId'][0]) << 16) | (\ord($analysis['streamId'][1]) << 8) | \ord($analysis['streamId'][2]),
     * 'data' => $analysis['data']
     * ];
     *
     * @param $tag
     * @return string
     */
    static function createFlvTag($tag)
    {
        $preTagLen = 11 + $tag['dataSize'];
        $packet = pack("Ca3a3Ca3a{$tag['dataSize']}N",
            $tag['type'],                                       //type
            pack("N", $tag['dataSize'] << 8),     //dataSize
            pack("N", $tag['timestamp'] << 8),    //timeStamp
            $tag['timestamp'] >> 24,                            //timeStampExt
            pack("N", $tag['streamId'] << 8),     //streamId
            $tag['data'],                                       //data
            $preTagLen                                          //preTagLen
        );

        return $packet;
    }

    const SCRIPT_TAG = 18;
    const AUDIO_TAG = 8;
    const VIDEO_TAG = 9;

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

    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;
    const AVC_PACKET_TYPE_END_SEQUENCE = 2;


    const SOUND_FORMAT_ACC = 10;

    const ACC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const ACC_PACKET_TYPE_RAW = 1;
}
