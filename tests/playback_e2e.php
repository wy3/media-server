<?php

/**
 * 回放链路 E2E 测试（可复用）。
 *
 * 用法：
 *   php tests/playback_e2e.php            # 运行全部用例
 *   php tests/playback_e2e.php aonly      # 只运行名称匹配的用例
 *
 * 前置：媒体服务器已启动（RTMP 1935 / HTTP 18080），ffmpeg/ffprobe 可用。
 *
 * 用例为数据驱动：新增场景只需在 $CASES 中加一项，无需写新代码。
 * 每个用例流程：清理旧录像 → ffmpeg 推流 → 等待分段落盘 → 发起回放请求 →
 * ffprobe 校验轨道与时长 → ffmpeg 全量解码校验 → 输出 PASS/FAIL。
 */

declare(strict_types=1);

const RTMP_URL = 'rtmp://127.0.0.1:1935';
const PLAYBACK_BASE = 'http://127.0.0.1:18080/playback/';
const DB_FILE = __DIR__ . '/../record/recordings.db';
const WORK_DIR = __DIR__ . '/tmp';

//--------------------------------
//		用例定义
//--------------------------------

/**
 * stream: 推流构成；ranges: 回放校验（t0/t1 为相对推流起点的秒数，boundary=true 时围绕首个段边界取窗）
 * expect_video/expect_audio: 回放输出应包含的轨道；max_gain: 预滚导致的时长上浮（秒）
 */
$CASES = [
    [
        'name' => 'av',
        'desc' => '音视频流：录制、整段回放、中段回放',
        'stream' => ['video' => true, 'audio' => true, 'duration' => 12],
        'ranges' => [
            ['t0' => 0.0, 't1' => 12.0, 'expect_video' => true, 'expect_audio' => true, 'max_gain' => 2.5, 'decode' => true],
            ['t0' => 5.0, 't1' => 8.0, 'expect_video' => true, 'expect_audio' => true, 'max_gain' => 2.5, 'decode' => true],
        ],
    ],
    [
        'name' => 'vonly',
        'desc' => '纯视频流（无音频）：录制与回放不含音频轨',
        'stream' => ['video' => true, 'audio' => false, 'duration' => 10],
        'ranges' => [
            ['t0' => 0.0, 't1' => 10.0, 'expect_video' => true, 'expect_audio' => false, 'max_gain' => 2.5, 'decode' => true],
        ],
    ],
    [
        'name' => 'aonly',
        'desc' => '纯音频流（无视频）：录制与回放不含视频轨',
        'stream' => ['video' => false, 'audio' => true, 'duration' => 10],
        'ranges' => [
            ['t0' => 0.0, 't1' => 10.0, 'expect_video' => false, 'expect_audio' => true, 'max_gain' => 2.5, 'decode' => true],
        ],
    ],
    [
        'name' => 'av_multi_segment',
        'desc' => '音视频长流：触发分段轮转（2 段）并校验跨段边界回放',
        'stream' => ['video' => true, 'audio' => true, 'duration' => 65],
        'expect_min_segments' => 2,
        'ranges' => [
            ['t0' => 0.0, 't1' => 65.0, 'expect_video' => true, 'expect_audio' => true, 'max_gain' => 2.5, 'decode' => true],
            ['boundary' => true, 'half' => 2.0, 'expect_video' => true, 'expect_audio' => true, 'max_gain' => 2.5, 'decode' => true],
        ],
    ],
];

//--------------------------------
//		执行
//--------------------------------

$filter = $argv[1] ?? '';
@mkdir(WORK_DIR, 0777, true);
$failures = 0;
$ran = 0;

foreach ($CASES as $case) {
    if ($filter !== '' && stripos($case['name'], $filter) === false) {
        continue;
    }
    $ran++;
    echo "==== [{$case['name']}] {$case['desc']} ====\n";
    try {
        runCase($case);
        echo "---- PASS: {$case['name']} ----\n\n";
    } catch (Throwable $e) {
        $failures++;
        echo '---- FAIL: ' . $case['name'] . ' : ' . $e->getMessage() . " ----\n\n";
    }
}

if ($ran === 0) {
    exit("no case matched filter '{$filter}'\n");
}
echo $failures === 0 ? "ALL {$ran} CASE(S) PASSED\n" : "{$failures}/{$ran} CASE(S) FAILED\n";
exit($failures === 0 ? 0 : 1);

//--------------------------------
//		用例执行与断言
//--------------------------------

