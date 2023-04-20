<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/1
 * Time: 2:37
 */

use MediaServer\MediaServer;
use React\EventLoop\Loop;
use React\Stream\ThroughStream;
use RingCentral\Psr7\Response;
use Workerman\Connection\TcpConnection;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';


class Main
{
    public function createRtmpServer()
    {
        $rtmpServer =  new class('tcp://0.0.0.0:1935') extends \Workerman\Worker{
            public function acceptConnection($socket)
            {
                // Accept a connection on server socket.
                \set_error_handler(function(){});
                $new_socket = \stream_socket_accept($socket, 0, $remote_address);
                \restore_error_handler();

                // Thundering herd.
                if (!$new_socket) {
                    return;
                }

                // create StreamTcpConnection.
                $connection                         = new \MediaServer\Utils\WKStreamTcpConnection($new_socket, $remote_address);
                $this->connections[$connection->id] = $connection;
                $connection->worker                 = $this;
                $connection->protocol               = $this->protocol;
                $connection->transport              = $this->transport;
                $connection->onMessage              = $this->onMessage;
                $connection->onClose                = $this->onClose;
                $connection->onError                = $this->onError;
                $connection->onBufferDrain          = $this->onBufferDrain;
                $connection->onBufferFull           = $this->onBufferFull;

                // Try to emit onConnect callback.
                if ($this->onConnect) {
                    try {
                        \call_user_func($this->onConnect, $connection);
                    } catch (\Exception $e) {
                        static::stopAll(250, $e);
                    } catch (\Error $e) {
                        static::stopAll(250, $e);
                    }
                }
            }
        };
        $rtmpServer->onConnect = function (\Workerman\Connection\TcpConnection $connection) {
            logger()->info("connection" . $connection->getRemoteAddress() . " connected . ");
            new \MediaServer\Rtmp\RtmpStream($connection);
        };
        logger()->info("rtmp server " . $rtmpServer->getSocketName() . " start . ");
    }

    public function createHttpServer()
    {
        $httpServer=new \MediaServer\Http\HttpServer();
        $socket = new React\Socket\SocketServer('tcp://0.0.0.0:18080');
        $httpServer()->listen($socket);
        $httpServer()->on('error',function($e){
            var_dump($e->getMessage());
        });

        logger()->info("http server " . $socket->getAddress() . " start . ");

    }

    public function run(){
        $this->createRtmpServer();
        //$this->createHttpServer();
    }
}


try {
    (new Main())->run();
    \Workerman\Worker::runAll();
} catch (Throwable $e) {

}

