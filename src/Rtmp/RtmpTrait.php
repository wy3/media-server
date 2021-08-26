<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/23
 * Time: 0:22
 */

namespace MediaServer\Rtmp;


use React\EventLoop\Loop;
use \Exception;

trait RtmpTrait
{

    use RtmpControlHandlerTrait,
        RtmpEventHandlerTrait,
        RtmpAudioHandlerTrait,
        RtmpVideoHandlerTrait,
        RtmpInvokeHandlerTrait,
        RtmpDataHandlerTrait;

    public function rtmpHandler(RtmpPacket $p)
    {
        //根据 msg type 进入处理流程
        switch ($p->type) {
            case RtmpPacket::TYPE_SET_CHUNK_SIZE:
            case RtmpPacket::TYPE_ABORT:
            case RtmpPacket::TYPE_ACKNOWLEDGEMENT:
            case RtmpPacket::TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE:
            case RtmpPacket::TYPE_SET_PEER_BANDWIDTH:
                //上面的类型全部进入协议控制信息处理流程
                return 0 === $this->rtmpControlHandler() ? -1 : 0;
            case RtmpPacket::TYPE_EVENT:
                //event 信息进入event 处理流程，不处理 event 信息
                return 0 === $this->rtmpEventHandler() ? -1 : 0;
            case RtmpPacket::TYPE_AUDIO:
                //audio 信息进入 audio 处理流程
                return $this->rtmpAudioHandler();
            case RtmpPacket::TYPE_VIDEO:
                //video 信息进入 video 处理流程
                return $this->rtmpVideoHandler();
            case RtmpPacket::TYPE_FLEX_MESSAGE:
            case RtmpPacket::TYPE_INVOKE:
                //上面信息进入invoke  引援？处理流程
                return $this->rtmpInvokeHandler();
            case RtmpPacket::TYPE_FLEX_STREAM: // AMF3
            case RtmpPacket::TYPE_DATA: // AMF0
                //其他rtmp信息处理
                return $this->rtmpDataHandler();
        }
    }


    public function stop(){
        if($this->pingInterval){
            Loop::cancelTimer($this->pingInterval);
            $this->pingInterval=null;
        }

    }

    public function reject(){
        $this->stop();
    }

}