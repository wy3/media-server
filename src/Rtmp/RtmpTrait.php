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
use Workerman\Timer;

trait RtmpTrait
{

    use RtmpControlHandlerTrait,
        RtmpEventHandlerTrait,
        RtmpAudioHandlerTrait,
        RtmpVideoHandlerTrait,
        RtmpInvokeHandlerTrait,
        RtmpDataHandlerTrait,
        RtmpAuthorizeTrait;

    /**
     * @param RtmpPacket $p
     * @return int|mixed|void
     * @throws Exception
     */
    public function rtmpHandler(RtmpPacket $p)
    {
        //根据 msg type 进入处理流程
        //logger()->info("[packet] {$p->type}");
        //$b = memory_get_usage();
        switch ($p->type) {
            case RtmpPacket::TYPE_SET_CHUNK_SIZE:
            case RtmpPacket::TYPE_ABORT:
            case RtmpPacket::TYPE_ACKNOWLEDGEMENT:
            case RtmpPacket::TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE:
            case RtmpPacket::TYPE_SET_PEER_BANDWIDTH:
                //上面的类型全部进入协议控制信息处理流程
                0 === $this->rtmpControlHandler() ? -1 : 0;
                break;
            case RtmpPacket::TYPE_EVENT:
                //event 信息进入event 处理流程，不处理 event 信息
                0 === $this->rtmpEventHandler() ? -1 : 0;
                break;
            case RtmpPacket::TYPE_AUDIO:
                //audio 信息进入 audio 处理流程
                $this->rtmpAudioHandler();
                break;
            case RtmpPacket::TYPE_VIDEO:
                //video 信息进入 video 处理流程
                $this->rtmpVideoHandler();
                break;
            case RtmpPacket::TYPE_FLEX_MESSAGE:
            case RtmpPacket::TYPE_INVOKE:
                //上面信息进入invoke  引援？处理流程
                $this->rtmpInvokeHandler();
                break;
            case RtmpPacket::TYPE_FLEX_STREAM: // AMF3
            case RtmpPacket::TYPE_DATA: // AMF0
                //其他rtmp信息处理
                $this->rtmpDataHandler();
                break;
        }
        //logger()->info("[memory] memory add:" . (memory_get_usage() - $b));
    }


    public function stop()
    {

        if ($this->isStarting) {
            $this->isStarting = false;
            if ($this->playStreamId > 0) {
                $this->onDeleteStream(['streamId' => $this->playStreamId]);
            }

            if ($this->publishStreamId > 0) {
                $this->onDeleteStream(['streamId' => $this->publishStreamId]);
            }

            if ($this->pingInterval) {
                Timer::del($this->pingInterval);
                $this->pingInterval = null;
            }
        }

        $this->emit('on_close');

        logger()->info("[rtmp disconnect] id={$this->id}");


    }


}
