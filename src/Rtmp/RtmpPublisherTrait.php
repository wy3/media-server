<?php


namespace MediaServer\Rtmp;


trait RtmpPublisherTrait
{
    /**
     * 获取当前推流路径
     * @return string
     */
    public function getPublishPath()
    {
        return $this->publishStreamPath;
    }


    public function isAACSequence()
    {
        return $this->isAACSequence;
    }

    public function getAACSequenceFrame()
    {
        return $this->aacSequenceHeaderFrame;
    }

    public function isAVCSequence()
    {
        return $this->isAVCSequence;
    }

    public function getAVCSequenceFrame()
    {
        return $this->avcSequenceHeaderFrame;
    }


    public function isMetaData()
    {
        return $this->isMetaData;
    }

    public function getMetaDataFrame()
    {
        return $this->metaDataFrame;
    }

    public function hasAudio(){
        return $this->isAACSequence();
    }

    public function hasVideo(){
        return $this->isAVCSequence();
    }

    public function getGopCacheQueue(){
        return $this->gopCacheQueue;
    }

    public function getPublishStreamInfo()
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
