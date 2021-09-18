<?php
/**
 * Date: 2021/9/18
 * Time: 16:30
 */

namespace MediaServer\Flv;


use Evenement\EventEmitter;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;
use MediaServer\PushServer\PlayStreamInterface;
use function chr;
use function ord;

class FlvPlayStream extends EventEmitter implements PlayStreamInterface
{
    protected $playPath = '';
    protected $input;


    protected $isPlayerIdling = true;
    protected $isPlaying = false;

    protected $isFlvHeader = false;

    /**
     * FlvPlayStream constructor.
     * @param $con
     * @param $playPath
     */
    public function __construct($con, $playPath)
    {
        $this->input = $con;
        $this->playPath = $playPath;
    }

    public function isPlayerIdling()
    {
        return $this->isPlaying;
    }

    public function write($data)
    {
        return $this->input->write($data);
    }

    public function enableAudio()
    {
        return true;
    }

    public function enableVideo()
    {
        return true;
    }

    public function enableGop()
    {
        return true;
    }


    public function startPlay()
    {
        //各种发送数据包
        $path = $this->getPlayPath();
        $publishStream = MediaServer::getPublishStream($path);

        if (!$this->isFlvHeader) {
            $flvHeader = "FLV\x01\x00" . pack('NN', 9, 0);
            if ($this->enableAudio() && $publishStream->hasAudio()) {
                $flvHeader[4] = chr(ord($flvHeader[4]) | 4);
            }
            if ($this->enableVideo() && $publishStream->hasVideo()) {
                $flvHeader[4] = chr(ord($flvHeader[4]) | 1);
            }
            $this->write($flvHeader);
            $this->isFlvHeader = true;
        }


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
        if ($this->enableGop()) {
            foreach ($publishStream->gopCacheQueue as &$frame) {
                $this->frameSend($frame);
            }
        }

        $this->isPlayerIdling = false;
        $this->isPlaying = true;
    }

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

    public function playClose()
    {
        $this->input->close();
    }

    public function getPlayPath()
    {
        return $this->playPath;
    }


    /**
     * @param $metaDataFrame MetaDataFrame
     * @return mixed
     */
    public function sendMetaDataFrame($metaDataFrame)
    {
        $tag=new FlvTag();
        $tag->type=Flv::SCRIPT_TAG;
        $tag->timestamp=0;
        $tag->data=(string)$metaDataFrame;
        $tag->dataSize=strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
    }

    /**
     * @param $audioFrame AudioFrame
     * @return mixed
     */
    public function sendAudioFrame($audioFrame)
    {
        $tag=new FlvTag();
        $tag->type=Flv::AUDIO_TAG;
        $tag->timestamp=$audioFrame->timestamp;
        $tag->data=(string)$audioFrame;
        $tag->dataSize=strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
    }

    /**
     * @param $videoFrame VideoFrame
     * @return mixed
     */
    public function sendVideoFrame($videoFrame)
    {
        $tag=new FlvTag();
        $tag->type=Flv::VIDEO_TAG;
        $tag->timestamp=$videoFrame->timestamp;
        $tag->data=(string)$videoFrame;
        $tag->dataSize=strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
    }


}
