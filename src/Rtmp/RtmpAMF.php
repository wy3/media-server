<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/23
 * Time: 0:44
 */

namespace MediaServer\Rtmp;

require_once __DIR__ . '/../../packages/SabreAMF/OutputStream.php';
require_once __DIR__ . '/../../packages/SabreAMF/InputStream.php';

require_once __DIR__ . '/../../packages/SabreAMF/AMF0/Serializer.php';
require_once __DIR__ . '/../../packages/SabreAMF/AMF0/Deserializer.php';

class RtmpAMF
{

    const RTMP_CMD_CODE = [
        '_result' => ['transId', 'cmdObj', 'info'],
        '_error' => ['transId', 'cmdObj', 'info', 'streamId'], // Info / Streamid are optional
        'onStatus' => ['transId', 'cmdObj', 'info'],
        'releaseStream' => ['transId', 'cmdObj', 'streamName'],
        'getStreamLength' => ['transId', 'cmdObj', 'streamId'],
        'getMovLen' => ['transId', 'cmdObj', 'streamId'],
        'FCPublish' => ['transId', 'cmdObj', 'streamName'],
        'FCUnpublish' => ['transId', 'cmdObj', 'streamName'],
        'FCSubscribe' => ['transId', 'cmdObj', 'streamName'],
        'onFCPublish' => ['transId', 'cmdObj', 'info'],
        'connect' => ['transId', 'cmdObj', 'args'],
        'call' => ['transId', 'cmdObj', 'args'],
        'createStream' => ['transId', 'cmdObj'],
        'close' => ['transId', 'cmdObj'],
        'play' => ['transId', 'cmdObj', 'streamName', 'start', 'duration', 'reset'],
        'play2' => ['transId', 'cmdObj', 'params'],
        'deleteStream' => ['transId', 'cmdObj', 'streamId'],
        'closeStream' => ['transId', 'cmdObj'],
        'receiveAudio' => ['transId', 'cmdObj', 'bool'],
        'receiveVideo' => ['transId', 'cmdObj', 'bool'],
        'publish' => ['transId', 'cmdObj', 'streamName', 'type'],
        'seek' => ['transId', 'cmdObj', 'ms'],
        'pause' => ['transId', 'cmdObj', 'pause', 'ms']
    ];

    const RTMP_DATA_CODE = [
        '@setDataFrame' => ['method', 'dataObj'],
        'onFI' => ['info'],
        'onMetaData' => ['dataObj'],
        '|RtmpSampleAccess' => ['bool1', 'bool2'],
    ];


    /**
     * @param $payload
     * @return null[]
     * @throws \Exception
     */
    static function rtmpCMDAmf0Reader($payload)
    {
        $stream = new \SabreAMF_InputStream($payload);
        $deserializer = new \SabreAMF_AMF0_Deserializer($stream);
        $result = [
            'cmd' => null,
        ];
        if ($cmd = @$deserializer->readAMFData()) {
            $result['cmd'] = $cmd;
            if (isset(self::RTMP_CMD_CODE[$cmd])) {
                foreach (self::RTMP_CMD_CODE[$cmd] as $k) {
                    if ($stream->isEnd()) {
                        break;
                    }
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


    /**
     * @param $payload
     * @return null[]
     * @throws \Exception
     */
    static function rtmpDataAmf0Reader($payload)
    {

        $stream = new \SabreAMF_InputStream($payload);
        $deserializer = new \SabreAMF_AMF0_Deserializer($stream);
        $result = [
            'cmd' => null,
        ];
        if ($cmd = @$deserializer->readAMFData()) {
            $result['cmd'] = $cmd;
            if (isset(self::RTMP_DATA_CODE[$cmd])) {
                foreach (self::RTMP_DATA_CODE[$cmd] as $k) {
                    if ($stream->isEnd()) {
                        break;
                    }
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

    /**
     * Encode AMF0 Command
     * @param $opt
     * @throws \Exception
     * @return string
     */
    static function rtmpCMDAmf0Creator($opt)
    {

        $outputStream = new \SabreAMF_OutputStream();
        $serializer = new \SabreAMF_AMF0_Serializer($outputStream);
        $serializer->writeAMFData($opt['cmd']);
        if (isset(self::RTMP_CMD_CODE[$opt['cmd']])) {
            foreach (self::RTMP_CMD_CODE[$opt['cmd']] as $k) {
                if (key_exists($k, $opt)) {
                    $serializer->writeAMFData($opt[$k]);
                } else {
                    logger()->debug("amf 0 create {$k} not in opt " . json_encode($opt));
                }
            }
        } else {
            logger()->debug('AMF Unknown command {cmd}', $opt);
        }
        //logger()->debug('Encoded as ' . bin2hex($outputStream->getRawData()));
        return $outputStream->getRawData();
    }

    /**
     * Encode AMF0 Command
     * @param $opt
     * @throws \Exception
     * @return string
     */
    static function rtmpDATAAmf0Creator($opt)
    {

        $outputStream = new \SabreAMF_OutputStream();
        $serializer = new \SabreAMF_AMF0_Serializer($outputStream);
        $serializer->writeAMFData($opt['cmd']);
        if (isset(self::RTMP_DATA_CODE[$opt['cmd']])) {
            foreach (self::RTMP_DATA_CODE[$opt['cmd']] as $k) {
                if (key_exists($k, $opt)) {
                    $serializer->writeAMFData($opt[$k]);
                }
            }
        } else {
            logger()->debug('AMF Unknown command {cmd}', $opt);
        }
        //logger()->debug('Encoded as' . bin2hex($outputStream->getRawData()));
        return $outputStream->getRawData();
    }
}