function runCase(array $case): void
{
    $path = '/live/e2e_' . $case['name'];
    $stream = $case['stream'];
    $duration = (int)$stream['duration'];

    cleanupStream($path);
    $proc = pushStream($path, $stream);

    $expectSegs = $case['expect_min_segments'] ?? 1;
    $segs = waitForSegments($path, $expectSegs, $duration + 30);
    echo '  segments: ' . count($segs) . "\n";
    if (count($segs) < $expectSegs) {
        fail('expected >= ' . $expectSegs . ' segments, got ' . count($segs));
    }

    // 录制分段本身的自检：ffprobe 原始分段
    foreach ($segs as $seg) {
        $probe = probe($seg['abs']);
        $hasV = streamOf($probe, 'video') !== null;
        $hasA = streamOf($probe, 'audio') !== null;
        if ($hasV !== $stream['video']) {
            fail('segment ' . $seg['file'] . ' video track = ' . var_export($hasV, true));
        }
        if ($hasA !== $stream['audio']) {
            fail('segment ' . $seg['file'] . ' audio track = ' . var_export($hasA, true));
        }
    }

    $startWall = (int)$segs[0]['start'];
    $totalMs = $duration * 1000;

    foreach ($case['ranges'] as $ri => $range) {
        if (!empty($range['boundary'])) {
            if (count($segs) < 2) {
                fail('boundary range needs >= 2 segments');
            }
            $mid = (int)$segs[1]['start'];
            $qs = $mid - (int)($range['half'] * 1000);
            $qe = $mid + (int)($range['half'] * 1000);
            // 窗口不得超出录像实际覆盖（末段可能远短于一个整段）
            $qe = min($qe, (int)$segs[count($segs) - 1]['end']);
        } else {
            $qs = $startWall + (int)($range['t0'] * 1000);
            $qe = $startWall + min((int)($range['t1'] * 1000), $totalMs);
        }
        if ($qe <= $qs) {
            fail('invalid range');
        }

        $out = WORK_DIR . '/' . $case['name'] . "_r{$ri}.mp4";
        $url = PLAYBACK_BASE . ltrim($path, '/') . "?start={$qs}&end={$qe}";
        [$status, $size] = httpDownload($url, $out);
        if ($status !== 200) {
            fail("playback HTTP {$status} for {$url}");
        }
        if ($size === 0) {
            fail('playback body empty');
        }

        $probe = probe($out);
        $vs = streamOf($probe, 'video');
        $as = streamOf($probe, 'audio');
        $hasV = $vs !== null;
        $hasA = $as !== null;
        if ($hasV !== (bool)$range['expect_video']) {
            fail('range ' . $ri . ' video track = ' . var_export($hasV, true));
        }
        if ($hasA !== (bool)$range['expect_audio']) {
            fail('range ' . $ri . ' audio track = ' . var_export($hasA, true));
        }

        // 音画时长一致性：两轨都存在时，差值应在 200ms 内（编码器偏移 + 取整）
        if ($hasV && $hasA) {
            $vd = (float)$vs['duration'];
            $ad = (float)$as['duration'];
            if (abs($vd - $ad) > 0.2) {
                fail(sprintf('range %d a/v duration gap %.3fs (video=%.3f audio=%.3f)', $ri, abs($vd - $ad), $vd, $ad));
            }
        }

        // 回放时长：请求跨度 <= 输出时长 <= 请求跨度 + 预滚上浮
        $span = ($qe - $qs) / 1000.0;
        $track = $hasV ? $vs : $as;
        $outDur = $track !== null ? (float)($track['duration'] ?? 0) : 0;
        if ($outDur < $span - 0.5) {
            fail(sprintf('range %d output duration %.3fs < requested span %.3fs', $ri, $outDur, $span));
        }
        if ($outDur > $span + (float)$range['max_gain'] + 0.5) {
            fail(sprintf('range %d output duration %.3fs exceeds span %.3fs + gain', $ri, $outDur, $span));
        }

        if (!empty($range['decode'])) {
            $errs = decodeErrors($out);
            if ($errs > 0) {
                fail('range ' . $ri . ' decode errors: ' . $errs);
            }
        }
        echo '  range ' . $ri . ': [' . $qs . ',' . $qe . '] out=' . sprintf('%.3fs', $outDur) . ' v=' . var_export($hasV, true) . ' a=' . var_export($hasA, true) . ' size=' . $size . "\n";
        @unlink($out);
    }

    // 推流进程应已正常结束
    waitForExit($proc, 10);
}

function fail(string $msg): void
{
    throw new RuntimeException($msg);
}

//--------------------------------
//		工具：推流 / DB / HTTP / ffprobe
//--------------------------------

