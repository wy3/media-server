<?php

namespace MediaServer\Utils;


class BitReader
{
    public $data;
    public $currentBytes = 0;
    public $currentBits = 0;
    public $isError=false;


    public  function __construct(&$data)
    {
        $this->data=$data;
    }

    /**
     * @param int $bits
     */
    public function skipBits($bits) {
        $newBits = $this->currentBits + $bits;
        $this->currentBytes += (int)floor($newBits / 8);
        $this->currentBits = $newBits % 8;
    }

    /**
     * @return int
     */
    public function getBit() {
        if(!isset($this->data[$this->currentBytes])){
            $this->isError=true;
            return 0;
        }
        $result = (ord($this->data[$this->currentBytes]) >> (7 - $this->currentBits)) & 0x01;
        $this->skipBits(1);
        return $result;
    }

    public function getBits($bits){
        $result = 0;
        for ($i = 0; $i < $bits; $i++) {
            $result = ($result << 1) + $this->getBit();
        }
        return $result;
    }

    public function expGolombUe()
    {
        for ($n = 0; $this->getBit() == 0 && !$this->isError; $n++) ;
        return (1 << $n) + $this->getBits($n) - 1;
    }

}