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
use MediaServer\Utils\PlayPackCache;
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
        if ($this->isEnableGop()) {
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
     *        命中时直接发送其中的 chunks，不再创建 FlvTag。
     * @return PlayPackCache 本端组好的数据，供下一个播放端复用
     */
    public function frameSend(MediaFrame $frame, ?PlayPackCache $playPackCache = null): PlayPackCache
    {
        if ($playPackCache instanceof FlvPlayPackCache) {
            $this->write($playPackCache->chunks);
            return $playPackCache;
        }

        return match ($frame->FRAME_TYPE) {
            MediaFrame::VIDEO_FRAME => $this->sendVideoFrame($frame),
            MediaFrame::AUDIO_FRAME => $this->sendAudioFrame($frame),
            MediaFrame::META_FRAME => $this->sendMetaDataFrame($frame),
        };
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
     * @return FlvPlayPackCache
     */
    public function sendMetaDataFrame(MediaFrame $metaDataFrame): FlvPlayPackCache
    {
        $tag = new FlvTag();
        $tag->type = Flv::SCRIPT_TAG;
        $tag->timestamp = 0;
        $tag->data = (string)$metaDataFrame;
        $tag->dataSize = strlen($tag->data);
        return $this->sendTag($tag);
    }

    /**
     * @param MediaFrame $audioFrame
     * @return FlvPlayPackCache
     */
    public function sendAudioFrame(MediaFrame $audioFrame): FlvPlayPackCache
    {
        $tag = new FlvTag();
        $tag->type = Flv::AUDIO_TAG;
        $tag->timestamp = $audioFrame->timestamp;
        $tag->data = (string)$audioFrame;
        $tag->dataSize = strlen($tag->data);
        return $this->sendTag($tag);
    }

    /**
     * @param MediaFrame $videoFrame
     * @return FlvPlayPackCache
     */
    public function sendVideoFrame(MediaFrame $videoFrame): FlvPlayPackCache
    {
        $tag = new FlvTag();
        $tag->type = Flv::VIDEO_TAG;
        $tag->timestamp = $videoFrame->timestamp;
        $tag->data = (string)$videoFrame;
        $tag->dataSize = strlen($tag->data);
        return $this->sendTag($tag);
    }

    /**
     * 组包并发送，返回组好的数据供下一个播放端复用。
     *
     * @param FlvTag $tag
     * @return FlvPlayPackCache
     */
    protected function sendTag(FlvTag $tag): FlvPlayPackCache
    {
        $playPackCache = new FlvPlayPackCache(Flv::createFlvTag($tag));
        $this->write($playPackCache->chunks);
        return $playPackCache;
    }


}
