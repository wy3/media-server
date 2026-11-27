<?php

declare(strict_types=1);

namespace MediaServer\Http;

use MediaServer\Flv\FlvPublisherStream;
use MediaServer\MediaServer;
use Psr\Http\Message\StreamInterface;
use React\Promise\Promise;
use React\Stream\ReadableStreamInterface;
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

        if (!preg_match('#^/live/.+#', $path)) {
            $request->connection->send(new Response(404, [], '404 Not Found'));
            return;
        }

        $bodyStream = $request->getBody();
        if (!$bodyStream instanceof StreamInterface || !$bodyStream instanceof ReadableStreamInterface) {
            $request->connection->send(new Response(500, ['Content-Type' => 'text/plain'], 'Stream error.'));
            return;
        }

        if (MediaServer::hasPublishStream($path)) {
            logger()->warning('Stream {path} exists', ['path' => $path]);
            $request->connection->send(new Response(400, ['Content-Type' => 'text/plain'], "Stream {$path} exists."));
            return;
        }

        return new Promise(function ($resolve, $reject) use ($bodyStream, $path) {
            $flvReadStream = new FlvPublisherStream($bodyStream, $path);
            MediaServer::addPublish($flvReadStream);
            logger()->info('stream {path} created', ['path' => $path]);
            $flvReadStream->on('on_end', function () use ($resolve) {
                $resolve(new Response(200));
            });
            $flvReadStream->on('error', function (\Exception $exception) use ($reject) {
                $reject(new Response(400, ['Content-Type' => 'text/plain'], $exception->getMessage()));
            });
        });
    }
}
