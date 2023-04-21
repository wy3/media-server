<?php


namespace MediaServer\MediaReader;


use MediaServer\Utils\BinaryStream;

class MetaDataFrame extends BinaryStream implements MediaFrame
{
    public $FRAME_TYPE=self::META_FRAME;

    public function __construct(string $data = "")
    {
        parent::__construct($data);
    }

    public function __toString()
    {
        return $this->dump();
    }

}
