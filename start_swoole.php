<?php

declare(strict_types=1);

/**
 * Swoole 运行时入口（对齐 start.php 的 Workerman 行为）。
 *
 *   php start_swoole.php
 *   RTMP: 0.0.0.0:1935（原始 TCP）
 *   HTTP: 127.0.0.1:18080（HTTP + WebSocket 升级，原生协议解析）
 *
 * 仅支持 Linux/macOS（Swoole 无 Windows 版本）。单 worker 进程，
 * 保持与 Workerman 版一致的静态共享状态语义；关闭协程以维持回调语义。
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';

if (!extension_loaded('swoole')) {
    fwrite(STDERR, "ext-swoole is required (use start.php for workerman runtime)\n");
    exit(1);
}

use MediaServer\Admin\AdminAuth;
use MediaServer\Recorder\RecorderManager;
use MediaServer\Runtime\SwooleBufferStream;
use MediaServer\Runtime\SwooleHttpApp;
use MediaServer\Runtime\SwoolePlaybackServer;
use MediaServer\Utils\RuntimeTimer;
use MediaServer\Rtmp\RtmpStream;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

//录像配置（与 start.php 一致）
RecorderManager::$enabled = true;
RecorderManager::$recordPath = __DIR__ . '/record';
RecorderManager::$fragmentDurationMs = 2000;
RecorderManager::$segmentDurationMs = 60000;

//管理后台账号配置
AdminAuth::$username = 'admin';
AdminAuth::$password = 'admin123';
AdminAuth::$startTime = timestamp();

//运行时选择
RuntimeTimer::$driver = 'swoole';
SwooleHttpApp::$publicPath = __DIR__ . '/public';

$server = new Swoole\Server('0.0.0.0', 1935, SWOOLE_BASE, SWOOLE_TCP);
$server->set([
    'worker_num' => 1,           //单进程：静态流表依赖进程内共享状态
    'enable_coroutine' => false, //保持与 Workerman 一致的回调语义
    'log_level' => SWOOLE_LOG_WARNING,
    'max_queued_bytes' => 32 * 1024 * 1024,
]);

//HTTP + WebSocket 端口（原生协议解析，替代 ExtHttpProtocol）
$httpPort = $server->addListener('127.0.0.1', 18080, SWOOLE_SOCK_TCP);
$httpPort->set([
    'open_http_protocol' => true,
    'open_websocket_protocol' => true,
    'max_package_size' => 64 * 1024 * 1024, //对齐 Workerman 版 POST 推流上限语义
]);

/** @var array<int, SwooleBufferStream> $rtmpStreams fd → RTMP 字节流 */
$rtmpStreams = [];
$app = new SwooleHttpApp($server);

$server->on('start', function (Swoole\Server $serv): void {
    logger()->info('swoole runtime start: rmp tcp://0.0.0.0:1935, http 127.0.0.1:18080');
});

$server->on('connect', function (Swoole\Server $serv, int $fd): void {
    logger()->info('connection ' . json_encode($serv->getClientInfo($fd, -1, true)['server_port'] ?? '') . ' fd=' . $fd);
});

//RTMP 数据（仅 1935 端口的数据会进入 onReceive；18080 走 HTTP 协议解析）
$server->on('receive', function (Swoole\Server $serv, int $fd, int $reactorId, string $data) use (&$rtmpStreams): void {
    if (!isset($rtmpStreams[$fd])) {
        $stream = new SwooleBufferStream($serv, $fd);
        $rtmpStreams[$fd] = $stream;
        new RtmpStream($stream);
        logger()->info('rtmp connection ' . $stream->getRemoteAddress() . ' fd=' . $fd);
    }
    $rtmpStreams[$fd]->feed($data);
});

$server->on('close', function (Swoole\Server $serv, int $fd) use (&$rtmpStreams, $app): void {
    if (isset($rtmpStreams[$fd])) {
        $stream = $rtmpStreams[$fd];
        unset($rtmpStreams[$fd]);
        $stream->serverClose();
        return;
    }
    SwoolePlaybackServer::abortByServer($fd);
    $app->onServerClose($fd);
});

//HTTP 路由（从 HttpWMServer 平移）
$httpPort->on('request', function (SwooleRequest $req, SwooleResponse $res) use ($app): void {
    $app->handleRequest($req, $res, (int)$res->fd);
});

//WebSocket 握手：手工完成，以便在升级前拒绝非 FLV 路径
$httpPort->on('handshake', function (SwooleRequest $req, SwooleResponse $res) use ($app): void {
    $app->onHandshake($req, $res, (int)$res->fd);
});

$httpPort->on('message', function (Swoole\Server $serv, Swoole\WebSocket\Frame $frame) use ($app): void {
    $app->onWsMessage((int)$frame->fd, (string)$frame->data);
});

//回放背压：发送缓冲排空后恢复（Swoole 服务器级事件，按 fd 关联）
$server->on('bufferEmpty', function (Swoole\Server $serv, int $fd): void {
    SwoolePlaybackServer::resumeByServer($serv, $fd);
});

$server->start();
