<?php
/**
 * Date: 2021/8/13
 * Time: 15:59
 */

namespace MediaServer\Rtmp;


use Evenement\EventEmitter;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\DuplexMediaStreamInterface;
use MediaServer\PushServer\VerifyAuthStreamInterface;
use MediaServer\Utils\BinaryStream;
use React\Stream\DuplexStreamInterface;
use React\Stream\ReadableStreamInterface;


/**
 * Class RtmpStream
 * @package MediaServer\Rtmp
 */
class RtmpStream extends EventEmitter implements DuplexMediaStreamInterface, VerifyAuthStreamInterface
{

    use RtmpHandshakeTrait,
        RtmpChunkHandlerTrait,
        RtmpPacketTrait,
        RtmpTrait,
        RtmpPublisherTrait,
        RtmpPlayerTrait;


    /**
     * @var DuplexStreamInterface
     */
    private $input;


    /**
     * @var int handshake state
     */
    public $handshakeState;

    public $id;

    public $ip;

    public $port;


    protected $chunkHeaderLen = 0;
    protected $chunkState;

    /**
     * @var RtmpPacket[]
     */
    protected $allPackets = [];

    /**
     * @var int 接收数据时的  chunk size
     */
    protected $inChunkSize = 128;
    /**
     * @var int 发送数据时的 chunk size
     */
    protected $outChunkSize = 60000;


    /**
     * @var RtmpPacket
     */
    protected $currentPacket;


    public $startTimestamp;

    public $objectEncoding;

    public $streams = 0;

    public $playStreamId = 0;
    public $playStreamPath = '';
    public $playArgs = [];

    public $isStarting = false;

    public $connectCmdObj = null;

    public $appName = '';

    public $isReceiveAudio = true;
    public $isReceiveVideo = true;


    /**
     * @var int
     */
    public $pingTimer;

    /**
     * @var int ping interval
     */
    public $pingTime = 60;
    public $bitrateCache;


    public $publishStreamPath;
    public $publishArgs;
    public $publishStreamId;


    /**
     * @var int 发送ack的长度
     */
    protected $ackSize = 0;

    /**
     * @var int 当前size统计
     */
    protected $inAckSize = 0;
    /**
     * @var int 上次ack的size
     */
    protected $inLastAck = 0;

    public $isMetaData = false;
    /**
     * @var MetaDataFrame
     */
    public $metaDataFrame;


    public $videoWidth = 0;
    public $videoHeight = 0;
    public $videoFps = 0;
    public $videoCount = 0;
    public $videoProfileName = '';
    public $videoLevel = 0;

    public $videoCodec = 0;
    public $videoCodecName = '';
    public $isAVCSequence = false;
    /**
     * @var VideoFrame
     */
    public $avcSequenceHeaderFrame;

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

    public $isPublishing = false;
    public $isPlaying = false;

    public $enableGop = true;

    /**
     * @var MediaFrame[]
     */
    public $gopCacheQueue = [];


    /**
     * @var BinaryStream
     */
    protected $buffer;

    /**
     * PlayerStream constructor.
     * @param $input EventEmitter|ReadableStreamInterface
     */
    public function __construct($input)
    {
        //先随机生成个id
        $this->id = generateNewSessionID();
        $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_UNINIT;
        $this->input = $input;
        $this->ip = '';

        $input->on('data', [$this, 'onStreamData']);
        $input->on('error', [$this, 'onStreamError']);
        $input->on('close', [$this, 'onStreamClose']);

        $this->isStarting = true;
        $this->buffer = new BinaryStream();

        /*        Loop::addPeriodicTimer(5,function(){
                    $avgTime=$this->frameTimeCount/($this->frameCount?:1);
                    $avgPack=$this->frameCount/5;
                    $packPs=(1/($avgTime?:1));
                    $s=$packPs/$avgPack;
                    $this->frameCount=0;
                    $this->frameTimeCount=0;
                    logger()->info("[rtmp on data] {$packPs} pps {$avgPack} ps {$s} stream");
                });*/
    }

    public $frameCount = 0;
    public $frameTimeCount = 0;

    public function onStreamData($data)
    {
        //若干秒后没有收到数据断开
        $b = microtime(true);

        //存入数据
        $this->buffer->push($data);


        if ($this->handshakeState < RtmpHandshake::RTMP_HANDSHAKE_C2) {
            $this->onHandShake();
        }

        if ($this->handshakeState === RtmpHandshake::RTMP_HANDSHAKE_C2) {
            $this->onChunkData();

            $this->inAckSize += strlen($data);
            if ($this->inAckSize >= 0xf0000000) {
                $this->inAckSize = 0;
                $this->inLastAck = 0;
            }
            if ($this->ackSize > 0 && $this->inAckSize - $this->inLastAck >= $this->ackSize) {
                //每次收到的数据超过ack设的值
                $this->inLastAck = $this->inAckSize;
                $this->sendACK($this->inAckSize);
            }
        }
        $this->frameTimeCount += microtime(true) - $b;
        $this->frameCount++;


        //已消费数据清理
        $this->buffer->clear();

        //logger()->info("[rtmp on data] per sec handler times: ".(1/($end?:1)));
    }


    public function onStreamClose()
    {
        $this->stop();
    }


    public function onStreamError()
    {
        $this->stop();
    }


    public function write(&$data)
    {
        return $this->input->write($data);
    }

    public function __destruct()
    {

        // TODO: Implement __destruct() method.
        logger()->info("[RtmpStream __destruct] id={$this->id}");
    }


}
