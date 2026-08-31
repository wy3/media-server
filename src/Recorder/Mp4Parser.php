<?php

declare(strict_types=1);

namespace MediaServer\Recorder;

/**
 * 录制 fMP4 分段解析器。
 *
 * 从录制文件中读取 moov 编解码信息（avcC/esds 等）以及每个 moof+mdat
 * 的样本表（dts/时长/大小/文件偏移），供指定时间回放切片使用。
 *
 * 由于录制器每个 moof 只含一个 traf 且 mdat 紧随其后，样本文件偏移可
 * 通过 trun 内样本大小顺序累加得到，无多轨交错歧义。
 */
class Mp4Parser
{
    /**
     * @return array{video:array|null,audio:array|null}
     */
    public static function parseSegment(string $file): array
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return ['video' => null, 'audio' => null];
        }

        try {
            $video = ['samples' => [], 'timescale' => Mp4Muxer::VIDEO_TIMESCALE];
            $audio = ['samples' => [], 'timescale' => Mp4Muxer::AUDIO_TIMESCALE];
            $pendingVideo = null;
            $pendingAudio = null;
            $configDone = false;

            $pos = 0;
            while (true) {
                $header = self::readAt($fh, $pos, 8);
                if ($header === false || strlen($header) < 8) {
                    break;
                }
                $size = unpack('N', substr($header, 0, 4))[1];
                $type = substr($header, 4, 4);
                if ($size < 8) {
                    break;
                }

                if ($type === 'moov' && !$configDone) {
                    $moov = self::readAt($fh, $pos + 8, $size - 8);
                    $cfg = $moov !== false ? self::parseMoov($moov) : [];
                    $video['avcC'] = (string)($cfg['avcC'] ?? '');
                    $video['width'] = (int)($cfg['width'] ?? 0);
                    $video['height'] = (int)($cfg['height'] ?? 0);
                    $audio['esds'] = (string)($cfg['esds'] ?? '');
                    $audio['channels'] = (int)($cfg['channels'] ?? 0);
                    $audio['samplerate'] = (int)($cfg['samplerate'] ?? 0);
                    $configDone = true;
                } elseif ($type === 'moof') {
                    $moof = self::readAt($fh, $pos + 8, $size - 8);
                    if ($moof !== false) {
                        $track = self::parseMoof($moof);
                        if ($track['trackId'] === Mp4Muxer::VIDEO_TRACK_ID) {
                            $pendingVideo = $track['samples'];
                        } else {
                            $pendingAudio = $track['samples'];
                        }
                    }
                } elseif ($type === 'mdat') {
                    $dataStart = $pos + 8;
                    if ($pendingVideo !== null) {
                        self::assignOffsets($pendingVideo, $dataStart);
                        $video['samples'] = array_merge($video['samples'], $pendingVideo);
                        $pendingVideo = null;
                    }
                    if ($pendingAudio !== null) {
                        self::assignOffsets($pendingAudio, $dataStart);
                        $audio['samples'] = array_merge($audio['samples'], $pendingAudio);
                        $pendingAudio = null;
                    }
                }

                $pos += $size;
            }

            return [
                'video' => $video['samples'] ? $video : null,
                'audio' => $audio['samples'] ? $audio : null,
            ];
        } finally {
            fclose($fh);
        }
    }

    //--------------------------------
    //		moov
    //--------------------------------

    protected static function parseMoov(string $moov): array
    {
        $cfg = ['avcC' => '', 'width' => 0, 'height' => 0, 'esds' => '', 'channels' => 0, 'samplerate' => 0];

        self::walk($moov, 0, strlen($moov), function ($type, $start, $end) use (&$cfg, $moov) {
            if ($type !== 'trak') {
                return;
            }
            self::walk($moov, $start, $end, function ($t2, $s2, $e2) use (&$cfg, $moov) {
                if ($t2 !== 'mdia') {
                    return;
                }
                self::walk($moov, $s2, $e2, function ($t3, $s3, $e3) use (&$cfg, $moov) {
                    if ($t3 !== 'minf') {
                        return;
                    }
                    self::walk($moov, $s3, $e3, function ($t4, $s4, $e4) use (&$cfg, $moov) {
                        if ($t4 !== 'stbl') {
                            return;
                        }
                        self::walk($moov, $s4, $e4, function ($t5, $s5, $e5) use (&$cfg, $moov) {
                            if ($t5 === 'stsd') {
                                self::parseStsd(substr($moov, $s5, $e5 - $s5), $cfg);
                            }
                        });
                    });
                });
            });
        });

        return $cfg;
    }

    protected static function parseStsd(string $stsd, array &$cfg): void
    {
        $payload = substr($stsd, 8); // 跳过 version/flags + entry_count
        $pos = 0;
        $len = strlen($payload);
        while ($pos + 8 <= $len) {
            $size = unpack('N', substr($payload, $pos, 4))[1];
            $type = substr($payload, $pos + 4, 4);
            if ($size < 8 || $pos + $size > $len) {
                break;
            }
            $entry = substr($payload, $pos + 8, $size - 8);
            if ($type === 'avc1') {
                $cfg['width'] = unpack('n', substr($entry, 24, 2))[1];
                $cfg['height'] = unpack('n', substr($entry, 26, 2))[1];
                self::walk($entry, 78, strlen($entry), function ($t, $s, $e) use (&$cfg, $entry) {
                    if ($t === 'avcC') {
                        $cfg['avcC'] = substr($entry, $s, $e - $s);
                    }
                });
            } elseif ($type === 'mp4a') {
                $cfg['channels'] = unpack('n', substr($entry, 16, 2))[1];
                $rateFixed = unpack('N', substr($entry, 24, 4))[1];
                $cfg['samplerate'] = $rateFixed >> 16;
                self::walk($entry, 28, strlen($entry), function ($t, $s, $e) use (&$cfg, $entry) {
                    if ($t === 'esds') {
                        $cfg['esds'] = self::parseEsds(substr($entry, $s, $e - $s));
                    }
                });
            }
            $pos += $size;
        }
    }

    protected static function parseEsds(string $esds): string
    {
        $p = substr($esds, 4); // 跳过 version/flags
        [$tag, $len, $p] = self::readDescriptor($p);
        if ($tag !== 0x03) {
            return '';
        }
        $p = substr($p, 3); // 跳过 ES_ID(2) + flags(1)
        [$tag2, $len2, $p] = self::readDescriptor($p);
        if ($tag2 !== 0x04) {
            return '';
        }
        $p = substr($p, 13); // 跳过 objectTypeIndication/streamType/bufferSizeDB/maxBitrate/avgBitrate
        [$tag3, $len3, $p] = self::readDescriptor($p);
        if ($tag3 !== 0x05) {
            return '';
        }
        return substr($p, 0, $len3);
    }

    //--------------------------------
    //		moof
    //--------------------------------

    protected static function parseMoof(string $moof): array
    {
        $result = ['trackId' => 0, 'samples' => []];

        self::walk($moof, 0, strlen($moof), function ($type, $start, $end) use (&$result, $moof) {
            if ($type !== 'traf') {
                return;
            }
            $trackId = 0;
            $baseDts = 0;
            $trun = '';
            self::walk($moof, $start, $end, function ($t2, $s2, $e2) use ($moof, &$trackId, &$baseDts, &$trun) {
                if ($t2 === 'tfhd') {
                    $p = substr($moof, $s2, $e2 - $s2);
                    $trackId = unpack('N', substr($p, 4, 4))[1];
                } elseif ($t2 === 'tfdt') {
                    $p = substr($moof, $s2, $e2 - $s2);
                    $version = ord($p[0]);
                    if ($version === 1) {
                        $hi = unpack('N', substr($p, 4, 4))[1];
                        $lo = unpack('N', substr($p, 8, 4))[1];
                        $baseDts = ($hi << 32) | $lo;
                    } else {
                        $baseDts = unpack('N', substr($p, 4, 4))[1];
                    }
                } elseif ($t2 === 'trun') {
                    $trun = substr($moof, $s2, $e2 - $s2);
                }
            });
            $result['trackId'] = $trackId;
            $result['samples'] = self::parseTrun($trun, $baseDts);
        });

        return $result;
    }

    protected static function parseTrun(string $p, int $baseDts): array
    {
        if ($p === '' || strlen($p) < 8) {
            return [];
        }
        $version = ord($p[0]);
        $flags = (ord($p[1]) << 16) | (ord($p[2]) << 8) | ord($p[3]);
        $count = unpack('N', substr($p, 4, 4))[1];
        $pos = 8;

        if ($flags & 0x01) {
            $pos += 4; // data_offset
        }
        $firstFlags = 0;
        if ($flags & 0x100) {
            $firstFlags = unpack('N', substr($p, $pos, 4))[1];
            $pos += 4;
        }

        $samples = [];
        $dts = $baseDts;
        for ($i = 0; $i < $count; $i++) {
            $dur = 0;
            $size = 0;
            // 仅首样本使用 first_sample_flags；其余样本未显式声明 flags 时
            // 取默认 0x00010000（trex 默认 sample_depends_on=2，非同步帧）
            $sflags = $i === 0 ? $firstFlags : 0x00010000;
            $cts = 0;
            if ($flags & 0x04) {
                $dur = unpack('N', substr($p, $pos, 4))[1];
                $pos += 4;
            }
            if ($flags & 0x08) {
                $size = unpack('N', substr($p, $pos, 4))[1];
                $pos += 4;
            }
            if ($flags & 0x10) {
                $sflags = unpack('N', substr($p, $pos, 4))[1];
                $pos += 4;
            }
            if ($flags & 0x200) {
                $v = unpack('N', substr($p, $pos, 4))[1];
                $cts = $version === 1 ? self::toSigned32($v) : $v;
                $pos += 4;
            }

            $samples[] = [
                'dts' => $dts,
                'cts' => $cts,
                'dur' => $dur,
                'size' => $size,
                'flags' => $sflags,
                'key' => ($sflags & 0x00010000) === 0,
            ];
            $dts += $dur;
        }

        return $samples;
    }

    //--------------------------------
    //		工具
    //--------------------------------

    protected static function assignOffsets(array &$samples, int $dataStart): void
    {
        $offset = $dataStart;
        foreach ($samples as &$s) {
            $s['offset'] = $offset;
            $offset += $s['size'];
        }
        unset($s);
    }

    /**
     * @return string|false
     */
    protected static function readAt($fh, int $pos, int $len)
    {
        if (fseek($fh, $pos) !== 0) {
            return false;
        }
        return fread($fh, $len);
    }

    protected static function walk(string $data, int $start, int $end, callable $visitor): void
    {
        $pos = $start;
        while ($pos + 8 <= $end) {
            $size = unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            if ($size < 8 || $pos + $size > $end) {
                break;
            }
            $visitor($type, $pos + 8, $pos + $size);
            $pos += $size;
        }
    }

    protected static function readDescriptor(string $p): array
    {
        $tag = ord($p[0]);
        $pos = 1;
        $len = 0;
        do {
            $b = ord($p[$pos++]);
            $len = ($len << 7) | ($b & 0x7F);
        } while ($b & 0x80);
        return [$tag, $len, substr($p, $pos)];
    }

    protected static function toSigned32(int $v): int
    {
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }
}
