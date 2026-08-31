<?php

declare(strict_types=1);

namespace MediaServer\Recorder;

use Throwable;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

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
 */
class PlaybackServer
{
    public static function handlePlaybackRequest(Request $request, string $path): void
    {
        if (strpos($path, '..') !== false || strpos($path, "\\") !== false) {
            $request->connection->send(new Response(400, [], 'Bad Request.'));
            return;
        }

        $start = (int)$request->get('start', 0);
        $end = (int)$request->get('end', 0);
        if ($end <= 0) {
            $end = PHP_INT_MAX;
        }
        if ($end <= $start) {
            $request->connection->send(new Response(400, ['Content-Type' => 'text/plain'], 'invalid time range'));
            return;
        }

        try {
            $body = self::buildPlayback($path, $start, $end);
        } catch (Throwable $e) {
            logger()->error('playback error {msg}', ['msg' => $e->getMessage()]);
            $request->connection->send(new Response(500, ['Content-Type' => 'text/plain'], 'playback error'));
            return;
        }

        if ($body === null) {
            $request->connection->send(new Response(404, ['Content-Type' => 'text/plain'], 'no record'));
            return;
        }

        $request->connection->send(new Response(200, ['Content-Type' => 'video/mp4'], $body));
    }

    protected static function buildPlayback(string $path, int $start, int $end): ?string
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
                    // 该段已选中样本的 dts 跨度续接到全局游标
                    $last = $segSamples[count($segSamples) - 1];
                    $videoTimeline += (int)$last['dts'] - $segFirstDts;
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
                    $last = $segSamples[count($segSamples) - 1];
                    $audioTimeline += (int)$last['dts'] - $segFirstDts;
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

        $out = Mp4Muxer::ftyp() . Mp4Muxer::moov($videoCfg ?? [], $audioCfg ?? []);
        $seq = 1;

        if ($videoSelected) {
            $moofSamples = self::toMoofSamples($videoSelected, $base);
            $out .= Mp4Muxer::buildTrackFragment($seq, Mp4Muxer::VIDEO_TRACK_ID, $moofSamples, Mp4Muxer::VIDEO_TIMESCALE, $moofSamples[0]['dts']);
            $out .= Mp4Muxer::mdat(self::readSamples($videoSelected));
            $seq++;
        }
        if ($audioSelected) {
            $moofSamples = self::toMoofSamples($audioSelected, $base);
            $out .= Mp4Muxer::buildTrackFragment($seq, Mp4Muxer::AUDIO_TRACK_ID, $moofSamples, Mp4Muxer::AUDIO_TIMESCALE, $moofSamples[0]['dts']);
            $out .= Mp4Muxer::mdat(self::readSamples($audioSelected));
        }

        return $out;
    }

    /**
     * @param array<int, array{dts:int,size:int,key?:bool,cts?:int}> $samples
     */
    protected static function toMoofSamples(array $samples, int $base): array
    {
        $n = count($samples);
        $out = [];
        foreach ($samples as $i => $s) {
            $dur = $i + 1 < $n ? $samples[$i + 1]['dts'] - $s['dts'] : 40;
            if ($dur <= 0) {
                $dur = 40;
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

    /**
     * @param array<int, array{file:string,offset:int,size:int}> $samples
     */
    protected static function readSamples(array $samples): string
    {
        $data = '';
        foreach ($samples as $s) {
            $chunk = @file_get_contents($s['file'], false, null, (int)$s['offset'], (int)$s['size']);
            $data .= $chunk === false ? '' : $chunk;
        }
        return $data;
    }
}
