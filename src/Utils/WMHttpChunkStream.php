<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use Evenement\EventEmitterTrait;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Response;

class WMHttpChunkStream implements WMChunkStreamInterface
{
    use EventEmitterTrait;

    protected ?TcpConnection $connection = null;

    protected bool $sendHeader = false;

    public function __construct(TcpConnection $connection)
    {
        $this->connection = $connection;
        $this->connection->onClose = function ($con) {
            $this->emit('close');
            $this->connection = null;
            $this->removeAllListeners();
        };
        $this->connection->onError = function ($con, $code, $msg) {
            $this->emit('error', [new \Exception($msg, $code)]);
        };
    }


    public function write(string $data): void
    {
        if (!$this->sendHeader) {
            $this->sendHeader = true;
            $this->connection->send(new Response(200, [
                'Cache-Control' => 'no-cache',
                'Content-Type' => 'video/x-flv',
                'Access-Control-Allow-Origin' => '*',
                'Connection' => 'keep-alive',
                'Transfer-Encoding' => 'chunked'
            ], $data));
        } else {
            $this->connection->send(new Chunk($data));
        }

    }

    public function end(?string $data = null): void
    {
        //empty chunk end
        $this->connection->send(new Chunk(''));
    }

    public function close(): void
    {
        $this->connection->close();
    }
}