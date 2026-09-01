<?php

declare(strict_types=1);

namespace MediaServer\Rtmp;


use Evenement\EventEmitter;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\DuplexMediaStreamInterface;
use MediaServer\PushServer\VerifyAuthStreamInterface;
use MediaServer\Contracts\BufferStreamInterface;
use MediaServer\Utils\RuntimeTimer as Timer;


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
    public int $handshakeState = 0;

    public string $id = '';

    public string $ip = '';

    public int $port = 0;


    protected int $chunkHeaderLen = 0;
    protected int $chunkState = RtmpChunk::CHUNK_STATE_BEGIN;

    /**
     * @var RtmpPacket[]
     */
    protected array $allPackets = [];

    /**
     * @var int 接收数据时的  chunk size
     */
    protected int $inChunkSize = 128;
    /**
     * @var int 发送数据时的 chunk size
     */
    protected int $outChunkSize = 60000;


    /**
     * @var RtmpPacket|null
     */
    protected ?RtmpPacket $currentPacket = null;


    public int $startTimestamp = 0;

    public int $objectEncoding = 0;

    public int $streams = 0;

    public int $playStreamId = 0;
    public string $playStreamPath = '';
    public array $playArgs = [];

    public bool $isStarting = false;

    public ?array $connectCmdObj = null;

    public string $appName = '';

    public bool $isReceiveAudio = true;
    public bool $isReceiveVideo = true;


    /**
     * @var int|null
     */
    public ?int $pingTimer = null;

    /**
     * @var int ping interval
     */
    public int $pingTime = 60;
    public ?array $bitrateCache = null;


    public ?string $publishStreamPath = null;
    public array $publishArgs = [];
    public int $publishStreamId = 0;


    /**
     * @var int 发送ack的长度
     */
    protected int $ackSize = 0;

    /**
     * @var int 当前size统计
     */
    protected int $inAckSize = 0;
    /**
     * @var int 上次ack的size
     */
    protected int $inLastAck = 0;

    public bool $isMetaData = false;
    /**
     * @var MetaDataFrame|null
     */
    public ?MetaDataFrame $metaDataFrame = null;


    public int $videoWidth = 0;
    public int $videoHeight = 0;
    public int|float $videoFps = 0;
    public int $videoCount = 0;
    public ?int $videoFpsCountTimer = null;
    public string $videoProfileName = '';
    public int|float $videoLevel = 0;

    public int $videoCodec = 0;
    public string $videoCodecName = '';
    public bool $isAVCSequence = false;
    /**
     * @var VideoFrame|null
     */
    public ?VideoFrame $avcSequenceHeaderFrame = null;

    public int $audioCodec = 0;
    public string $audioCodecName = '';
    public int $audioSamplerate = 0;
    public int $audioChannels = 1;
    public bool $isAACSequence = false;
    /**
     * @var AudioFrame|null
     */
    public ?AudioFrame $aacSequenceHeaderFrame = null;
    public string $audioProfileName = '';

    public bool $isPublishing = false;
    public bool $isPlaying = false;

    /**
     * 是否已注册 on_frame 事件监听（由 MediaServer 动态赋值，显式声明以避免 PHP 8.2+ 动态属性弃用）
     */
    public bool $is_on_frame = false;

    public bool $enableGop = true;

    /**
     * @var MediaFrame[]
     */
    public array $gopCacheQueue = [];


    /**
     * @var BufferStreamInterface|null
     */
    protected ?BufferStreamInterface $buffer = null;

    public ?int $dataCountTimer = null;
    public int $frameCount = 0;
    public float $frameTimeCount = 0;
    public int $bytesRead = 0;
    public float $bytesReadRate = 0;

    /**
     * PlayerStream constructor.
     * @param BufferStreamInterface $bufferStream
     */
    public function __construct(BufferStreamInterface $bufferStream)
    {
        //先随机生成个id
        $this->id = generateNewSessionID();
        $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_UNINIT;
        $this->ip = '';
        $this->isStarting = true;
        $this->buffer = $bufferStream;
        $bufferStream->on('onData', [$this, 'onStreamData']);
        $bufferStream->on('onError', [$this, 'onStreamError']);
        $bufferStream->on('onClose', [$this, 'onStreamClose']);

        /*
         *  统计数据量代码
         *
         */
        $this->dataCountTimer = Timer::add(5, function () {
            $avgTime = $this->frameTimeCount / ($this->frameCount ?: 1);
            $avgPack = $this->frameCount / 5;
            $packPs = (1 / ($avgTime ?: 1));
            // $s=$packPs/$avgPack;
            $this->frameCount = 0;
            $this->frameTimeCount = 0;
            $this->bytesRead = $this->buffer->getBytesRead();
            $this->bytesReadRate = $this->bytesRead / (timestamp() - $this->startTimestamp) * 1000;
            //logger()->info("[rtmp on data] {$packPs} pps {$avgPack} ps {$s} stream");
        });
    }

    public function onStreamData(): void
    {
        //若干秒后没有收到数据断开
        $b = microtime(true);

        if ($this->handshakeState < RtmpHandshake::RTMP_HANDSHAKE_C2) {
            $this->onHandShake();
        }

        if ($this->handshakeState === RtmpHandshake::RTMP_HANDSHAKE_C2) {
            $this->onChunkData();

            $this->inAckSize += $this->buffer->recvSize();
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


    public function onStreamClose(): void
    {
        $this->stop();
    }


    public function onStreamError(): void
    {
        $this->stop();
    }


    public function write(string $data): ?bool
    {
        return $this->buffer->send($data);
    }

/*    public function __destruct()
    {
        logger()->info("[RtmpStream __destruct] id={$this->id}");
    }*/



}