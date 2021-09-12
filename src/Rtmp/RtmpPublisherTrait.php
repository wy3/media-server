<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/13
 * Time: 1:10
 */

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


    public function isAACSequence(){
        return $this->isAACSequence;
    }

    public function getAACSequenceFrame(){
        return $this->aacSequenceHeaderFrame;
    }

    public function isAVCSequence(){
        return $this->isAVCSequence;
    }

    public function getAVCSequenceFrame(){
        return $this->avcSequenceHeaderFrame;
    }


    public function isMetaData(){
        $this->isMetaData;
    }

    public function getMetaDataFrame(){
        return $this->metaDataFrame;
    }

}