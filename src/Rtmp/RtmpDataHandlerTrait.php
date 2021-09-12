<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/23
 * Time: 0:22
 */

namespace MediaServer\Rtmp;


use MediaServer\MediaReader\MetaDataFrame;
use React\EventLoop\Loop;
use \Exception;

trait RtmpDataHandlerTrait
{

    /**
     * @throws Exception
     */
    public function rtmpDataHandler()
    {
        $p = $this->currentPacket;
        //AMF0 数据解释
        $dataMessage = RtmpAMF::rtmpDataAmf0Reader($p->payload);
        logger()->info("rtmpDataHandler {$dataMessage['cmd']} " . json_encode($dataMessage));
        switch ($dataMessage['cmd']) {
            case '@setDataFrame':
                if (isset($dataMessage['dataObj'])) {
                    $this->audioSamplerate = $dataMessage['dataObj']['audiosamplerate'] ?? $this->audioSamplerate;
                    $this->audioChannels = isset($dataMessage['dataObj']['stereo']) ? ($dataMessage['dataObj']['stereo'] ? 2 : 1) : $this->audioChannels;
                    $this->videoWidth = $dataMessage['dataObj']['width'] ?? $this->videoWidth;
                    $this->videoHeight = $dataMessage['dataObj']['height'] ?? $this->videoHeight;
                    $this->videoFps = $dataMessage['dataObj']['framerate'] ?? $this->videoFps;
                }

                $this->isMetaData = true;
                $metaDataFrame = new MetaDataFrame();
                $stream = new \SabreAMF_OutputStream();
                $s = new \SabreAMF_AMF0_Serializer($stream);
                $s->writeAMFData([
                    'cmd' => 'onMetaData',
                    'dataObj' => $dataMessage['dataObj']
                ]);
                $metaDataFrame->rawData = $stream->getRawData();
                $this->metaDataFrame = $metaDataFrame;

            //播放类群发onMetaData
        }
    }
}