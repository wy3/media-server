<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/1
 * Time: 2:37
 */

use MediaServer\FlvStream;
use MediaServer\MediaServer;
use MediaServer\PlayerStream;
use React\EventLoop\Loop;
use React\Stream\ThroughStream;
use RingCentral\Psr7\Response;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';


$rtmpServer = new React\Socket\SocketServer('tcp://0.0.0.0:1935');
$rtmpServer->on('connection', function (React\Socket\ConnectionInterface $connection) {
    logger()->info("connection" . $connection->getRemoteAddress() . " connected . ");
    $rtmpStream=new \MediaServer\Rtmp\RtmpStream($connection);
});
logger()->info("rtmp server start " . $rtmpServer->getAddress() . " start . ");

$server = new React\Http\HttpServer(
    new React\Http\Middleware\StreamingRequestMiddleware(),
    function (Psr\Http\Message\ServerRequestInterface $request, $next) {
        logger()->info("{method} {path}", ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath()]);
        $p = explode('.', $request->getUri()->getPath());
        if (end($p) !== 'flv') {
            return new Response(404, [], 'failed path.');
        }
        return $next($request);
    },
    function (Psr\Http\Message\ServerRequestInterface $request, $next) {
        switch ($request->getMethod()) {
            case "GET":
                $path = $request->getUri()->getPath();

                $playerStream=new \MediaServer\Flv\FlvPlayStream($throughStream = new ThroughStream(),$path);

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


                Loop::futureTick(function ()use ($playerStream, $path) {
                    MediaServer::addPlayer($playerStream);
                });

                $response= new React\Http\Message\Response(
                    200,
                    array(
                        'Cache-Control' => 'no-cache',
                        'Content-Type' => 'video/x-flv',
                        'Access-Control-Allow-Origin' => '*',
                        'Connection' => 'keep-alive'
                    ),
                    $throughStream
                );

                return $response;
            case "POST":
                return $next($request);
            default:
                logger()->warning("unknown method", ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath()]);
                return new \React\Http\Message\Response(405);
        }
    },

    function (Psr\Http\Message\ServerRequestInterface $request) {

        $path = $request->getUri()->getPath();
        $bodyStream = $request->getBody();
        assert($bodyStream instanceof Psr\Http\Message\StreamInterface);
        assert($bodyStream instanceof React\Stream\ReadableStreamInterface);


        return new React\Promise\Promise(function ($resolve, $reject) use ($bodyStream, $path) {
            $flvReadStream = new \MediaServer\Flv\FlvPublisherStream(
                $bodyStream,
                $path
            );
            //http 推流
            if(MediaServer::addPublish($flvReadStream)) {
                logger()->info("stream {path} created", ['path' => $path]);
                $flvReadStream->on('on_end', function () use ($resolve) {
                    $resolve(new Response(200));
                });
                $flvReadStream->on('error', function (Exception $exception) use ($resolve, &$bytes) {
                    $resolve(new React\Http\Message\Response(
                        400,
                        array(
                            'Content-Type' => 'text/plain'
                        ),
                        $exception->getMessage()
                    ));
                });
            } else {
                logger()->warning("stream {path} exists", ['path' => $path]);
                $resolve(new React\Http\Message\Response(
                    400,
                    array(
                        'Content-Type' => 'text/plain'
                    ),
                    "stream {$path} exists."
                ));
            }
        });

    }
);
$socket = new React\Socket\SocketServer('tcp://127.0.0.1:18080');

$server->listen($socket);
logger()->info("server " . $socket->getAddress() . " start . ");



Loop::run();
