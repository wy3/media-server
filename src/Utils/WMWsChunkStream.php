<?php

namespace MediaServer\Utils;

use Evenement\EventEmitterTrait;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Response;

class WMWsChunkStream implements  WMChunkStreamInterface
{
    use EventEmitterTrait;

    /**
     * @var TcpConnection
     */
    protected $connection;

    /**
     * WMHttpChunkStream constructor.
     * @param $connection TcpConnection
     */
    public function __construct($connection){
        $this->connection = $connection;
        $this->connection->onClose = function ($con){
            $this->emit('close');
            $this->connection = null;
            $this->removeAllListeners();
        };
        $this->connection->onError = function ($con,$code,$msg){
            $this->emit('error',[new \Exception($msg,$code)]);
        };
    }


    public function write($data)
    {
        $this->connection->send($data);
    }

    public function end($data = null)
    {
        //empty chunk end
        $this->connection->send(new Chunk(''));
    }

    public function close()
    {
        $this->connection->close();
    }
}
