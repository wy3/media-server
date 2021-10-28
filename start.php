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
use Symfony\Component\Console\Input\ArgvInput;
use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Output\OutputInterface;
use \Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use \Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\SingleCommandApplication;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';

define("IS_WIN", DIRECTORY_SEPARATOR === '\\');


class Main extends SingleCommandApplication
{
    protected static $master_pid = 0;

    protected static $pid_file = __DIR__ . '/pid';

    protected $is_daemon = false;

    protected function configure()
    {
        $this->setDefinition(
            new \Symfony\Component\Console\Input\InputDefinition([
                new InputOption('daemon', 'd', null, "Run service in DAEMON mode."),
                new InputOption('status', 'S', null, "Get service status."),
                new InputOption('stop', 's', null, "Stop service."),
            ]));
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void
     * @throws Exception
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->command($input);
        $this->daemon();
        $this->savePid();
        $this->createRtmpServer();
        $this->createHttpServer();
        Loop::run();
    }

    public function command(InputInterface $input)
    {
        if ($input->getOption('status')) {

            exit('status');
        } else if ($input->getOption('stop')) {
            exit('stop');
        } else {
            $this->is_daemon = $input->getOption('daemon');
        }
    }


    public function createRtmpServer()
    {
        $rtmpServer = new React\Socket\SocketServer('tcp://0.0.0.0:1935');
        $rtmpServer->on('connection', function (React\Socket\ConnectionInterface $connection) {
            logger()->info("connection" . $connection->getRemoteAddress() . " connected . ");
            new \MediaServer\Rtmp\RtmpStream($connection);
        });
        logger()->info("rtmp server start " . $rtmpServer->getAddress() . " start . ");
    }

    public function createHttpServer()
    {
        $server = new React\Http\HttpServer(
            new React\Http\Middleware\StreamingRequestMiddleware(),
            function (Psr\Http\Message\ServerRequestInterface $request, $next) {
                logger()->info("{method} {path}", ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath()]);
                $p = explode('.', $request->getUri()->getPath());
                if (end($p) !== 'flv') {
                    return new Response(404, [], 'failed path.');
                }
                return $next($request);
            },
            function (Psr\Http\Message\ServerRequestInterface $request, $next) {
                switch ($request->getMethod()) {
                    case "GET":
                        $path = $request->getUri()->getPath();

                        $playerStream = new \MediaServer\Flv\FlvPlayStream($throughStream = new ThroughStream(), $path);

                        $disableAudio = $request->getQueryParams()['disableAudio'] ?? false;
                        if ($disableAudio) {
                            $playerStream->setEnableAudio(false);
                        }

                        $disableVideo = $request->getQueryParams()['disableVideo'] ?? false;
                        if ($disableVideo) {
                            $playerStream->setEnableVideo(false);
                        }

                        $disableGop = $request->getQueryParams()['disableGop'] ?? false;
                        if ($disableGop) {
                            $playerStream->setEnableGop(false);
                        }


                        Loop::futureTick(function () use ($playerStream, $path) {
                            MediaServer::addPlayer($playerStream);
                        });

                        $response = new React\Http\Message\Response(
                            200,
                            array(
                                'Cache-Control' => 'no-cache',
                                'Content-Type' => 'video/x-flv',
                                'Access-Control-Allow-Origin' => '*',
                                'Connection' => 'keep-alive'
                            ),
                            $throughStream
                        );

                        return $response;
                    case "POST":
                        return $next($request);
                    case "HEAD":
                        return $response = new React\Http\Message\Response(
                            200
                        );
                    default:
                        logger()->warning("unknown method", ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath()]);
                        return new \React\Http\Message\Response(405);
                }
            },
            function (Psr\Http\Message\ServerRequestInterface $request) {

                $path = $request->getUri()->getPath();
                $bodyStream = $request->getBody();
                assert($bodyStream instanceof Psr\Http\Message\StreamInterface);
                assert($bodyStream instanceof React\Stream\ReadableStreamInterface);


                return new React\Promise\Promise(function ($resolve, $reject) use ($bodyStream, $path) {
                    $flvReadStream = new \MediaServer\Flv\FlvPublisherStream(
                        $bodyStream,
                        $path
                    );
                    //http 推流
                    if (MediaServer::addPublish($flvReadStream)) {
                        logger()->info("stream {path} created", ['path' => $path]);
                        $flvReadStream->on('on_end', function () use ($resolve) {
                            $resolve(new Response(200));
                        });
                        $flvReadStream->on('error', function (Exception $exception) use ($resolve, &$bytes) {
                            $resolve(new React\Http\Message\Response(
                                400,
                                array(
                                    'Content-Type' => 'text/plain'
                                ),
                                $exception->getMessage()
                            ));
                        });
                    } else {
                        logger()->warning("stream {path} exists", ['path' => $path]);
                        $resolve(new React\Http\Message\Response(
                            400,
                            array(
                                'Content-Type' => 'text/plain'
                            ),
                            "stream {$path} exists."
                        ));
                    }
                });

            }
        );
        $socket = new React\Socket\SocketServer('tcp://127.0.0.1:18080');
        $server->listen($socket);
        logger()->info("server " . $socket->getAddress() . " start . ");
    }

    /**
     * @throws Exception
     */
    public function daemon()
    {
        if (!$this->is_daemon || IS_WIN) {
            return;
        }
        \umask(0);
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new Exception('fork fail');
        } elseif ($pid > 0) {
            exit(0);
        }
        if (-1 === \posix_setsid()) {
            throw new Exception("setsid fail");
        }
        // Fork again avoid SVR4 system regain the control of terminal.
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new Exception("fork fail");
        } elseif (0 !== $pid) {
            exit(0);
        }
    }

    /**
     * Save pid.
     *
     * @throws Exception
     */
    protected function savePid()
    {
        if (IS_WIN) {
            return;
        }

        static::$master_pid = \posix_getpid();
        if (false === \file_put_contents(static::$pid_file, static::$master_pid)) {
            throw new Exception('can not save pid to ' . static::$pid_file);
        }
    }
}


try {
    (new Main())->run();
} catch (Throwable $e) {

}

