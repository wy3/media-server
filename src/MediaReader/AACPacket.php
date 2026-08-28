<?php

declare(strict_types=1);

namespace MediaServer\MediaReader;


use MediaServer\Utils\BinaryStream;

class AACPacket
{
    const AAC_SAMPLE_RATE = [
        96000, 88200, 64000, 48000,
        44100, 32000, 24000, 22050,
        16000, 12000, 11025, 8000,
        7350, 0, 0, 0
    ];

    const AAC_CHANNELS = [
        0, 1, 2, 3, 4, 5, 6, 8
    ];

    const AAC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AAC_PACKET_TYPE_RAW = 1;


    public int $aacPacketType;

    /**
     * @var BinaryStream
     */
    public BinaryStream $stream;

    public function __construct(BinaryStream $stream)
    {
        $this->stream = $stream;
        $this->aacPacketType = $stream->readTinyInt();

    }

    /**
     * @var AACSequenceParameterSet
     */
    protected ?AACSequenceParameterSet $aacSequenceParameterSet = null;

    public function getAACSequenceParameterSet(): AACSequenceParameterSet
    {
        if (!$this->aacSequenceParameterSet) {
            $this->aacSequenceParameterSet = new AACSequenceParameterSet($this->stream->readRaw());
        }
        return $this->aacSequenceParameterSet;
    }

}