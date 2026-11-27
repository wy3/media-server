<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use MediaServer\MediaReader\MediaFrame;
use MediaServer\PushServer\PublishStreamInterface;

/**
 * 帧分发总线接口。
 *
 * 抽象「一帧如何送达所有消费者」这件事：
 * - 单进程：直接遍历播放端发送（InProcessFrameBus）
 * - 多进程：写共享内存 + UDP 组播通知（SharedMemoryFrameBus）
 *
 * 上层（推流 handler）只管 emit('on_frame')，不关心分发方式。
 */
interface FrameBusInterface
{
    /**
     * 发布一帧给该路流的所有消费者。
     *
     * @param MediaFrame $frame 待分发的帧
     * @param PublishStreamInterface $publisher 产生该帧的推流端
     * @return void
     */
    public function publish(MediaFrame $frame, PublishStreamInterface $publisher): void;
}
