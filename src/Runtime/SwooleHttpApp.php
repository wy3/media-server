<?php

declare(strict_types=1);

namespace MediaServer\Runtime;

use MediaServer\Admin\AdminAuth;
use MediaServer\Flv\FlvPlayStream;
use MediaServer\Flv\FlvPublisherStream;
use MediaServer\MediaServer;
use MediaServer\Utils\WMChunkStreamInterface;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server;

/**
 * Swoole 版 HTTP 路由与应用逻辑。
 *
 * 路由行为从 Workerman 版 MediaServer\Http\HttpWMServer 平移而来：
 *   GET  /api?name=&args=       → MediaServer::callApi
 *   GET  /admin                 → 管理后台静态入口
 *   GET  /playback/{path}       → 指定时间回放（SwoolePlaybackServer）
 *   GET  /*.flv                 → HTTP-FLV 拉流
 *   WS   握手 *.flv             → WS-FLV 拉流（二进制帧）
 *   POST /*（publish）          → FLV 推流（on_end 后返回 200）
 *   其余                         → public 静态文件 / 404
 *
 * Swoole 原生解析 HTTP 并完成 WebSocket 升级，ExtHttpProtocol 不再需要。
 */
class SwooleHttpApp
{
    static string $publicPath = '';

    /** @var array<int, WMChunkStreamInterface> fd → 活跃的 FLV 输出流（onClose 清理用） */
    protected array $streams = [];

    /** @var array<int, SwooleRequestBodyStream> fd → POST 推流输入流 */
    protected array $publishInputs = [];

    public function __construct(protected Server $server)
    {
    }

    /** fd 上绑定的活跃输出流（onClose 清理用） */
    public function bindStream(int $fd, WMChunkStreamInterface $stream): void
    {
        $this->streams[$fd] = $stream;
    }

    public function getStream(int $fd): ?WMChunkStreamInterface
    {
        return $this->streams[$fd] ?? null;
    }

    // ------------------------------------------------------------------ HTTP

    public function handleRequest(SwooleRequest $req, SwooleResponse $res, int $fd): void
    {
        $method = strtoupper((string)($req->server['request_method'] ?? 'GET'));
        $path = (string)($req->server['request_uri'] ?? '/');

        switch ($method) {
            case 'GET':
                $this->getHandler($req, $res, $fd, $path);
                return;
            case 'POST':
                $this->postHandler($req, $res, $fd, $path);
                return;
            case 'HEAD':
                $res->status(200);
                $res->end();
                return;
            default:
                logger()->warning('unknown method', ['method' => $method, 'path' => $path]);
                $res->status(405);
                $res->end();
                return;
        }
    }

    protected function getHandler(SwooleRequest $req, SwooleResponse $res, int $fd, string $path): void
    {
        //api
        if ($path === '/api') {
            $name = (string)$this->queryGet($req, 'name', '');
            $args = $this->queryGet($req, 'args', []);
            if (is_string($args)) {
                $decoded = json_decode($args, true);
                $args = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($args)) {
                $args = [];
            }
            if ($name !== 'login' && !$this->isAuthorized($req)) {
                $this->json($res, 401, ['code' => 401, 'msg' => 'Unauthorized']);
                return;
            }
            $data = MediaServer::callApi($name, $args);
            if ($data === false) {
                $res->status(404);
                $res->end('404 Not Found');
            } elseif ($name === 'login' && $data === null) {
                $this->json($res, 401, ['code' => 401, 'msg' => '用户名或密码错误']);
            } else {
                $this->json($res, 200, $data);
            }
            return;
        }

        //admin 管理后台入口
        if ($path === '/admin' || $path === '/admin/') {
            if ($this->serveFile($res, self::$publicPath . '/admin/index.html')) {
                return;
            }
        }

        //playback (指定时间回放)
        if (strpos($path, '/playback/') === 0) {
            $recordPath = substr($path, strlen('/playback/'));
            SwoolePlaybackServer::bindServer($this->server);
            SwoolePlaybackServer::servePlayback($req, $res, $fd, $recordPath);
            return;
        }

        //flv / 静态文件
        if (
            $this->unsafeUri($res, $path) ||
            $this->findFlv($req, $res, $fd, $path) ||
            $this->findStaticFile($res, $path)
        ) {
            return;
        }

        $res->status(404);
        $res->end('404 Not Found');
    }

