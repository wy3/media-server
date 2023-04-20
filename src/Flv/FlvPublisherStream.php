<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/9
 * Time: 2:35
 */

namespace MediaServer\Flv;


use Evenement\EventEmitter;
use Exception;
use MediaServer\MediaReader\AACPacket;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\PublishStreamInterface;
use MediaServer\Utils\BinaryStream;
use React\Stream\ReadableStreamInterface;
use Workerman\Timer;

class FlvPublisherStream extends EventEmitter implements PublishStreamInterface
{
    const FLV_STATE_FLV_HEADER = 0;
    const FLV_STATE_TAG_HEADER = 1;
    const FLV_STATE_TAG_DATA = 2;

    public $id;

    /**
     * @var EventEmitter|ReadableStreamInterface
     */
    private $input;
    private $closed = false;


    /**
     * @var BinaryStream
     */
    protected $buffer;


    public $flvHeader;
    public $hasFlvHeader = false;

    public $hasAudio = false;
    public $hasVideo = false;

    public $audioCodec = 0;
    public $audioCodecName = '';
    public $audioSamplerate = 0;
    public $audioChannels = 1;
    public $isAACSequence = false;

    /**
     * @var AudioFrame
     */
    public $aacSequenceHeaderFrame;
    public $audioProfileName = '';


    public $isMetaData = false;
    /**
     * @var MetaDataFrame
     */
    public $metaDataFrame;


    public $isAVCSequence = false;
    /**
     * @var VideoFrame
     */
    public $avcSequenceHeaderFrame;
    public $videoWidth = 0;
    public $videoHeight = 0;
    public $videoFps = 0;
    public $videoCount = 0;
    public $videoFpsCountTimer;

    public $videoProfileName = '';
    public $videoLevel = 0;

    public $videoCodec = 0;
    public $videoCodecName = '';


    /**
     * @var string
     */
    public $publishPath;

    /**
     * @var MediaFrame[]
     */
    public $gopCacheQueue = [];

    public function __destruct()
    {
        logger()->info("publisher flv stream {path} destruct", ['path' => $this->publishPath]);
    }

    /**
     * FlvStream constructor.
     * @param $input EventEmitter|ReadableStreamInterface
     * @param $path  string
     */
    public function __construct($input, $path)
    {
        //先随机生成个id
        $this->id = generateNewSessionID();
        $this->input = $input;
        $this->publishPath = $path;
        $input->on('data', [$this, 'onStreamData']);
        $input->on('error', [$this, 'onStreamError']);
        $input->on('close', [$this, 'onStreamClose']);
        $this->buffer = new BinaryStream();
    }


    /**
     * @var FlvTag
     */
    protected $currentTag;


    protected $steamStatus = self::FLV_STATE_FLV_HEADER;


    /**
     * @param $data
     * @throws Exception
     * @internal
     */
    public function onStreamData($data)
    {
        //若干秒后没有收到数据断开
        $this->buffer->push($data);
        switch ($this->steamStatus) {
            case self::FLV_STATE_FLV_HEADER:
                if ($this->buffer->has(9)) {
                    $this->flvHeader = new FlvHeader($this->buffer->readRaw(9));
                    $this->hasFlvHeader = true;
                    $this->hasAudio = $this->flvHeader->hasAudio;
                    $this->hasVideo = $this->flvHeader->hasVideo;

                    $this->buffer->clear();
                    logger()->info("publisher {path} recv flv header.", ['path' => $this->publishPath]);
                    $this->emit("on_publish_ready");
                    $this->steamStatus = self::FLV_STATE_TAG_HEADER;
                } else {
                    break;
                }
            default:
                //进入tag flv 处理流程
                $this->flvTagHandler();
                break;
        }

    }

    /**
     * @throws Exception
     */
    public function flvTagHandler()
    {
        //若干秒后没有收到数据断开
        switch ($this->steamStatus) {
            case self::FLV_STATE_TAG_HEADER:
                if ($this->buffer->has(15)) {
                    //除去pre tag size 4byte
                    $this->buffer->skip(4);
                    $tag = new FlvTag();
                    $tag->type = $this->buffer->readTinyInt();
                    $tag->dataSize = $this->buffer->readInt24();
                    $tag->timestamp = $this->buffer->readInt24() | $this->buffer->readTinyInt() << 24;
                    $tag->streamId = $this->buffer->readInt24();
                    $this->currentTag = $tag;
                    //进入等待 Data
                    $this->steamStatus = self::FLV_STATE_TAG_DATA;
                } else {
                    break;
                }
            case self::FLV_STATE_TAG_DATA:
                $curTag = $this->currentTag;
                if ($this->buffer->has($curTag->dataSize)) {
                    $curTag->data = $this->buffer->readRaw($curTag->dataSize);

                    //处理tag
                    $this->onTagEvent();

                    $this->buffer->clear();
                    //进入等待header流程
                    $this->steamStatus = self::FLV_STATE_TAG_HEADER;
                } else {
                    break;
                }
            default:
                //跑一下看看剩余的数据够不够
                $this->flvTagHandler();
                break;
        }
    }


