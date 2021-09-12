<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/13
 * Time: 1:40
 */

namespace MediaServer\MediaReader;

class AudioFrame
{

    public $soundFormat;
    public $soundRate;
    public $soundSize;
    public $soundType;
    public $data;
    public $rawData='';

    public function getAudioCodecName()
    {
        return AudioAnalysis::AUDIO_CODEC_NAME[$this->soundFormat];
    }

    public function getAudioSamplerate()
    {
        $rate = AudioAnalysis::AUDIO_SOUND_RATE[$this->soundRate];
        switch ($this->soundFormat) {
            case 4:
                $rate = 16000;
                break;
            case 5:
                $rate = 8000;
                break;
            case 11:
                $rate = 16000;
                break;
            case 14:
                $rate = 8000;
                break;
        }
        return $rate;
    }


    /**
     * @param $args
     * @return AudioFrame
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