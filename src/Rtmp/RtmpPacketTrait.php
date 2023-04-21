<?php


namespace MediaServer\Rtmp;


use MediaServer\Utils\BinaryStream;


/**
 * Trait RtmpPacketTrait
 * @package MediaServer\Rtmp
 */
trait RtmpPacketTrait
{

    public function onPacketHandler()
    {
        /**
         * @var $stream BinaryStream
         */
        $stream = $this->buffer;


        /** @var RtmpPacket $p */
        $p = $this->currentPacket;
        switch ($p->state) {
            case RtmpPacket::PACKET_STATE_MSG_HEADER:
                //base header + message header
                if ($stream->has($p->msgHeaderLen)) {
                    switch ($p->chunkType) {
                        case RtmpChunk::CHUNK_TYPE_3:
                            // all same
                            break;
                        case RtmpChunk::CHUNK_TYPE_2:
                            //new timestamp delta, 3bytes
                            $p->timestamp = $stream->readInt24();
                            break;
                        case RtmpChunk::CHUNK_TYPE_1:
                            //new timestamp delta, length,type 7bytes
                            $p->timestamp = $stream->readInt24();
                            $p->length = $stream->readInt24();
                            $p->type = $stream->readTinyInt();
                            break;
                        case RtmpChunk::CHUNK_TYPE_0:
                            //all different, 11bytes
                            $p->timestamp = $stream->readInt24();
                            $p->length = $stream->readInt24();
                            $p->type = $stream->readTinyInt();
                            $p->streamId = $stream->readInt32LE();
                            break;
                    }

                    if ($p->chunkType == RtmpChunk::CHUNK_TYPE_0) {
                        //当前时间是绝对时间
                        $p->hasAbsTimestamp = true;
                    }


                    $p->state = RtmpPacket::PACKET_STATE_EXT_TIMESTAMP;

                    //logger()->info("chunk header fin");
                } else {
                    //长度不够，等待下个数据包
                    return false;
                }
            case RtmpPacket::PACKET_STATE_EXT_TIMESTAMP:
                if ($p->timestamp === RtmpPacket::MAX_TIMESTAMP) {
                    if ($stream->has(4)) {
                        $extTimestamp = $stream->readInt32();
                        logger()->info("chunk has ext timestamp {$extTimestamp}");
                        $p->hasExtTimestamp = true;
                    } else {
                        //当前长度不够，等待下个数据包
                        return false;
                    }
                } else {
                    $extTimestamp = $p->timestamp;
                }

                //判断当前包是不是有数据
                if ($p->bytesRead == 0) {
                    if ($p->chunkType == RtmpChunk::CHUNK_TYPE_0) {
                        $p->clock = $extTimestamp;
                    } else {
                        $p->clock += $extTimestamp;
                    }

                }

                $p->state = RtmpPacket::PACKET_STATE_PAYLOAD;
            case RtmpPacket::PACKET_STATE_PAYLOAD:

                $size = min(
                    $this->inChunkSize, //读取完整的包
                    $p->length - $p->bytesRead  //当前剩余的数据
                );


                if ($size > 0) {
                    if ($stream->has($size)) {
                        //数据拷贝
                        $p->payload .= $stream->readRaw($size);
                        $p->bytesRead += $size;
                        //logger()->info("packet csid {$p->chunkStreamId} stream {$p->streamId} payload  size {$size} payload size: {$p->length} bytesRead {$p->bytesRead}");
                    } else {
                        //长度不够，等待下个数据包
                        //logger()->info("packet csid  {$p->chunkStreamId} stream {$p->streamId} payload  size {$size} payload size: {$p->length} bytesRead {$p->bytesRead} buffer ") . " not enough.");
                        return false;
                    }
                }

                if ($p->isReady()) {
                    //开始读取下一个包
                    $this->chunkState = RtmpChunk::CHUNK_STATE_BEGIN;

                    $this->rtmpHandler($p);

                    //当前包已经读取完成数据，释放当前包
                    $p->free();
                } elseif (0 === $p->bytesRead % $this->inChunkSize) {
                    //当前chunk已经读取完成
                    $this->chunkState = RtmpChunk::CHUNK_STATE_BEGIN;
                }
        }


    }
}
