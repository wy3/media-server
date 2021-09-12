<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/9
 * Time: 2:35
 */

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
        /** @var RtmpPacket $p */
        $p = $this->currentPacket;
        switch ($p->state) {
            case RtmpPacket::PACKET_STATE_MSG_HEADER:
                //base header + message header
                if (isset($this->buffer[$p->msgHeaderLen - 1])) {
                    $data = substr($this->buffer, 0, $p->msgHeaderLen);
                    $bin = new BinaryStream($data);
                    switch ($p->chunkType) {
                        case RtmpChunk::CHUNK_TYPE_3:
                            // all same
                            break;
                        case RtmpChunk::CHUNK_TYPE_2:
                            //new timestamp delta, 3bytes
                            $p->timestamp = $bin->readInt24();
                            break;
                        case RtmpChunk::CHUNK_TYPE_1:
                            //new timestamp delta, length,type 7bytes
                            $p->timestamp = $bin->readInt24();
                            $p->length = $bin->readInt24();
                            $p->type = $bin->readTinyInt();
                            break;
                        case RtmpChunk::CHUNK_TYPE_0:
                            //all different, 11bytes
                            $p->timestamp = $bin->readInt24();
                            $p->length = $bin->readInt24();
                            $p->type = $bin->readTinyInt();
                            $p->streamId = $bin->readInt32LE();
                            break;
                    }

                    if ($p->chunkType == RtmpChunk::CHUNK_TYPE_0) {
                        //当前时间是绝对时间
                        $p->hasAbsTimestamp = true;
                    }


                    $p->state = RtmpPacket::PACKET_STATE_EXT_TIMESTAMP;
                    $this->buffer = substr($this->buffer, $p->msgHeaderLen);


                    //logger()->info("chunk header fin");
                    //var_dump($p);
                } else {
                    //长度不够，等待下个数据包
                    return false;
                }
            case RtmpPacket::PACKET_STATE_EXT_TIMESTAMP:

                if ($p->timestamp === RtmpPacket::MAX_TIMESTAMP) {
                    if (isset($this->buffer[3])) {
                        list(, $extTimestamp) = unpack("N", substr($this->buffer, 0, 4));

                        logger()->info("chunk has ext timestamp {$extTimestamp}");

                        $p->hasExtTimestamp = true;
                        $this->buffer = substr($this->buffer, 4);
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
                    if (isset($this->buffer[$size - 1])) {
                        //数据拷贝
                        $p->payload .= substr($this->buffer, 0, $size);
                        $p->bytesRead += $size;
                        $this->buffer = substr($this->buffer, $size);
                        //logger()->info("packet csid {$p->chunkStreamId} stream {$p->streamId} payload  size {$size} payload size: {$p->length} bytesRead {$p->bytesRead}");
                    } else {
                        //长度不够，等待下个数据包
                        //logger()->info("packet csid  {$p->chunkStreamId} stream {$p->streamId} payload  size {$size} payload size: {$p->length} bytesRead {$p->bytesRead} buffer " . strlen($this->buffer) . " not enough.");
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
