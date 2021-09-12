<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/12
 * Time: 0:31
 */

namespace MediaServer\MediaReader;


class VideoAnalysis
{
    const VIDEO_CODEC_NAME = [
        '',
        'Jpeg',
        'Sorenson-H263',
        'ScreenVideo',
        'On2-VP6',
        'On2-VP6-Alpha',
        'ScreenVideo2',
        'H264',
        '',
        '',
        '',
        '',
        'H265'
    ];


    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const VIDEO_FRAME_TYPE_INTER_FRAME = 2;
    const VIDEO_FRAME_TYPE_DISPOSABLE_INTER_FRAME = 3;
    const VIDEO_FRAME_TYPE_GENERATED_KEY_FRAME = 4;
    const VIDEO_FRAME_TYPE_VIDEO_INFO_FRAME = 5;


    const VIDEO_CODEC_ID_JPEG = 1;
    const VIDEO_CODEC_ID_H263 = 2;
    const VIDEO_CODEC_ID_SCREEN = 3;
    const VIDEO_CODEC_ID_VP6_FLV = 4;
    const VIDEO_CODEC_ID_VP6_FLV_ALPHA = 5;
    const VIDEO_CODEC_ID_SCREEN_V2 = 6;
    const VIDEO_CODEC_ID_AVC = 7;


    /**
     * @param $videoData
     * @return VideoFrame
     */
    static function frameReader($videoData)
    {
        $firstByte = ord($videoData[0]);
        return VideoFrame::create([
            'frameType' => $firstByte >> 4,
            'codecId' => $firstByte & 15,
            'data' => substr($videoData, 1),
        ]);
    }
}

class VideoFrame
{
    public $frameType;
    public $codecId;
    public $data;

    public function getVideoCodecName(){
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