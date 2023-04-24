<?php


namespace MediaServer\PushServer;


use Evenement\EventEmitterInterface;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;

/**
 * Interface PublishStreamInterface
 * @package MediaServer\PushServer
 * @property $is_on_frame
 */
interface PublishStreamInterface extends EventEmitterInterface
{
    /**
     * 获取当前推流路径
     * @return string
     */
    public function getPublishPath();

    /**
     * Have meta data
     * @return bool
     */
    public function isMetaData();

    /**
     * @return MetaDataFrame
     */
    public function getMetaDataFrame();

    /**
     * Have aac sequence header
     * @return bool
     */
    public function isAACSequence();

    /**
     * @return AudioFrame
     */
    public function getAACSequenceFrame();

    /**
     * Have avc sequence header
     * @return bool
     */
    public function isAVCSequence();

    /**
     * @return VideoFrame
     */
    public function getAVCSequenceFrame();

    /**
     * 是否包含音频
     * @return bool
     */
    public function hasAudio();

    /**
     * 是否包含视频
     * @return mixed
     */
    public function hasVideo();

    /**
     * 获取gop
     * @return MediaFrame[]
     */
    public function getGopCacheQueue();


    public function getPublishStreamInfo();

}
