<?php

declare(strict_types=1);

namespace MediaServer\Rtmp;


use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;

trait RtmpPlayerTrait
{
    public bool $isPlayerIdling = true;

    /**
     * @return bool
     */
    public function isPlayerIdling(): bool
    {
        return $this->isPlayerIdling;
    }

    public function isEnableAudio(): bool
    {
        return true;
    }

    public function isEnableVideo(): bool
    {
        return true;
    }

    public function isEnableGop(): bool
    {
        return true;
    }

    public function setEnableAudio(bool $status): void
    {
    }

    public function setEnableVideo(bool $status): void
    {
    }

    public function setEnableGop(bool $status): void
    {
    }



    /**
     * 播放开始
     * @return mixed
     */
    public function startPlay(): void
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
    public function frameSend(MediaFrame $frame): void
    {
        switch ($frame->FRAME_TYPE) {
            case MediaFrame::VIDEO_FRAME:
                $this->sendVideoFrame($frame);
                break;
            case MediaFrame::AUDIO_FRAME:
                $this->sendAudioFrame($frame);
                break;
            case MediaFrame::META_FRAME:
                $this->sendMetaDataFrame($frame);
                break;
        }
    }

    /**
     * @param $metaDataFrame MetaDataFrame|MediaFrame
     * @return mixed
     */
    public function sendMetaDataFrame(MediaFrame $metaDataFrame): void
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
    public function sendAudioFrame(MediaFrame $audioFrame): void
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
    public function sendVideoFrame(MediaFrame $videoFrame): void
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
     * @return void
     */
    public function playClose(): void
    {
        $this->stop();
        // 关闭底层连接（通过 BufferStream 接口，运行时无关）
        $this->buffer?->close();
    }

    /**
     * 获取当前路径
     * @return string
     */
    public function getPlayPath(): string
    {
        return $this->playStreamPath;
    }
}