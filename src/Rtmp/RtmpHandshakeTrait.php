<?php


namespace MediaServer\Rtmp;


use MediaServer\Utils\BinaryStream;

/**
 * Trait RtmpHandshakeTrait
 * @package MediaServer\Rtmp
 */
trait RtmpHandshakeTrait
{

    /**
     */
    public function onHandShake()
    {
        /**
         * @var $stream BinaryStream
         */
        $stream=$this->buffer;

        switch ($this->handshakeState) {
            case RtmpHandshake::RTMP_HANDSHAKE_UNINIT:
                if ($stream->has(1)) {
                    logger()->info('RTMP_HANDSHAKE_UNINIT');
                    //read c0
                    $stream->readByte();
                    // goto c0
                    $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_C0;
                } else {
                    break;
                }
            case RtmpHandshake::RTMP_HANDSHAKE_C0:
                if ($stream->has(1536)) {
                    logger()->info('RTMP_HANDSHAKE_C0');
                    $c1=$stream->readRaw(1536);

                    //向客户端发送 s0s1s2
                    $s0s1s2 = RtmpHandshake::handshakeGenerateS0S1S2($c1);
                    $this->write($s0s1s2);
                    $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_C1;
                } else {
                    break;
                }
            case RtmpHandshake::RTMP_HANDSHAKE_C1:
                if ($stream->has(1536)) {
                    logger()->info('RTMP_HANDSHAKE_C1');
                    $stream->readRaw(1536);
                    $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_C2;
                    $this->chunkState = RtmpChunk::CHUNK_STATE_BEGIN;
                } else {
                    break;
                }
            case RtmpHandshake::RTMP_HANDSHAKE_C2:
        }

    }
}
