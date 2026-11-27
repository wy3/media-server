<?php

declare(strict_types=1);

/**
 * 多进程模式启动器（Windows / Linux 通用）。
 *
 * 用法：php start_multiprocess.php [ingestCount] [playCount] [recordCount] [rtmpPort] [playRtmpPort] [httpPort] [ingestHttpPort]
 *
 * 职责：
 *   1. 启动 control 进程（流注册表 + 事件通知中心，监听 18090）
 *   2. 启动 N 个 ingest worker + M 个 play worker + K 个 record worker
 *   3. 监控子进程，退出时统一回收
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';


$ingestCount = max(0, (int)($argv[1] ?? 1));
$playCount = max(0, (int)($argv[2] ?? 1));
$recordCount = max(0, (int)($argv[3] ?? 1));
$rtmpPort = (int)($argv[4] ?? 1935);       // ingest: RTMP publish
$playRtmpPort = (int)($argv[5] ?? 1936);  // play: RTMP playback
$httpPort = (int)($argv[6] ?? 18080);     // play: HTTP-FLV playback
$ingestHttpPort = (int)($argv[7] ?? 18081); // ingest: HTTP-FLV publish
$controlHttpPort = (int)($argv[8] ?? 18099); // admin: HTTP 管理后台
$registryPort = (int)($argv[9] ?? 18090);    // control: 流注册表 TCP 服务
$adminHttpPort = $controlHttpPort;

if (!extension_loaded('shmop')) {
    fwrite(STDERR, "ERROR: ext-shmop required\n");
    exit(1);
}

printf(
    "Starting multi-process mode: ingest=%d play=%d record=%d\n  rtmp(publish)=%d  rtmp(play)=%d  http(play)=%d  http(publish)=%d\n",
    $ingestCount, $playCount, $recordCount, $rtmpPort, $playRtmpPort, $httpPort, $ingestHttpPort
);

$extDir = '';
if (PHP_OS_FAMILY === 'Windows' && extension_loaded('sockets')) {
    $ini = php_ini_loaded_file();
    $dir = $ini ? dirname($ini) . '/ext' : '';
    if ($dir && is_dir($dir)) $extDir = $dir;
}

/** @var array<int, array{proc:resource,pipes:array,role:string}> $workers */
$workers = [];

/**
 * 启动一个进程。
 *
 * @param string $process 进程名（worker.php 路由键）
 * @param list<string|int> $args 路由参数（依次跟在进程名后）
 */
function spawnWorker(string $process, array $args, string $extDir, string $logDir): void
{
    global $workers;

    $cmd = escapeshellarg(PHP_BINARY);
    if ($extDir !== '') {
        $cmd .= ' -d extension_dir=' . escapeshellarg($extDir);
        $cmd .= ' -d extension=sockets -d extension=event -d extension=shmop';
    }
    // php CLI 默认 memory_limit=128M，多路推流的 publisher/GOP 状态会超
    $memLimit = getenv('MP_MEMORY_LIMIT') ?: '1G';
    $cmd .= ' -d memory_limit=' . escapeshellarg($memLimit);
    $cmd .= ' ' . escapeshellarg(__DIR__ . '/worker.php');
    $cmd .= ' ' . escapeshellarg($process);
    foreach ($args as $a) {
        $cmd .= ' ' . (is_int($a) ? $a : escapeshellarg((string)$a));
    }

    $logFile = $logDir . '/' . $process . '.log';
    $handle = fopen($logFile, 'a');
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => $handle,
        2 => $handle,
    ];
    // 注册表 SQLite 路径用环境变量传（Workerman Windows 会把存在的 argv
    // 文件当 worker 脚本 fork，不能传文件路径）
    $env = array_merge(getenv() ?: [], ['MP_REGISTRY_DB' => $dbFile]);
    $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to spawn $process worker\n");
        return;
    }
    $workers[] = ['proc' => $proc, 'pipes' => $pipes, 'role' => $process];
    echo "spawned $process pid=" . proc_get_status($proc)['pid'] . " log=$logFile\n";
    if (getenv('MP_DEBUG')) {
        echo "  cmd: $cmd\n";
    }
}

$logDir = getenv('MP_LOG_DIR') ?: sys_get_temp_dir();
@mkdir($logDir, 0777, true);

$recordPath = __DIR__ . '/record';
$dbFile = $recordPath . '/registry.db';

// control 进程：注册表 + 事件通知中心（一直启动，注册表是全局依赖）
//   worker.php control [registryPort]；dbFile 用环境变量传
//   （Workerman Windows 会把存在的 argv 文件当 worker 脚本 fork，不能传文件路径）
spawnWorker('control', [$registryPort], $extDir, $logDir);

// admin 进程：管理后台 HTTP（从 control 拉数据）+ 录像回放
//   worker.php admin [httpPort] [recordPath] [registryAddr]
spawnWorker('admin', [$adminHttpPort, $recordPath, "127.0.0.1:$registryPort"], $extDir, $logDir);

// 多实例时端口偏移：第 i 个实例 = 基准端口 + i×10（避免监听冲突）
for ($i = 0; $i < $ingestCount; $i++) {
    //   worker.php ingest [rtmpPort] [httpPort] [registryAddr]
    spawnWorker('ingest', [$rtmpPort + $i * 10, $ingestHttpPort + $i * 10, "127.0.0.1:$registryPort"], $extDir, $logDir);
}
for ($i = 0; $i < $playCount; $i++) {
    //   worker.php play [rtmpPort] [httpPort] [registryAddr]
    spawnWorker('play', [$playRtmpPort + $i * 10, $httpPort + $i * 10, "127.0.0.1:$registryPort"], $extDir, $logDir);
}
for ($i = 0; $i < $recordCount; $i++) {
    //   worker.php record [recordPath] [registryAddr]
    spawnWorker('record', [$recordPath, "127.0.0.1:$registryPort"], $extDir, $logDir);
}

if (empty($workers)) {
    fwrite(STDERR, "ERROR: no workers spawned\n");
    exit(1);
}

echo "\nAll workers started. Ctrl+C to stop.\n";

// 主循环：转发 worker 输出，检测退出
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
}

while ($running) {
    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();

    foreach ($workers as $i => &$w) {
        $status = proc_get_status($w['proc']);
        if (!$status['running']) {
            echo "[{$w['role']}] exited with code {$status['exitcode']}\n";
            proc_close($w['proc']);
            unset($workers[$i]);
        }
    }
    unset($w);
    usleep(100000);
}

// 清理
foreach ($workers as $w) {
    $status = proc_get_status($w['proc']);
    if ($status['running']) {
        proc_terminate($w['proc']);
    }
    proc_close($w['proc']);
}
echo "All workers stopped.\n";
