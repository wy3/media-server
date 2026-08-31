<?php

declare(strict_types=1);

namespace MediaServer\Recorder;

use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\VideoFrame;

/**
 * 纯 PHP fMP4 (Fragmented MP4) box 生成器。
 *
 * 生成结构与思路：
 *  - ftyp + moov(空 stbl + mvex/trex) 位于文件头部
 *  - 之后按片段交替写入 moof+mdat（视频轨、音频轨各一对）
 *  - 每个 moof 只包含一个 traf，trun 的 data_offset 指向紧随的 mdat 数据，
 *    避免多轨交错带来的解析歧义，也便于回放时切片重打包。
 *
 * 音视频样本数据均直接复用推流端 MediaFrame 中的 AVCC(H264)/raw AAC，
 * 不进行转码。avcC / esds(AudioSpecificConfig) 亦直接复用 FLV 序列头原始字节。
 */
class Mp4Muxer
{
    public const VIDEO_TRACK_ID = 1;
    public const AUDIO_TRACK_ID = 2;

    /** 音视频时间基统一使用 1000（毫秒），与 FLV 时间戳一致，避免换算误差 */
    public const VIDEO_TIMESCALE = 1000;
    public const AUDIO_TIMESCALE = 1000;

    //--------------------------------
    //		顶层组合
    //--------------------------------

    public static function ftyp(): string
    {
        return self::box('ftyp', 'isom' . pack('N', 0x00000200) . 'isomiso2avc1mp41');
    }

    /**
     * @param array{avcC?:string,width?:int,height?:int} $video
     * @param array{esds?:string,channels?:int,samplerate?:int} $audio
     */
    public static function moov(array $video = [], array $audio = []): string
    {
        $trackCount = 0;
        $tracks = '';
        if ($video) {
            $tracks .= self::videoTrack($video);
            $trackCount++;
        }
        if ($audio) {
            $tracks .= self::audioTrack($audio);
            $trackCount++;
        }
        $mvex = self::mvex($video ? self::VIDEO_TRACK_ID : 0, $audio ? self::AUDIO_TRACK_ID : 0);
        return self::box('moov', self::mvhd($trackCount) . $tracks . $mvex);
    }

    /**
     * 生成单个轨道的 moof（tfhd + tfdt + trun）。
     *
     * @param array<int, array{dts:int,dur:int,cts:int,size:int,flags:int}> $samples
     */
    public static function buildTrackFragment(int $sequence, int $trackId, array $samples, int $timescale, int $baseDecodeTime): string
    {
        $count = count($samples);
        if ($count === 0) {
            return '';
        }

        $hasCts = false;
        foreach ($samples as $s) {
            if (!empty($s['cts'])) {
                $hasCts = true;
                break;
            }
        }

        $flags = 0x000001 | 0x000004 | 0x000008 | 0x000100;
        if ($hasCts) {
            $flags |= 0x000200;
        }

        $trunPayload = pack('N', $flags) . pack('N', $count) . pack('N', 0) . pack('N', (int)($samples[0]['flags'] ?? 0x02000000));
        foreach ($samples as $s) {
            $trunPayload .= pack('N', (int)$s['dur']);
            $trunPayload .= pack('N', (int)$s['size']);
            if ($hasCts) {
                $trunPayload .= pack('N', (int)$s['cts'] & 0xFFFFFFFF);
            }
        }

        $tfhd = self::box('tfhd', pack('N', 0x020000) . pack('N', $trackId));
        $tfdt = self::box('tfdt', "\x00\x00\x00\x01" . pack('NN', ($baseDecodeTime >> 32) & 0xFFFFFFFF, $baseDecodeTime & 0xFFFFFFFF));
        $trun = self::box('trun', $trunPayload);
        $mfhd = self::box('mfhd', "\x00\x00\x00\x00" . pack('N', $sequence));

        $traf = self::box('traf', $tfhd . $tfdt . $trun);
        $moof = self::box('moof', $mfhd . $traf);

        // 修正 trun.data_offset：default-base-is-moof，基准为 moof 起始；mdat 紧随 moof，
        // 其数据区位于 moof 之后再偏移 8 字节（mdat box 头）。
        $dataOffsetPos = 8 + strlen($mfhd) + 8 + strlen($tfhd) + strlen($tfdt) + 8 + 8;
        $dataOffset = strlen($moof) + 8;
        $moof = substr_replace($moof, pack('N', $dataOffset), $dataOffsetPos, 4);

        return $moof;
    }

    public static function mdat(string $data): string
    {
        return self::box('mdat', $data);
    }

    //--------------------------------
    //		moov 子 box
    //--------------------------------

