<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/13
 * Time: 1:39
 */

namespace MediaServer\MediaReader;



use MediaServer\Utils\BinaryStream;

class AVCPacket
{
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;
    const AVC_PACKET_TYPE_END_SEQUENCE = 2;



    public $avcPacketType;
    public $compositionTime;
    public $stream;

    /**
     * AVCPacket constructor.
     * @param $stream BinaryStream
     */
    public function __construct($stream)
    {
        $this->stream=$stream;
        $this->avcPacketType=$stream->readTinyInt();
        $this->compositionTime=$stream->readInt24();
    }


    /**
     * @var AACSequenceParameterSet
     */
    protected $avcSequenceParameterSet;

    /**
     * @return AVCSequenceParameterSet
     */
    public function getAVCSequenceParameterSet(){

        if(!$this->avcSequenceParameterSet){
            $this->avcSequenceParameterSet=new AVCSequenceParameterSet($this->stream->readRaw());
        }
        return $this->avcSequenceParameterSet;
    }
}