<?php

declare(strict_types=1);

namespace MediaServer\MediaReader;


use MediaServer\Utils\BinaryStream;

class MetaDataFrame extends BinaryStream implements MediaFrame
{
    public int $FRAME_TYPE = self::META_FRAME;

    public function __construct(string $data = "")
    {
        parent::__construct($data);
    }

    public function __toString(): string
    {
        return $this->dump();
    }

}