<?php

declare(strict_types=1);

namespace MediaServer\Contracts;

use Evenement\EventEmitterInterface;

/**
 * RTMP 协议层与底层运行时（Workerman / Swoole）之间的字节流契约。
 *
 * 实现方持有真实连接，协议层通过本接口收发字节，不感知运行时类型。
 * 事件约定（沿用 WMBufferStream）：
 *  - onData(array $self)   收到一段原始字节并已重置内部缓冲
 *  - onClose()             连接已关闭
 *  - onError()             连接发生错误
 */
interface BufferStreamInterface extends EventEmitterInterface
{
    /** 将一段收到的原始字节置入缓冲（覆盖式），供协议层重组 */
    public function recvBuffer(string $data): self;

    /** 当前缓冲内未处理字节数 */
    public function recvSize(): int;

    /** 本轮已处理的字节数 */
    public function handledSize(): int;

    /** 通知底层清空已处理部分（Workerman 语义；Swoole 实现为 no-op） */
    public function clearConnectionRecvBuffer(): void;

    /** 写出原始字节（等价 TcpConnection::send($data, true)） */
    public function send(string $data): bool;

    /** 关闭底层连接 */
    public function close(): void;

    /** 累计收到的字节数 */
    public function getBytesRead(): int;

    /** 远端地址，形如 127.0.0.1:12345；不可得时返回空串 */
    public function getRemoteAddress(): string;
}
