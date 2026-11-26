<?php

declare(strict_types=1);

namespace MediaServer\PushServer;


use Evenement\EventEmitterInterface;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\Utils\PlayPackCache;

interface PlayStreamInterface extends EventEmitterInterface
{

    public function isPlayerIdling(): bool;

    /**
     * 播放开始
     */
    public function startPlay(): void;

    /**
     * 发送一帧数据。
     *
     * @param MediaFrame $frame 待发送的帧
     * @param PlayPackCache|null $playPackCache 上一个播放端组好的数据。
     *        命中时直接发送其中的 chunks，只改写与连接相关的字段，
     *        既不再组包也不再创建载体对象。
     * @return PlayPackCache 本端组好的数据，供下一个播放端复用
     */
    public function frameSend(MediaFrame $frame, ?PlayPackCache $playPackCache = null): PlayPackCache;

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