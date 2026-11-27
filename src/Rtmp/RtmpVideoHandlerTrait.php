<?php

declare(strict_types=1);

namespace MediaServer\Rtmp;

use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\HEVCPacket;
use MediaServer\MediaReader\VideoFrame;
use Workerman\Timer;

trait RtmpVideoHandlerTrait
{


    public function rtmpVideoHandler(): void
    {
        //视频包拆解
        /**
         * @var $p RtmpPacket
         */
        $p = $this->currentPacket;
        $videoFrame = new VideoFrame($p->payload, $p->clock);
        if ($this->videoCodecName === '') {
            // Enhanced RTMP 的 codecId 恒为 0，不能再用 videoCodec == 0 作为判定条件
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

        switch (true) {
            case $videoFrame->isHEVC():
                //h265：Enhanced RTMP（codecId=0 + hvc1）与 legacy codecId=12 统一处理
                $hevcPack = $videoFrame->getHEVCPacket();
                if ($hevcPack->hevcPacketType === HEVCPacket::HEVC_PACKET_TYPE_SEQUENCE_START) {
                    $this->isHEVCSequence = true;
                    $this->hevcSequenceHeaderFrame = $videoFrame;
                    $set = $hevcPack->getHEVCSequenceParameterSet();
                    $this->videoWidth = $set->width;
                    $this->videoHeight = $set->height;
                    $this->videoProfileName = $set->getHEVCProfileName();
                    $this->videoLevel = $set->level;
                }
                if ($this->isHEVCSequence) {
                    if ($videoFrame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                        && $hevcPack->isCodedFrame()) {
                        $this->gopCacheQueue = [];
                    }
                    if ($hevcPack->hevcPacketType === HEVCPacket::HEVC_PACKET_TYPE_SEQUENCE_START) {
                        //skip hevc sequence
                    } elseif (!\MediaServer\MediaServer::frameBusOwnsGopCache()) {
                        // 单进程扇出：维护 PHP 数组 GOP 缓存；多进程 ingest 时 GOP 在 shm，跳过
                        $this->gopCacheQueue[] = $videoFrame;
                    }
                }
                break;
            case $videoFrame->codecId === VideoFrame::VIDEO_CODEC_ID_AVC:
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
                    } elseif (!\MediaServer\MediaServer::frameBusOwnsGopCache()) {
                        // 单进程扇出：维护 PHP 数组 GOP 缓存；多进程 ingest 时 GOP 在 shm，跳过
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