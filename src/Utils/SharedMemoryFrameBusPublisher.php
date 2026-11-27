<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\PublishStreamInterface;
use Workerman\Timer;

/**
 * 多进程帧总线（纯共享内存版）：写 shm + 维护流注册表。
 *
 * 推流进程调用 publish()：
 *   1. 帧序列化后写入该流的 shm 环形缓冲
 *   2. 首帧时把 path 注册进 StreamRegistry（全 shm，无 UDP）
 *
 * 消费者进程（播放/录像）轮询注册表发现流、轮询段头 writeSeq 追帧。
 * 没有消息传递：一切状态都在共享内存里，消费者拉（pull）。
 */
class SharedMemoryFrameBusPublisher implements FrameBusInterface
{
    /** @var array<string, SharedMemoryFrameBus> */
    private array $segments = [];

    /** @var array<string, int> path => 当前 GOP 已写帧数（含关键帧） */
    private array $gopCount = [];

    /** @var array<string, int> path => 上次告警的 GOP 起点 seq */
    private array $gopWarnedAt = [];

    /**
     * @param int $frameSlots 帧索引槽数（可容纳帧数）
     * @param int $dataKb 单个 GOP 环容量 KB
     */
    public function __construct(
        private readonly int $frameSlots = 0,   // 0=默认 200
        private readonly int $dataKb = 0        // 0=默认 2048KB
    ) {
    }

    public function publish(MediaFrame $frame, PublishStreamInterface $publisher): void
    {
        $path = $publisher->getPublishPath();

        // 每个 path 一个段，懒创建（注册表登记在 MediaServer::addPublish 完成）
        if (!isset($this->segments[$path])) {
            $this->segments[$path] = new SharedMemoryFrameBus(
                $path, $this->frameSlots, $this->dataKb, true
            );
        }

        // 帧序列化：MediaFrame 的 __toString() 就是 payload
        $payload = (string)$frame;
        $isKey = $this->isKeyFrame($frame);
        $special = $this->specialFrame($frame);

        $this->segments[$path]->write(
            $payload,
            $isKey,
            $frame->FRAME_TYPE,
            $frame->timestamp ?? 0,
            $special
        );

        // GOP 完整性保障：GOP 帧数不能超过槽位数，否则会覆盖本 GOP 开头
        if ($isKey || !isset($this->gopCount[$path])) {
            $this->gopCount[$path] = 1;
        } else {
            $this->gopCount[$path]++;
        }
        $slotLimit = $this->segments[$path]->slotCount();
        $count = $this->gopCount[$path];
        if ($count >= $slotLimit - 4 && ($this->gopWarnedAt[$path] ?? 0) !== $this->gopCount[$path]) {
            $this->gopWarnedAt[$path] = $count;
            logger()->warning("GOP exceeds shm slots ({$count} frames), new players may read a broken GOP for {path}", ['path' => $path]);
        }
    }

    /**
     * 通知所有消费进程该路流已结束（unpublish）。
     * 注册表注销在 MediaServer 的 on_close 里完成，这里只清本进程段缓存。
     */
    public function notifyStreamEnd(string $path): void
    {
        unset($this->segments[$path], $this->gopCount[$path], $this->gopWarnedAt[$path]);
    }

    /**
     * 判断特殊帧类型（序列头索引用）。
     */
    private function specialFrame(\MediaServer\MediaReader\MediaFrame $frame): int
    {
        if ($frame instanceof \MediaServer\MediaReader\MetaDataFrame) {
            return 1;   // meta
        }
        if ($frame instanceof VideoFrame) {
            if ($frame->isHEVC()) {
                $p = $frame->getHEVCPacket();
                if ($p->hevcPacketType === \MediaServer\MediaReader\HEVCPacket::HEVC_PACKET_TYPE_SEQUENCE_START) {
                    return 4;   // hevc sequence
                }
            } elseif ($frame->codecId === VideoFrame::VIDEO_CODEC_ID_AVC) {
                $p = $frame->getAVCPacket();
                if ($p->avcPacketType === \MediaServer\MediaReader\AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    return 2;   // avc sequence
                }
            }
        }
        if ($frame instanceof AudioFrame) {
            $p = $frame->getAACPacket();
            if ($p->aacPacketType === \MediaServer\MediaReader\AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                return 3;   // aac sequence
            }
        }
        return 0;
    }

    /**
     * 判断关键帧。
     */
    private function isKeyFrame(MediaFrame $frame): bool
    {
        if ($frame instanceof \MediaServer\MediaReader\VideoFrame) {
            return $frame->frameType === \MediaServer\MediaReader\VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME;
        }
        // META/AUDIO 帧不算视频关键帧
        return false;
    }
}
