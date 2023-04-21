<?php


namespace MediaServer\Flv;


use Evenement\EventEmitter;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;
use MediaServer\PushServer\PlayStreamInterface;
use MediaServer\Utils\WMChunkStreamInterface;
use MediaServer\Utils\WMHttpChunkStream;
use function chr;
use function ord;

class FlvPlayStream extends EventEmitter implements PlayStreamInterface
{
    protected $playPath = '';
    /**
     * @var WMHttpChunkStream
     */
    protected $input;


    protected $isPlayerIdling = true;
    protected $isPlaying = false;

    protected $isFlvHeader = false;

    protected $closed = false;

    /**
     * FlvPlayStream constructor.
     * @param $input WMChunkStreamInterface
     * @param $playPath
     */
    public function __construct($input, $playPath)
    {
        $this->input = $input;
        $input->on('error', [$this, 'onStreamError']);
        $input->on('close', [$this, 'close']);
        $this->playPath = $playPath;
    }

    public function __destruct()
    {
        logger()->info("player flv stream {path} destruct", ['path' => $this->playPath]);
    }

    /**
     * @param \Exception $e
     * @internal
     */
    public function onStreamError(\Exception $e)
    {
        $this->close();
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->input->close();
        $this->emit('on_close');
        $this->removeAllListeners();
    }


    public function isPlayerIdling()
    {
        return $this->isPlayerIdling;
    }

    public function write($data)
    {
        return $this->input->write($data);
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


    public function startPlay()
    {
        //各种发送数据包
        $path = $this->getPlayPath();
        $publishStream = MediaServer::getPublishStream($path);
        logger()->info('flv play stream start play');

        if (!$this->isFlvHeader) {
            $flvHeader = "FLV\x01\x00" . pack('NN', 9, 0);
            if ($this->isEnableAudio() && $publishStream->hasAudio()) {
                $flvHeader[4] = chr(ord($flvHeader[4]) | 4);
            }
            if ($this->isEnableVideo() && $publishStream->hasVideo()) {
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
        if ($this->isEnableGop()) {
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
        //   logger()->info("send ".get_class($frame)." timestamp:".($frame->timestamp??0));
        switch ($frame->FRAME_TYPE) {
            case MediaFrame::VIDEO_FRAME:
                return $this->sendVideoFrame($frame);
            case MediaFrame::AUDIO_FRAME:
                return $this->sendAudioFrame($frame);
            case MediaFrame::META_FRAME:
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
     * @param $metaDataFrame MetaDataFrame|MediaFrame
     * @return mixed
     */
    public function sendMetaDataFrame($metaDataFrame)
    {
        $tag = new FlvTag();
        $tag->type = Flv::SCRIPT_TAG;
        $tag->timestamp = 0;
        $tag->data = (string)$metaDataFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
    }

    /**
     * @param $audioFrame AudioFrame|MediaFrame
     * @return mixed
     */
    public function sendAudioFrame($audioFrame)
    {
        $tag = new FlvTag();
        $tag->type = Flv::AUDIO_TAG;
        $tag->timestamp = $audioFrame->timestamp;
        $tag->data = (string)$audioFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
    }

    /**
     * @param $videoFrame VideoFrame|MediaFrame
     * @return mixed
     */
    public function sendVideoFrame($videoFrame)
    {
        $tag = new FlvTag();
        $tag->type = Flv::VIDEO_TAG;
        $tag->timestamp = $videoFrame->timestamp;
        $tag->data = (string)$videoFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
    }


}
