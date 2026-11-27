<?php

declare(strict_types=1);

/**
 * 统一启动入口：配置全部服务后按多进程模式运行。
 *
 * 推流（ingest）与拉流（play）的 host/port 彼此独立配置。
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';

//录像配置
\MediaServer\Recorder\RecorderManager::$enabled = true;
// 录像数据存放在 public 之外，避免 recordings.db 与分段 .mp4 被 HTTP 直接下载
\MediaServer\Recorder\RecorderManager::$recordPath = __DIR__ . '/record';
\MediaServer\Recorder\RecorderManager::$fragmentDurationMs = 2000;  //fMP4 分片时长
\MediaServer\Recorder\RecorderManager::$segmentDurationMs = 60000;  //单个 .mp4 文件时长

//管理后台账号配置
\MediaServer\Admin\AdminAuth::$username = 'admin';
\MediaServer\Admin\AdminAuth::$password = 'admin123';

// ── 进程数量 ──
\MediaServer\ServerConfig::$ingestCount = 1;
\MediaServer\ServerConfig::$playCount = 1;
\MediaServer\ServerConfig::$recordCount = 1;

// ── 推流进程（ingest）监听配置 ──
\MediaServer\ServerConfig::$ingestRtmpHost = '0.0.0.0';
\MediaServer\ServerConfig::$ingestRtmpPort = 1935;
\MediaServer\ServerConfig::$ingestHttpHost = '0.0.0.0';
\MediaServer\ServerConfig::$ingestHttpPort = 18081;

// ── 拉流进程（play）监听配置 ──
\MediaServer\ServerConfig::$playRtmpHost = '0.0.0.0';
\MediaServer\ServerConfig::$playRtmpPort = 1936;
\MediaServer\ServerConfig::$playHttpHost = '0.0.0.0';
\MediaServer\ServerConfig::$playHttpPort = 18080;

// ── 管理后台（admin）监听配置 ──
\MediaServer\ServerConfig::$adminHttpHost = '0.0.0.0';
\MediaServer\ServerConfig::$adminHttpPort = 18099;

// ── 注册中心（control）监听配置 ──
\MediaServer\ServerConfig::$registryHost = '0.0.0.0';
\MediaServer\ServerConfig::$registryPort = 18090;
\MediaServer\ServerConfig::$registryClientAddr = '127.0.0.1:18090';

// 全部按多进程模式运行
require __DIR__ . '/start_multiprocess.php';
