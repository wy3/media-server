<?php

namespace MediaServer\Utils;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use Workerman\Connection\TcpConnection;

class WMBufferStream extends BinaryStream implements  EventEmitterInterface
{
    use EventEmitterTrait;


    /**
     * @var TcpConnection
     */
    public $connection;
    /**
     * WMStreamProtocol constructor.
     * @param $connection TcpConnection
     */
    public function  __construct($connection){
        $this->connection = $connection;
        $this->connection->protocol = $this;
        $this->connection->onClose = [$this,'_onClose'];
        $this->connection->onError = [$this,'_onError'];

        parent::__construct();

    }

/*    public function __destruct(){
        logger()->info("WMBufferStream destruct");
    }*/

    public function _onClose($con){
        $this->connection->protocol = null;
        $this->connection = null;
        $this->emit("onClose");
        $this->removeAllListeners();
    }
    public function _onError($con,$code,$msg){
        $this->emit("onError");
    }

    /**
     * @param $buffer string
     * @param $connection TcpConnection
     */
    public static function input($buffer,$connection){
        /** @var WMBufferStream $me */
        $me = $connection->protocol;
        //reset recv buffer
        $me->recvBuffer($buffer);
        $me->emit("onData",[$me]);
        // clear connection recv buffer
        $me->clearConnectionRecvBuffer();
        return 0;
    }

    public static function encode($buffer,$connection){
        return $buffer;
    }

    public static function decode($buffer,$connection){
        return $buffer;
    }



    public function recvBuffer($data){
        $this->_data = $data;
        return $this->begin();
    }

    public function recvSize(){
        return strlen($this->_data);
    }

    public function handledSize(){
        return $this->_index;
    }

    public function clearConnectionRecvBuffer(){
        $this->connection->consumeRecvBuffer($this->_index);
    }



}
