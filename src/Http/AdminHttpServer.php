<?php

declare(strict_types=1);

namespace MediaServer\Http;

use MediaServer\Admin\AdminAuth;
use MediaServer\MediaServer;
use MediaServer\Recorder\PlaybackServer;
use React\Promise\PromiseInterface;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * admin 进程的 HTTP 入口：管理后台 + /api + 录像回放。
 *
 * /api 在本进程处理：login/logout 本地（AdminAuth 进程内会话）；
 * 注册表/统计类异步代理到 control（AdminApiProxy）。
 * 录像回放（/playback/）本进程处理：持有 record 目录与 recordings.db。
 * 无直播流状态：拒绝推流与直播播放请求。
 */
class AdminHttpServer extends HttpWMServer
{
    public function getHandler(Request $request): void
    {
        $path = $request->path();

        if ($path === '/api') {
            $this->handleApi($request);
            return;
        }
        if ($path === '/admin' || $path === '/admin/') {
            $this->sendAdminIndex($request);
            return;
        }
        // 录像回放（admin 进程持有 record 目录与 recordings.db 索引）
        if (strpos($path, '/playback/') === 0) {
            PlaybackServer::handlePlaybackRequest($request, substr($path, strlen('/playback/')));
            return;
        }
        if ($this->findStaticFile($request, $path)) {
            return;
        }
        $request->connection->send(new Response(404, [], '404 Not Found'));
    }

    public function postHandler(Request $request): void
    {
        if ($request->path() === '/api') {
            fwrite(STDERR, '[admin-http] POST /api role=' . (MediaServer::$processRole ?? 'null') . "\n");
            $this->handleApiPost($request);
            return;
        }
        $request->connection->send(new Response(404, [], '404 Not Found'));
    }

    // ── API 处理（admin 进程专属） ──

    /**
     * GET /api（query 参数形态）。
     */
    protected function handleApi(Request $request): void
    {
        $name = (string)$request->get('name', '');
        $args = $request->get('args', []);
        if (is_string($args)) {
            $decoded = json_decode($args, true);
            $args = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($args)) {
            $args = [];
        }
        //除 login 外均需鉴权
        if ($name !== 'login' && !self::isAuthorized($request)) {
            $request->connection->send(new Response(401, ['Content-Type' => 'application/json'], json_encode(['code' => 401, 'msg' => 'Unauthorized'])));
            return;
        }
        // 注册表/统计类异步代理到 control；login/logout 本地处理
        if (!in_array($name, ['login', 'logout'], true)) {
            MediaServer::adminApiProxyAsync($name, $args, function ($data) use ($request) {
                if ($data === false) {
                    $request->connection->send(new Response(404, [], '404 Not Found'));
                } else {
                    $request->connection->send(new Response(200, ['Content-Type' => 'application/json'], json_encode($data)));
                }
            });
            return;
        }
        $data = MediaServer::callApi($name, $args);
        if ($data === false) {
            $request->connection->send(new Response(404, [], '404 Not Found'));
        } elseif ($name === 'login' && $data === null) {
            $request->connection->send(new Response(401, ['Content-Type' => 'application/json'], json_encode(['code' => 401, 'msg' => '用户名或密码错误'])));
        } else {
            $request->connection->send(new Response(200, ['Content-Type' => 'application/json'], json_encode($data)));
        }
    }

    /**
     * POST /api（JSON body 形态）。
     */
    protected function handleApiPost(Request $request): void
    {
        $raw = $request->rawBody();
        $body = json_decode($raw === '' ? '{}' : $raw, true);
        if (!is_array($body)) {
            $request->connection->send(new Response(400, ['Content-Type' => 'application/json'], json_encode(['code' => 400, 'msg' => 'Invalid JSON'])));
            return ;
        }
        $name = (string)($body['name'] ?? '');
        $args = $body['args'] ?? [];
        if (!is_array($args)) {
            $args = [];
        }
        if ($name !== 'login' && !self::isAuthorized($request)) {
            $request->connection->send(new Response(401, ['Content-Type' => 'application/json'], json_encode(['code' => 401, 'msg' => 'Unauthorized'])));
            return ;
        }
        fwrite(STDERR, '[admin-http] handleApiPost name=' . $name . "\n");
        // 注册表/统计类异步代理到 control；login/logout 本地处理
        if (!in_array($name, ['login', 'logout'], true)) {
            fwrite(STDERR, "[admin-http] branch: proxy to control\n");
            MediaServer::adminApiProxyAsync($name, $args, function ($data) use ($request) {
                if ($data === false) {
                    $request->connection->send(new Response(404, [], '404 Not Found'));
                } else {
                    $request->connection->send(new Response(200, ['Content-Type' => 'application/json'], json_encode($data)));
                }
            });
            return ;
        }
        $data = MediaServer::callApi($name, $args);
        if ($data === false) {
            $request->connection->send(new Response(404, [], '404 Not Found'));
        } elseif ($name === 'login' && $data === null) {
            $request->connection->send(new Response(401, ['Content-Type' => 'application/json'], json_encode(['code' => 401, 'msg' => '用户名或密码错误'])));
        } else {
            $request->connection->send(new Response(200, ['Content-Type' => 'application/json'], json_encode($data)));
        }
        return ;
    }

    /**
     * 校验请求是否携带有效管理 token。
     * 支持 X-Auth-Token / Authorization: Bearer 头或 query 参数 token。
     */
    protected static function isAuthorized(Request $request): bool
    {
        $token = (string)$request->get('token', '');
        if ($token === '') {
            $token = (string)$request->header('x-auth-token', '');
        }
        if ($token === '') {
            $auth = (string)$request->header('authorization', '');
            if (stripos($auth, 'bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        }
        return AdminAuth::check($token);
    }

    /**
     * 管理后台首页。
     */
    protected function sendAdminIndex(Request $request): void
    {
        $file = self::$publicPath . '/admin/index.html';
        if (is_file($file)) {
            $request->connection->send((new Response())->withFile($file));
            return;
        }
        $request->connection->send(new Response(404, [], '404 Not Found'));
    }

    // ── admin 专属工具 ──

    public function unsafeUri(Request $request, string $path): bool
    {
        if (
            !$path ||
            strpos($path, '..') !== false ||
            strpos($path, '\\') !== false ||
            strpos($path, "\0") !== false
        ) {
            $request->connection->send(new Response(404, [], '404 Not Found.'));
            return true;
        }
        return false;
    }

    public function findStaticFile(Request $request, string $path): bool
    {
        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $path = urldecode($path);
            if ($this->unsafeUri($request, $path)) {
                return true;
            }
        }

        $file = self::$publicPath . "/$path";
        if (!is_file($file)) {
            return false;
        }

        $request->connection->send((new Response())->withFile($file));
        return true;
    }
}
