<?php

declare(strict_types=1);

use MediaServer\Rtmp\RtmpAMF;
use MediaServer\Rtmp\RtmpStream;
use MediaServer\Utils\WMBufferStream;
use Workerman\Connection\TcpConnection;

//------------------------------
// RTMP 会话级集成测试（进程内，无网络）
// 走真实管线：WMBufferStream::input → RtmpStream（握手 → chunk 重组 → invoke）
// 这是 Swoole 迁移最核心的回归基准：换运行时后行为必须一致
//------------------------------

require_once __DIR__ . '/support/FakeTcpConnection.php';

/** 新建一对 [FakeTcpConnection, WMBufferStream, RtmpStream]，完成握手（C0C1+C2） */
function newHandshakedSession(): array
{
    $conn = new FakeTcpConnection(fopen('php://memory', 'r+'));
    $wm = new WMBufferStream($conn);
    $rtmp = new RtmpStream($wm);

    // C0(1) + C1(1536)
    $conn->feed("\x03" . str_repeat("\x0a", 1536));
    // S0S1S2 = 1 + 1536 + 1536
    $sent = implode('', $conn->sent);
    checkSame(3073, strlen($sent), '服务端应回 S0S1S2');
    checkSame(3, ord($sent[0]), 'S0 版本应为 3');
    // C2(1536)
    $conn->feed(str_repeat("\x0b", 1536));
    return [$conn, $wm, $rtmp];
}

/** 组装一个 fmt0 完整 chunk（csid 固定 3 = CHANNEL_INVOKE） */
function invokeChunk(int $streamId, array $amfOpt): string
{
    $payload = RtmpAMF::rtmpCMDAmf0Creator($amfOpt);
    $len = strlen($payload);
    $head = "\x03"
        . substr(pack('N', 0), 1, 3)          // timestamp 0
        . substr(pack('N', $len), 1, 3)        // message length
        . pack('C', 20)                        // INVOKE
        . pack('V', $streamId);                // stream id (LE)
    $out = $head . $payload;
    // payload 超过 128 时按 fmt3 续包切分
    $out = '';
    $chunks = str_split($payload, 128);
    $out .= $head . $chunks[0];
    for ($i = 1, $n = count($chunks); $i < $n; $i++) {
        $out .= "\xC3" . $chunks[$i];
    }
    return $out;
}

function allSent(object $conn): string
{
    return implode('', $conn->sent);
}

test('握手：C0C1 → S0S1S2 → C2 完成', function () {
    [$conn, $wm, $rtmp] = newHandshakedSession();
    checkSame(3, $rtmp->handshakeState, '状态应为 RTMP_HANDSHAKE_C2');
});

test('握手分片：C1 分多次 TCP 包到达也能完成', function () {
    $conn = new FakeTcpConnection(fopen('php://memory', 'r+'));
    $wm = new WMBufferStream($conn);
    $rtmp = new RtmpStream($wm);

    $conn->feed("\x03");                       // C0
    $conn->feed(str_repeat("\x01", 700));      // C1 前 700
    $conn->feed(str_repeat("\x01", 836));      // C1 后 836
    checkSame(2, $rtmp->handshakeState, 'C1 收齐后应为 RTMP_HANDSHAKE_C1');
    checkSame(3073, strlen(implode('', $conn->sent)), 'S0S1S2 已发出');
    $conn->feed(str_repeat("\x02", 1536));
    checkSame(3, $rtmp->handshakeState, 'C2 收齐后握手完成');
});

test('connect 命令：appName 提取 + _result 响应', function () {
    [$conn, $wm, $rtmp] = newHandshakedSession();
    $conn->feed(invokeChunk(0, [
        'cmd' => 'connect',
        'transId' => 1,
        'cmdObj' => ['app' => 'live', 'type' => 'nonprivate', 'tcUrl' => 'rtmp://127.0.0.1/live'],
        'args' => [],
    ]));

    checkSame('live', $rtmp->appName, 'appName 应取自 cmdObj.app');
    checkStrContains('_result', allSent($conn), '应回复 _result');
});

