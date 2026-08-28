<?php

declare(strict_types=1);

namespace MediaServer\Rtmp;

use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;

trait RtmpPublisherTrait
{
    /**
     * 获取当前推流路径
     * @return string
     */
    public function getPublishPath(): string
    {
        return $this->publishStreamPath;
    }


    public function isAACSequence(): bool
    {
        return $this->isAACSequence;
    }

    public function getAACSequenceFrame(): ?AudioFrame
    {
        return $this->aacSequenceHeaderFrame;
    }

    public function isAVCSequence(): bool
    {
        return $this->isAVCSequence;
    }

    public function getAVCSequenceFrame(): ?VideoFrame
    {
        return $this->avcSequenceHeaderFrame;
    }


    public function isMetaData(): bool
    {
        return $this->isMetaData;
    }

    public function getMetaDataFrame(): ?MetaDataFrame
    {
        return $this->metaDataFrame;
    }

    public function hasAudio(): bool
    {
        return $this->isAACSequence();
    }

    public function hasVideo(): bool
    {
        return $this->isAVCSequence();
    }

    public function getGopCacheQueue(): array
    {
        return $this->gopCacheQueue;
    }

    public function getPublishStreamInfo(): array
    {
        return [
            "id"=>$this->id,
            "bytesRead"=>$this->bytesRead,
            "bytesReadRate"=>$this->bytesReadRate,
            "startTimestamp"=>$this->startTimestamp,
            "currentTimestamp"=>timestamp(),
            "publishStreamPath"=>$this->publishStreamPath,
            "videoWidth"=>$this->videoWidth,
            "videoHeight"=>$this->videoHeight,
            "videoFps"=> $this->videoFps,
            "videoCodecName"=>$this->videoCodecName,
            "videoProfileName"=>$this->videoProfileName,
            "videoLevel"=>$this->videoLevel,
            "audioSamplerate"=>$this->audioSamplerate,
            "audioChannels"=>$this->audioChannels,
            "audioCodecName"=>$this->audioCodecName,
        ];
    }

}