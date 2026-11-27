<?php

declare(strict_types=1);

namespace MediaServer\Http;

use Workerman\Protocols\Http\Request;

/**
 * 扩展 Request：chunked 请求携带一个持续可读的 bodyStream。
 *
 * 协议层在 decode header 时创建此对象并挂上 bodyStream；
 * 后续 chunked body 字节由协议层直接 feed 进该 stream。
 */
class StreamRequest extends Request
{
    public ?RequestBodyStream $bodyStream = null;
}
