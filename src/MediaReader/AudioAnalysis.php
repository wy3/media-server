<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/12
 * Time: 0:31
 */

namespace MediaServer\MediaReader;


class AudioAnalysis
{


    const AUDIO_CODEC_NAME = [
        '',
        'ADPCM',
        'MP3',
        'LinearLE',
        'Nellymoser16',
        'Nellymoser8',
        'Nellymoser',
        'G711A',
        'G711U',
        '',
        'AAC',
        'Speex',
        '',
        'OPUS',
        'MP3-8K',
        'DeviceSpecific',
        'Uncompressed'
    ];

    const AUDIO_SOUND_RATE = [
        5512, 11025, 22050, 44100
    ];


    const SOUND_FORMAT_AAC = 10;

    /**
     * @param $audioData
     * @return AudioFrame
     */
    static function audioFrameDataRead($audioData)
    {
        $firstByte = ord($audioData[0]);
        return AudioFrame::create([
            'soundFormat' => $firstByte >> 4,
            'soundRate' => $firstByte >> 2 & 3,
            'soundSize' => $firstByte >> 1 & 1,
            'soundType' => $firstByte & 1,
            'data' => substr($audioData, 1)
        ]);
    }
}

class AudioFrame
{

    public $soundFormat;
    public $soundRate;
    public $soundSize;
    public $soundType;
    public $data;

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