/** 启动 ffmpeg 推流（后台），返回进程句柄 */
function pushStream(string $path, array $stream)
{
    $url = RTMP_URL . $path;
    $dur = (int)$stream['duration'];
    $cmd = 'ffmpeg -y -loglevel error ';
    if ($stream['video']) {
        $cmd .= '-re -f lavfi -i "testsrc2=size=320x240:rate=25" ';
    }
    if ($stream['audio']) {
        $cmd .= '-re -f lavfi -i "sine=frequency=440:sample_rate=44100" ';
    }
    $cmd .= '-t ' . $dur . ' ';
    if ($stream['video']) {
        $cmd .= '-c:v libx264 -preset veryfast -tune zerolatency -g 50 -pix_fmt yuv420p ';
    }
    if ($stream['audio']) {
        $cmd .= '-c:a aac -b:a 64k -ar 44100 ';
    }
    if (!$stream['video'] || !$stream['audio']) {
        // 单流时显式禁用另一轨
        $cmd .= $stream['video'] ? '-an ' : '-vn ';
    }
    $cmd .= '-f flv ' . escapeshellarg($url);
    $proc = proc_open($cmd, [1 => ['file', WORK_DIR . '/push_' . basename($path) . '.log', 'w'], 2 => ['file', WORK_DIR . '/push_' . basename($path) . '.err', 'w']], $pipes);
    if (!is_resource($proc)) {
        fail('failed to start ffmpeg');
    }
    return $proc;
}

/** 清理指定流的旧分段文件与索引 */
function cleanupStream(string $path): void
{
    $dir = streamDir($path);
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.mp4') ?: [] as $f) {
            @unlink($f);
        }
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM segments WHERE stream_path = :p')->execute([':p' => $path]);
    $pdo->prepare('DELETE FROM streams WHERE path = :p')->execute([':p' => $path]);
}

/** 与 RecorderManager::recordDir 一致的目录换算（/live/xxx → record/live_xxx） */
function streamDir(string $path): string
{
    $s = trim($path, '/\\');
    $s = str_replace(['\\', ' ', "\0"], '_', $s);
    $s = preg_replace('#/+#', '_', $s) ?? $s;
    return __DIR__ . '/../record' . DIRECTORY_SEPARATOR . ($s === '' ? 'default' : $s);
}

function db(): PDO
{
    return new PDO('sqlite:' . DB_FILE);
}

/** 轮询等待指定流的分段数量达标 */
function waitForSegments(string $path, int $min, int $timeoutSec): array
{
    $deadline = time() + $timeoutSec;
    while (time() < $deadline) {
        $stmt = db()->prepare('SELECT file, start, end FROM segments WHERE stream_path = :p ORDER BY start');
        $stmt->execute([':p' => $path]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) >= $min) {
            foreach ($rows as &$r) {
                $r['abs'] = streamDir($path) . DIRECTORY_SEPARATOR . $r['file'];
            }
            return $rows;
        }
        sleep(1);
    }
    fail('timeout waiting for ' . $min . ' segments of ' . $path);
}

function httpDownload(string $url, string $outFile): array
{
    $meta = WORK_DIR . '/http_meta.txt';
    $cmd = 'curl --noproxy "*" -s -o ' . escapeshellarg($outFile)
        . ' -w "%{http_code} %{size_download}" ' . escapeshellarg($url) . ' > ' . escapeshellarg($meta);
    exec($cmd, $o, $code);
    if ($code !== 0) {
        fail('curl exited ' . $code);
    }
    $parts = explode(' ', trim((string)file_get_contents($meta)));
    return [(int)$parts[0], (int)($parts[1] ?? 0)];
}

function probe(string $file): array
{
    $cmd = 'ffprobe -v error -show_entries stream=codec_type,codec_name,duration -of json ' . escapeshellarg($file);
    exec($cmd . ' 2>&1', $o, $code);
    if ($code !== 0) {
        fail('ffprobe exited ' . $code . ' for ' . $file);
    }
    $json = json_decode(implode('', $o), true);
    return $json['streams'] ?? [];
}

function streamOf(array $streams, string $type): ?array
{
    foreach ($streams as $s) {
        if (($s['codec_type'] ?? '') === $type) {
            return $s;
        }
    }
    return null;
}

/** ffmpeg 全量解码，返回错误行数 */
function decodeErrors(string $file): int
{
    $cmd = 'ffmpeg -v error -i ' . escapeshellarg($file) . ' -f null - 2>&1';
    exec($cmd, $o);
    return count(array_filter($o, fn($l) => trim($l) !== ''));
}

function waitForExit($proc, int $timeoutSec): void
{
    $deadline = time() + $timeoutSec;
    while (time() < $deadline) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            proc_close($proc);
            return;
        }
        sleep(1);
    }
    proc_terminate($proc);
    proc_close($proc);
}
