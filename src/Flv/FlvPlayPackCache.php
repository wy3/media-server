<?php

declare(strict_types=1);

namespace MediaServer\Flv;

use MediaServer\Utils\PlayPackCache;

/**
 * FLV 组包结果载体。
 *
 * FLV tag 不含任何与连接相关的字段（streamId 恒为 0），
 * 组包结果可被所有播放端直接复用，无需改写。
 */
class FlvPlayPackCache extends PlayPackCache
{
    /**
     * @param string $chunks 已组好的 tag
     */
    public function __construct(string $chunks)
    {
        $this->chunks = $chunks;
    }
}
