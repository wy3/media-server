<?php

declare(strict_types=1);

use MediaServer\Recorder\Mp4Muxer;
use MediaServer\Recorder\Mp4Parser;

//------------------------------
// MP4 封装/解析 round-trip（fMP4）
// 字节级正确性是回放切片的根基，迁移必须保持一致
//------------------------------

/** 构造一个内容上合法的最小 avcC（SPS/PPS 为 320x240 baseline 常见值） */
function fakeAvcC(): string
{
    $sps = "\x67\x42\x00\x1f\xab\x40\xb0\x4a\x50";
    $pps = "\x68\xce\x3c\x80";
    return "\x01\x42\x00\x1f\xff\xe1"
        . pack('n', strlen($sps)) . $sps
        . "\x01"
        . pack('n', strlen($pps)) . $pps;
}

/** esds 内容解析器只做提取，最小合法结构即可 */
function fakeEsds(): string
{
    return "\x03\x19\x00\x00\x00\x04\x11\x40\x15\x00\x00\x00\x00\x01\xf4\x00\x00\x01\xf4\x00\x05\x02\x12\x10\x06\x01\x02";
}

test('box() 长度与类型头一致', function () {
    $b = Mp4Muxer::box('moov', 'ABCDEFGH');
    checkSame(16, strlen($b), '8 头 + 8 载荷');
    checkSame('moov', substr($b, 4, 4), 'type');
    checkSame(16, unpack('N', substr($b, 0, 4))[1], 'size 字段');
});

test('ftyp 结构', function () {
    $f = Mp4Muxer::ftyp();
    checkSame('ftyp', substr($f, 4, 4), 'type');
    checkSame('isom', substr($f, 8, 4), 'brand');
    checkSame(strlen($f), unpack('N', substr($f, 0, 4))[1], 'size 一致');
});

test('moov 结构：音视频双轨', function () {
    $moov = Mp4Muxer::moov(
        ['avcC' => fakeAvcC(), 'width' => 320, 'height' => 240],
        ['esds' => fakeEsds(), 'channels' => 2, 'samplerate' => 44100]
    );
    checkSame('moov', substr($moov, 4, 4), 'type');
    // 顶层子 box 逐个走一遍，验证 size 链不断裂
    $pos = 8;
    $types = [];
    while ($pos + 8 <= strlen($moov)) {
        $size = unpack('N', substr($moov, $pos, 4))[1];
        check($size >= 8, '子 box size >= 8');
        $types[] = substr($moov, $pos + 4, 4);
        $pos += $size;
    }
    checkSame(strlen($moov), $pos, '子 box 链总长应精确覆盖 moov');
    checkSame(['mvhd', 'trak', 'trak', 'mvex'], $types, 'mvhd + 双轨 + mvex');
});

test('单视频轨 moov', function () {
    $moov = Mp4Muxer::moov(['avcC' => fakeAvcC(), 'width' => 640, 'height' => 360]);
    $pos = 8;
    $types = [];
    while ($pos + 8 <= strlen($moov)) {
        $size = unpack('N', substr($moov, $pos, 4))[1];
        $types[] = substr($moov, $pos + 4, 4);
        $pos += $size;
    }
    checkSame(['mvhd', 'trak', 'mvex'], $types, 'mvhd + 单轨 + mvex');
});

test('round-trip: 音视频分段 mux → parse', function () {
    $videoSamples = [
        ['dur' => 40, 'size' => 1000, 'cts' => 0, 'flags' => 0x02000000], // key
        ['dur' => 40, 'size' => 500, 'cts' => 0, 'flags' => 0x01000000],
        ['dur' => 40, 'size' => 600, 'cts' => 40, 'flags' => 0x01000000],
    ];
    $audioSamples = [];
    for ($i = 0; $i < 10; $i++) {
        $audioSamples[] = ['dur' => 23, 'size' => 50, 'cts' => 0, 'flags' => 0x02000000];
    }

    $videoData = str_repeat('V', 2100);
    $audioData = str_repeat('A', 500);

    $file = sys_get_temp_dir() . '/ms_mux_test_' . getmypid() . '.mp4';
    $bytes = Mp4Muxer::ftyp()
        . Mp4Muxer::moov(
            ['avcC' => fakeAvcC(), 'width' => 320, 'height' => 240],
            ['esds' => fakeEsds(), 'channels' => 2, 'samplerate' => 44100]
        )
        . Mp4Muxer::buildTrackFragment(1, Mp4Muxer::VIDEO_TRACK_ID, $videoSamples, Mp4Muxer::VIDEO_TIMESCALE, 0)
        . Mp4Muxer::mdat($videoData)
        . Mp4Muxer::buildTrackFragment(2, Mp4Muxer::AUDIO_TRACK_ID, $audioSamples, Mp4Muxer::AUDIO_TIMESCALE, 0)
        . Mp4Muxer::mdat($audioData);
    file_put_contents($file, $bytes);

    try {
        $r = Mp4Parser::parseSegment($file);
        check(isset($r['video'], $r['audio']), '应含 video/audio 两组');

        $v = $r['video'];
        checkCount(3, $v['samples'], '视频样本数');
        checkSame(320, $v['width'], 'width');
        checkSame(240, $v['height'], 'height');
        check(!empty($v['avcC']), 'avcC 应被提取');
        checkSame(1000, $v['timescale'], 'timescale');

        // 样本属性与 trun 输入对齐
        checkSame(40, $v['samples'][0]['dur'], 'v0.dur');
        checkSame(1000, $v['samples'][0]['size'], 'v0.size');
        checkSame(40, $v['samples'][2]['cts'], 'v2.cts');
        // dts = baseDecodeTime 累加 dur
        checkSame(0, $v['samples'][0]['dts'], 'v0.dts');
        checkSame(40, $v['samples'][1]['dts'], 'v1.dts');
        checkSame(80, $v['samples'][2]['dts'], 'v2.dts');

        $a = $r['audio'];
        checkCount(10, $a['samples'], '音频样本数');
        checkSame(2, $a['channels'], 'channels');
        checkSame(44100, $a['samplerate'], 'samplerate');
        checkSame(23, $a['samples'][0]['dur'], 'a0.dur');
        checkSame(50, $a['samples'][9]['size'], 'a9.size');
        // dts 连续
        checkSame(0, $a['samples'][0]['dts'], 'a0.dts');
        checkSame(207, $a['samples'][9]['dts'], 'a9.dts = 23*9');

        // 偏移应指向各自 mdat 数据区内部（可读出与写入一致的数据）
        foreach ($v['samples'] as $i => $s) {
            check($s['offset'] >= 8, "v{$i} offset 合法");
            $got = substr($bytes, $s['offset'], $s['size']);
            checkSame(str_repeat('V', $s['size']), $got, "v{$i} 偏移应精确指向视频样本数据");
        }
        foreach ($a['samples'] as $i => $s) {
            $got = substr($bytes, $s['offset'], $s['size']);
            checkSame(str_repeat('A', $s['size']), $got, "a{$i} 偏移应精确指向音频样本数据");
        }
    } finally {
        @unlink($file);
    }
});

test('parseSegment: 不存在的文件返回空结构', function () {
    $r = Mp4Parser::parseSegment(sys_get_temp_dir() . '/ms_no_such_' . getmypid() . '.mp4');
    // 实现约定：打不开文件时 video/audio 均为 null
    checkSame(null, $r['video'], 'video 为 null');
    checkSame(null, $r['audio'], 'audio 为 null');
});
