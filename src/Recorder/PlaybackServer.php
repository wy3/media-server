<?php

declare(strict_types=1);

namespace MediaServer\Recorder;

use Throwable;
use Workerman\Protocols\Http\Request;

/**
 * 指定时间回放服务。
 *
 * 请求格式：GET /playback/{publishPath}?start={wallMs}&end={wallMs}
 *
 * 实现思路（服务端切片）：
 *  1. 依据录像索引找出与 [start, end] 相交的分段；
 *  2. 解析首个分段样本表，视频从 start 之前最近的关键帧起（保证可解码），
 *     音频起点与视频关键帧对齐起点一致（跟随预滚，避免末尾只剩视频无声音），直至 end；
 *  3. 跨分段续接，统一时间轴后重新封装为新的 fMP4 返回。
 *
 * 输出采用流式发送：ftyp/moov/moof 及样本表在内存中构建（体积小），
 * mdat 数据按块从源分段文件读取直发，内存占用与回放时长无关。
 */
class PlaybackServer
{
    /** mdat 数据流式发送的块大小 */
    protected const STREAM_CHUNK = 1024 * 1024;

    public static function handlePlaybackRequest(Request $request, string $path): void
    {
        if (strpos($path, '..') !== false || strpos($path, "\\") !== false) {
            $request->connection->send("HTTP/1.1 400 Bad Request\r\nServer: workerman\r\nContent-Type: text/plain\r\nContent-Length: 12\r\nConnection: close\r\n\r\nBad Request.\n", true);
            return;
        }

        $start = (int)$request->get('start', 0);
        $end = (int)$request->get('end', 0);
        if ($end <= 0) {
            $end = PHP_INT_MAX;
        }
        if ($end <= $start) {
            $request->connection->send("HTTP/1.1 400 Bad Request\r\nServer: workerman\r\nContent-Type: text/plain\r\nContent-Length: 18\r\nConnection: close\r\n\r\ninvalid time range\r\n", true);
            return;
        }

        try {
            $plan = self::buildPlayback($path, $start, $end);
        } catch (Throwable $e) {
            logger()->error('playback error {msg}', ['msg' => $e->getMessage()]);
            $request->connection->send("HTTP/1.1 500 Internal Server Error\r\nServer: workerman\r\nContent-Type: text/plain\r\nContent-Length: 14\r\nConnection: close\r\n\r\nplayback error\r\n", true);
            return;
        }

        if ($plan === null) {
            $request->connection->send("HTTP/1.1 404 Not Found\r\nServer: workerman\r\nContent-Type: text/plain\r\nContent-Length: 9\r\nConnection: close\r\n\r\nno record\r\n", true);
            return;
        }

        $total = $plan['bytes'];
        foreach ($plan['parts'] as $part) {
            if (isset($part['bytes'])) {
                $total += strlen($part['bytes']);
            }
        }
        $head = "HTTP/1.1 200 OK\r\nServer: workerman\r\n"
            . "Content-Type: video/mp4\r\n"
            . "Content-Length: $total\r\n"
            . "Connection: keep-alive\r\n\r\n";
        $request->connection->send($head, true);
        self::sendParts($request->connection, $plan['parts']);
    }

