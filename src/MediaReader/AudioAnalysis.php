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
            'data' => substr($audioData, 1),
            'rawData'=>$audioData
        ]);
    }
}
