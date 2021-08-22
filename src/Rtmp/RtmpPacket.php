<?php

namespace MediaServer\Rtmp;


class RtmpPacket
{
    const PACKET_STATE_BEGIN = 0;
    const PACKET_STATE_MSG_HEADER = 1;
    const PACKET_STATE_EXT_TIMESTAMP = 2;
    const PACKET_STATE_PAYLOAD = 3;

    /* Protocol Control Messages */
    const TYPE_SET_CHUNK_SIZE = 1;
    const TYPE_ABORT = 2;
    const TYPE_ACKNOWLEDGEMENT = 3;
    const TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE = 5;
    const TYPE_SET_PEER_BANDWIDTH = 6;

    /* User Control Messages Event (4) */
    const TYPE_EVENT = 4;

    const TYPE_AUDIO = 8;
    const TYPE_VIDEO = 9;

    /* Data Message */
    const TYPE_FLEX_STREAM = 15; //AMF3
    const TYPE_DATA = 18; //AMF0

    /* Shared Object Message */
    const TYPE_FLEX_OBJECT = 16; // AMF3
    const TYPE_SHARED_OBJECT = 19; // AMF0


    /* Command Message */
    const TYPE_FLEX_MESSAGE = 17; // AMF3
    const TYPE_INVOKE = 20; // AMF0

    /* Aggregate Message */
    const TYPE_METADATA = 22;  //flv tags


    const STREAM_BEGIN = 0x00;
    const STREAM_EOF = 0x01;
    const STREAM_DRY = 0x02;
    const STREAM_EMPTY = 0x1f;
    const STREAM_READY = 0x20;

    const MAX_TIMESTAMP = 0xffffff;


    public $baseHeaderLen = 0;
    public $msgHeaderLen = 0;
    public $chunkHeaderLen = 0;
    public $chunkType = 0;
    public $chunkStreamId = 0;

    public $timestamp = 0;
    public $length = 0;
    public $type = 0;
    public $streamId = 0;

    public $clock = 0;
    public $hasAbsTimestamp = false;
    public $hasExtTimestamp = false;

    public $bytesRead = 0;
    public $payload = "";

    public $state = self::PACKET_STATE_BEGIN;

    public function reset()
    {
        $this->chunkType = 0;
        $this->chunkStreamId = 0;
        $this->timestamp = 0;
        $this->length = 0;
        $this->type = 0;
        $this->streamId = 0;
        $this->hasAbsTimestamp = false;
        $this->hasExtTimestamp = false;
        $this->bytesRead = 0;
        $this->payload = "";
        $this->state = self::PACKET_STATE_BEGIN;
    }

    public function free()
    {
        $this->payload = "";
        $this->bytesRead = 0;
    }

    public function isReady()
    {
        return $this->bytesRead == $this->length;
    }
}

