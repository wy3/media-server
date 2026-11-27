<?php

declare(strict_types=1);

namespace MediaServer\Http;

use Evenement\EventEmitter;
use Workerman\Protocols\Http\Request;

/**
 * HTTP 请求体的持续流封装。
 *
 * 协议层每收到一段原始 chunked 字节就调用 feed()，内部增量解析 chunk 边界，
 * 每解析出一个完整 chunk 立即 emit('data')；0-chunk 时 emit('end')/emit('close')。
 */
class RequestBodyStream extends EventEmitter
{
    private ?Request $request;
    private string $buffer = '';
    private bool $ended = false;

    public function __construct(?Request $request = null)
    {
        $this->request = $request;
    }

    public function getRequest(): ?Request
    {
        return $this->request;
    }

    /**
     * 推入原始 chunked 字节，增量解析并立即把完整 chunk 作为 data 事件发出。
     */
    public function feed(string $data): void
    {
        if ($this->ended || $data === '') {
            return;
        }
        $this->buffer .= $data;

        while (true) {
            $lineEnd = \strpos($this->buffer, "\r\n");
            if (false === $lineEnd) {
                return;
            }

            $semiPos = \strpos($this->buffer, ';');
            $hexEnd = (false !== $semiPos && $semiPos < $lineEnd) ? $semiPos : $lineEnd;
            $hexStr = \trim(\substr($this->buffer, 0, $hexEnd));
            if ($hexStr === '' || !\ctype_xdigit($hexStr) || isset($hexStr[16])) {
                $this->fail(new \RuntimeException('Invalid chunk size line.'));
                return;
            }

            $chunkSize = \hexdec($hexStr);
            $pos = $lineEnd + 2;

            if ($chunkSize === 0) {
                $trailerEnd = \strpos($this->buffer, "\r\n\r\n", $pos);
                if (false === $trailerEnd) {
                    $directEnd = \strpos($this->buffer, "\r\n", $pos);
                    if (false !== $directEnd && $directEnd === $pos) {
                        $this->buffer = \substr($this->buffer, $pos + 2);
                        $this->end();
                        return;
                    }
                    return;
                }
                $this->buffer = \substr($this->buffer, $trailerEnd + 4);
                $this->end();
                return;
            }

            if (\strlen($this->buffer) < $pos + $chunkSize + 2) {
                return;
            }
            if (\substr($this->buffer, $pos + $chunkSize, 2) !== "\r\n") {
                $this->fail(new \RuntimeException('Invalid chunk terminator.'));
                return;
            }

            $payload = \substr($this->buffer, $pos, $chunkSize);
            $this->buffer = \substr($this->buffer, $pos + $chunkSize + 2);
            $this->push($payload);
        }
    }

    /**
     * 推入一段已经解码好的 body 数据。
     */
    public function push(string $data): void
    {
        if ($this->ended || $data === '') {
            return;
        }
        $this->emit('data', [$data]);
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->emit('end');
        $this->emit('close');
    }

    public function fail(\Throwable $e): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->emit('error', [$e]);
        $this->emit('close');
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    /**
     * 一次性请求（Content-Length）使用：把 Request 的 body 推出去并结束。
     */
    public function read(): string
    {
        $body = $this->request?->rawBody() ?? '';
        $this->push($body);
        $this->end();
        return $body;
    }
}
