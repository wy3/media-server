<?php

namespace MediaServer\Utils;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use Workerman\Connection\TcpConnection;

class WMBufferStream implements  EventEmitterInterface
{
    use EventEmitterTrait;

    private $_index = 0;
    public $_data = '';

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


    public function reset()
    {
        $this->_index = 0;
    }

    public function skip($length)
    {
        $this->_index += $length;
    }

    public function flush($length = -1)
    {
        if ($length == -1) {
            $d = $this->_data;
            $this->_data = "";
        } else {
            $d = substr($this->_data, 0, $length);
            $this->_data = substr($this->_data, $length);
        }
        $this->_index = 0;
        return $d;
    }


    public function dump()
    {
        return $this->_data;
    }

    public function has($len)
    {
        $pos = $len - 1;
        return isset($this->_data[$this->_index + $pos]);
    }

    public function clear()
    {
        $this->_data = substr($this->_data, $this->_index);
        $this->_index = 0;

    }

    public function begin()
    {
        $this->_index = 0;
        return $this;
    }

    public function move($pos)
    {
        $this->_index = max(array(0, min(array($pos, strlen($this->_data)))));
        return $this;
    }

    public function end()
    {
        $this->_index = strlen($this->_data);
        return $this;
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

    public function push($data)
    {
        $this->_data .= $data;
        return $this;
    }

    //--------------------------------
    //		Writer
    //--------------------------------

    public function writeByte($value)
    {
        $this->_data .= is_int($value) ? chr($value) : $value;
        $this->_index++;
    }

    public function writeInt16($value)
    {
        $this->_data .= pack("s", $value);
        $this->_index += 2;
    }

    public function writeInt24($value)
    {
        $this->_data .= substr(pack("N", $value), 1);
        $this->_index += 3;
    }

    public function writeInt32($value)
    {
        $this->_data .= pack("N", $value);
        $this->_index += 4;
    }

    public function writeInt32LE($value)
    {
        $this->_data .= pack("V", $value);
        $this->_index += 4;
    }

    public function write($value)
    {
        $this->_data .= $value;
        $this->_index += strlen($value);
    }

    //-------------------------------
    //		Reader
    //-------------------------------

    public function readByte()
    {
        return ($this->_data[$this->_index++]);
    }

    public function readTinyInt()
    {
        return ord($this->readByte());
    }

    public function readInt16()
    {
        return ($this->readTinyInt() << 8) + $this->readTinyInt();
    }

    public function readInt16LE()
    {
        return $this->readTinyInt() + ($this->readTinyInt() << 8);
    }

    public function readInt24()
    {
        $m = unpack("N", "\x00" . substr($this->_data, $this->_index, 3));
        $this->_index += 3;
        return $m[1];
    }

    public function readInt32()
    {
        return $this->read("N", 4);
    }

    public function readInt32LE()
    {
        return $this->read("V", 4);
    }

    public function readRaw($length = 0)
    {
        if ($length == 0)
            $length = strlen($this->_data) - $this->_index;
        $datas = substr($this->_data, $this->_index, $length);
        $this->_index += $length;
        return $datas;
    }

    private function read($type, $size)
    {
        $m = unpack("$type", substr($this->_data, $this->_index, $size));
        $this->_index += $size;
        return $m[1];
    }

    //-------------------------------
    //		Tag & rollback
    //-------------------------------

    protected $tagPos;

    public function tag()
    {
        $this->tagPos = $this->_index;
    }

    public function rollBack()
    {
        $this->_index = $this->tagPos;
    }

}
