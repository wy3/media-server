<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use MediaServer\MediaReader\MediaFrame;
use MediaServer\PushServer\PublishStreamInterface;

/**
 * 单进程帧分发：直接遍历本进程内的播放端发送。
 *
 * 行为与改造前的 MediaServer::publisherOnFrame() 完全一致，
 * 是多进程改造前的默认实现，也是 Windows 单进程模式的兜底。
 */
class InProcessFrameBus implements FrameBusInterface
{
    /**
     * @var callable(MediaFrame, PublishStreamInterface): void
     */
    private $sendToPlayers;

    /**
     * @param callable(MediaFrame, PublishStreamInterface): void $sendToPlayers
     */
    public function __construct(callable $sendToPlayers)
    {
        $this->sendToPlayers = $sendToPlayers;
    }

    public function publish(MediaFrame $frame, PublishStreamInterface $publisher): void
    {
        ($this->sendToPlayers)($frame, $publisher);
    }
}
