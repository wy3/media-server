<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/13
 * Time: 1:39
 */

namespace MediaServer\MediaReader;



class AVCPacket
{
    public $avcPacketType;
    public $compositionTime;
    public $data;

    /**
     * @param $args
     * @return AVCPacket
     */
    public static function create($args)
    {
        $f = new self();
        foreach ($args as $k => $v) {
            $f->$k = $v;
        }
        return $f;
    }
}