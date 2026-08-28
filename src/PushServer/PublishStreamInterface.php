<?php

declare(strict_types=1);

namespace MediaServer\PushServer;


use Evenement\EventEmitterInterface;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;

/**
 * Interface PublishStreamInterface
 * @package MediaServer\PushServer
 */
interface PublishStreamInterface extends EventEmitterInterface
{
    /**
     * 获取当前推流路径
     */
    public function getPublishPath(): string;

    /**
     * Have meta data
     */
    public function isMetaData(): bool;

    public function getMetaDataFrame(): ?MetaDataFrame;

    /**
     * Have aac sequence header
     */
    public function isAACSequence(): bool;

    public function getAACSequenceFrame(): ?AudioFrame;

    /**
     * Have avc sequence header
     */
    public function isAVCSequence(): bool;

    public function getAVCSequenceFrame(): ?VideoFrame;

    /**
     * 是否包含音频
     */
    public function hasAudio(): bool;

    /**
     * 是否包含视频
     */
    public function hasVideo(): bool;

    /**
     * 获取gop
     * @return MediaFrame[]
     */
    public function getGopCacheQueue(): array;

    public function getPublishStreamInfo(): array;

}