    protected function postHandler(SwooleRequest $req, SwooleResponse $res, int $fd, string $path): void
    {
        //api (POST JSON)
        if ($path === '/api') {
            $raw = (string)$req->rawContent();
            $body = json_decode($raw === '' ? '{}' : $raw, true);
            if (!is_array($body)) {
                $this->json($res, 400, ['code' => 400, 'msg' => 'Invalid JSON']);
                return;
            }
            $name = (string)($body['name'] ?? '');
            $args = $body['args'] ?? [];
            if (!is_array($args)) {
                $args = [];
            }
            if ($name !== 'login' && !$this->isAuthorized($req)) {
                $this->json($res, 401, ['code' => 401, 'msg' => 'Unauthorized']);
                return;
            }
            $data = MediaServer::callApi($name, $args);
            if ($data === false) {
                $res->status(404);
                $res->end('404 Not Found');
            } elseif ($name === 'login' && $data === null) {
                $this->json($res, 401, ['code' => 401, 'msg' => '用户名或密码错误']);
            } else {
                $this->json($res, 200, $data);
            }
            return;
        }

        if (MediaServer::hasPublishStream($path)) {
            logger()->warning('Stream {path} exists', ['path' => $path]);
            $res->status(400);
            $res->end("Stream {$path} exists.");
            return;
        }

        //POST 推流：Swoole 已缓存完整请求体，以事件流形式喂给 FlvPublisherStream
        $input = new SwooleRequestBodyStream();
        $this->publishInputs[$fd] = $input;

        $flvReadStream = new FlvPublisherStream($input, $path);
        MediaServer::addPublish($flvReadStream);
        logger()->info('stream {path} created', ['path' => $path]);

        $flvReadStream->on('on_end', function () use ($res) {
            $res->status(200);
            $res->end();
        });
        $flvReadStream->on('error', function (\Exception $e) use ($res) {
            $res->status(400);
            $res->end($e->getMessage());
        });

        //立即投递请求体（Swoole 无分块回调；此处一次性喂入）
        $body = (string)$req->rawContent();
        if ($body !== '') {
            $input->emitData($body);
        }
    }

    // ------------------------------------------------------------------ WS

    /**
     * 手工完成 RFC6455 握手（不依赖 Swoole 自动升级，以便在握手前拒绝非 FLV 路径）。
     */
    public function onHandshake(SwooleRequest $req, SwooleResponse $res, int $fd): void
    {
        $path = (string)($req->server['request_uri'] ?? '/');
        if (!preg_match('/(.*)\.flv$/', $path)) {
            $res->status(404);
            $res->end('stream not found');
            return;
        }

        $key = (string)($req->header['sec-websocket-key'] ?? '');
        if ($key === '') {
            $res->status(400);
            $res->end();
            return;
        }
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $res->status(101);
        $res->header('Upgrade', 'websocket');
        $res->header('Connection', 'Upgrade');
        $res->header('Sec-WebSocket-Accept', $accept);
        $res->end();

        $this->setupWsPlayer($req, $fd, $path);
    }

    protected function setupWsPlayer(SwooleRequest $req, int $fd, string $path): void
    {
        if (!preg_match('/(.*)\.flv$/', $path, $matches)) {
            return;
        }
        $flvPath = $matches[1];
        if (!MediaServer::hasPublishStream($flvPath)) {
            logger()->warning('Stream {path} not found', ['path' => $flvPath]);
            $this->server->close($fd);
            return;
        }

        $throughStream = new SwooleWsChunkStream($this->server, $fd);
        $this->bindStream($fd, $throughStream);
        $playerStream = new FlvPlayStream($throughStream, $flvPath);

        if ($this->queryGet($req, 'disableAudio', false)) {
            $playerStream->setEnableAudio(false);
        }
        if ($this->queryGet($req, 'disableVideo', false)) {
            $playerStream->setEnableVideo(false);
        }
        if ($this->queryGet($req, 'disableGop', false)) {
            $playerStream->setEnableGop(false);
        }
        MediaServer::addPlayer($playerStream);
    }

