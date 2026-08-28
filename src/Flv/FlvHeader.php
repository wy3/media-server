<?php

declare(strict_types=1);

namespace MediaServer\Flv;


class FlvHeader
{
    public string $signature = '';
    public int $version = 0;
    public int $typeFlags = 0;
    public int $dataOffset = 0;
    public bool $hasAudio = false;
    public bool $hasVideo = false;

    public function __construct(string $data)
    {

        $data = unpack("a3signature/Cversion/CtypeFlags/NdataOffset", $data);
        $this->signature = $data['signature'];
        $this->version = $data['version'];
        $this->typeFlags = $data['typeFlags'];
        $this->dataOffset = $data['dataOffset'];
        $this->hasAudio = ($this->typeFlags & 4) !== 0;
        $this->hasVideo = ($this->typeFlags & 1) !== 0;
    }
}
