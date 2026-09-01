<?php

declare(strict_types=1);

use MediaServer\Rtmp\RtmpAMF;

//------------------------------
// AMF0 编解码（SabreAMF 封装层）
// 编码 → 解码 round-trip，以及畸形输入容错
//------------------------------

test('CMD round-trip: connect', function () {
    $opt = [
        'cmd' => 'connect',
        'transId' => 1,
        'cmdObj' => ['app' => 'live', 'type' => 'nonprivate', 'flashVer' => 'FMLE/3.0'],
        'args' => ['x' => 1],
    ];
    $bytes = RtmpAMF::rtmpCMDAmf0Creator($opt);
    checkStrContains('connect', $bytes, '编码应含 cmd 字符串');

    $r = RtmpAMF::rtmpCMDAmf0Reader($bytes);
    checkSame('connect', $r['cmd'], 'cmd');
    // 已知行为：AMF0 数字反序列化一律为 float（生产代码如 onConnect 自行 (int) 转换）
    checkSame(1.0, $r['transId'], 'transId');
    checkSame('live', $r['cmdObj']['app'] ?? null, 'cmdObj.app');
    checkSame('nonprivate', $r['cmdObj']['type'] ?? null, 'cmdObj.type');
    checkSame(1.0, $r['args']['x'] ?? null, 'args.x');
});

test('CMD round-trip: publish 带可选字段', function () {
    $opt = ['cmd' => 'publish', 'transId' => 3, 'cmdObj' => [], 'streamName' => 'test_stream?token=abc', 'type' => 'live'];
    $r = RtmpAMF::rtmpCMDAmf0Reader(RtmpAMF::rtmpCMDAmf0Creator($opt));
    checkSame('publish', $r['cmd'], 'cmd');
    checkSame('test_stream?token=abc', $r['streamName'] ?? null, 'streamName');
    checkSame('live', $r['type'] ?? null, 'type');
});

test('CMD round-trip: play 的多字段顺序', function () {
    $opt = ['cmd' => 'play', 'transId' => 4, 'cmdObj' => [], 'streamName' => 'movie', 'start' => -2, 'duration' => 0, 'reset' => true];
    $r = RtmpAMF::rtmpCMDAmf0Reader(RtmpAMF::rtmpCMDAmf0Creator($opt));
    checkSame('play', $r['cmd'], 'cmd');
    checkSame('movie', $r['streamName'] ?? null, 'streamName');
    checkSame(-2.0, $r['start'] ?? null, 'start');
    checkSame(0.0, $r['duration'] ?? null, 'duration');
    checkSame(true, $r['reset'] ?? null, 'reset');
});

test('CMD 编码缺字段时跳过该字段（可变参数）', function () {
    // play 未提供 duration/reset，解码时按顺序应停在 streamName/start
    $r = RtmpAMF::rtmpCMDAmf0Reader(RtmpAMF::rtmpCMDAmf0Creator([
        'cmd' => 'play', 'transId' => 1, 'cmdObj' => [], 'streamName' => 's1', 'start' => 0,
    ]));
    checkSame('s1', $r['streamName'] ?? null, 'streamName');
    checkSame(0.0, $r['start'] ?? null, 'start');
    check(!array_key_exists('duration', $r) || ($r['duration'] ?? null) === null, 'duration 不应存在');
});

test('CMD round-trip: _result 响应', function () {
    $r = RtmpAMF::rtmpCMDAmf0Reader(RtmpAMF::rtmpCMDAmf0Creator([
        'cmd' => '_result', 'transId' => 1, 'cmdObj' => ['fmsVer' => 'media-server'], 'info' => ['level' => 'status'],
    ]));
    checkSame('_result', $r['cmd'], 'cmd');
    checkSame('media-server', $r['cmdObj']['fmsVer'] ?? null, 'cmdObj.fmsVer');
    checkSame('status', $r['info']['level'] ?? null, 'info.level');
});

test('DATA round-trip: @setDataFrame / onMetaData', function () {
    $bytes = RtmpAMF::rtmpDATAAmf0Creator([
        'cmd' => '@setDataFrame', 'method' => 'onMetaData',
        'dataObj' => ['width' => 320, 'height' => 240, 'framerate' => 25, 'audiocodecid' => 10],
    ]);
    $r = RtmpAMF::rtmpDataAmf0Reader($bytes);
    checkSame('@setDataFrame', $r['cmd'], 'cmd');
    checkSame('onMetaData', $r['method'] ?? null, 'method');
    checkSame(320.0, $r['dataObj']['width'] ?? null, 'dataObj.width（AMF 数字为 float）');
});

test('畸形输入容错：空串/垃圾字节不抛异常', function () {
    foreach (['', "\x00", "\xff\xff\xff\xff", "\x02\x00"] as $i => $garbage) {
        $r1 = RtmpAMF::rtmpCMDAmf0Reader($garbage);
        $r2 = RtmpAMF::rtmpDataAmf0Reader($garbage);
        check(is_array($r1) && is_array($r2), "garbage #{$i} 应返回数组结构");
    }
});

test('未知命令只告警不抛异常', function () {
    $bytes = RtmpAMF::rtmpCMDAmf0Creator(['cmd' => 'someUnknownCmd', 'transId' => 9]);
    $r = RtmpAMF::rtmpCMDAmf0Reader($bytes);
    checkSame('someUnknownCmd', $r['cmd'], '未知 cmd 原样返回');
});
