<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/9
 * Time: 2:33
 */

namespace MediaServer\Http;

use MediaServer\Flv\FlvPlayStream;
use MediaServer\Flv\FlvPublisherStream;
use MediaServer\MediaServer;
use MediaServer\Utils\WMHttpChunkStream;
use MediaServer\Utils\WMWsChunkStream;
use Psr\Http\Message\StreamInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Protocols\Websocket;
use Workerman\Worker;


class HttpWMServer extends Worker
{

    public  function __construct($socket_name = '', array $context_option = array())
    {
        parent::__construct($socket_name, $context_option);
        //使用扩展的协议
        $this->onWebSocketConnect = [$this,'onWebsocketRequest'];
        $this->onMessage = [$this,'onHttpRequest'];
    }

    public function onWebsocketRequest($connection,$headerData){
        $request = new Request($headerData);
        $request->connection = $connection;
        //ignore connection message
        $connection->onMessage = null;
        $this->playMediaStream($request);
        return;
    }


    /**
     * @param $connection TcpConnection
     * @param Request $request
     */
    public function onHttpRequest($connection,Request  $request)
    {
        switch ($request->method()) {
            case "GET":
                return $this->getHandler($request);
            case "POST":
                return $this->postHandler($request);
            case "HEAD":
                return $connection->send(new \Workerman\Protocols\Http\Response(200));
            default:
                logger()->warning("unknown method", ['method' => $request->method(), 'path' => $request->path()]);
                return $connection->send(new \Workerman\Protocols\Http\Response(405));
        }
    }


    /**
     * @param Request $request
     */
    public function getHandler(Request $request)
    {
        $path = $request->path();
        //api

        //flv
        $this->playMediaStream($request);
        return;
    }



    /**
     * @param Request $request
     * @return Promise|Response
     */
    public function postHandler(Request $request)
    {
        $path = $request->getUri()->getPath();
        $bodyStream = $request->getBody();
        if(!$bodyStream instanceof StreamInterface || !$bodyStream instanceof ReadableStreamInterface){
            return new Response(
                500,
                ['Content-Type' => 'text/plain'],
                "Stream error."
            );
        };

        if (MediaServer::hasPublishStream($path)) {
            //publishStream already
            logger()->warning("Stream {path} exists", ['path' => $path]);
            return new Response(
                400,
                ['Content-Type' => 'text/plain'],
                "Stream {$path} exists."
            );
        }


        return new Promise(function ($resolve, $reject) use ($bodyStream, $path) {
            $flvReadStream = new FlvPublisherStream(
                $bodyStream,
                $path
            );

            MediaServer::addPublish($flvReadStream);
            logger()->info("stream {path} created", ['path' => $path]);
            $flvReadStream->on('on_end', function () use ($resolve) {
                $resolve(new Response(200));
            });
            $flvReadStream->on('error', function (\Exception $exception) use ($reject, &$bytes) {
                $reject(new Response(
                    400,
                    ['Content-Type' => 'text/plain'],
                    $exception->getMessage()
                ));
            });
        });
    }


    public function playMediaStream(Request $request){
        $path = $request->path();
        if(!preg_match('/(.*)\.flv$/',$path,$matches)){
            echo $request->connection->protocol;
            if($request->connection->protocol === Websocket::class){
                $request->connection->close();
            }else{
                $request->connection->send(new Response(400,
                    ['Content-Type' => 'text/plain'],
                    "failed path: {$path} ."));
            }
            return;
        }
        list(,$path) = $matches;
        //check stream
        if (MediaServer::hasPublishStream($path)) {

            if($request->connection->protocol === Websocket::class){
                $request->connection->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;
                $throughStream = new WMWsChunkStream($request->connection);
            }else{
                $throughStream = new WMHttpChunkStream($request->connection);
            }
            $playerStream = new FlvPlayStream($throughStream, $path);

            $disableAudio = $request->get('disableAudio',false);
            if ($disableAudio) {
                $playerStream->setEnableAudio(false);
            }

            $disableVideo = $request->get('disableVideo', false);
            if ($disableVideo) {
                $playerStream->setEnableVideo(false);
            }

            $disableGop = $request->get('disableGop', false);
            if ($disableGop) {
                $playerStream->setEnableGop(false);
            }
            MediaServer::addPlayer($playerStream);
        } else {
            logger()->warning("Stream {path} not found", ['path' => $path]);
            if($request->connection->protocol === Websocket::class){
                $request->connection->close();
            }else{
                $request->connection->send(new Response(404,
                    ['Content-Type' => 'text/plain'],
                    "Stream not found."));
            }

        }
    }


}