    protected static function videoTrack(array $v): string
    {
        $tkhd = self::tkhd(self::VIDEO_TRACK_ID, 0, (int)$v['width'], (int)$v['height']);
        $mdhd = self::mdhd(self::VIDEO_TIMESCALE);
        $hdlr = self::hdlr('vide', 'VideoHandler');
        $vmhd = self::vmhd();
        $dinf = self::dinf();
        $stsd = self::box('stsd', pack('N', 0) . pack('N', 1) . self::avc1($v));
        $stbl = self::box('stbl', $stsd . self::emptyStts() . self::emptyStsc() . self::emptyStsz() . self::emptyStco());
        $minf = self::box('minf', $vmhd . $dinf . $stbl);
        $mdia = self::box('mdia', $mdhd . $hdlr . $minf);
        return self::box('trak', $tkhd . $mdia);
    }

    protected static function audioTrack(array $a): string
    {
        $tkhd = self::tkhd(self::AUDIO_TRACK_ID, 0x0100, 0, 0);
        $mdhd = self::mdhd(self::AUDIO_TIMESCALE);
        $hdlr = self::hdlr('soun', 'SoundHandler');
        $smhd = self::smhd();
        $dinf = self::dinf();
        $stsd = self::box('stsd', pack('N', 0) . pack('N', 1) . self::mp4a($a));
        $stbl = self::box('stbl', $stsd . self::emptyStts() . self::emptyStsc() . self::emptyStsz() . self::emptyStco());
        $minf = self::box('minf', $smhd . $dinf . $stbl);
        $mdia = self::box('mdia', $mdhd . $hdlr . $minf);
        return self::box('trak', $tkhd . $mdia);
    }

    protected static function mvhd(int $trackCount): string
    {
        $p = "\x00\x00\x00\x00"
            . pack('N', 0) . pack('N', 0)          // creation_time, modification_time
            . pack('N', 1000)                       // timescale
            . pack('N', 0)                          // duration
            . pack('N', 0x00010000)                 // rate 1.0
            . pack('n', 0x0100)                     // volume 1.0
            . "\x00\x00"                            // reserved
            . "\x00\x00\x00\x00\x00\x00\x00\x00"    // reserved
            . pack('N', 0x00010000) . pack('N', 0) . pack('N', 0)
            . pack('N', 0) . pack('N', 0x00010000) . pack('N', 0)
            . pack('N', 0) . pack('N', 0) . pack('N', 0x40000000)
            . pack('N', 0) . pack('N', 0) . pack('N', 0)
            . pack('N', 0) . pack('N', 0) . pack('N', 0)
            . pack('N', $trackCount + 1);           // next_track_ID
        return self::box('mvhd', $p);
    }

    protected static function tkhd(int $trackId, int $volume, int $width, int $height): string
    {
        $p = "\x00\x00\x00\x03"                     // version 0, flags 3
            . pack('N', 0) . pack('N', 0)           // creation_time, modification_time
            . pack('N', $trackId)
            . pack('N', 0)                          // reserved
            . pack('N', 0)                          // duration
            . "\x00\x00\x00\x00\x00\x00\x00\x00"    // reserved
            . pack('n', 0) . pack('n', 0)           // layer, alternate_group
            . pack('n', $volume)                    // volume
            . "\x00\x00"                            // reserved
            . pack('N', 0x00010000) . pack('N', 0) . pack('N', 0)
            . pack('N', 0) . pack('N', 0x00010000) . pack('N', 0)
            . pack('N', 0) . pack('N', 0) . pack('N', 0x40000000)
            . pack('N', $width << 16) . pack('N', $height << 16);
        return self::box('tkhd', $p);
    }

    protected static function mdhd(int $timescale): string
    {
        $p = "\x00\x00\x00\x00"
            . pack('N', 0) . pack('N', 0)
            . pack('N', $timescale)
            . pack('N', 0)
            . pack('n', 0x55C4)                     // language 'und'
            . pack('n', 0);
        return self::box('mdhd', $p);
    }

    protected static function hdlr(string $handlerType, string $name): string
    {
        $p = "\x00\x00\x00\x00"
            . pack('N', 0)
            . $handlerType
            . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            . $name . "\x00";
        return self::box('hdlr', $p);
    }

    protected static function vmhd(): string
    {
        return self::box('vmhd', "\x00\x00\x00\x01" . "\x00\x00" . "\x00\x00\x00\x00\x00\x00");
    }

    protected static function smhd(): string
    {
        return self::box('smhd', "\x00\x00\x00\x00" . "\x00\x00" . "\x00\x00");
    }

    protected static function dinf(): string
    {
        $dref = self::box('dref', "\x00\x00\x00\x00" . pack('N', 1) . self::box('url ', "\x00\x00\x00\x01"));
        return self::box('dinf', $dref);
    }

