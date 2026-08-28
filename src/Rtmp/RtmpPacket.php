<?php

declare(strict_types=1);

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
    const TYPE_METADATA = 22; //flv tags


    const STREAM_BEGIN = 0x00;
    const STREAM_EOF = 0x01;
    const STREAM_DRY = 0x02;
    const STREAM_EMPTY = 0x1f;
    const STREAM_READY = 0x20;

    const MAX_TIMESTAMP = 0xffffff;


    public int $baseHeaderLen = 0;
    public int $msgHeaderLen = 0;
    public int $chunkHeaderLen = 0;
    public int $chunkType = 0;
    public int $chunkStreamId = 0;

    public int $timestamp = 0;
    public int $length = 0;
    public int $type = 0;
    public int $streamId = 0;

    public int $clock = 0;
    public bool $hasAbsTimestamp = false;
    public bool $hasExtTimestamp = false;

    public int $bytesRead = 0;
    public string $payload = "";

    public int $state = self::PACKET_STATE_BEGIN;

    public function reset(): void
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

    public function free(): void
    {
        $this->payload = "";
        $this->bytesRead = 0;
    }

    public function isReady(): bool
    {
        return $this->bytesRead == $this->length;
    }
}