    /**
     * 构建回放计划：按输出顺序排列的 parts（bytes=内存中的 box，samples=待流式发送的样本）。
     * 布局必须与 box 顺序一致：[ftyp+moov][moof_v][mdat_v头][视频数据][moof_a][mdat_a头][音频数据]。
     *
     * @return array{parts:array,bytes:int}|null
     */
    protected static function buildPlayback(string $path, int $start, int $end): ?array
    {
        $indexes = RecorderManager::listRecordFiles($path);
        if (!$indexes) {
            return null;
        }

        $selected = [];
        foreach ($indexes as $idx) {
            if (((int)($idx['end'] ?? 0)) >= $start && ((int)($idx['start'] ?? 0)) <= $end) {
                $selected[] = $idx;
            }
        }
        if (!$selected) {
            return null;
        }

        $dir = RecorderManager::recordDir($path);
        $videoCfg = null;
        $audioCfg = null;
        $items = [];
        foreach ($selected as $idx) {
            $file = $dir . DIRECTORY_SEPARATOR . $idx['file'];
            if (!is_file($file)) {
                continue;
            }
            $parsed = Mp4Parser::parseSegment($file);
            if ($parsed['video'] && $videoCfg === null) {
                $videoCfg = [
                    'avcC' => (string)$parsed['video']['avcC'],
                    'width' => (int)$parsed['video']['width'],
                    'height' => (int)$parsed['video']['height'],
                ];
            }
            if ($parsed['audio'] && $audioCfg === null) {
                $audioCfg = [
                    'esds' => (string)$parsed['audio']['esds'],
                    'channels' => (int)$parsed['audio']['channels'],
                    'samplerate' => (int)$parsed['audio']['samplerate'],
                ];
            }
            $items[] = ['idx' => $idx, 'parsed' => $parsed, 'file' => $file];
        }
        if ($videoCfg === null && $audioCfg === null) {
            return null;
        }

        // 跨分段选取样本；输出时间轴按 dts 连续续接（跨段不再用墙钟重锚定，
        // 避免墙钟与编码器时钟速率偏差导致分段边界 dts 负跳变/画面重复）。
        // 音频标称帧时长（AAC 每帧 1024 采样），用于末样本/段边界兜底，避免固定 40ms 虚高
        $audioNominal = $audioCfg !== null
            ? max(1, (int)round(1024 * 1000 / max(1, (int)$audioCfg['samplerate'])))
            : 23;
        $videoSelected = [];
        $audioSelected = [];
        $videoTimeline = 0; // 视频全局 dts 游标（首段自 0 起）
        $audioTimeline = 0; // 音频全局 dts 游标
        $videoStartWall = null; // 首段视频解码起点（关键帧对齐后）对应的墙钟时间，用于音频对齐
        $firstSeg = true;
        $reachedEnd = false;

        foreach ($items as $item) {
            $segStart = (int)$item['idx']['start'];
            $parsed = $item['parsed'];

            if ($parsed['video']) {
                $startIdx = 0;
                if ($firstSeg) {
                    // 取 start 之前最近的关键帧作为解码起点
                    $startAt = null;
                    foreach ($parsed['video']['samples'] as $i => $s) {
                        if ($segStart + $s['dts'] > $start) {
                            break;
                        }
                        if ($s['key']) {
                            $startAt = $i;
                        }
                    }
                    if ($startAt === null) {
                        foreach ($parsed['video']['samples'] as $i => $s) {
                            if ($s['key']) {
                                $startAt = $i;
                                break;
                            }
                        }
                    }
                    $startIdx = $startAt ?? 0;
                    // 记录视频解码起点的墙钟时间，音频起点与之一致，保证音画时长一致
                    $videoStartWall = $segStart + (int)$parsed['video']['samples'][$startIdx]['dts'];
                }
                $segSamples = array_slice($parsed['video']['samples'], $startIdx);
                if ($segSamples) {
                    $segFirstDts = (int)$segSamples[0]['dts'];
                    foreach ($segSamples as $s) {
                        if ($segStart + $s['dts'] > $end) {
                            $reachedEnd = true;
                            break;
                        }
                        $s['dts'] = $videoTimeline + ((int)$s['dts'] - $segFirstDts);
                        $s['file'] = $item['file'];
                        $videoSelected[] = $s;
                    }
                    // 该段已选中样本的 dts 跨度 + 末样本帧间隔续接到全局游标，
                    // 保证下一段首样本紧接本段末样本（原公式缺末样本时长，段间 dts 重叠）
                    $cnt = count($segSamples);
                    $last = $segSamples[$cnt - 1];
                    $lastGap = $cnt > 1 ? (int)$last['dts'] - (int)$segSamples[$cnt - 2]['dts'] : 0;
                    if ($lastGap <= 0) {
                        $lastGap = 40;
                    }
                    $videoTimeline += (int)$last['dts'] - $segFirstDts + $lastGap;
                }
            }

            if ($parsed['audio']) {
                $startIdx = 0;
                if ($firstSeg) {
                    // 音频起点与视频关键帧对齐起点一致（视频预滚若干 GOP，音频跟随，
                    // 避免输出末尾只剩视频无声音）；无视频时回退 200ms 预滚
                    $audioBoundary = $videoStartWall ?? ($start - 200);
                    foreach ($parsed['audio']['samples'] as $i => $s) {
                        if ($segStart + $s['dts'] >= $audioBoundary) {
                            $startIdx = $i;
                            break;
                        }
                    }
                }
                $segSamples = array_slice($parsed['audio']['samples'], $startIdx);
                if ($segSamples) {
                    $segFirstDts = (int)$segSamples[0]['dts'];
                    foreach ($segSamples as $s) {
                        if ($segStart + $s['dts'] > $end) {
                            $reachedEnd = true;
                            break;
                        }
                        $s['dts'] = $audioTimeline + ((int)$s['dts'] - $segFirstDts);
                        $s['file'] = $item['file'];
                        $audioSelected[] = $s;
                    }
                    $cnt = count($segSamples);
                    $last = $segSamples[$cnt - 1];
                    $lastGap = $cnt > 1 ? (int)$last['dts'] - (int)$segSamples[$cnt - 2]['dts'] : 0;
                    if ($lastGap <= 0) {
                        $lastGap = $audioNominal;
                    }
                    $audioTimeline += (int)$last['dts'] - $segFirstDts + $lastGap;
                }
            }

            if ($reachedEnd) {
                break; // 已越过 end，后续分段更晚，无需处理
            }
            $firstSeg = false;
        }

        if (!$videoSelected && !$audioSelected) {
            return null;
        }

        // 统一时间轴：以首段首个选中样本为 0 基准
        $base = PHP_INT_MAX;
        if ($videoSelected) {
            $base = min($base, (int)$videoSelected[0]['dts']);
        }
        if ($audioSelected) {
            $base = min($base, (int)$audioSelected[0]['dts']);
        }

        // 输出布局按 box 顺序交错：moof 头与对应 mdat 数据必须相邻，
        // mdat 仅写 8 字节头，数据按源文件连续区间流式发送（区间聚合 I/O）
        $parts = [];
        $parts[] = ['bytes' => Mp4Muxer::ftyp() . Mp4Muxer::moov($videoCfg ?? [], $audioCfg ?? [])];
        $seq = 1;

        if ($videoSelected) {
            $moofSamples = self::toMoofSamples($videoSelected, $base);
            $parts[] = ['bytes' => Mp4Muxer::buildTrackFragment($seq, Mp4Muxer::VIDEO_TRACK_ID, $moofSamples, Mp4Muxer::VIDEO_TIMESCALE, $moofSamples[0]['dts'])
                . self::mdatHeader(array_sum(array_column($videoSelected, 'size')))];
            $parts[] = ['ranges' => self::toRanges($videoSelected)];
            $seq++;
        }
        if ($audioSelected) {
            $moofSamples = self::toMoofSamples($audioSelected, $base, $audioNominal);
            $parts[] = ['bytes' => Mp4Muxer::buildTrackFragment($seq, Mp4Muxer::AUDIO_TRACK_ID, $moofSamples, Mp4Muxer::AUDIO_TIMESCALE, $moofSamples[0]['dts'])
                . self::mdatHeader(array_sum(array_column($audioSelected, 'size')))];
            $parts[] = ['ranges' => self::toRanges($audioSelected)];
        }

        $bytes = 0;
        foreach ($parts as $part) {
            if (isset($part['ranges'])) {
                foreach ($part['ranges'] as $r) {
                    $bytes += (int)$r['size'];
                }
            }
        }

        return ['parts' => $parts, 'bytes' => $bytes];
    }

