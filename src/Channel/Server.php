<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/9
 * Time: 2:06
 */

namespace MediaServer\Channel;


use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

class Server
{
    use FrameProtocol;

    /**
     * Max udp package size.
     *
     * @var int
     */
    const MAX_UDG_PACKAGE_SIZE = 65535;

    public $context;
    public $local_socket = '';
    public $socket;
    public $onMessage;

    /**
     * @var EventInterface
     */
    public $loop;

    public function __construct($loop, $local_socket, $context_option = [])
    {
        $this->loop = $loop;
        $this->local_socket = $local_socket;
        $this->context = \stream_context_create($context_option);
    }

    /**
     * @throws Exception
     */
    public function listen()
    {
        $errNo = 0;
        $errMsg = '';

        // Create an Internet or Unix domain server socket.

        $this->socket = \stream_socket_server($this->local_socket, $errNo, $errMsg, STREAM_SERVER_BIND);


        if (!$this->socket) {
            throw new Exception($errMsg);
        }

        // Non blocking.
        \stream_set_blocking($this->socket, false);

        $this->loop->add($this->socket, \Workerman\Events\EventInterface::EV_READ, array($this, 'acceptMsg'));

    }

    /**
     * @param resource $socket
     * @return mixed|void|bool
     */
    public function acceptMsg($socket)
    {
        \set_error_handler(function () {
        });

        $recv_buffer = \stream_socket_recvfrom($socket, static::MAX_UDG_PACKAGE_SIZE, 0, $remote_address);
        \restore_error_handler();
        if (false === $recv_buffer) {
            return false;
        }

        if ($this->onMessage) {
            try {
                $data = self::decode($recv_buffer);
                // Discard bad packets.
                if ($data === false)
                    return true;
                \call_user_func($this->onMessage, $data);
            } catch (\Exception $e) {
                Worker::log($e);
            } catch (\Error $e) {
                Worker::log($e);
            }
        }
    }


}