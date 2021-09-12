<?php
/**
 * Date: 2021/8/13
 * Time: 15:59
 */

namespace MediaServer\Rtmp;


use Evenement\EventEmitter;
use Exception;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Stream\DuplexStreamInterface;
use function ord;


/**
 * Class RtmpStream
 * @package MediaServer\Rtmp
 */
class RtmpStream extends EventEmitter
{

    use RtmpHandshakeTrait, RtmpChunkHandlerTrait, RtmpPacketTrait, RtmpTrait;

    /**
     * @var EventEmitter|DuplexStreamInterface
     */
    private $input;


    protected $buffer = "";
    /**
     * @var int 计划读取的数据长度
     */
    protected $wantedRead = 1;

    /**
     * @var int handshake state
     */
    public $handshakeState;

    public $id;

    public $ip;

    public $port;


    public $currentChunk = null;


    protected $chunkHeaderLen = 0;
    protected $chunkCID;
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


    public $connectTime;

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
     * @var TimerInterface
     */
    public $pingInterval;

    /**
     * @var int ping interval
     */
    public $pingTime = 10;
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


    public $videoWidth = 0;
    public $videoHeight = 0;
    public $videoFps = 0;
    public $videoCount = 0;
    public $videoProfileName = '';
    public $videoLevel = 0;

    public $videoCodec = 0;
    public $videoCodecName = '';

    public $audioCodec = 0;
    public $audioCodecName = '';
    public $audioSamplerate = 0;
    public $audioChannels = 1;
    public $isAACSequence = false;
    public $aacSequenceHeader;
    public $audioProfileName = '';


    /**
     * PlayerStream constructor.
     * @param $con ConnectionInterface
     */
    public function __construct($con)
    {
        //先随机生成个id
        $this->id = generateNewSessionID();
        $this->input = $con;
        $this->ip = $con->getRemoteAddress();
        $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_UNINIT;
        $con->on('error', [$this, 'streamError']);
        $con->on('end', [$this, 'end']);
        $con->on('close', [$this, 'close']);
        $con->on('data', [$this, 'onData']);
        $this->isStarting = true;
    }

    public function onData($data)
    {
        $this->buffer .= $data;
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

    }

    public function end()
    {
    }

    public function close()
    {

    }


    public function streamError()
    {
    }


    public function write(&$data)
    {
        return $this->input->write($data);
    }

    public function isWritable()
    {
        return $this->input->isWritable();
    }

}