    /**
     * 将样本序列归并为源文件内的连续读取区间：样本在 mdat 内连续存放，
     * 相邻且偏移衔接的样本合并为一个区间，减少流式发送时的 fseek/fread 次数；
     * 超过块预算的区间切分为连续子区间，保证每个区间单次读完（无需断点续读状态）。
     *
     * @param array<int, array{file:string,offset:int,size:int}> $samples
     * @return array<int, array{file:string,offset:int,size:int}>
     */
    protected static function toRanges(array $samples): array
    {
        $ranges = [];
        foreach ($samples as $s) {
            $n = count($ranges);
            $file = (string)$s['file'];
            $offset = (int)$s['offset'];
            $size = (int)$s['size'];
            if ($n > 0 && $ranges[$n - 1]['file'] === $file
                && $ranges[$n - 1]['offset'] + $ranges[$n - 1]['size'] === $offset) {
                $ranges[$n - 1]['size'] += $size;
            } else {
                $ranges[] = ['file' => $file, 'offset' => $offset, 'size' => $size];
            }
        }
        $out = [];
        foreach ($ranges as $r) {
            while ($r['size'] > self::STREAM_CHUNK) {
                $out[] = ['file' => $r['file'], 'offset' => $r['offset'], 'size' => self::STREAM_CHUNK];
                $r['offset'] += self::STREAM_CHUNK;
                $r['size'] -= self::STREAM_CHUNK;
            }
            if ($r['size'] > 0) {
                $out[] = $r;
            }
        }
        return $out;
    }

    protected static function mdatHeader(int $payloadLen): string
    {
        return pack('N', 8 + $payloadLen) . 'mdat';
    }

