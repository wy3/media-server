<?php

declare(strict_types=1);

namespace MediaServer\Http;

use MediaServer\Flv\FlvPlayStream;
use MediaServer\MediaServer;
use MediaServer\Utils\WMHttpChunkStream;
use MediaServer\Utils\WMWsChunkStream;
use React\Promise\PromiseInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Protocols\Websocket;

/**
 * play 进程的 HTTP 入口：HTTP-FLV/WS-FLV 直播播放（GET .flv）+ 静态文件。
 * 拒绝 HTTP-FLV 推流；录像回放由 admin 进程提供，不在此处理。
 */
class PlayHttpServer extends HttpWMServer
{

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
    }

    public function getHandler(Request $request): void
    {
        $path = $request->path();

        //flv / 静态文件
        if (!$this->findFlv($request, $path)) {
            $request->connection->send(new Response(404, [], '404 Not Found'));
        }
    }




    /**
     * GET xxx.flv → 播放。
     */
    public function findFlv(Request $request, string $path): bool
    {
        if (!preg_match('/(.*)\.flv$/', $path, $matches)) {
            return false;
        }
        list(, $flvPath) = $matches;
        $this->playMediaStream($request, $flvPath);
        return true;
    }

    public function playMediaStream(Request $request, string $flvPath): void
    {
        //check stream
        if (MediaServer::hasPublishStream($flvPath)) {
            if ($request->connection->protocol === Websocket::class) {
                $request->connection->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;
                $throughStream = new WMWsChunkStream($request->connection);
            } else {
                $throughStream = new WMHttpChunkStream($request->connection);
            }
            $playerStream = new FlvPlayStream($throughStream, $flvPath);

            $disableAudio = $request->get('disableAudio', false);
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
            logger()->warning('Stream {path} not found', ['path' => $flvPath]);
            if ($request->connection->protocol === Websocket::class) {
                $request->connection->close();
            } else {
                $request->connection->send(new Response(
                    404,
                    ['Content-Type' => 'text/plain'],
                    'Stream not found.'
                ));
            }
        }
    }
}
