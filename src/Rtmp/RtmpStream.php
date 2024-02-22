<?php


namespace MediaServer\Rtmp;


use Evenement\EventEmitter;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\DuplexMediaStreamInterface;
use MediaServer\PushServer\VerifyAuthStreamInterface;
use MediaServer\Utils\WMBufferStream;
use Workerman\Timer;


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
    public $videoFpsCountTimer;
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
     * @var WMBufferStream
     */
    protected $buffer;

    /**
     * PlayerStream constructor.
     * @param $bufferStream WMBufferStream
     */
    public function __construct($bufferStream)
    {
        //先随机生成个id
        $this->id = generateNewSessionID();
        $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_UNINIT;
        $this->ip = '';
        $this->isStarting = true;
        $this->buffer = $bufferStream;
        $bufferStream->on('onData',[$this,'onStreamData']);
        $bufferStream->on('onError',[$this,'onStreamError']);
        $bufferStream->on('onClose',[$this,'onStreamClose']);

        /*
         *  统计数据量代码
         *
         */
         $this->dataCountTimer = Timer::add(5,function(){
            $avgTime=$this->frameTimeCount/($this->frameCount?:1);
            $avgPack=$this->frameCount/5;
            $packPs=(1/($avgTime?:1));
            // $s=$packPs/$avgPack;
            $this->frameCount=0;
            $this->frameTimeCount=0;
            $this->bytesRead = $this->buffer->connection->bytesRead;
            $this->bytesReadRate = $this->bytesRead/ (timestamp() - $this->startTimestamp) * 1000;
            //logger()->info("[rtmp on data] {$packPs} pps {$avgPack} ps {$s} stream");
        });
    }

    public $dataCountTimer;
    public $frameCount = 0;
    public $frameTimeCount = 0;
    public $bytesRead = 0;
    public $bytesReadRate = 0;

    public function onStreamData()
    {
        //若干秒后没有收到数据断开
        $b = microtime(true);

        if ($this->handshakeState < RtmpHandshake::RTMP_HANDSHAKE_C2) {
            $this->onHandShake();
        }

        if ($this->handshakeState === RtmpHandshake::RTMP_HANDSHAKE_C2) {
            $this->onChunkData();

            $this->inAckSize += strlen($this->buffer->recvSize());
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


    public function write($data)
    {
        return $this->buffer->connection->send($data,true);
    }

/*    public function __destruct()
    {
        logger()->info("[RtmpStream __destruct] id={$this->id}");
    }*/



}
