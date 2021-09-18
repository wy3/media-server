<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/13
 * Time: 1:53
 */

namespace MediaServer\MediaReader;


use MediaServer\Utils\BinaryStream;

class MetaDataFrame extends BinaryStream
{
    public function __construct(string $data = "")
    {
        parent::__construct($data);
    }

    public function __toString()
    {
        return $this->dump();
    }

}
