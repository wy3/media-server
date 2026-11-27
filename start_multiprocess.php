<?php

declare(strict_types=1);

/**
 * 多进程模式启动器（Windows / Linux 通用）。
 *
 * 各服务的 host/port 与进程数统一从 MediaServer\ServerConfig 读取
 * （由 start.php 配置），推流（ingest）与拉流（play）彼此独立。
 *
 * 用法：php start_multiprocess.php
 *
 * 职责：
 *   1. 启动 control 进程（流注册表 + 事件通知中心，监听 18090）
 *   2. 启动 N 个 ingest worker + M 个 play worker + K 个 record worker
 *   3. 监控子进程，退出时统一回收
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';

use MediaServer\ServerConfig;

// 压测 / 容器场景：允许用环境变量覆盖监听地址与端口
ServerConfig::loadFromEnv();

$ingestCount = max(0, ServerConfig::$ingestCount);
$playCount = max(0, ServerConfig::$playCount);
$recordCount = max(0, ServerConfig::$recordCount);

$ingestRtmpHost = ServerConfig::$ingestRtmpHost;
$ingestRtmpPort = ServerConfig::$ingestRtmpPort;
$ingestHttpHost = ServerConfig::$ingestHttpHost;
$ingestHttpPort = ServerConfig::$ingestHttpPort;
$playRtmpHost = ServerConfig::$playRtmpHost;
$playRtmpPort = ServerConfig::$playRtmpPort;
$playHttpHost = ServerConfig::$playHttpHost;
$playHttpPort = ServerConfig::$playHttpPort;
$adminHttpHost = ServerConfig::$adminHttpHost;
$adminHttpPort = ServerConfig::$adminHttpPort;
$registryHost = ServerConfig::$registryHost;
$registryPort = ServerConfig::$registryPort;
$registryClientAddr = ServerConfig::$registryClientAddr;

if (!extension_loaded('shmop')) {
    fwrite(STDERR, "ERROR: ext-shmop required\n");
    exit(1);
}

printf(
    "Starting multi-process mode: ingest=%d play=%d record=%d\n"
    . "  ingest rtmp=%s:%d  ingest http=%s:%d\n"
    . "  play rtmp=%s:%d  play http=%s:%d\n"
    . "  admin http=%s:%d  registry=%s:%d\n",
    $ingestCount, $playCount, $recordCount,
    $ingestRtmpHost, $ingestRtmpPort, $ingestHttpHost, $ingestHttpPort,
    $playRtmpHost, $playRtmpPort, $playHttpHost, $playHttpPort,
    $adminHttpHost, $adminHttpPort, $registryHost, $registryPort
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
    global $workers, $dbFile;

    $cmd = escapeshellarg(PHP_BINARY);
    if ($extDir !== '') {
        $cmd .= ' -d extension_dir=' . escapeshellarg($extDir);
        $cmd .= ' -d extension=sockets -d extension=event -d extension=shmop';
        // JIT（压测开关）：opcache 扩展必须在 CLI 下显式启用
        if (getenv('MP_JIT') === '1') {
            $buffer = getenv('MP_JIT_BUFFER') ?: '100M';
            $cmd .= ' -d zend_extension=' . escapeshellarg($extDir . DIRECTORY_SEPARATOR . 'php_opcache.dll');
            $cmd .= ' -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=' . escapeshellarg($buffer);
        }
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

// control 进程：注册表 + 事件通知中心
//   worker.php control [registryPort] [registryHost]；dbFile 用环境变量传
//   （Workerman Windows 会把存在的 argv 文件当 worker 脚本 fork，不能传文件路径）
spawnWorker('control', [$registryPort, $registryHost], $extDir, $logDir);

// admin 进程：管理后台 HTTP（从 control 拉数据）+ 录像回放
//   worker.php admin [httpPort] [recordPath] [registryAddr] [httpHost]
spawnWorker('admin', [$adminHttpPort, $recordPath, $registryClientAddr, $adminHttpHost], $extDir, $logDir);

// 多实例时端口偏移：第 i 个实例 = 基准端口 + i×10（避免监听冲突）
for ($i = 0; $i < $ingestCount; $i++) {
    //   worker.php ingest [rtmpPort] [httpPort] [registryAddr] [rtmpHost] [httpHost]
    spawnWorker('ingest', [
        $ingestRtmpPort + $i * 10, $ingestHttpPort + $i * 10, $registryClientAddr,
        $ingestRtmpHost, $ingestHttpHost,
    ], $extDir, $logDir);
}
for ($i = 0; $i < $playCount; $i++) {
    //   worker.php play [rtmpPort] [httpPort] [registryAddr] [rtmpHost] [httpHost]
    spawnWorker('play', [
        $playRtmpPort + $i * 10, $playHttpPort + $i * 10, $registryClientAddr,
        $playRtmpHost, $playHttpHost,
    ], $extDir, $logDir);
}
for ($i = 0; $i < $recordCount; $i++) {
    //   worker.php record [recordPath] [registryAddr]
    spawnWorker('record', [$recordPath, $registryClientAddr], $extDir, $logDir);
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
