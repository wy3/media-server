<?php

declare(strict_types=1);

namespace MediaServer\MediaReader;

use MediaServer\Utils\BitReader;

/**
 * HEVC（H.265）解码配置记录（hvcC）解析。
 *
 * 只解析出管理后台展示需要的信息：profile / level / 分辨率。
 * 分辨率必须从 SPS 的 Exp-Golomb 语法里算，hvcC 头本身不含宽高。
 *
 * 注意 hvcC 的 SPS 是「NALU 原始字节」（含 2 字节 NALU 头），
 * 而 H.264 的 avcC 中 SPS 也是同样布局，两者都需先跳过 NALU 头再读 RBSP。
 */
class HEVCSequenceParameterSet extends BitReader
{
    /** general_profile_idc → 名称 */
    private const PROFILE_NAMES = [
        1 => 'Main',
        2 => 'Main 10',
        3 => 'Main Still Picture',
        4 => 'RExt',
    ];

    public int $profile = 0;
    public int|float $level = 0;
    public int $width = 0;
    public int $height = 0;
    public int $bitDepth = 8;

    public function __construct(string $data)
    {
        // hvcC 的 SPS 数组里可能有很多条，这里只解析第一条用于取宽高
        parent::__construct($data);
        $this->readData();
    }

    /**
     * @return string
     */
    public function getHEVCProfileName(): string
    {
        return self::PROFILE_NAMES[$this->profile] ?? '';
    }

    /**
     * 解析 hvcC：
     *   configurationVersion(1) general_profile_space/tier/profile_idc(1) ...
     *   bitDepthLumaMinus8(1) bitDepthChromaMinus8(1) avgFrameRate(2)
     *   constantFrameRate/numTemporalLayers/temporalIdNested/lengthSizeMinusOne(1)
     *   numOfArrays(1) 之后是 VPS/SPS/PPS 数组
     *
     * @return void
     */
    public function readData(): void
    {
        // 偏移 1：general_profile_idc 位于第 2 字节的低 5 位
        $this->profile = ord($this->data[1] ?? "\0") & 0x1F;
        // 偏移 12：general_level_idc（值为 level * 30，即 93 表示 L3.1）
        $levelIdc = ord($this->data[12] ?? "\0");
        $this->level = $levelIdc === 0 ? 0 : round($levelIdc / 30, 1);
        // 偏移 17/18：bitDepthLumaMinus8 / bitDepthChromaMinus8
        $this->bitDepth = 8 + (ord($this->data[17] ?? "\0") & 0x07);

        $numArrays = ord($this->data[22] ?? "\0");
        $pos = 23;
        for ($i = 0; $i < $numArrays; $i++) {
            if ($pos + 3 > strlen($this->data)) {
                return;
            }
            $nalType = ord($this->data[$pos]) & 0x3F;
            $count = (ord($this->data[$pos + 1]) << 8) | ord($this->data[$pos + 2]);
            $pos += 3;
            for ($j = 0; $j < $count; $j++) {
                if ($pos + 2 > strlen($this->data)) {
                    return;
                }
                $len = (ord($this->data[$pos]) << 8) | ord($this->data[$pos + 1]);
                $pos += 2;
                if ($pos + $len > strlen($this->data)) {
                    return;
                }
                // SPS 的 NALU type 为 33
                if ($nalType === 33 && $this->width === 0) {
                    $sps = substr($this->data, $pos, $len);
                    $this->parseSps($sps);
                }
                $pos += $len;
            }
        }
    }

