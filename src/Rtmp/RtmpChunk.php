<?php


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





}
