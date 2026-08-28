<?php

declare(strict_types=1);

namespace MediaServer\Utils;


class BitReader
{
    public string $data;
    public int $currentBytes = 0;
    public int $currentBits = 0;
    public bool $isError = false;


    public function __construct(string &$data)
    {
        $this->data = $data;
    }

    public function skipBits(int $bits): void
    {
        $newBits = $this->currentBits + $bits;
        $this->currentBytes += (int)floor($newBits / 8);
        $this->currentBits = $newBits % 8;
    }

    public function getBit(): int
    {
        if (!isset($this->data[$this->currentBytes])) {
            $this->isError = true;
            return 0;
        }
        $result = (ord($this->data[$this->currentBytes]) >> (7 - $this->currentBits)) & 0x01;
        $this->skipBits(1);
        return $result;
    }

    public function getBits(int $bits): int
    {
        $result = 0;
        for ($i = 0; $i < $bits; $i++) {
            $result = ($result << 1) + $this->getBit();
        }
        return $result;
    }


}