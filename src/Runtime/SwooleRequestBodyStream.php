<?php

declare(strict_types=1);

namespace MediaServer\Runtime;

use Evenement\EventEmitter;

/**
 * Swoole POST 推流请求体适配器。
 *
 * Workerman 版通过 React 风格 ReadableStreamInterface 以 'data'/'close' 事件
 * 喂给 FlvPublisherStream；Swoole 的请求体在 onRequest 时已完整到达，
 * 这里以同一套事件语义一次性投递。
 */
class SwooleRequestBodyStream extends EventEmitter
{
    public function emitData(string $data): void
    {
        $this->emit('data', [$data]);
    }

    public function emitClose(): void
    {
        $this->emit('close');
    }
}