    /**
     * @throws Exception
     */
    public function onTagEvent()
    {
        $tag = $this->currentTag;
        switch ($tag->type) {
            case Flv::SCRIPT_TAG:
                $metaData = Flv::scriptFrameDataRead($tag->data);
                logger()->info("publisher {path} metaData: " . json_encode($metaData));
                $this->videoWidth = $metaData['dataObj']['width'] ?? 0;
                $this->videoHeight = $metaData['dataObj']['height'] ?? 0;
                $this->videoFps = $metaData['dataObj']['framerate'] ?? 0;

                $this->audioSamplerate = $metaData['dataObj']['audiosamplerate'] ?? 0;
                $this->audioChannels = $metaData['dataObj']['stereo'] ?? 1;

                $this->metaDataFrame = new MetaDataFrame($tag->data);
                $this->isMetaData = true;
                $this->emit('on_frame', [$this->metaDataFrame, $this]);
                break;
            case Flv::VIDEO_TAG:
                //视频数据
                $videoFrame = new VideoFrame($tag->data, $tag->timestamp);
                if ($this->videoCodec == 0) {
                    $this->videoCodec = $videoFrame->codecId;
                    $this->videoCodecName = $videoFrame->getVideoCodecName();
                }

                if ($this->videoFps === 0) {
                    //当前帧为第0
                    if ($this->videoCount++ === 0) {
                        $this->videoFpsCountTimer = Timer::add(5, function () {
                            $this->videoFps = ceil($this->videoCount / 5);
                            $this->videoFpsCountTimer = null;
                        },[],false);
                    }
                }

                if ($videoFrame->codecId === VideoFrame::VIDEO_CODEC_ID_AVC) {
                    //h264
                    $avcPack = $videoFrame->getAVCPacket();

                    //read avc
                    if ($avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                        $this->isAVCSequence = true;
                        $this->avcSequenceHeaderFrame = $videoFrame;
                        $specificConfig = $avcPack->getAVCSequenceParameterSet();
                        $this->videoWidth = $specificConfig->width;
                        $this->videoHeight = $specificConfig->height;
                        $this->videoProfileName = $specificConfig->getAVCProfileName();
                        $this->videoLevel = $specificConfig->level;
                        logger()->info("publisher {path} recv avc sequence.", ['path' => $this->publishPath]);
                    }

                    if ($this->isAVCSequence) {
                        if ($videoFrame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                            &&
                            $avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_NALU) {
                            $this->gopCacheQueue = [];
                        }

                        if ($videoFrame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                            &&
                            $avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                            //skip avc sequence
                        } else {
                            $this->gopCacheQueue[] = $videoFrame;
                        }
                    }
                }

                //数据处理与数据发送
                $this->emit('on_frame', [$videoFrame, $this]);
                //销毁AVC
                $videoFrame->destroy();
                break;
            case Flv::AUDIO_TAG:
                //音频数据
                $audioFrame = new AudioFrame($tag->data, $tag->timestamp);
                if ($this->audioCodec === 0) {
                    $this->audioCodec = $audioFrame->soundFormat;
                    $this->audioCodecName = $audioFrame->getAudioCodecName();
                    $this->audioSamplerate = $audioFrame->getAudioSamplerate();
                    $this->audioChannels = ++$audioFrame->soundType;
                }

                if ($audioFrame->soundFormat === AudioFrame::SOUND_FORMAT_AAC) {
                    $aacPack = $audioFrame->getAACPacket();
                    if ($aacPack->aacPacketType === AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                        $this->isAACSequence = true;
                        $this->aacSequenceHeaderFrame = $audioFrame;
                        $set = $aacPack->getAACSequenceParameterSet();
                        $this->audioProfileName = $set->getAACProfileName();
                        $this->audioSamplerate = $set->sampleRate;
                        $this->audioChannels = $set->channels;
                        //logger()->info("publisher {path} recv acc sequence.", ['path' => $this->pathIndex]);
                    }

                    if ($this->isAACSequence) {
                        if ($aacPack->aacPacketType == AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {

                        } else {
                            //音频关键帧缓存
                            $this->gopCacheQueue[] = $audioFrame;
                        }
                    }


                }

                $this->emit('on_frame', [$audioFrame, $this]);
                //logger()->info("rtmpAudioHandler");
                $audioFrame->destroy();
                break;
        }
    }


    /**
     * @param Exception $e
     * @internal
     */
    public function onStreamError(\Exception $e)
    {
        $this->emit('on_error', [$e]);
        $this->onStreamClose();
    }

    public function onStreamClose()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $this->buffer = null;
        $this->gopCacheQueue = [];
        $this->input->close();

        if($this->videoFpsCountTimer){
            Timer::del($this->videoFpsCountTimer);
            $this->videoFpsCountTimer = null;
        }


        $this->emit('on_close');
        $this->removeAllListeners();
    }

    public function getPublishPath()
    {
        return $this->publishPath;
    }

    public function isMetaData()
    {
        return $this->isMetaData;
    }


    public function getMetaDataFrame()
    {
        return $this->metaDataFrame;
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

    public function hasAudio()
    {
        return $this->hasAudio;
    }

    public function hasVideo()
    {
        return $this->hasVideo;
    }

    public function getGopCacheQueue()
    {
        return $this->gopCacheQueue;
    }
}