    /** WebSocket 数据帧：FLV 播放是单向流，客户端帧（含 ping/pong）由 Swoole 协议层处理 */
    public function onWsMessage(int $fd, string $data): void
    {
    }

    // ------------------------------------------------------------------ 生命周期

    /**
     * 连接关闭（服务器 onClose 统一入口）：清理 FLV 流与推流输入。
     */
    public function onServerClose(int $fd): void
    {
        if (isset($this->streams[$fd])) {
            $stream = $this->streams[$fd];
            unset($this->streams[$fd]);
            if (method_exists($stream, 'onServerClose')) {
                $stream->onServerClose();
            }
        }
        if (isset($this->publishInputs[$fd])) {
            $input = $this->publishInputs[$fd];
            unset($this->publishInputs[$fd]);
            $input->emitClose();
        }
    }

    // ------------------------------------------------------------------ 工具

    protected function queryGet(SwooleRequest $req, string $key, mixed $default = ''): mixed
    {
        return isset($req->get[$key]) ? $req->get[$key] : $default;
    }

    protected function json(SwooleResponse $res, int $status, array $data): void
    {
        $res->status($status);
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode($data));
    }

    protected function isAuthorized(SwooleRequest $req): bool
    {
        $token = (string)$this->queryGet($req, 'token', '');
        if ($token === '') {
            $token = (string)($req->header['x-auth-token'] ?? '');
        }
        if ($token === '') {
            $auth = (string)($req->header['authorization'] ?? '');
            if (stripos($auth, 'bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        }
        return AdminAuth::check($token);
    }

    protected function unsafeUri(SwooleResponse $res, string $path): bool
    {
        if (
            !$path ||
            strpos($path, '..') !== false ||
            strpos($path, '\\') !== false ||
            strpos($path, "\0") !== false
        ) {
            $res->status(404);
            $res->end('404 Not Found.');
            return true;
        }
        return false;
    }

    protected function findFlv(SwooleRequest $req, SwooleResponse $res, int $fd, string $path): bool
    {
        if (!preg_match('/(.*)\.flv$/', $path, $matches)) {
            return false;
        }
        $this->playMediaStream($req, $res, $fd, $matches[1]);
        return true;
    }

    protected function playMediaStream(SwooleRequest $req, SwooleResponse $res, int $fd, string $flvPath): void
    {
        if (!MediaServer::hasPublishStream($flvPath)) {
            logger()->warning('Stream {path} not found', ['path' => $flvPath]);
            $res->status(404);
            $res->header('Content-Type', 'text/plain');
            $res->end('Stream not found.');
            return;
        }

        $throughStream = new SwooleHttpChunkStream($this->server, $fd, $res);
        $this->bindStream($fd, $throughStream);
        $playerStream = new FlvPlayStream($throughStream, $flvPath);

        if ($this->queryGet($req, 'disableAudio', false)) {
            $playerStream->setEnableAudio(false);
        }
        if ($this->queryGet($req, 'disableVideo', false)) {
            $playerStream->setEnableVideo(false);
        }
        if ($this->queryGet($req, 'disableGop', false)) {
            $playerStream->setEnableGop(false);
        }
        MediaServer::addPlayer($playerStream);
    }

    protected function findStaticFile(SwooleResponse $res, string $path): bool
    {
        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $path = urldecode($path);
            if ($this->unsafeUri($res, $path)) {
                return true;
            }
        }

        $file = self::$publicPath . "/$path";
        return $this->serveFile($res, $file);
    }

    protected function serveFile(SwooleResponse $res, string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'html', 'htm' => 'text/html',
            'js' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'mp4' => 'video/mp4',
            'flv' => 'video/x-flv',
            default => 'application/octet-stream',
        };
        $res->status(200);
        $res->header('Content-Type', $mime);
        // 不用 sendfile()：FUSE/网络文件系统上 sendfile 系统调用不可靠
        $res->end((string)file_get_contents($file));
        return true;
    }
}
