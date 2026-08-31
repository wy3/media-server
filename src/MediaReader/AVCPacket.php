<?php

declare(strict_types=1);

namespace MediaServer\MediaReader;


use MediaServer\Utils\BinaryStream;

class AVCPacket
{
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;
    const AVC_PACKET_TYPE_END_SEQUENCE = 2;


    public int $avcPacketType;
    public int $compositionTime;
    public BinaryStream $stream;

    public function __construct(BinaryStream $stream)
    {
        $this->stream = $stream;
        $this->avcPacketType = $stream->readTinyInt();
        $this->compositionTime = $stream->readInt24();
    }


    /**
     * @var AVCSequenceParameterSet
     */
    protected ?AVCSequenceParameterSet $avcSequenceParameterSet = null;

    public function getAVCSequenceParameterSet(): AVCSequenceParameterSet
    {
        if (!$this->avcSequenceParameterSet) {
            $this->avcSequenceParameterSet = new AVCSequenceParameterSet($this->stream->readRawRemaining());
        }
        return $this->avcSequenceParameterSet;
    }
}