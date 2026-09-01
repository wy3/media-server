<?php

declare(strict_types=1);

namespace MediaServer\Rtmp;


trait RtmpControlHandlerTrait
{
    public function rtmpControlHandler(): void
    {
        $b = microtime(true);
        $p = $this->currentPacket;
        switch ($p->type) {
            case RtmpPacket::TYPE_SET_CHUNK_SIZE:
                // payload 不足 4 字节时 unpack 返回 false，直接解包会把 null 赋给 int 属性
                // 抛 TypeError 后会逃逸到 Workerman 的 stopAll() 兜底，导致整个进程退出
                if (strlen($p->payload) >= 4) {
                    $v = (int)unpack("N", $p->payload)[1];
                    // inChunkSize 为 0 会在分片重组时触发除零；上限按协议最大 24 位
                    if ($v >= 1 && $v <= 0xFFFFFF) {
                        $this->inChunkSize = $v;
                        logger()->debug('set inChunkSize ' . $this->inChunkSize);
                    }
                }
                break;
            case RtmpPacket::TYPE_ABORT:
                break;
            case RtmpPacket::TYPE_ACKNOWLEDGEMENT:
                break;
            case RtmpPacket::TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE:
                // 同上：仅接受完整 4 字节 payload，避免 null 赋给强类型属性
                if (strlen($p->payload) >= 4) {
                    $this->ackSize = (int)unpack("N", $p->payload)[1];
                    logger()->debug('set ack Size ' . $this->ackSize);
                }
                break;
            case RtmpPacket::TYPE_SET_PEER_BANDWIDTH:
                break;
        }

        //logger()->info("rtmpControlHandler use:" . ((microtime(true) - $b) * 1000) . 'ms');
    }
}