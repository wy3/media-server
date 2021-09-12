<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/9
 * Time: 2:35
 */

namespace MediaServer;


use Evenement\EventEmitter;
use Exception;
use Psr\Http\Message\StreamInterface;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Stream\ReadableStreamInterface;
use function ord;

class FlvStream extends EventEmitter
{
    /**
     * @var EventEmitter|ReadableStreamInterface
     */
    private $input;
    private $closed = false;

    /** @var $readDeferred [0] int */
    /** @var $readDeferred [1] Deferred */
    private $readDeferred;

    /**
     * @var int max buffer size, default 4MB
     */
    private $maxBufferSize = 4194304;

    private $buffer = "";


    public $flvHeader;
    public $hasFlvHeader = false;

    public $hasAudio = false;
    public $hasVideo = false;

    public $isACCSequence = false;
    public $accSequence;

    public $isAVCSequence = false;
    public $avcSequence;

    public $isMetaData = false;
    public $metaData;

    public $videoWidth;
    public $videoHeight;
    public $audioSampleRate;
    public $audioChannels;
    public $videoFps;

    public $pathIndex;

    public $gopCacheQueue = [];

    public function __destruct()
    {
        $this->buffer = null;
        logger()->info("flv stream {path} destruct", ['path' => $this->pathIndex]);
    }

    /**
     * FlvStream constructor.
     * @param $input EventEmitter|ReadableStreamInterface
     * @param string $pathIndex
     */
    public function __construct($input, $pathIndex)
    {
        $this->input = $input;
        $this->pathIndex = $pathIndex;
        $input->on('data', [$this, 'streamData']);
        $input->on('error', [$this, 'streamError']);
        $input->on('end', [$this, 'streamEnd']);
        $input->on('close', [$this, 'close']);
    }

    const FLV_STATE_FLV_HEADER = 0;
    const FLV_STATE_TAG_HEADER = 1;
    const FLV_STATE_TAG_DATA = 2;

    protected $flvTagLen = 0;


    protected $steamStatus = self::FLV_STATE_FLV_HEADER;
    /**
     * @var int 下个循环想要读取的数据长度，默认先读取header的长度9byte
     */
    protected $wantedRead = 9;

    /** @internal */
    public function streamData($data)
    {
        $this->buffer .= $data;
        switch ($this->steamStatus) {
            case self::FLV_STATE_FLV_HEADER:
                if (isset($this->buffer[8])) {
                    $data = substr($this->buffer, 0, 9);

                    $this->flvHeader = FlvStreamConst::flvHeaderRead($data);
                    $this->hasFlvHeader = true;
                    $this->hasAudio = $this->flvHeader['typeFlags'] & 4 ? true : false;
                    $this->hasVideo = $this->flvHeader['typeFlags'] & 1 ? true : false;

                    logger()->info("publisher {path} recv flv header.", ['path' => $this->pathIndex]);
                    $this->emit("flv_ready", [$this->flvHeader]);

                    $this->buffer = substr($this->buffer, 9);
                    $this->steamStatus = self::FLV_STATE_TAG_HEADER;
                } else {
                    break;
                }
            case self::FLV_STATE_TAG_HEADER:
                if (isset($this->buffer[14])) {
                    //除去pre tag size 4byte
                    $this->buffer = substr($this->buffer, 4);
                    //补充 tagData部分数据
                    $this->flvTagLen = 11 + ((ord($this->buffer[1]) << 16) | (ord($this->buffer[2]) << 8) | ord($this->buffer[3]));
                    //进入等待 Data
                    $this->steamStatus = self::FLV_STATE_TAG_DATA;
                } else {
                    break;
                }
            case self::FLV_STATE_TAG_DATA:
                if (isset($this->buffer[$this->flvTagLen - 1])) {
                    $data = substr($this->buffer, 0, $this->flvTagLen);

                    //此处出来的应该是一个完整的tag数据了
                    $dataSize = (ord($data[1]) << 16) | (ord($data[2]) << 8) | ord($data[3]);
                    $analysis = unpack("CtagType/a3tagSize/a3timestamp/CtimestampEx/a3streamId/a{$dataSize}data", $data);
                    $tag = [
                        'type' => $analysis['tagType'],
                        'dataSize' => $dataSize,
                        'timestamp' => ($analysis['timestampEx'] << 24) | (ord($analysis['timestamp'][0]) << 16) | (ord($analysis['timestamp'][1]) << 8) | ord($analysis['timestamp'][2]),
                        'streamId' => (ord($analysis['streamId'][0]) << 16) | (ord($analysis['streamId'][1]) << 8) | ord($analysis['streamId'][2]),
                        'data' => $analysis['data']
                    ];

                    //处理tag
                    $this->onTagEvent($tag);

                    $this->buffer = substr($this->buffer, $this->flvTagLen);

                    //进入等待header流程
                    $this->steamStatus = self::FLV_STATE_TAG_HEADER;

                } else {
                    break;
                }
            default:
                //跑一下看看剩余的数据够不够
                $this->streamData("");
                break;
        }

    }


