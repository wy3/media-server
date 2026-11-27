<?php

declare(strict_types=1);

/**
 * admin 进程：管理后台 + 录像回放 HTTP。
 *
 * 无本地流状态——/api 的注册表/统计类请求异步代理到 control（admin.list /
 * admin.stats），login/logout 本地处理（AdminAuth 进程内会话）。
 * 录像回放（/playback/）在本进程处理：持有 record 目录与 recordings.db 索引
 * （RecorderManager::$recordPath 指向共享 record 目录；不开启录制——录制由
 * record 进程负责）。
 */

namespace MediaServer\Process;

use MediaServer\MediaServer;
use MediaServer\Recorder\RecorderManager;

final class AdminProcess
{
    public static function run(int $httpPort, string $recordPath, string $registryAddr): void
    {
        RecorderManager::$enabled = false;
        RecorderManager::$recordPath = $recordPath;
        MediaServer::enableAdminProxy($registryAddr);

        $worker = new \Workerman\Worker('tcp://0.0.0.0:0');   // 无监听占位
        $worker->onWorkerStart = function ($w) use ($httpPort) {
            \MediaServer\Admin\AdminAuth::$startTime = timestamp();
            \MediaServer\Http\HttpWMServer::$publicPath = __DIR__ . '/../public';
            $httpServer = new \MediaServer\Http\AdminHttpServer(
                "\\MediaServer\\Http\\ExtHttpProtocol://0.0.0.0:$httpPort"
            );
            $httpServer->listen();
        };

        \Workerman\Worker::runAll();
    }
}
