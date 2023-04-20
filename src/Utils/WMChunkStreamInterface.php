<?php


namespace MediaServer\Utils;


use Evenement\EventEmitterInterface;

interface WMChunkStreamInterface extends  EventEmitterInterface
{

    public function write($data);

    public function end($data = null);

    public function close();

}