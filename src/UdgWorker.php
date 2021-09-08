<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/9
 * Time: 0:56
 */

namespace MediaServer;


use Workerman\Connection\ConnectionInterface;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

class UdgWorker extends Worker
{
    /**
     * PHP built-in protocols.
     *
     * @var array
     */
    protected static $_builtinTransports = array(
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'tcp',
        'udg' => 'udg',
    );

    /**
     * @throws Exception
     */
    public function listen()
    {
        if (!$this->_socketName) {
            return;
        }

        if (!$this->_mainSocket) {

            $local_socket = $this->parseSocketAddress();

            if (!$this->transport === 'udg') {
                throw new Exception("This transport is not 'udg'.");
            }

            // Flag.
            $flags = \STREAM_SERVER_BIND;
            $errno = 0;
            $errmsg = '';
            // SO_REUSEPORT.
            if ($this->reusePort) {
                \stream_context_set_option($this->_context, 'socket', 'so_reuseport', 1);
            }

            // Create an Internet or Unix domain server socket.
            $this->_mainSocket = \stream_socket_server($local_socket, $errno, $errmsg, $flags, $this->_context);
            if (!$this->_mainSocket) {
                throw new Exception($errmsg);
            }


//            $socket_file = \substr($local_socket, 7);
//            if ($this->user) {
//                \chown($socket_file, $this->user);
//            }
//            if ($this->group) {
//                \chgrp($socket_file, $this->group);
//            }


            // Non blocking.
            \stream_set_blocking($this->_mainSocket, false);
        }

        $this->resumeUdgAccept();
    }

    public function resumeUdgAccept()
    {
        // Register a listener to be notified when server socket is ready to read.
        if (static::$globalEvent && true === $this->_pauseAccept && $this->_mainSocket) {

            static::$globalEvent->add($this->_mainSocket, EventInterface::EV_READ, array($this, 'acceptUdgConnection'));

            $this->_pauseAccept = false;
        }
    }

    /**
     * For udg package.
     *
     * @param resource $socket
     * @return bool
     */
    public function acceptUdgConnection($socket)
    {
        \set_error_handler(function () {
        });
        $recv_buffer = \stream_socket_recvfrom($socket, static::MAX_UDP_PACKAGE_SIZE, 0, $remote_address);
        \restore_error_handler();
        if (false === $recv_buffer) {
            return false;
        }
        echo "acceptUdgConnection\n";
        var_dump($remote_address);
        // UdpConnection.
        $connection = new UdgConnection($socket, $remote_address);
        $connection->protocol = $this->protocol;
        if ($this->onMessage) {
            try {
                if ($this->protocol !== null) {
                    /** @var \Workerman\Protocols\ProtocolInterface $parser */
                    $parser = $this->protocol;
                    if ($parser && \method_exists($parser, 'input')) {
                        while ($recv_buffer !== '') {
                            $len = $parser::input($recv_buffer, $connection);
                            if ($len === 0)
                                return true;
                            $package = \substr($recv_buffer, 0, $len);
                            $recv_buffer = \substr($recv_buffer, $len);
                            $data = $parser::decode($package, $connection);
                            if ($data === false)
                                continue;
                            \call_user_func($this->onMessage, $connection, $data);
                        }
                    } else {
                        $data = $parser::decode($recv_buffer, $connection);
                        // Discard bad packets.
                        if ($data === false)
                            return true;
                        \call_user_func($this->onMessage, $connection, $data);
                    }
                } else {
                    \call_user_func($this->onMessage, $connection, $recv_buffer);
                }
                ++ConnectionInterface::$statistics['total_request'];
            } catch (\Exception $e) {
                static::log($e);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                exit(250);
            }
        }
        return true;
    }

}