    public function onTagEvent(&$tag)
    {
        switch ($tag['type']) {
            case FlvStreamConst::SCRIPT_TAG:
                $this->isMetaData = true;
                $this->metaData = $tag;
                $metaData = FlvStreamConst::scriptFrameDataRead($tag['data']);
                $this->videoWidth = $metaData['dataObj']['width'] ?? null;
                $this->videoHeight = $metaData['dataObj']['height'] ?? null;
                $this->audioSampleRate = $metaData['dataObj']['audiosamplerate'] ?? null;
                $this->audioChannels = $metaData['dataObj']['stereo'] ?? null;
                $this->videoFps = $metaData['dataObj']['framerate'] ?? null;
                logger()->info("publisher {path} metaData: " . json_encode($metaData));
                break;
            case FlvStreamConst::VIDEO_TAG:
                //视频数据
                $videoFrame = FLVStreamConst::videoFrameDataRead($tag['data']);

                if ($videoFrame['codecId'] == FLVStreamConst::VIDEO_CODEC_ID_AVC) {
                    $avcPack = FLVStreamConst::avcPacketRead($videoFrame['data']);

                    //read avc
                    if ($avcPack['avcPacketType'] == FlvStreamConst::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                        $this->isAVCSequence = true;
                        $this->avcSequence = &$tag;
                        logger()->info("publisher {path} recv avc sequence.", ['path' => $this->pathIndex]);
                    }

                    //gop
                    if ($videoFrame['frameType'] == FlvStreamConst::VIDEO_FRAME_TYPE_KEY_FRAME &&
                        $avcPack['avcPacketType'] == FlvStreamConst::AVC_PACKET_TYPE_NALU) {
                        logger()->info("publisher {path} clear gop.", ['path' => $this->pathIndex]);
                        $this->gopCacheQueue = [];
                    }

                    if ($videoFrame['frameType'] == FlvStreamConst::VIDEO_FRAME_TYPE_KEY_FRAME &&
                        $avcPack['avcPacketType'] == FlvStreamConst::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                        //skip avc sequence
                    } else {
                        $this->gopCacheQueue[] = &$tag;
                    }

                    $tag['frameType'] = $videoFrame['frameType'];
                }
                break;

            case FlvStreamConst::AUDIO_TAG:
                //音频数据
                $audioFrame = FLVStreamConst::audioFrameDataRead($tag['data']);
                if ($audioFrame['soundFormat'] == FlvStreamConst::SOUND_FORMAT_ACC) {
                    $accPack = FLVStreamConst::accPacketDataRead($audioFrame['data']);
                    if ($accPack['accPacketType'] == FlvStreamConst::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
                        $this->isACCSequence = true;
                        $this->accSequence = &$tag;
                        logger()->info("publisher {path} recv acc sequence.", ['path' => $this->pathIndex]);
                    }

                    if ($accPack['accPacketType'] == FlvStreamConst::ACC_PACKET_TYPE_SEQUENCE_HEADER) {

                    } else {
                        //音频关键帧缓存
                        $this->gopCacheQueue[] = &$tag;
                    }
                }
                break;
        }

        //数据群发
        $this->emit('flv_tag', [$tag]);
    }

    /** @internal */
    public function streamEnd()
    {
        if (!$this->closed) {
            $this->emit('end');
            $this->close();
        }
    }


    /** @internal */
    public function streamError(Exception $e)
    {
        $this->emit('error', [$e]);
        $this->close();
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $this->buffer = "";
        $this->gopCacheQueue = [];
        $this->input->close();

        $this->emit('close');
        $this->removeAllListeners();
    }

}
