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
        // FLV 是二进制流，手动封装 RFC6455 二进制帧后用 send() 发送
        // （Swoole\Server 原生类没有 push()，18080 端口手动握手后需自管帧协议）
        try {
            $ok = $this->server->send($this->fd, $this->buildBinaryFrame($data));
            if ($ok === false) {
                // send 失败：fd 可能已断，交由 onClose 清理
                $this->close();
            }
        } catch (\Throwable $e) {
            // 发送异常（如 fd 已不在连接状态），记录并关闭，避免拖垮进程
            \logger()->warning('ws send fail: {msg}', ['msg' => $e->getMessage()]);
            $this->close();
        }
    }

    /** 构造 RFC6455 服务端→客户端二进制帧（服务端不掩码） */
    protected function buildBinaryFrame(string $payload): string
    {
        $len = strlen($payload);
        $frame = "\x82"; // FIN + opcode 0x2 (binary)
        if ($len <= 125) {
            $frame .= chr($len);
        } elseif ($len <= 0xFFFF) {
            $frame .= chr(126) . pack('n', $len);
        } else {
            $frame .= chr(127) . pack('J', $len);
        }
        return $frame . $payload;
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
