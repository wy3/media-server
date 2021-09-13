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

trait RtmpControlHandlerTrait
{
    public function rtmpControlHandler()
    {
        $b = microtime(true);
        $p = $this->currentPacket;
        switch ($p->type) {
            case RtmpPacket::TYPE_SET_CHUNK_SIZE:
                list(, $this->inChunkSize) = unpack("N", $p->payload);
                logger()->debug('set inChunkSize ' . $this->inChunkSize);
                break;
            case RtmpPacket::TYPE_ABORT:
                break;
            case RtmpPacket::TYPE_ACKNOWLEDGEMENT:
                break;
            case RtmpPacket::TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE:
                list(, $this->ackSize) = unpack("N", $p->payload);
                logger()->debug('set ack Size ' . $this->ackSize);
                break;
            case RtmpPacket::TYPE_SET_PEER_BANDWIDTH:
                break;
        }

        //logger()->info("rtmpControlHandler use:" . ((microtime(true) - $b) * 1000) . 'ms');
    }
}