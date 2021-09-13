<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/23
 * Time: 0:22
 */

namespace MediaServer\Rtmp;

use \Exception;

/**
 * Trait RtmpChunkHandlerTrait
 * @package MediaServer\Rtmp
 */
trait RtmpChunkHandlerTrait
{

    /**
     *
     */
    public function onChunkData()
    {

        switch ($this->chunkState) {
            case RtmpChunk::CHUNK_STATE_BEGIN:
                if (isset($this->buffer[0])) {
                    $header = ord($this->buffer[0]);
                    $chunkHeaderLen = RtmpChunk::BASE_HEADER_SIZES[$header & 0x3f] ?? 1; //base header size
                    //logger()->info('base header size ' . $chunkHeaderLen);
                    $chunkHeaderLen += RtmpChunk::MSG_HEADER_SIZES[$header >> 6]; //messaege header size
                    //logger()->info('base + msg header size ' . $chunkHeaderLen);
                    //base header + message header
                    $this->chunkHeaderLen = $chunkHeaderLen;
                    $this->chunkState = RtmpChunk::CHUNK_STATE_HEADER_READY;
                    //不截断当前buffer

                } else {
                    break;
                }
            case RtmpChunk::CHUNK_STATE_HEADER_READY:
                if (isset($this->buffer[$this->chunkHeaderLen - 1])) {
                    //get base header + message header
                    //$this->buffer = substr($this->buffer, $this->chunkHeaderLen);
                    //logger()->info(bin2hex($this->buffer[0]));
                    $header = ord($this->buffer[0]);
                    $fmt = $header >> 6;
                    switch ($csId = $header & 0x3f) {
                        case 0:
                            $csId = ord($this->buffer[1]) + 64;
                            break;
                        case 1:
                            //小端
                            $csId = 64 + ord($this->buffer[2]) + (ord($this->buffer[3]) << 8);
                            break;
                    }

                    //logger()->info("header ready fmt {$fmt}  csid {$csId}");
                    //找出当前的流所属的包
                    if (!isset($this->allPackets[$csId])) {
                        logger()->info("new packet csid {$csId}");
                        $p = new RtmpPacket();
                        $p->chunkStreamId = $csId;
                        $p->baseHeaderLen = RtmpChunk::BASE_HEADER_SIZES[$csId] ?? 1;
                        $this->allPackets[$csId] = $p;
                    } else {
                        //logger()->info("old packet csid {$csId}");
                        $p = $this->allPackets[$csId];
                    }

                    //set fmt
                    $p->chunkType = $fmt;
                    //更新长度数据
                    $p->chunkHeaderLen = $this->chunkHeaderLen;

                    //base header 长度不变
                    //$p->baseHeaderLen = RtmpPacket::$BASEHEADERSIZE[$csId] ?? 1;
                    $p->msgHeaderLen = $p->chunkHeaderLen - $p->baseHeaderLen;

                    //logger()->info("packet chunkheaderLen  {$p->chunkHeaderLen}  msg header len {$p->msgHeaderLen}");
                    //当前包
                    $this->currentPacket = $p;
                    $this->chunkState = RtmpChunk::CHUNK_STATE_CHUNK_READY;


                    //截取base header
                    $this->buffer = substr($this->buffer, $p->baseHeaderLen);

                    if ($p->chunkType === RtmpChunk::CHUNK_TYPE_3) {
                        //直接进入判断是否需要读取扩展时间戳的流程
                        $p->state = RtmpPacket::PACKET_STATE_EXT_TIMESTAMP;
                    } else {
                        //当前包的状态初始化
                        $p->state = RtmpPacket::PACKET_STATE_MSG_HEADER;

                    }
                } else {
                    break;
                }
            case RtmpChunk::CHUNK_STATE_CHUNK_READY:

                if (false === $this->onPacketHandler()) {
                    break;
                }
            default:
                //跑一下看看剩余的数据够不够
                $this->onChunkData();
                break;
        }


    }



    /**
     * @param $packet
     * @return string
     */
    public function rtmpChunksCreate(&$packet)
    {
        $baseHeader = $this->rtmpChunkBasicHeaderCreate($packet->chunkType, $packet->chunkStreamId);
        $baseHeader3 = $this->rtmpChunkBasicHeaderCreate(RtmpChunk::CHUNK_TYPE_3, $packet->chunkStreamId);

        $msgHeader = $this->rtmpChunkMessageHeaderCreate($packet);

        $useExtendedTimestamp = $packet->timestamp >= RtmpPacket::MAX_TIMESTAMP;

        $timestampBin = pack('N', $packet->timestamp);
        $out = $baseHeader . $msgHeader;
        if ($useExtendedTimestamp) {
            $out .= $timestampBin;
        }

        //读取payload
        $readOffset = 0;
        $chunkSize = $this->outChunkSize;
        while ($remain = $packet->length - $readOffset) {

            $size = min($remain, $chunkSize);
            //logger()->debug("rtmpChunksCreate remain {$remain} size {$size}");
            $out .= substr($packet->payload, $readOffset, $size);
            $readOffset += $size;
            if ($readOffset < $packet->length) {
                //payload 还没读取完
                $out .= $baseHeader3;
                if ($useExtendedTimestamp) {
                    $out .= $timestampBin;
                }
            }

        }

        return $out;
    }


    /**
     * @param $fmt
     * @param $cid
     */
    public function rtmpChunkBasicHeaderCreate($fmt, $cid)
    {
        if ($cid >= 64 + 255) {
            //cid 小端字节序
            return pack('CS', $fmt << 6 | 1, $cid - 64);
        } elseif ($cid >= 64) {
            return pack('CC', $fmt << 6 | 0, $cid - 64);
        } else {
            return pack('C', $fmt << 6 | $cid);
        }
    }


    /**
     * @param $packet RtmpPacket
     */
    public function rtmpChunkMessageHeaderCreate($packet)
    {
        $out = "";
        if ($packet->chunkType <= RtmpChunk::CHUNK_TYPE_2) {
            //timestamp
            $out .= substr(pack('N', $packet->timestamp >= RtmpPacket::MAX_TIMESTAMP ? RtmpPacket::MAX_TIMESTAMP : $packet->timestamp), 1, 3);
        }

        if ($packet->chunkType <= RtmpChunk::CHUNK_TYPE_1) {
            //payload len and stream type
            $out .= substr(pack('N', $packet->length), 1, 3);
            //stream type
            $out .= pack('C', $packet->type);
        }

        if ($packet->chunkType == RtmpChunk::CHUNK_TYPE_0) {
            //stream id  小端字节序
            $out .= pack('L', $packet->streamId);
        }

        //logger()->debug("rtmpChunkMessageHeaderCreate " . bin2hex($out));

        return $out;
    }



    public function sendACK($size)
    {
        $buf = hex2bin('02000000000004030000000000000000');
        $buf = substr_replace($buf, pack('N', $size), 12);
        $this->write($buf);
    }

    public function sendWindowACK($size)
    {
        $buf = hex2bin('02000000000004050000000000000000');
        $buf = substr_replace($buf, pack('N', $size), 12);
        $this->write($buf);
    }

    public function setPeerBandwidth($size, $type)
    {
        $buf = hex2bin('0200000000000506000000000000000000');
        $buf = substr_replace($buf, pack('NC', $size, $type), 12);
        $this->write($buf);

    }

    public function setChunkSize($size)
    {
        $buf = hex2bin('02000000000004010000000000000000');
        $buf = substr_replace($buf, pack('N', $size), 12);
        $this->write($buf);
    }




}