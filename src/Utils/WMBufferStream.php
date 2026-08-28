<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use Workerman\Connection\TcpConnection;

class WMBufferStream extends BinaryStream implements EventEmitterInterface
{
    use EventEmitterTrait;


    public ?TcpConnection $connection = null;

    public function __construct(TcpConnection $connection)
    {
        $this->connection = $connection;
        $this->connection->protocol = $this;
        $this->connection->onClose = [$this, '_onClose'];
        $this->connection->onError = [$this, '_onError'];

        parent::__construct();

    }

    public function _onClose(TcpConnection $con): void
    {
        $this->connection->protocol = null;
        $this->connection = null;
        $this->emit("onClose");
        $this->removeAllListeners();
    }

    public function _onError(TcpConnection $con, int $code, string $msg): void
    {
        $this->emit("onError");
    }

    public static function input(string $buffer, TcpConnection $connection): int
    {
        /** @var WMBufferStream $me */
        $me = $connection->protocol;
        //reset recv buffer
        $me->recvBuffer($buffer);
        $me->emit("onData", [$me]);
        // clear connection recv buffer
        $me->clearConnectionRecvBuffer();
        return 0;
    }

    public static function encode(string $buffer, TcpConnection $connection): string
    {
        return $buffer;
    }

    public static function decode(string $buffer, TcpConnection $connection): string
    {
        return $buffer;
    }


    public function recvBuffer(string $data): self
    {
        $this->_data = $data;
        return $this->begin();
    }

    public function recvSize(): int
    {
        return strlen($this->_data);
    }

    public function handledSize(): int
    {
        return $this->_index;
    }

    public function clearConnectionRecvBuffer(): void
    {
        $this->connection->consumeRecvBuffer($this->_index);
    }


}