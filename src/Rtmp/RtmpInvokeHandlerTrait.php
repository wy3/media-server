<?php


namespace MediaServer\Rtmp;


use MediaServer\MediaServer;
use \Exception;
use React\Promise\PromiseInterface;
use Workerman\Timer;

trait RtmpInvokeHandlerTrait
{

    /**
     * @return mixed | void
     * @throws Exception
     */
    public function rtmpInvokeHandler()
    {

        $b = microtime(true);
        $p = $this->currentPacket;
        //AMF0 数据解释
        $invokeMessage = RtmpAMF::rtmpCMDAmf0Reader($p->payload);

        switch ($invokeMessage['cmd']) {
            case 'connect':
                $this->onConnect($invokeMessage);
                break;
            case 'releaseStream':
                break;
            case 'FCPublish':
                break;
            case 'createStream':
                $this->onCreateStream($invokeMessage);
                break;
            case 'publish':
                $this->onPublish($invokeMessage);
                break;
            case 'play':
                $this->onPlay($invokeMessage);
                break;
            case 'pause':
                $this->onPause($invokeMessage);
                break;
            case 'FCUnpublish':
                break;
            case 'deleteStream':
                $this->onDeleteStream($invokeMessage);
                break;
            case 'closeStream':
                $this->onCloseStream();
                break;
            case 'receiveAudio':
                $this->onReceiveAudio($invokeMessage);
                break;
            case 'receiveVideo':
                $this->onReceiveVideo($invokeMessage);
                break;
        }
        logger()->info("rtmpInvokeHandler {$invokeMessage['cmd']} use:" . ((microtime(true) - $b) * 1000) . 'ms');
    }

