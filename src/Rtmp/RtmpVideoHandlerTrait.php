<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/23
 * Time: 0:22
 */

namespace MediaServer\Rtmp;


use MediaServer\MediaReader\AVC;
use MediaServer\MediaReader\VideoAnalysis;
use React\EventLoop\Loop;
use \Exception;

trait RtmpVideoHandlerTrait
{


    public function rtmpVideoHandler()
    {
        //视频包拆解
        $p = $this->currentPacket;
        $videoFrame = VideoAnalysis::frameReader($p->payload);
        if ($this->videoCodec == 0) {
            $this->videoCodec = $videoFrame->codecId;
            $this->videoCodecName = $videoFrame->getVideoCodecName();
        }


        if ($this->videoFps === 0) {
            //当前帧为第0
            if ($this->videoCount++ === 0) {
                Loop::addTimer(5, function () {
                    $this->videoFps = ceil($this->videoCount / 5);
                });
            }
        }

        switch ($videoFrame->codecId) {
            case VideoAnalysis::VIDEO_CODEC_ID_AVC:
                //h264
                $avcPack = AVC::packetRead($videoFrame->data);
                if ($avcPack->avcPacketType === AVC::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->isAVCSequence = true;
                    $this->avcSequenceHeaderFrame = $videoFrame;
                    $specificConfig = AVC::readAVCSpecificConfig($avcPack->data);
                    $this->videoWidth = $specificConfig->width;
                    $this->videoHeight = $specificConfig->height;
                    $this->videoProfileName = AVC::getAVCProfileName($specificConfig->profile);
                    $this->videoLevel = $specificConfig->level;
                }

                break;
        }
        //数据处理与数据发送
        $this->emit('on_frame', [$videoFrame]);

    }
}