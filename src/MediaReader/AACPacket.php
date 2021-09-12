<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/13
 * Time: 1:38
 */

namespace MediaServer\MediaReader;



use MediaServer\Utils\BitReader;

class AACPacket
{
    public $aacPacketType;
    public $data;

    /**
     * @param $args
     * @return AACPacket
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
