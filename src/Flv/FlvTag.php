<?php

declare(strict_types=1);

namespace MediaServer\Flv;


class FlvTag
{
    public int $type = 0;
    public int $dataSize = 0;
    public int $timestamp = 0;
    public int $streamId = 0;
    public string $data = '';

}
