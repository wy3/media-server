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
                $metaDataFrame = new MetaDataFrame(RtmpAMF::rtmpDATAAmf0Creator([
                    'cmd' => 'onMetaData',
                    'dataObj' => $dataMessage['dataObj']
                ]));
                $this->metaDataFrame = $metaDataFrame;

                $this->emit('on_frame',[$metaDataFrame]);

            //播放类群发onMetaData
        }
    }
}