    /**
     * 解析 SPS RBSP 取出编码宽高。
     *
     * 语法参照 ITU-T H.265 §7.3.2.2，只需读到 conformance_window 即可得到显示尺寸。
     *
     * @param string $sps 含 2 字节 NALU 头的 SPS
     * @return void
     */
    protected function parseSps(string $sps): void
    {
        // 跳过 2 字节 NALU 头（nal_unit_type = 33）
        $rbsp = $this->stripEmulationPrevention(substr($sps, 2));
        $reader = new BitReader($rbsp);

        $reader->getBits(4);                     // sps_video_parameter_set_id
        $maxSubLayersMinus1 = $reader->getBits(3);
        $reader->getBits(1);                     // sps_temporal_id_nesting_flag

        // profile_tier_level(1, sps_max_sub_layers_minus1)
        $this->skipProfileTierLevel($reader, $maxSubLayersMinus1);

        $this->expGolombUe($reader);              // sps_seq_parameter_set_id
        $chromaFormatIdc = $this->expGolombUe($reader);
        if ($chromaFormatIdc === 3) {
            $reader->getBits(1);                  // separate_colour_plane_flag
        }
        $width = $this->expGolombUe($reader);     // pic_width_in_luma_samples
        $height = $this->expGolombUe($reader);    // pic_height_in_luma_samples

        $conformanceWindow = $reader->getBits(1);
        $left = $right = $top = $bottom = 0;
        if ($conformanceWindow) {
            $left = $this->expGolombUe($reader);
            $right = $this->expGolombUe($reader);
            $top = $this->expGolombUe($reader);
            $bottom = $this->expGolombUe($reader);
        }

        // 裁剪单位取决于色度采样格式：4:2:0/4:2:2 为 2，4:4:4 为 1
        $subWidthC = in_array($chromaFormatIdc, [1, 2], true) ? 2 : 1;
        $subHeightC = $chromaFormatIdc === 1 ? 2 : 1;

        $this->width = $width - ($left + $right) * $subWidthC;
        $this->height = $height - ($top + $bottom) * $subHeightC;
    }

    /**
     * 跳过 profile_tier_level 语法结构。
     *
     * @param BitReader $reader
     * @param int $maxSubLayersMinus1
     * @return void
     */
    protected function skipProfileTierLevel(BitReader $reader, int $maxSubLayersMinus1): void
    {
        $reader->getBits(2);                      // general_profile_space
        $reader->getBits(1);                      // general_tier_flag
        $reader->getBits(5);                      // general_profile_idc
        $reader->getBits(32);                     // general_profile_compatibility_flags
        $reader->getBits(16);                     // general_constraint_flags 前 16 位
        $reader->getBits(32);                     // 后 32 位
        $reader->getBits(8);                      // general_level_idc

        $subLayerProfilePresent = [];
        $subLayerLevelPresent = [];
        for ($i = 0; $i < $maxSubLayersMinus1; $i++) {
            $subLayerProfilePresent[$i] = $reader->getBits(1);
            $subLayerLevelPresent[$i] = $reader->getBits(1);
        }
        if ($maxSubLayersMinus1 > 0) {
            for ($i = $maxSubLayersMinus1; $i < 8; $i++) {
                $reader->getBits(2);              // reserved_zero_2bits
            }
        }
        for ($i = 0; $i < $maxSubLayersMinus1; $i++) {
            if ($subLayerProfilePresent[$i]) {
                // sub_layer profile 部分为 88 位（2+1+5+32+48）
                $reader->getBits(88);
            }
            if ($subLayerLevelPresent[$i]) {
                $reader->getBits(8);
            }
        }
    }

    /**
     * 去掉 RBSP 的防竞争字节（0x000003 → 0x0000）。
     *
     * @param string $data
     * @return string
     */
    protected function stripEmulationPrevention(string $data): string
    {
        return preg_replace('/\x00\x00\x03/', "\x00\x00", $data) ?? $data;
    }

    /**
     * 读取无符号 Exp-Golomb 码。
     *
     * @param BitReader $reader
     * @return int
     */
    protected function expGolombUe(BitReader $reader): int
    {
        $leadingZeros = 0;
        while ($reader->getBits(1) === 0 && $leadingZeros < 32) {
            $leadingZeros++;
        }
        if ($leadingZeros === 0) {
            return 0;
        }
        return (1 << $leadingZeros) - 1 + $reader->getBits($leadingZeros);
    }
}
