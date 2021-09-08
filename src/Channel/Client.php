<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/9
 * Time: 2:06
 */

namespace MediaServer\Channel;


use Workerman\Events\Event;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

class Client
{
    use FrameProtocol;

    /**
     * Udp socket.
     *
     * @var resource
     */
    protected $_socket = null;

    /**
     * Remote address.
     *
     * @var string
     */
    protected $_remoteAddress = '';


    /**
     * Connected or not.
     *
     * @var bool
     */
    protected $connected = false;

    /**
     * Context option.
     *
     * @var array
     */
    protected $_contextOption = null;

    /**
     * @var bool
     */
    protected $listening = false;

    /**
     * @var EventInterface
     */
    protected $loop;

    /**
     * @var array msg buffer
     */
    protected $messages = [];

    public $onceMaxSend = 1000;

    /**
     * Construct.
     *
     * @param EventInterface $loop
     * @param string $remote_address
     * @throws Exception
     */
    public function __construct($loop, $remote_address, $context_option = null)
    {
        $this->loop = $loop;
        // Get the application layer communication protocol and listening address.
        list($scheme, $address) = \explode(':', $remote_address, 2);

        $this->_remoteAddress = \substr($address, 2);
        $this->_contextOption = $context_option;

        $this->connect();

    }

    /**
     * Sends data on the connection.
     *
     * @param string $send_buffer
     * @param bool $raw
     * @return void|boolean
     */
    public function send($send_buffer, $raw = false)
    {
        if (false === $raw) {
            $send_buffer = self::encode($send_buffer);
            if ($send_buffer === '') {
                return;
            }
        }
        if ($this->connected === false) {
            return;
        }


        $this->messages[] = $send_buffer;

        if (!$this->listening) {
            $this->listening = true;
            $this->loop->add($this->_socket, EventInterface::EV_WRITE, [$this, 'handleWriteEvent']);
        }


    }

    /**
     * @throws Exception
     */
    public function handleWriteEvent()
    {
        $sendCount = 0;
        $beginSend=microtime(true);


        while ($message = array_shift($this->messages)) {
            $res = @\stream_socket_sendto($this->_socket, $message, 0);
            //$res = @\fwrite($this->_socket, $message);
            if (!$res) {
                throw new Exception("write data error.");
            }
            $sendCount++;
            if ($sendCount >= $this->onceMaxSend) {
                break;
            }
        }
        $use=microtime(true)-$beginSend;
        echo "now ".microtime(true)." raw send $sendCount packets use $use s.\n";

        if (count($this->messages) === 0) {
            $this->loop->del($this->_socket, EventInterface::EV_WRITE);
            $this->listening = false;
        }
    }


    /**
     * Close connection.
     * @return bool
     */
    public function close()
    {
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        \fclose($this->_socket);
        $this->connected = false;
        return true;
    }

    /**
     * Connect.
     *
     * @return void
     */
    public function connect()
    {
        if ($this->connected === true) {
            return;
        }
        if ($this->_contextOption) {
            $context = \stream_context_create($this->_contextOption);
            $this->_socket = \stream_socket_client("udg://{$this->_remoteAddress}", $errno, $errmsg,
                30, \STREAM_CLIENT_CONNECT, $context);
        } else {
            $this->_socket = \stream_socket_client("udg://{$this->_remoteAddress}", $errno, $errmsg);
        }


        if (!$this->_socket) {
            Worker::safeEcho(new \Exception($errmsg));
            return;
        }

        \stream_set_blocking($this->_socket, false);


        $this->connected = true;
    }

}