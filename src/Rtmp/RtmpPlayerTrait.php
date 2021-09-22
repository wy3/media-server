<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/13
 * Time: 1:10
 */

namespace MediaServer\Rtmp;


use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;

trait RtmpPlayerTrait
{
    public $isPlayerIdling = true;

    /**
     * @return bool
     */
    public function isPlayerIdling()
    {
        return $this->isPlayerIdling;
    }

    public function isEnableAudio()
    {
        return true;
    }

    public function isEnableVideo()
    {
        return true;
    }

    public function isEnableGop()
    {
        return true;
    }

    public function setEnableAudio($status)
    {
    }

    public function setEnableVideo($status)
    {
    }

    public function setEnableGop($status)
    {
    }



    /**
     * 播放开始
     * @return mixed
     */
    public function startPlay()
    {

        //各种发送数据包
        $path = $this->getPlayPath();
        $publishStream = MediaServer::getPublishStream($path);
        /**
         * meta data send
         */
        if ($publishStream->isMetaData()) {
            $metaDataFrame = $publishStream->getMetaDataFrame();
            $this->sendMetaDataFrame($metaDataFrame);
        }

        /**
         * avc sequence send
         */
        if ($publishStream->isAVCSequence()) {
            $avcFrame = $publishStream->getAVCSequenceFrame();
            $this->sendVideoFrame($avcFrame);
        }


        /**
         * aac sequence send
         */
        if ($publishStream->isAACSequence()) {
            $aacFrame = $publishStream->getAACSequenceFrame();
            $this->sendAudioFrame($aacFrame);
        }

        //gop 发送
        if ($this->enableGop) {
            foreach ($publishStream->getGopCacheQueue() as &$frame) {
                $this->frameSend($frame);
            }
        }

        $this->isPlayerIdling = false;
        $this->isPlaying = true;
    }

    /**
     * @param $frame MediaFrame
     * @return mixed
     */
    public function frameSend($frame)
    {
        switch ($frame->FRAME_TYPE) {
            case MediaFrame::VIDEO_FRAME:
                return $this->sendVideoFrame($frame);
            case MediaFrame::AUDIO_FRAME:
                return $this->sendAudioFrame($frame);
            case MediaFrame::META_FRAME:
                return $this->sendMetaDataFrame($frame);
        }
    }

    /**
     * @param $metaDataFrame MetaDataFrame|MediaFrame
     * @return mixed
     */
    public function sendMetaDataFrame($metaDataFrame)
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_DATA;
        $packet->type = RtmpPacket::TYPE_DATA;
        $packet->payload = (string)$metaDataFrame;
        $packet->length = strlen($packet->payload);
        $packet->streamId = $this->playStreamId;
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }

    /**
     * @param $audioFrame AudioFrame|MediaFrame
     * @return mixed
     */
    public function sendAudioFrame($audioFrame)
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_AUDIO;
        $packet->type = RtmpPacket::TYPE_AUDIO;
        $packet->payload = (string)$audioFrame;
        $packet->timestamp = $audioFrame->timestamp;
        $packet->length = strlen($packet->payload);
        $packet->streamId = $this->playStreamId;
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }

    /**
     * @param $videoFrame VideoFrame|MediaFrame
     * @return mixed
     */
    public function sendVideoFrame($videoFrame)
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_VIDEO;
        $packet->type = RtmpPacket::TYPE_VIDEO;
        $packet->payload = (string)$videoFrame;
        $packet->length = strlen($packet->payload);
        $packet->streamId = $this->playStreamId;
        $packet->timestamp = $videoFrame->timestamp;
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }

    /**
     * @return mixed
     */
    public function playClose()
    {
        $this->stop();
        $this->input->close();
    }

    /**
     * 获取当前路径
     * @return string
     */
    public function getPlayPath()
    {
        return $this->playStreamPath;
    }
}
