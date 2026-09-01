<?php

declare(strict_types=1);

use MediaServer\Rtmp\RtmpControlHandlerTrait;
use MediaServer\Rtmp\RtmpPacket;

//------------------------------
// RTMP 控制消息处理（trait 级）
// P0(A1/A2) 回归：畸形 SET_CHUNK_SIZE / WINDOW_ACK 不得抛异常
//------------------------------

class TestControlSubject
{
    use RtmpControlHandlerTrait;

    public RtmpPacket $currentPacket;

    protected int $inChunkSize = 128;

    protected int $ackSize = 0;

    public function inChunkSize(): int
    {
        return $this->inChunkSize;
    }

    public function ackSize(): int
    {
        return $this->ackSize;
    }
}

function feedControl(int $type, string $payload): TestControlSubject
{
    $c = new TestControlSubject();
    $p = new RtmpPacket();
    $p->type = $type;
    $p->payload = $payload;
    $c->currentPacket = $p;
    $c->rtmpControlHandler();
    return $c;
}

test('SET_CHUNK_SIZE=0 被拒绝且不抛异常（除零攻击）', function () {
    $c = feedControl(RtmpPacket::TYPE_SET_CHUNK_SIZE, pack('N', 0));
    checkSame(128, $c->inChunkSize(), 'inChunkSize 应保持默认 128');
});

test('SET_CHUNK_SIZE 合法值生效', function () {
    $c = feedControl(RtmpPacket::TYPE_SET_CHUNK_SIZE, pack('N', 4096));
    checkSame(4096, $c->inChunkSize(), '4096');
    $c = feedControl(RtmpPacket::TYPE_SET_CHUNK_SIZE, pack('N', 0xFFFFFF));
    checkSame(0xFFFFFF, $c->inChunkSize(), '上限 0xFFFFFF');
});

test('SET_CHUNK_SIZE 超上限被拒绝', function () {
    $c = feedControl(RtmpPacket::TYPE_SET_CHUNK_SIZE, pack('N', 0x1000000));
    checkSame(128, $c->inChunkSize(), '超过 24 位上限应忽略');
});

test('SET_CHUNK_SIZE 畸形 payload 不抛异常（TypeError 回归）', function () {
    // 不足 4 字节的 payload 一律忽略
    foreach (["\x00\x00\x00", "\x00\x00", "\x00", ''] as $i => $payload) {
        $c = feedControl(RtmpPacket::TYPE_SET_CHUNK_SIZE, $payload);
        checkSame(128, $c->inChunkSize(), "payload #" . $i);
    }
    // 恰好 4 字节的最小合法值 1 应被接受
    $c = feedControl(RtmpPacket::TYPE_SET_CHUNK_SIZE, pack('N', 1));
    checkSame(1, $c->inChunkSize(), '4 字节最小值 1 应生效');
});

test('WINDOW_ACK 合法与畸形 payload', function () {
    $c = feedControl(RtmpPacket::TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE, pack('N', 2500000));
    checkSame(2500000, $c->ackSize(), '正常值');

    // 不足 4 字节忽略
    foreach (["\x00\x00\x00", "\x01", ''] as $i => $payload) {
        $c = feedControl(RtmpPacket::TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE, $payload);
        checkSame(0, $c->ackSize(), "畸形 payload #" . $i . " 不应改变 ackSize 且不抛异常");
    }
    // 4 字节最小值生效
    $c = feedControl(RtmpPacket::TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE, pack('N', 1));
    checkSame(1, $c->ackSize(), '4 字节值 1 应生效');
});

test('无操作类型（ABORT/ACK/SET_PEER_BANDWIDTH）不抛异常', function () {
    feedControl(RtmpPacket::TYPE_ABORT, 'anything');
    feedControl(RtmpPacket::TYPE_ACKNOWLEDGEMENT, pack('N', 123));
    feedControl(RtmpPacket::TYPE_SET_PEER_BANDWIDTH, pack('NC', 5000000, 2));
    check(true, '到达即通过');
});
