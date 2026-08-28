<?php

declare(strict_types=1);

namespace MediaServer\Rtmp;


use MediaServer\MediaReader\MetaDataFrame;
use \Exception;

trait RtmpDataHandlerTrait
{

    /**
     * @throws Exception
     */
    public function rtmpDataHandler(): void
    {
        $p = $this->currentPacket;
        //AMF0 数据解释
        $dataMessage = RtmpAMF::rtmpDataAmf0Reader($p->payload);
        logger()->info("rtmpDataHandler {$dataMessage['cmd']} " . json_encode($dataMessage));
        switch ($dataMessage['cmd']) {
            case '@setDataFrame':
                if (isset($dataMessage['dataObj'])) {
                    // AMF 数字以 float 反序列化，强类型下需转 int 后再赋值
                    $this->audioSamplerate = (int)($dataMessage['dataObj']['audiosamplerate'] ?? $this->audioSamplerate);
                    $this->audioChannels = isset($dataMessage['dataObj']['stereo']) ? ($dataMessage['dataObj']['stereo'] ? 2 : 1) : $this->audioChannels;
                    $this->videoWidth = (int)($dataMessage['dataObj']['width'] ?? $this->videoWidth);
                    $this->videoHeight = (int)($dataMessage['dataObj']['height'] ?? $this->videoHeight);
                    $this->videoFps = $dataMessage['dataObj']['framerate'] ?? $this->videoFps;
                }

                $this->isMetaData = true;
                $metaDataFrame = new MetaDataFrame(RtmpAMF::rtmpDATAAmf0Creator([
                    'cmd' => 'onMetaData',
                    'dataObj' => $dataMessage['dataObj']
                ]));
                $this->metaDataFrame = $metaDataFrame;

                $this->emit('on_frame', [$metaDataFrame, $this]);

            //播放类群发onMetaData
        }
    }
}