    /**
     * @param $invokeMessage
     * @throws Exception
     */
    public function onConnect($invokeMessage)
    {

        $b = microtime(true);
        $invokeMessage['cmdObj']['app'] = str_replace('/', '', $invokeMessage['cmdObj']['app']); //fix jwplayer??
        $this->emit('preConnect', [$this->id, $invokeMessage['cmdObj']]);
        if (!$this->isStarting) {
            return;
        }
        $this->connectCmdObj = $invokeMessage['cmdObj'];
        $this->appName = $invokeMessage['cmdObj']['app'];
        $this->objectEncoding = (isset($invokeMessage['cmdObj']['objectEncoding']) && !is_null($invokeMessage['cmdObj']['objectEncoding'])) ? $invokeMessage['cmdObj']['objectEncoding'] : 0;


        $this->startTimestamp = timestamp();

        $this->pingTimer = Timer::add($this->pingTime,function(){
            $this->sendPingRequest();
        });

        $this->sendWindowACK(5000000);
        $this->setPeerBandwidth(5000000, 2);
        $this->setChunkSize($this->outChunkSize);

        $this->responseConnect($invokeMessage['transId']);


        $this->bitrateCache = [
            'intervalMs' => 1000,
            'last_update' => $this->startTimestamp,
            'bytes' => 0,
        ];

        logger()->info("[rtmp connect] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage['cmdObj']) . " use:" . ((microtime(true) - $b) * 1000) . 'ms');
    }

    /**
     * @param $invokeMessage
     * @throws Exception
     */
    public function onCreateStream($invokeMessage)
    {
        logger()->info("[rtmp create stream] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
        $this->respondCreateStream($invokeMessage['transId']);
    }

    /**
     * @param $invokeMessage
     * @param $isPromise bool
     * @throws Exception
     */
    public function onPublish($invokeMessage, $isPromise = false)
    {
        if (!$isPromise) {
            //发布一个视频
            logger()->info("[rtmp publish] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            if (!is_string($invokeMessage['streamName'])) {
                return;
            }
            $streamInfo = explode('?', $invokeMessage['streamName']);
            $this->publishStreamPath = '/' . $this->appName . '/' . $streamInfo[0];
            parse_str($streamInfo[1] ?? '', $this->publishArgs);
            $this->publishStreamId = $this->currentPacket->streamId;
        }
        //auth check
        if (!$isPromise && $result = MediaServer::verifyAuth($this)) {
            if ($result === false) {
                logger()->info("[rtmp publish] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
                //check false
                $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.publish.Unauthorized', 'Authorization required.');
                return;
            }

            if ($result instanceof PromiseInterface) {
                //异步检查
                $result->then(function () use ($invokeMessage) {
                    //resolve
                    $this->onPublish($invokeMessage, true);
                }, function ($exception) use ($invokeMessage) {
                    logger()->info("[rtmp publish] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage) . " " . $exception->getMessage());
                    //check false
                    $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.publish.Unauthorized', 'Authorization required.');
                });
                return;
            }
        }

        if (MediaServer::hasPublishStream($this->publishStreamPath)) {
            //publishStream already
            logger()->info("[rtmp publish] Already has a stream. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            $this->reject();
            $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.Publish.BadName', 'Stream already publishing');
        } else if ($this->isPublishing) {
            logger()->info("[rtmp publish] NetConnection is publishing. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.Publish.BadConnection', 'Connection already publishing');
        } else {

            MediaServer::addPublish($this);

            $this->isPublishing = true;
            $this->sendStatusMessage($this->publishStreamId, 'status', 'NetStream.Publish.Start', "{$this->publishStreamPath} is now published.");

            //emit on on_publish_ready
            $this->emit('on_publish_ready');

        }
    }

    /**
     * @param $invokeMessage
     * @param $isPromise bool
     * @throws Exception
     */
    public function onPlay($invokeMessage, $isPromise = false)
    {
        if (!$isPromise) {
            logger()->info("[rtmp play] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            if (!is_string($invokeMessage['streamName'])) {
                return;
            }
            /** @var RtmpPacket $p */
            $parse = explode('?', $invokeMessage['streamName']);
            $this->playStreamPath = '/' . $this->appName . '/' . $parse[0];
            parse_str($parse[1] ?? '', $this->playArgs);
            $this->playStreamId = $this->currentPacket->streamId;
        }

        //auth check
        if (!$isPromise && $result = MediaServer::verifyAuth($this)) {
            if ($result === false) {
                logger()->info("[rtmp play] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
                $this->sendStatusMessage($this->playStreamId, 'error', 'NetStream.play.Unauthorized', 'Authorization required.');
                return;
            }

            if ($result instanceof PromiseInterface) {
                //异步检查
                $result->then(function () use ($invokeMessage) {
                    //resolve
                    $this->onPlay($invokeMessage, true);
                }, function ($exception) use ($invokeMessage) {
                    logger()->info("[rtmp play] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage) . " " . $exception->getMessage());
                    //check false
                    $this->sendStatusMessage($this->playStreamId, 'error', 'NetStream.play.Unauthorized', 'Authorization required.');
                });
                return;
            }
        }

        if ($this->isPlaying) {
            $this->sendStatusMessage($this->playStreamId, 'error', 'NetStream.Play.BadConnection', 'Connection already playing');
        } else {
            $this->respondPlay();
        }

        MediaServer::addPlayer($this);

    }

    public function onPause($invokeMessage)
    {
        //暂停视频
    }

    public function onDeleteStream($invokeMessage)
    {
        //删除流
    }

    public function onCloseStream()
    {
        //关闭流，调用删除流逻辑
        $this->onDeleteStream(['streamId' => $this->currentPacket->streamId]);
    }

    public function onReceiveAudio($invokeMessage)
    {
        logger()->info("[rtmp play] receiveAudio=" . ($invokeMessage['bool'] ? 'true' : 'false'));
        $this->isReceiveAudio = $invokeMessage['bool'];
    }

    public function onReceiveVideo($invokeMessage)
    {
        logger()->info("[rtmp play] receiveVideo=" . ($invokeMessage['bool'] ? 'true' : 'false'));
        $this->isReceiveVideo = $invokeMessage['bool'];
    }

    public function sendStreamStatus($st, $id)
    {

        $buf = hex2bin('020000000000060400000000000000000000');
        $buf = substr_replace($buf, pack('nN', $st, $id), 12);
        $this->write($buf);
    }


    /**
     * @param $sid
     * @param $opt
     * @throws Exception
     */
    public function sendInvokeMessage($sid, $opt)
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_INVOKE;
        $packet->type = RtmpPacket::TYPE_INVOKE;
        $packet->streamId = $sid;
        $packet->payload = RtmpAMF::rtmpCMDAmf0Creator($opt);
        $packet->length = strlen($packet->payload);
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }

    /**
     * @param $sid
     * @param $opt
     * @throws Exception
     */
    public function sendDataMessage($sid, $opt)
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_DATA;
        $packet->type = RtmpPacket::TYPE_DATA;
        $packet->streamId = $sid;
        $packet->payload = RtmpAMF::rtmpDATAAmf0Creator($opt);
        $packet->length = strlen($packet->payload);
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }

    /**
     * @param $sid
     * @param $level
     * @param $code
     * @param $description
     * @throws Exception
     */
    public function sendStatusMessage($sid, $level, $code, $description)
    {
        $opt = [
            'cmd' => 'onStatus',
            'transId' => 0,
            'cmdObj' => null,
            'info' => [
                'level' => $level,
                'code' => $code,
                'description' => $description
            ]
        ];
        $this->sendInvokeMessage($sid, $opt);
    }

    /**
     * @param $sid
     * @throws Exception
     */
    public function sendRtmpSampleAccess($sid)
    {
        $opt = [
            'cmd' => '|RtmpSampleAccess',
            'bool1' => false,
            'bool2' => false
        ];
        $this->sendDataMessage($sid, $opt);
    }


    /**
     * @throws Exception
     */
    public function sendPingRequest()
    {

        $currentTimestamp = timestamp() - $this->startTimestamp;
        //logger()->debug("send ping time:" . $currentTimestamp);
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_PROTOCOL;
        $packet->type = RtmpPacket::TYPE_EVENT;
        $packet->payload = pack("nN", 6, $currentTimestamp);
        $packet->length = 6;
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }


    /**
     * @param $tid
     * @throws Exception
     */
    public function responseConnect($tid)
    {
        $opt = [
            'cmd' => '_result',
            'transId' => $tid,
            'cmdObj' => [
                'fmsVer' => 'FMS/3,0,1,123',
                'capabilities' => 31
            ],
            'info' => [
                'level' => 'status',
                'code' => 'NetConnection.Connect.Success',
                'description' => 'Connection succeeded.',
                'objectEncoding' => $this->objectEncoding
            ]
        ];
        $this->sendInvokeMessage(0, $opt);
    }

    /**
     * @param $tid
     * @throws Exception
     */
    public function respondCreateStream($tid)
    {
        $this->streams++;
        $opt = [
            'cmd' => '_result',
            'transId' => $tid,
            'cmdObj' => null,
            'info' => $this->streams
        ];
        $this->sendInvokeMessage(0, $opt);
    }

    /**
     * @throws Exception
     */
    public function respondPlay()
    {
        $this->sendStreamStatus(RtmpPacket::STREAM_BEGIN, $this->playStreamId);
        $this->sendStatusMessage($this->playStreamId, 'status', 'NetStream.Play.Reset', 'Playing and resetting stream.');
        $this->sendStatusMessage($this->playStreamId, 'status', 'NetStream.Play.Start', 'Started playing stream.');
        $this->sendRtmpSampleAccess($this->playStreamId);
    }

    public function reject()
    {
        logger()->info("[rtmp reject] reject stream publish id={$this->id}");
        $this->stop();
    }

}