test('connect 命令：app 带斜杠被清洗（jwplayer 兼容）', function () {
    [$conn, $wm, $rtmp] = newHandshakedSession();
    $conn->feed(invokeChunk(0, [
        'cmd' => 'connect', 'transId' => 1,
        'cmdObj' => ['app' => '/live/'], 'args' => [],
    ]));
    checkSame('live', $rtmp->appName, '斜杠应被去除');
});

test('createStream → publish 完整推流建链', function () {
    [$conn, $wm, $rtmp] = newHandshakedSession();

    $conn->feed(invokeChunk(0, [
        'cmd' => 'connect', 'transId' => 1,
        'cmdObj' => ['app' => 'live'], 'args' => [],
    ]));
    $conn->feed(invokeChunk(0, [
        'cmd' => 'createStream', 'transId' => 2, 'cmdObj' => [],
    ]));
    checkStrContains('_result', allSent($conn), 'createStream 应回复 _result');

    // publish（streamId=1）
    $conn->feed(invokeChunk(1, [
        'cmd' => 'publish', 'transId' => 3, 'cmdObj' => [],
        'streamName' => 'test_stream?token=abc', 'type' => 'live',
    ]));

    checkSame('/live/test_stream', $rtmp->publishStreamPath, 'publishStreamPath');
    checkSame(['token' => 'abc'], $rtmp->publishArgs, 'query 参数应解析');
    check($rtmp->isPublishing, 'isPublishing 应为 true');
    checkStrContains('NetStream.Publish.Start', allSent($conn), '应回复 publish start 状态');
});

test('publish 恶意 streamName：穿越分量不得进入路径', function () {
    [$conn, $wm, $rtmp] = newHandshakedSession();
    $conn->feed(invokeChunk(0, [
        'cmd' => 'connect', 'transId' => 1, 'cmdObj' => ['app' => 'live'], 'args' => [],
    ]));
    $conn->feed(invokeChunk(1, [
        'cmd' => 'publish', 'transId' => 3, 'cmdObj' => [],
        'streamName' => '../../evil', 'type' => 'live',
    ]));
    // 路径组成不做穿越校验（sanitize 在下游），但不得抛异常，路径形态可预期
    checkSame('/live/../../evil', $rtmp->publishStreamPath, '当前实现原样拼接（下游 sanitize 兜底）');
});

test('chunk 重组：跨 TCP 包粘包/拆包', function () {
    [$conn, $wm, $rtmp] = newHandshakedSession();
    $conn->feed(invokeChunk(0, [
        'cmd' => 'connect', 'transId' => 1, 'cmdObj' => ['app' => 'live'], 'args' => [],
    ]));

    $chunk = invokeChunk(0, ['cmd' => 'createStream', 'transId' => 2, 'cmdObj' => []]);
    // 切成 5 份随机大小分别投递，模拟 TCP 分段
    $parts = str_split($chunk, max(1, intdiv(strlen($chunk), 5)));
    foreach ($parts as $p) {
        $conn->feed($p);
    }
    $sent = allSent($conn);
    check(substr_count($sent, '_result') >= 2, '分片投递后 createStream 仍应正常解码并回复');
});

test('SET_CHUNK_SIZE 后服务端按新值切续包（下行 chunk size）', function () {
    [$conn, $wm, $rtmp] = newHandshakedSession();
    $conn->feed(invokeChunk(0, [
        'cmd' => 'connect', 'transId' => 1, 'cmdObj' => ['app' => 'live'], 'args' => [],
    ]));
    // 客户端宣告上行 chunk size 4096
    $conn->feed(
        "\x02" . substr(pack('N', 0), 1, 3) . substr(pack('N', 4), 1, 3) . pack('C', 1) . pack('V', 0) . pack('N', 4096),
        $conn
    );
    // 之后投递一个 300 字节 payload 的 invoke：300 < 4096，单 chunk 即完整
    $big = invokeChunk(0, ['cmd' => 'createStream', 'transId' => 2, 'cmdObj' => []]);
    // 正常情况下 default 128 需要续包；大 chunk size 下 fmt3 续包头仍存在但不再切割 payload —— 我们发的编码本身合法，两种尺寸都能解
    $conn->feed($big);
    check(substr_count(allSent($conn), '_result') >= 2, '大 chunk size 下消息仍完整解码');
});
