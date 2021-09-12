<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/13
 * Time: 1:40
 */

namespace MediaServer\MediaReader;


class VideoFrame
{
    public $frameType;
    public $codecId;
    public $data;
    public $rawData = '';

    public function getVideoCodecName()
    {
        return VideoAnalysis::VIDEO_CODEC_NAME[$this->codecId];
    }

    /**
     * @param $args
     * @return VideoFrame
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