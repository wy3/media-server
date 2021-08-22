<?php
/**
 * Date: 2021/8/13
 * Time: 15:59
 */

namespace MediaServer\Rtmp;


use Evenement\EventEmitter;
use Exception;
use MediaServer\BinaryStream;
use React\EventLoop\Loop;
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

    protected $ackSize = 0;

    /**
     * @var RtmpPacket
     */
    protected $currentPacket;


    public $connectTime;

    public $startTimestamp;

    public $objectEncoding;

    public $streams = 0;

    public $playStreamId = 0;

    public $isStarting = false;

    public $connectCmdObj = null;

    public $appName;

    /**
     * @var integer ping timer
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
        $con->on('data', [$this, 'onHandShake']);
        $this->isStarting = true;
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
