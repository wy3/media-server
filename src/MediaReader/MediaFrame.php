<?php


namespace MediaServer\MediaReader;


/**
 * Interface MediaFrame
 * @package MediaServer\MediaReader
 * @property $FRAME_TYPE
 */
interface MediaFrame
{
    const   VIDEO_FRAME = 1;
    const   AUDIO_FRAME = 2;
    const   META_FRAME = 0;

}