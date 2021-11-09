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
use React\EventLoop\Loop;
use React\Stream\ThroughStream;
use React\Http\HttpServer as Server;
use React\Http\Middleware\StreamingRequestMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use Psr\Http\Message\StreamInterface;
use React\Stream\ReadableStreamInterface;
use React\Promise\Promise;


class HttpServer
{
    /**
     * @var Server
     */
    public $server;

    /**
     * @return Server
     */
    public function __invoke()
    {
        return $this->server;
    }

    public function __construct()
    {

        $this->server = new Server(
            new StreamingRequestMiddleware(),
            $this->pathFilterMiddleWare(),
            $this->handler()
        );

    }


    public function pathFilterMiddleWare()
    {
        return function (ServerRequestInterface $request, $next) {
            $path = $request->getUri()->getPath();
            $p = explode('.', $path);
            if (end($p) !== 'flv') {
                return new Response(
                    400,
                    ['Content-Type' => 'text/plain'],
                    "failed path: {$path} ."
                );
            }
            return $next($request);
        };
    }

    public function handler()
    {
        return function (ServerRequestInterface $request) {
            switch ($request->getMethod()) {
                case "GET":
                    return $this->getHandler($request);
                case "POST":
                    return $this->postHandler($request);
                case "HEAD":
                    return $response = new Response(200);
                default:
                    logger()->warning("unknown method", ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath()]);
                    return new Response(405);
            }
        };
    }


    /**
     * @param ServerRequestInterface $request
     * @return Response
     */
    public function getHandler(ServerRequestInterface $request)
    {
        $path = $request->getUri()->getPath();
        //判断当前播放流在不在
        if (MediaServer::hasPublishStream($path)) {
            $playerStream = new FlvPlayStream($throughStream = new ThroughStream(), $path);

            $disableAudio = $request->getQueryParams()['disableAudio'] ?? false;
            if ($disableAudio) {
                $playerStream->setEnableAudio(false);
            }

            $disableVideo = $request->getQueryParams()['disableVideo'] ?? false;
            if ($disableVideo) {
                $playerStream->setEnableVideo(false);
            }

            $disableGop = $request->getQueryParams()['disableGop'] ?? false;
            if ($disableGop) {
                $playerStream->setEnableGop(false);
            }

            Loop::futureTick(function () use ($playerStream, $path) {
                MediaServer::addPlayer($playerStream);
            });

            $response = new Response(
                200,
                [
                    'Cache-Control' => 'no-cache',
                    'Content-Type' => 'video/x-flv',
                    'Access-Control-Allow-Origin' => '*',
                    'Connection' => 'keep-alive'
                ],
                $throughStream
            );

            return $response;
        } else {
            logger()->warning("Stream {path} not found", ['path' => $path]);
            return new Response(
                404,
                ['Content-Type' => 'text/plain'],
                "Stream not found."
            );
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @return Promise|Response
     */
    public function postHandler(ServerRequestInterface $request)
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
}