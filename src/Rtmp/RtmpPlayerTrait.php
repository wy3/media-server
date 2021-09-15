<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/13
 * Time: 1:10
 */

namespace MediaServer\Rtmp;


use MediaServer\MediaReader\AudioFrame;
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
            foreach ($publishStream->gopCacheQueue as &$frame) {
                $this->frameSend($frame);
            }
        }

        $this->isPlayerIdling = false;
        $this->isPlaying = true;
    }

    /**
     * @param $frame VideoFrame|AudioFrame|MetaDataFrame
     * @return mixed
     */
    public function frameSend($frame)
    {
        switch (get_class($frame)) {
            case VideoFrame::class:
                return $this->sendVideoFrame($frame);
            case AudioFrame::class:
                return $this->sendAudioFrame($frame);
            case MetaDataFrame::class:
                return $this->sendMetaDataFrame($frame);
        }
    }

    /**
     * @param $metaDataFrame MetaDataFrame
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
     * @param $audioFrame AudioFrame
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
     * @param $videoFrame VideoFrame
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