    /**
     * 按顺序发送 parts（带背压的事件驱动状态机）。
     *
     * bytes 部件同步直发；ranges 部件按连续区间读取源文件直发。发送缓冲写满时挂起，
     * 待 onBufferDrain 后从断点继续（协作式分时，单次事件内的读盘量受块预算约束，
     * 不会长时间阻塞事件循环）。所有部件共用一套回调，避免相互覆盖导致流遗弃。
     *
     * @param array<int, array{bytes?:string,ranges?:array}> $parts
     */
    protected static function sendParts($connection, array $parts): void
    {
        $connection->bufferFull = false;
        $n = count($parts);
        $state = ['i' => 0, 'ri' => 0, 'fh' => null, 'file' => null, 'buf' => ''];

        $closeFh = function () use (&$state) {
            if (is_resource($state['fh'])) {
                fclose($state['fh']);
            }
            $state['fh'] = null;
            $state['buf'] = '';
        };

        $finish = function () use ($connection, $closeFh) {
            $closeFh();
            $connection->onBufferFull = null;
            $connection->onBufferDrain = null;
        };

        $do_write = function () use ($connection, $parts, $n, &$state, $closeFh, $finish) {
            while ($connection->bufferFull === false) {
                if ($state['i'] >= $n) {
                    $finish();
                    return;
                }
                $part = $parts[$state['i']];
                if (isset($part['bytes'])) {
                    $connection->send($part['bytes'], true);
                    $state['i']++;
                    continue;
                }
                $ranges = $part['ranges'];
                $rn = count($ranges);
                while (strlen($state['buf']) < self::STREAM_CHUNK && $state['ri'] < $rn) {
                    $r = $ranges[$state['ri']++];
                    $state['buf'] .= self::readRangeData($state, $r);
                }
                if ($state['buf'] !== '') {
                    $connection->send($state['buf'], true);
                    $state['buf'] = '';
                }
                if ($state['ri'] >= $rn) {
                    $closeFh();
                    $state['i']++;
                    $state['ri'] = 0;
                }
            }
        };

        $connection->onBufferFull = function ($conn) {
            $conn->bufferFull = true;
        };
        $connection->onBufferDrain = function ($conn) use ($do_write) {
            $conn->bufferFull = false;
            $do_write();
        };
        $do_write();
    }

    /**
     * 读取一个连续区间的数据（区间已在构建时切分至块预算内，单次读完）。
     *
     * @param array{fh:resource|null,file:string|null,buf:string} $state
     * @param array{file:string,offset:int,size:int} $r
     */
    protected static function readRangeData(array &$state, array $r): string
    {
        $file = (string)$r['file'];
        if (!is_resource($state['fh']) || $state['file'] !== $file) {
            if (is_resource($state['fh'])) {
                fclose($state['fh']);
            }
            $state['fh'] = @fopen($file, 'rb');
            $state['file'] = $file;
        }
        $size = (int)$r['size'];
        if ($state['fh'] === false || $state['fh'] === null) {
            return str_repeat("\x00", $size); // 读取失败以零填充，保持 Content-Length 精确
        }
        fseek($state['fh'], (int)$r['offset']);
        $data = (string)@fread($state['fh'], $size);
        $len = strlen($data);
        if ($len < $size) {
            $data .= str_repeat("\x00", $size - $len);
        }
        return $data;
    }

    /**
     * @param array<int, array{dts:int,size:int,key?:bool,cts?:int}> $samples
     */
    protected static function toMoofSamples(array $samples, int $base, int $fallbackDur = 40): array
    {
        $n = count($samples);
        $out = [];
        foreach ($samples as $i => $s) {
            if ($i + 1 < $n) {
                $dur = (int)$samples[$i + 1]['dts'] - (int)$s['dts'];
            } else {
                // 末样本无后继可求差，沿用前一真实帧间隔，避免固定 40ms 造成音频时长虚高
                $dur = $n > 1 ? (int)$samples[$n - 1]['dts'] - (int)$samples[$n - 2]['dts'] : $fallbackDur;
            }
            if ($dur <= 0) {
                $dur = $fallbackDur;
            }
            $out[] = [
                'dts' => $s['dts'] - $base,
                'dur' => $dur,
                'cts' => (int)($s['cts'] ?? 0) & 0xFFFFFFFF,
                'size' => (int)$s['size'],
                'flags' => !empty($s['key']) ? 0x02000000 : 0x01010000,
            ];
        }
        return $out;
    }
}
