<?php

declare(strict_types=1);

namespace MediaServer\Runtime;

use Evenement\EventEmitterTrait;
use MediaServer\Utils\WMChunkStreamInterface;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server;

/**
 * Swoole 版 HTTP-FLV chunked 响应流（对应 Workerman 的 WMHttpChunkStream）。
 *
 * 首次 write 自动携带响应头，后续 write 走 Swoole chunked 输出。
 * 连接关闭事件由服务器 onClose 统一触发（见 SwooleHttpApp::onServerClose）。
 */
class SwooleHttpChunkStream implements WMChunkStreamInterface
{
    use EventEmitterTrait;

    protected Server $server;
    protected int $fd;
    protected SwooleResponse $response;
    protected bool $sendHeader = false;
    protected bool $closed = false;

    public function __construct(Server $server, int $fd, SwooleResponse $response)
    {
        $this->server = $server;
        $this->fd = $fd;
        $this->response = $response;
    }

    /** 连接已关闭（由服务器 onClose 调用） */
    public function onServerClose(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->emit('close');
        $this->removeAllListeners();
    }

    public function write(string $data): void
    {
        if ($this->closed) {
            return;
        }
        if (!$this->sendHeader) {
            $this->sendHeader = true;
            $this->response->status(200);
            $this->response->header('Cache-Control', 'no-cache');
            $this->response->header('Content-Type', 'video/x-flv');
            $this->response->header('Access-Control-Allow-Origin', '*');
            $this->response->header('Connection', 'keep-alive');
            // 不设 Content-Length，Swoole write() 自动使用 chunked 传输
            $this->response->write($data);
            return;
        }
        $this->response->write($data);
    }

    public function end(?string $data = null): void
    {
        if ($this->closed) {
            return;
        }
        if (!$this->sendHeader) {
            // 未写过任何数据就结束：发一个空 chunked 响应保持头部语义
            $this->write('');
        }
        $this->response->end();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->server->close($this->fd);
    }
}
