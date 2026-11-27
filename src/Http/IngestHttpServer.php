<?php

declare(strict_types=1);

namespace MediaServer\Http;

use MediaServer\Flv\FlvPublisherStream;
use MediaServer\MediaServer;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;


/**
 * ingest 进程的 HTTP 入口：HTTP-FLV 推流（POST /live/x）。
 * 拒绝播放类请求；管理 API 由 admin 进程提供，不在此处理。
 */
class IngestHttpServer extends HttpWMServer
{

    public function postHandler(Request $request): void
    {
        $path = $request->path();

        if (!preg_match('#^/live/.+\.flv$#', $path)) {
            $request->connection->send(new Response(404, [], '404 Not Found'));
            return;
        }

        if (MediaServer::hasPublishStream($path)) {
            logger()->warning('Stream {path} exists', ['path' => $path]);
            $request->connection->send(new Response(400, ['Content-Type' => 'text/plain'], "Stream {$path} exists."));
            return;
        }

        $connection = $request->connection;

        if (!$request instanceof StreamRequest || $request->bodyStream === null) {
            $connection->send(new Response(400, ['Content-Type' => 'text/plain'], 'Chunked transfer encoding required for FLV publish.'));
            return;
        }
        $bodyStream = $request->bodyStream;

        $flvReadStream = new FlvPublisherStream($bodyStream, $path);
        if (!MediaServer::addPublish($flvReadStream)) {
            $connection->send(new Response(400, ['Content-Type' => 'text/plain'], "Stream {$path} exists."));
            return;
        }
        logger()->info('stream {path} created', ['path' => $path]);

        $flvReadStream->on('on_publish_ready', function () use ($connection) {
            $connection->send(new Response(200));
        });
    }
}
