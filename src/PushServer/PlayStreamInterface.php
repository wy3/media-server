<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/13
 * Time: 0:24
 */

namespace MediaServer\PushServer;


use Evenement\EventEmitterInterface;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;

interface PlayStreamInterface extends EventEmitterInterface
{

    /**
     * @return bool
     */
    public function isPlayerIdling();

    /**
     * 播放开始
     * @return mixed
     */
    public function startPlay();

    /**
     * @param $frame VideoFrame|AudioFrame|MetaDataFrame
     * @return mixed
     */
    public function frameSend($frame);

    /**
     * @return mixed
     */
    public function playClose();

    /**
     * 获取当前路径
     * @return string
     */
    public function getPlayPath();

    /**
     * 是否启用音频
     * @return bool
     */
    public function enableAudio();

    /**
     * 是否启用视频
     * @return bool
     */
    public function enableVideo();


    /**
     * 是否启用gop，关闭能降低延迟
     * @return bool
     */
    public function enableGop();
}
