<?php

declare(strict_types=1);

namespace MediaServer\MediaReader;

use MediaServer\Utils\BinaryStream;

/**
 * HEVC（H.265）视频包解析。
 *
 * 对应 FLV 中的两种封装：
 *  - Enhanced RTMP：首字节 `1 + frameType(3bit) + packetType(4bit)`，随后 4 字节 FourCC，
 *    再往后才是载荷。**packetType 已经在首字节里，不再单独占一个字节**。
 *  - 旧版 legacy codecId=12：`frameType(4bit) + codecId(4bit)`，随后 packetType(1B) + cts(3B)。
 *
 * HEVC 载荷格式按 packetType 区分：
 *  - SequenceStart(0)：hvcC 配置记录（原样）
 *  - CodedFrames(1)：CompositionTime(3B, 有符号) + 4 字节长度前缀的 NALU
 *  - CodedFramesX(3)：4 字节长度前缀的 NALU（无 CTS，按 0 处理）
 *  - SequenceEnd(2) / Metadata(4)：无音视频载荷
 */
class HEVCPacket
{
    /** 序列头（hvcC 配置记录） */
    public const HEVC_PACKET_TYPE_SEQUENCE_START = 0;
    /** 普通帧（带 CompositionTime） */
    public const HEVC_PACKET_TYPE_CODED_FRAMES = 1;
    /** 序列结束 */
    public const HEVC_PACKET_TYPE_SEQUENCE_END = 2;
    /** 普通帧（不带 CompositionTime，CTS 视为 0） */
    public const HEVC_PACKET_TYPE_CODED_FRAMES_X = 3;
    /** 编码侧元数据（colorInfo 等），无音视频载荷 */
    public const HEVC_PACKET_TYPE_METADATA = 4;
    /** MPEG2-TS 序列头，FLV 直播中基本不出现 */
    public const HEVC_PACKET_TYPE_MPEG2TS_SEQUENCE_START = 5;

    public int $hevcPacketType = 0;
    public int $compositionTime = 0;

    /**
     * 载荷起点：序列头为 hvcC 原始字节，帧为 NALU 区（4 字节长度前缀）。
     */
    public BinaryStream $stream;

    /**
     * @param BinaryStream $stream 已跳过「首字节 + FourCC」的流
     * @param int $packetType Enhanced RTMP 从首字节取出的 packetType；legacy 传 -1 表示需自行读取
     */
    public function __construct(BinaryStream $stream, int $packetType)
    {
        $this->stream = $stream;

        if ($packetType >= 0) {
            // Enhanced RTMP：packetType 已由 VideoFrame 从首字节解析
            $this->hevcPacketType = $packetType;
        } else {
            // legacy codecId=12：packetType(1B) + cts(3B)
            if (!$stream->has(4)) {
                $this->stream = new BinaryStream('');
                return;
            }
            $this->hevcPacketType = $stream->readTinyInt();
            $this->compositionTime = self::toSigned24($stream->readInt24());
            return;
        }

        // Enhanced RTMP 仅有 CodedFrames 带 CompositionTime；
        // CodedFramesX 明确不带，其余类型也没有该字段。
        if ($this->hevcPacketType === self::HEVC_PACKET_TYPE_CODED_FRAMES) {
            if ($stream->has(3)) {
                $this->compositionTime = self::toSigned24($stream->readInt24());
            }
        }
    }

    /**
     * 是否为带可解码画面的视频帧（序列头 / 元数据等不算）。
     *
     * @return bool
     */
    public function isCodedFrame(): bool
    {
        return $this->hevcPacketType === self::HEVC_PACKET_TYPE_CODED_FRAMES
            || $this->hevcPacketType === self::HEVC_PACKET_TYPE_CODED_FRAMES_X;
    }

    /**
     * 读取剩余的载荷字节（hvcC 或 NALU 区）。
     *
     * @return string
     */
    public function readPayload(): string
    {
        return $this->stream->readRaw();
    }

    /**
     * @var HEVCSequenceParameterSet|null
     */
    protected ?HEVCSequenceParameterSet $sequenceParameterSet = null;

    /**
     * 解析序列头中的宽高等信息（仅对序列头有效）。
     *
     * @return HEVCSequenceParameterSet
     */
    public function getHEVCSequenceParameterSet(): HEVCSequenceParameterSet
    {
        if (!$this->sequenceParameterSet) {
            $this->sequenceParameterSet = new HEVCSequenceParameterSet($this->stream->readRawRemaining());
        }
        return $this->sequenceParameterSet;
    }

    /**
     * 把 24 位无符号值按有符号解释（CTS 可能为负）。
     *
     * @param int $v
     * @return int
     */
    private static function toSigned24(int $v): int
    {
        return ($v & 0x800000) ? $v - 0x1000000 : $v;
    }
}
