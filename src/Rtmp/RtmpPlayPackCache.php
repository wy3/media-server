<?php

declare(strict_types=1);

namespace MediaServer\Rtmp;

use MediaServer\Utils\PlayPackCache;

/**
 * RTMP 组包结果载体。
 *
 * streamId 位于 CHUNK_TYPE_0 message header 的固定偏移处，
 * 而不同播放端可能用不同 streamId 拉同一路流，
 * 因此复用组包结果时只需改写该字段，不必重新拼接整个 payload。
 */
class RtmpPlayPackCache extends PlayPackCache
{
    /**
     * streamId 在 chunks 中的字节偏移，-1 表示该包不含 streamId
     *
     * @var int
     */
    public int $streamIdOffset = -1;

    /**
     * chunks 当前写入的 streamId，用于跳过无变化的改写
     *
     * @var int
     */
    public int $streamId = 0;

    /**
     * @param string $chunks 已组好的 chunks
     * @param RtmpPacket $packet 组包时使用的包描述，用于定位 streamId
     */
    public function __construct(string $chunks, RtmpPacket $packet)
    {
        $this->chunks = $chunks;
        $this->streamId = $packet->streamId;
        // 只有 CHUNK_TYPE_0 的 message header 带 streamId，
        // 位于 base header + 时间戳(3) + 长度(3) + 类型(1) 之后
        $this->streamIdOffset = $packet->chunkType === RtmpChunk::CHUNK_TYPE_0
            ? RtmpChunk::basicHeaderLength($packet->chunkStreamId) + 7
            : -1;
    }

    /**
     * 改写 chunks 中的 streamId。
     *
     * @param int $streamId 目标播放端的 streamId
     * @return $this
     */
    public function patchStreamId(int $streamId): self
    {
        if ($this->streamIdOffset >= 0 && $this->streamId !== $streamId) {
            $this->chunks = substr_replace($this->chunks, pack('L', $streamId), $this->streamIdOffset, 4);
            $this->streamId = $streamId;
        }
        return $this;
    }
}
