<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/9
 * Time: 2:35
 */

namespace MediaServer\Rtmp;


/**
 * Trait RtmpHandshakeTrait
 * @package MediaServer\Rtmp
 */
trait RtmpHandshakeTrait
{

    /**
     * @param $data
     */
    public function onHandShake($data)
    {
        $this->buffer .= $data;

        switch ($this->handshakeState) {
            case RtmpHandshake::RTMP_HANDSHAKE_UNINIT:
                if (isset($this->buffer[0])) {
                    //logger()->info(bin2hex(substr($this->buffer, 0, 10)));
                    logger()->info('RTMP_HANDSHAKE_UNINIT');
                    // goto c0
                    $this->buffer = substr($this->buffer, 1);
                    $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_C0;
                } else {
                    break;
                }
            case RtmpHandshake::RTMP_HANDSHAKE_C0:
                if (isset($this->buffer[1535])) {
                    //logger()->info(bin2hex(substr($this->buffer, 0, 10)));
                    logger()->info('RTMP_HANDSHAKE_C0');
                    $c1 = substr($this->buffer, 0, 1536);

                    //向客户端发送 s0s1s2
                    $s0s1s2 = RtmpHandshake::handshakeGenerateS0S1S2($c1);

                    $this->write($s0s1s2);

                    $this->buffer = substr($this->buffer, 1536);
                    $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_C1;
                } else {
                    break;
                }
            case RtmpHandshake::RTMP_HANDSHAKE_C1:
                if (isset($this->buffer[1535])) {
                    //logger()->info(bin2hex(substr($this->buffer, 0, 10)));
                    logger()->info('RTMP_HANDSHAKE_C1');
                    $this->buffer = substr($this->buffer, 1536);
                    $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_C2;
                    $this->chunkState = RtmpChunk::CHUNK_STATE_BEGIN;
                } else {
                    break;
                }
            case RtmpHandshake::RTMP_HANDSHAKE_C2:
            default:
                //logger()->info(bin2hex(substr($this->buffer, 0, 10)));
                //进入 rtmp 数据处理
                $this->onChunkData();
                break;
        }

    }
}
