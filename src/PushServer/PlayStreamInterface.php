<?php

declare(strict_types=1);

namespace MediaServer\PushServer;


use Evenement\EventEmitterInterface;
use MediaServer\MediaReader\MediaFrame;

interface PlayStreamInterface extends EventEmitterInterface
{

    public function isPlayerIdling(): bool;

    /**
     * 播放开始
     */
    public function startPlay(): void;

    public function frameSend(MediaFrame $frame): void;

    public function playClose(): void;

    /**
     * 获取当前路径
     */
    public function getPlayPath(): string;

    /**
     * 是否启用音频
     */
    public function isEnableAudio(): bool;

    /**
     * 是否启用视频
     */
    public function isEnableVideo(): bool;

    /**
     * 是否启用gop，关闭能降低延迟
     */
    public function isEnableGop(): bool;

    public function setEnableAudio(bool $status): void;

    public function setEnableVideo(bool $status): void;

    public function setEnableGop(bool $status): void;
}