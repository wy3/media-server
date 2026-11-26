<?php

declare(strict_types=1);

namespace MediaServer\Utils;

/**
 * 组包结果载体基类，用于在同一帧扇出到多个播放端时复用已拼好的数据。
 *
 * 由首个播放端组包后返回，后续播放端直接发送 chunks，
 * 只有与连接相关的字段才需要改写（由子类负责），
 * 避免每个播放端重复拼接整个 payload。
 */
abstract class PlayPackCache
{
    /**
     * 已组好的完整数据（RTMP chunks / FLV tag）
     *
     * @var string
     */
    public string $chunks = '';
}
