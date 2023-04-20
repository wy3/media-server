<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/23
 * Time: 0:22
 */

namespace MediaServer\Rtmp;

use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\VideoFrame;
use Workerman\Timer;

trait RtmpVideoHandlerTrait
{


    public function rtmpVideoHandler()
    {
        //视频包拆解
        /**
         * @var $p RtmpPacket
         */
        $p = $this->currentPacket;
        $videoFrame = new VideoFrame($p->payload, $p->clock);
        if ($this->videoCodec == 0) {
            $this->videoCodec = $videoFrame->codecId;
            $this->videoCodecName = $videoFrame->getVideoCodecName();
        }


        if ($this->videoFps === 0) {
            //当前帧为第0
            if ($this->videoCount++ === 0) {
                $this->videoFpsCountTimer = Timer::add(5,function(){
                    $this->videoFps = ceil($this->videoCount / 5);
                    $this->videoFpsCountTimer = null;
                },[],false);
            }
        }

        switch ($videoFrame->codecId) {
            case VideoFrame::VIDEO_CODEC_ID_AVC:
                //h264
                $avcPack = $videoFrame->getAVCPacket();
                if ($avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->isAVCSequence = true;
                    $this->avcSequenceHeaderFrame = $videoFrame;
                    $specificConfig = $avcPack->getAVCSequenceParameterSet();
                    $this->videoWidth = $specificConfig->width;
                    $this->videoHeight = $specificConfig->height;
                    $this->videoProfileName = $specificConfig->getAVCProfileName();
                    $this->videoLevel = $specificConfig->level;
                }
                if ($this->isAVCSequence) {
                    if ($videoFrame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                        &&
                        $avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_NALU) {
                        $this->gopCacheQueue = [];
                    }

                    if ($videoFrame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                        &&
                        $avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                        //skip avc sequence
                    } else {
                        $this->gopCacheQueue[] = $videoFrame;
                    }
                }

                break;
        }
        //数据处理与数据发送
        $this->emit('on_frame', [$videoFrame, $this]);
        //销毁AVC
        $videoFrame->destroy();

    }
}
