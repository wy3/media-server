<?php

declare(strict_types=1);

namespace MediaServer\Rtmp;


use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;
use MediaServer\Utils\PlayPackCache;

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
         * hevc sequence send（H.265 必须先把 hvcC 发给播放端，否则解码器拿不到配置）
         */
        if ($publishStream->isHEVCSequence()) {
            $hevcFrame = $publishStream->getHEVCSequenceFrame();
            $this->sendVideoFrame($hevcFrame);
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
     * 发送一帧数据。
     *
     * @param MediaFrame $frame 待发送的帧
     * @param PlayPackCache|null $playPackCache 上一个播放端组好的数据。
     *        命中时直接发送其中的 chunks，只改写本端 streamId，
     *        既不组包也不创建 RtmpPacket。
     * @return PlayPackCache 本端组好的数据，供下一个播放端复用
     */
    public function frameSend(MediaFrame $frame, ?PlayPackCache $playPackCache = null): PlayPackCache
    {
        if ($playPackCache instanceof RtmpPlayPackCache) {
            $this->write($playPackCache->patchStreamId($this->playStreamId)->chunks);
            return $playPackCache;
        }

        return match ($frame->FRAME_TYPE) {
            MediaFrame::VIDEO_FRAME => $this->sendVideoFrame($frame),
            MediaFrame::AUDIO_FRAME => $this->sendAudioFrame($frame),
            MediaFrame::META_FRAME => $this->sendMetaDataFrame($frame),
        };
    }

    /**
     * @param $metaDataFrame MetaDataFrame|MediaFrame
     * @return RtmpPlayPackCache
     */
    public function sendMetaDataFrame(MediaFrame $metaDataFrame): RtmpPlayPackCache
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_DATA;
        $packet->type = RtmpPacket::TYPE_DATA;
        $packet->payload = (string)$metaDataFrame;
        $packet->length = strlen($packet->payload);
        $packet->streamId = $this->playStreamId;
        $packet->timestamp = 0;
        return $this->sendPacket($packet);
    }

    /**
     * @param $audioFrame AudioFrame|MediaFrame
     * @return RtmpPlayPackCache
     */
    public function sendAudioFrame(MediaFrame $audioFrame): RtmpPlayPackCache
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_AUDIO;
        $packet->type = RtmpPacket::TYPE_AUDIO;
        $packet->payload = (string)$audioFrame;
        $packet->timestamp = $audioFrame->timestamp;
        $packet->length = strlen($packet->payload);
        $packet->streamId = $this->playStreamId;
        return $this->sendPacket($packet);
    }

    /**
     * @param $videoFrame VideoFrame|MediaFrame
     * @return RtmpPlayPackCache
     */
    public function sendVideoFrame(MediaFrame $videoFrame): RtmpPlayPackCache
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_VIDEO;
        $packet->type = RtmpPacket::TYPE_VIDEO;
        $packet->payload = (string)$videoFrame;
        $packet->length = strlen($packet->payload);
        $packet->streamId = $this->playStreamId;
        $packet->timestamp = $videoFrame->timestamp;
        return $this->sendPacket($packet);
    }

    /**
     * 组包并发送，返回组好的数据供下一个播放端复用。
     *
     * @param RtmpPacket $packet 本端的包描述
     * @return RtmpPlayPackCache 本端使用的组包结果
     */
    protected function sendPacket(RtmpPacket $packet): RtmpPlayPackCache
    {
        $playPackCache = new RtmpPlayPackCache($this->rtmpChunksCreate($packet), $packet);
        $this->write($playPackCache->chunks);
        return $playPackCache;
    }

    /**
     * @return void
     */
    public function playClose(): void
    {
        $this->stop();
        // 关闭底层连接（RtmpStream 无 $input，通过 buffer->connection 关闭）
        $this->buffer?->connection?->close();
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