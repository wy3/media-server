<?php


declare(strict_types=1);

namespace MediaServer\Rtmp;

class RtmpChunk
{

    const CHUNK_STATE_BEGIN = 0; //Chunk state begin
    const CHUNK_STATE_HEADER_READY = 1; //Chunk state header ready
    const CHUNK_STATE_CHUNK_READY = 2; //Chunk state chunk date ready

    /**
     * chunk stream id length base header length
     */
    const BASE_HEADER_SIZES = [3, 4];

    /**
     * fmt message header size
     */
    const MSG_HEADER_SIZES = [11, 7, 3, 0];



    const CHUNK_TYPE_0 = 0; //Large type
    const CHUNK_TYPE_1 = 1; //Medium
    const CHUNK_TYPE_2 = 2;    //Small
    const CHUNK_TYPE_3 = 3; //Minimal


    /**
     * chunk type default chunk stream id
     */
    const CHANNEL_PROTOCOL = 2;
    const CHANNEL_INVOKE = 3;
    const CHANNEL_AUDIO = 4;
    const CHANNEL_VIDEO = 5;
    const CHANNEL_DATA = 6;

    /**
     * base header 字节数，由 csid 决定用 1 / 2 / 3 字节编码
     *
     * @param int $cid chunk stream id
     * @return int
     */
    static function basicHeaderLength(int $cid): int
    {
        if ($cid >= 64 + 255) {
            return 3;
        }
        return $cid >= 64 ? 2 : 1;
    }





}
