<?php

declare(strict_types=1);

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
    protected string $playPath = '';
    /**
     * @var WMChunkStreamInterface
     */
    protected WMChunkStreamInterface $input;


    protected bool $isPlayerIdling = true;
    protected bool $isPlaying = false;

    protected bool $isFlvHeader = false;

    protected bool $closed = false;

    /**
     * FlvPlayStream constructor.
     * @param WMChunkStreamInterface $input
     * @param string $playPath
     */
    public function __construct(WMChunkStreamInterface $input, string $playPath)
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
    public function onStreamError(\Exception $e): void
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->input->close();
        $this->emit('on_close');
        $this->removeAllListeners();
    }


    public function isPlayerIdling(): bool
    {
        return $this->isPlayerIdling;
    }

    public function write(string $data): void
    {
        $this->input->write($data);
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


    public function startPlay(): void
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
     * @param MediaFrame $frame
     */
    public function frameSend(MediaFrame $frame): void
    {
        //   logger()->info("send ".get_class($frame)." timestamp:".($frame->timestamp??0));
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

    public function playClose(): void
    {
        $this->input->close();
    }

    public function getPlayPath(): string
    {
        return $this->playPath;
    }


    /**
     * @param MediaFrame $metaDataFrame
     */
    public function sendMetaDataFrame(MediaFrame $metaDataFrame): void
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
     * @param MediaFrame $audioFrame
     */
    public function sendAudioFrame(MediaFrame $audioFrame): void
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
     * @param MediaFrame $videoFrame
     */
    public function sendVideoFrame(MediaFrame $videoFrame): void
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
