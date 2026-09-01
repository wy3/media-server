<?php

declare(strict_types=1);

namespace MediaServer\Runtime;

use Evenement\EventEmitterTrait;
use MediaServer\Contracts\BufferStreamInterface;
use MediaServer\Utils\BinaryStream;
use Swoole\Server;

/**
 * Swoole 运行时的 RTMP 字节流封装。
 *
 * 与 WMBufferStream 的事件语义完全一致（onData/onClose/onError），
 * 协议层 RtmpStream 无需感知运行时差异：
 *  - 收数据：由 Swoole onReceive 调 feed()（对齐 WMBufferStream::input 的三步流程）
 *  - 发数据：server->send(fd)
 *  - 关闭：由 Swoole onClose 事件调 serverClose()
 */
class SwooleBufferStream extends BinaryStream implements BufferStreamInterface
{
    use EventEmitterTrait;

    protected Server $server;
    protected int $fd;
    protected int $bytesRead = 0;
    protected bool $closed = false;

    public function __construct(Server $server, int $fd)
    {
        $this->server = $server;
        $this->fd = $fd;
        parent::__construct();
    }

    public function getFd(): int
    {
        return $this->fd;
    }

    /**
     * Swoole onReceive 投递原始字节（对齐 WMBufferStream::input 流程）。
     */
    public function feed(string $data): void
    {
        if ($this->closed) {
            return;
        }
        $this->bytesRead += strlen($data);
        $this->recvBuffer($data);
        $this->emit("onData", [$this]);
        $this->clearConnectionRecvBuffer();
    }

    /**
     * 底层连接已关闭（由服务器 onClose 事件调用）。
     */
    public function serverClose(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->emit("onClose");
        $this->removeAllListeners();
    }

    public function serverError(): void
    {
        $this->emit("onError");
    }

    public function recvBuffer(string $data): self
    {
        $this->_data = $data;
        return $this->begin();
    }

    public function recvSize(): int
    {
        return strlen($this->_data);
    }

    public function handledSize(): int
    {
        return $this->_index;
    }

    public function clearConnectionRecvBuffer(): void
    {
        // Swoole 按回调投递数据，无连接级接收缓冲需要清理
    }

    public function send(string $data): bool
    {
        if ($this->closed || !$this->server->exists($this->fd)) {
            return false;
        }
        return (bool)$this->server->send($this->fd, $data);
    }

    public function close(): void
    {
        if (!$this->closed && $this->server->exists($this->fd)) {
            $this->server->close($this->fd);
        }
    }

    public function getBytesRead(): int
    {
        return $this->bytesRead;
    }

    public function getRemoteAddress(): string
    {
        $info = $this->server->getClientInfo($this->fd);
        $ip = (string)($info['remote_ip'] ?? '');
        $port = (int)($info['remote_port'] ?? 0);
        return $ip !== '' ? "{$ip}:{$port}" : '';
    }
}
