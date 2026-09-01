<?php

declare(strict_types=1);

namespace MediaServer\Runtime;

use Evenement\EventEmitterTrait;
use MediaServer\Utils\WMChunkStreamInterface;
use Swoole\Server;

/**
 * Swoole 版 WebSocket-FLV 输出流（对应 Workerman 的 WMWsChunkStream）。
 *
 * 握手由 SwooleHttpApp::onHandshake 完成，此后数据帧走 server->push。
 * 连接关闭事件由服务器 onClose 统一触发。
 */
class SwooleWsChunkStream implements WMChunkStreamInterface
{
    use EventEmitterTrait;

    protected Server $server;
    protected int $fd;
    protected bool $closed = false;

    public function __construct(Server $server, int $fd)
    {
        $this->server = $server;
        $this->fd = $fd;
    }

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
        // FLV 是二进制流，一律以二进制帧推送
        $this->server->push($this->fd, $data, WEBSOCKET_BINARY_FRAME);
    }

    public function end(?string $data = null): void
    {
        // WS 无 chunk 终止语义，保持连接由上层决定关闭时机
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
