<?php

declare(strict_types=1);

namespace MediaServer\Http;

use MediaServer\Flv\FlvPlayStream;
use MediaServer\Flv\FlvPublisherStream;
use MediaServer\MediaServer;
use MediaServer\Recorder\PlaybackServer;
use MediaServer\Utils\WMHttpChunkStream;
use MediaServer\Utils\WMWsChunkStream;
use Psr\Http\Message\StreamInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Protocols\Websocket;
use Workerman\Worker;


class HttpWMServer extends Worker
{
    static string $publicPath = '';

    public  function __construct(string $socket_name = '', array $context_option = [])
    {
        parent::__construct($socket_name, $context_option);
        //使用扩展的协议
        $this->onWebSocketConnect = [$this,'onWebsocketRequest'];
        $this->onMessage = [$this,'onHttpRequest'];
    }

    public function onWebsocketRequest(TcpConnection $connection, string $headerData): void
    {
        $request = new Request($headerData);
        $request->connection = $connection;
        //ignore connection message
        $connection->onMessage = null;
        if ($this->findFlv($request, $request->path())) {
            return;
        }
        $request->connection->close();
        return;
    }


    /**
     * @param TcpConnection $connection
     * @param Request $request
     */
    public function onHttpRequest(TcpConnection $connection, Request $request): ?PromiseInterface
    {
        switch ($request->method()) {
            case "GET":
                $this->getHandler($request);
                return null;
            case "POST":
                return $this->postHandler($request);
            case "HEAD":
                $connection->send(new \Workerman\Protocols\Http\Response(200));
                return null;
            default:
                logger()->warning("unknown method", ['method' => $request->method(), 'path' => $request->path()]);
                $connection->send(new \Workerman\Protocols\Http\Response(405));
                return null;
        }
    }


    /**
     * @param Request $request
     */
    public function getHandler(Request $request): void
    {
        $path = $request->path();

        //api
        if($path ==='/api'){
            $name = $request->get('name');
            $args = $request->get('args',[]);
            $data = MediaServer::callApi($name,$args);
            if(!is_null($data)){
                $request->connection->send(new Response(200,['Content-Type'=>"application/json"],json_encode($data)));
            }else{
                $request->connection->send(new Response(404,[],'404 Not Found'));
            }
            return;
        }
        //playback (指定时间回放)
        if (strpos($path, '/playback/') === 0) {
            $recordPath = substr($path, strlen('/playback/'));
            PlaybackServer::handlePlaybackRequest($request, $recordPath);
            return;
        }
        //flv
        if (
            $this->unsafeUri($request,$path) ||
            $this->findFlv($request,$path) ||
            $this->findStaticFile($request,$path)
        ){
            return;
        }

        //api

        //404
        $request->connection->send(new Response(404,[],'404 Not Found'));
        return;
    }



    /**
     * @param Request $request
     * @return PromiseInterface|Response
     */
    public function postHandler(Request $request): PromiseInterface|Response
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
            $flvReadStream->on('error', function (\Exception $exception) use ($reject) {
                $reject(new Response(
                    400,
                    ['Content-Type' => 'text/plain'],
                    $exception->getMessage()
                ));
            });
        });
    }

    public function unsafeUri(Request $request, string $path): bool
    {
        if (
            !$path ||
            strpos($path, '..') !== false ||
            strpos($path, "\\") !== false ||
            strpos($path, "\0") !== false
        ) {
            $request->connection->send(new Response(404,[],'404 Not Found.'));
            return true;
        }
        return false;
    }

    public function findStaticFile(Request $request, string $path): bool
    {

        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $path = urldecode($path);
            if ($this->unsafeUri($request,$path)) {
                return true;
            }
        }

        $file = self::$publicPath."/$path";
        if (!is_file($file)) {
            return false;
        }

        $request->connection->send((new Response())->withFile($file));

        return true;
    }

    public function findFlv(Request $request, string $path): bool
    {
        if(!preg_match('/(.*)\.flv$/',$path,$matches)){
            return false;
        }else{
            list(,$flvPath) = $matches;
            $this->playMediaStream($request,$flvPath);
            return true;
        }
    }


    public function playMediaStream(Request $request, string $flvPath): void
    {
        //check stream
        if (MediaServer::hasPublishStream($flvPath)) {

            if($request->connection->protocol === Websocket::class){
                $request->connection->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;
                $throughStream = new WMWsChunkStream($request->connection);
            }else{
                $throughStream = new WMHttpChunkStream($request->connection);
            }
            $playerStream = new FlvPlayStream($throughStream, $flvPath);

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
            logger()->warning("Stream {path} not found", ['path' => $flvPath]);
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
