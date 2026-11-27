<?php

declare(strict_types=1);

namespace MediaServer\Http;

use React\Promise\PromiseInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

/**
 * HTTP 服务基类：仅协议分发（GET/POST/HEAD）。
 * 路由与业务全部在子类：
 *   IngestHttpServer — HTTP-FLV 推流（POST /live/x）
 *   PlayHttpServer   — HTTP-FLV/WS-FLV 播放（GET .flv，含 WebSocket 接入）+ 静态文件
 *   AdminHttpServer  — 管理后台 + /api + 录像回放 + 静态文件
 */
abstract class HttpWMServer extends Worker
{
    static string $publicPath = '';

    public function __construct(int $httpPort, array $context_option = [])
    {
        $new_socket_name = "\\MediaServer\\Http\\ExtHttpProtocol://0.0.0.0:$httpPort";
        parent::__construct($new_socket_name, $context_option);
        $this->onMessage = [$this, 'onHttpRequest'];
        // 用来预留http 升级到websocket 进行函数处理
        $this->onWebSocketConnect = [$this, 'onWebsocketRequest'];
    }

    public function onWebsocketRequest(TcpConnection $connection, string $headerData): void
    {
        // 子类可覆盖实现
    }

    public function getHandler(Request $request):void
    {
        $request->connection->send(new Response(405));
    }

    public function postHandler(Request $request):void
    {
        $request->connection->send(new Response(405));
    }

    /**
     * @param TcpConnection $connection
     * @param Request $request
     */
    public function onHttpRequest(TcpConnection $connection, Request $request): void
    {
        switch ($request->method()) {
            case 'GET':
                $this->getHandler($request);
                break;
            case 'POST':
                $this->postHandler($request);
                break;
            case 'HEAD':
                $connection->send(new Response(200));
                break;
            default:
                logger()->warning('unknown method', ['method' => $request->method(), 'path' => $request->path()]);
                $connection->send(new Response(405));
                break;
        }
    }
}
