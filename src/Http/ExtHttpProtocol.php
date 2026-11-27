<?php

declare(strict_types=1);

namespace MediaServer\Http;


use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Websocket;

class ExtHttpProtocol extends Http
{
    /**
     * 识别一个 HTTP 包的长度。
     *
     * 非 chunked 请求沿用父类逻辑；POST chunked 请求只返回 header 长度，
     * 让 header 先经 decode 生成带 bodyStream 的 StreamRequest；
     * 后续到达的字节在 body 阶段直接喂给该 stream，不再形成 HTTP 包。
     */
    public static function input($recv_buffer, TcpConnection $connection)
    {
        // body 阶段：chunked 字节直接喂给 Request 携带的 bodyStream
        $bodyStream = $connection->context->bodyStream ?? null;
        if ($bodyStream !== null) {
            if (!$bodyStream->isEnded()) {
                $bodyStream->feed($recv_buffer);
                $connection->consumeRecvBuffer(\strlen($recv_buffer));
            }
            if ($bodyStream->isEnded()) {
                unset($connection->context->bodyStream);
            }
            return 0;
        }

        $crlf_pos = \strpos($recv_buffer, "\r\n\r\n");
        if (false === $crlf_pos) {
            if (\strlen($recv_buffer) >= 16384) {
                $connection->close("HTTP/1.1 413 Request Entity Too Large\r\n\r\n", true);
                return 0;
            }
            return 0;
        }

        $length = $crlf_pos + 4;
        $method = \strstr($recv_buffer, ' ', true);

        if (!\in_array($method, ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'])) {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            return 0;
        }

        $header = \substr($recv_buffer, 0, $crlf_pos);

        if (\preg_match("/\r\nUpgrade: websocket/i", $header)) {
            $connection->protocol = Websocket::class;
            return Websocket::input($recv_buffer, $connection);
        }

        // POST chunked：只返回 header 长度，进入 decode 生成带 bodyStream 的 StreamRequest
        if ($method === 'POST' && \preg_match('~\r\nTransfer-Encoding[ \t]*:~i', $header)) {
            if (
                \preg_match('~\r\nContent-Length[ \t]*:~i', $header)
                || \preg_match_all('~\r\nTransfer-Encoding[ \t]*:~i', $header) !== 1
                || !\preg_match('~\r\nTransfer-Encoding[ \t]*:[ \t]*chunked[ \t]*(?:\r\n|\z)~i', $header)
            ) {
                $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
                return 0;
            }
            return $length;
        }

        // 非 POST 的 chunked 以及无 Content-Length 的请求：按父类为非法请求处理
        static $input = [];
        if (!isset($recv_buffer[512]) && isset($input[$recv_buffer])) {
            return $input[$recv_buffer];
        }

        if ($pos = \strpos($header, "\r\nContent-Length: ")) {
            $length += (int)\substr($header, $pos + 18, 10);
        } else if (\preg_match("/\r\ncontent-length: ?(\d+)/i", $header, $match)) {
            $length += (int)$match[1];
        }

        if ($length > $connection->maxPackageSize) {
            $connection->close("HTTP/1.1 413 Request Entity Too Large\r\n\r\n", true);
            return 0;
        }

        if (!isset($recv_buffer[512])) {
            $input[$recv_buffer] = $length;
            if (\count($input) > 512) {
                unset($input[key($input)]);
            }
        }

        return $length;
    }

    /**
     * header 阶段构造 Request；POST chunked 时构造带 bodyStream 的 StreamRequest。
     */
    public static function decode($recv_buffer, TcpConnection $connection)
    {
        $request = parent::decode($recv_buffer, $connection);

        if (
            $request->method() === 'POST'
            && \preg_match('~\r\nTransfer-Encoding[ \t]*:~i', $request->rawHead()) === 1
        ) {
            $streamRequest = new StreamRequest($request->rawHead() . "\r\n\r\n");
            $streamRequest->connection = $connection;
            $connection->__request = $streamRequest;

            $bodyStream = new RequestBodyStream($streamRequest);
            $streamRequest->bodyStream = $bodyStream;
            $connection->context ??= new \stdClass();
            $connection->context->bodyStream = $bodyStream;

            return $streamRequest;
        }

        return $request;
    }
}
