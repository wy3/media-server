<?php

declare(strict_types=1);

use Workerman\Connection\TcpConnection;

/**
 * 进程内假 TCP 连接：捕获 send 输出，收发都不触碰真实 socket 与事件循环。
 * 仅覆盖 WMBufferStream / RtmpStream 管线实际用到的接口。
 */
class FakeTcpConnection extends TcpConnection
{
    /** @var array<int, string> 捕获的下行数据 */
    public array $sent = [];

    public bool $closed = false;

    /** 模拟 Workerman 的连接级 recv buffer：input 喂的是累积缓冲 */
    public string $pendingRecv = '';

    public function __construct($socket)
    {
        parent::__construct($socket, '127.0.0.1:0');
    }

    /** 等价于 Workerman 收到 TCP 数据：累积后整体交给协议层 */
    public function feed(string $data): void
    {
        $this->pendingRecv .= $data;
        \MediaServer\Utils\WMBufferStream::input($this->pendingRecv, $this);
    }

    public function send($send_buffer, $raw = false)
    {
        $this->sent[] = (string)$send_buffer;
        return true;
    }

    public function consumeRecvBuffer($length)
    {
        // 消费已处理字节数，剩余部分保留到下次 input
        $this->pendingRecv = substr($this->pendingRecv, $length);
    }

    public function close($data = null, $raw = false)
    {
        $this->closed = true;
    }

    public function destroy(bool $force = false)
    {
        $this->closed = true;
    }

    public function getRemoteAddress(): string
    {
        return '127.0.0.1:12345';
    }

    public function getLocalAddress(): string
    {
        return '127.0.0.1:1935';
    }
}