    protected static function emptyStts(): string
    {
        return self::box('stts', "\x00\x00\x00\x00" . pack('N', 0));
    }

    protected static function emptyStsc(): string
    {
        return self::box('stsc', "\x00\x00\x00\x00" . pack('N', 0));
    }

    protected static function emptyStsz(): string
    {
        return self::box('stsz', "\x00\x00\x00\x00" . pack('N', 0) . pack('N', 0));
    }

    protected static function emptyStco(): string
    {
        return self::box('stco', "\x00\x00\x00\x00" . pack('N', 0));
    }

    protected static function mvex(int $videoTrackId, int $audioTrackId): string
    {
        $p = '';
        if ($videoTrackId) {
            $p .= self::trex($videoTrackId);
        }
        if ($audioTrackId) {
            $p .= self::trex($audioTrackId);
        }
        return self::box('mvex', $p);
    }

    protected static function trex(int $trackId): string
    {
        $p = "\x00\x00\x00\x00"
            . pack('N', $trackId)
            . pack('N', 1)                          // default_sample_description_index
            . pack('N', 0)                          // default_sample_duration
            . pack('N', 0)                          // default_sample_size
            . pack('N', 0x00010000);                // default_sample_flags (non-sync)
        return self::box('trex', $p);
    }

    //--------------------------------
    //		stsd 样本条目
    //--------------------------------

    protected static function avc1(array $v): string
    {
        $entry = "\x00\x00\x00\x00\x00\x00" . pack('n', 1)
            . "\x00\x00\x00\x00"
            . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            . pack('n', (int)$v['width']) . pack('n', (int)$v['height'])
            . pack('N', 0x00480000) . pack('N', 0x00480000)
            . pack('N', 0) . pack('n', 1)
            . "\x0dAVC Coding" . str_repeat("\x00", 32 - 11)
            . pack('n', 0x0018) . pack('n', 0xFFFF)
            . self::box('avcC', $v['avcC']);
        return self::box('avc1', $entry);
    }

    protected static function mp4a(array $a): string
    {
        $entry = "\x00\x00\x00\x00\x00\x00" . pack('n', 1)
            . pack('n', 0) . pack('n', 0) . pack('N', 0)
            . pack('n', (int)$a['channels']) . pack('n', 16)
            . pack('n', 0) . pack('n', 0)
            . pack('N', ((int)$a['samplerate']) << 16)
            . self::esds($a['esds']);
        return self::box('mp4a', $entry);
    }

    protected static function esds(string $audioSpecificConfig): string
    {
        $dsi = "\x05" . self::descriptorLength(strlen($audioSpecificConfig)) . $audioSpecificConfig;
        $decConfig = "\x04" . self::descriptorLength(13 + strlen($dsi))
            . "\x40\x15" . "\x00\x00\x00" . "\x00\x00\x00\x00" . "\x00\x00\x00\x00"
            . $dsi;
        $sl = "\x06\x01\x02";
        $es = "\x03" . self::descriptorLength(3 + strlen($decConfig) + strlen($sl))
            . "\x00\x00\x00" . $decConfig . $sl;
        return self::box('esds', "\x00\x00\x00\x00" . $es);
    }

    protected static function descriptorLength(int $len): string
    {
        $bytes = '';
        while ($len > 127) {
            $bytes = chr(($len & 0x7F) | 0x80) . $bytes;
            $len >>= 7;
        }
        return chr($len) . $bytes;
    }

    //--------------------------------
    //		样本数据提取
    //--------------------------------

    /**
     * 提取 H264 NALU 样本（AVCC 长度前缀格式），跳过 FLV 视频 tag 头与 AVC 分包头。
     */
    public static function videoSampleData(VideoFrame $frame): string
    {
        $avc = $frame->getAVCPacket();
        return $avc->stream->readRaw();
    }

    /**
     * 提取 AAC 裸样本。
     */
    public static function audioSampleData(AudioFrame $frame): string
    {
        $aac = $frame->getAACPacket();
        return $aac->stream->readRaw();
    }

    /**
     * 提取 FLV AVC 序列头中的原始 avcC（AVCDecoderConfigurationRecord）。
     */
    public static function avcCFromSequence(VideoFrame $frame): string
    {
        $avc = $frame->getAVCPacket();
        return $avc->stream->readRaw();
    }

    /**
     * 提取 FLV AAC 序列头中的原始 AudioSpecificConfig。
     */
    public static function audioSpecificConfigFromSequence(AudioFrame $frame): string
    {
        $aac = $frame->getAACPacket();
        return $aac->stream->readRaw();
    }

    //--------------------------------
    //		工具
    //--------------------------------

    public static function box(string $type, string $payload): string
    {
        return pack('N', strlen($payload) + 8) . $type . $payload;
    }
}
