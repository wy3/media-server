<?php

declare(strict_types=1);

/**
 * control 进程：流注册表 + 事件通知中心（协议见 docs/multiprocess/06-registry-protocol.md）。
 *
 * 全局依赖：control 挂即整个多进程服务不可用。
 * 职责：流注册/查重（跨进程重复推流控制）、流生命周期事件推送（notify.publish /
 *       notify.unpublish / notify.kick）、统计聚合（ingest/play 上报）、
 *       admin 数据接口、崩溃自愈（ingest 连接断开 → 注销其全部流并广播）。
 */

namespace MediaServer\Process;

use MediaServer\Recorder\RecorderManager;
use MediaServer\Utils\StreamRegistryControl;

final class ControlProcess
{
    public static function run(int $registryPort, string $dbFile): void
    {
        RecorderManager::$enabled = false;
        StreamRegistryControl::setDbFile($dbFile);

        // 注册表监听：Workerman Worker（Text 协议，行分隔 JSON）
        $registry = new \Workerman\Worker("tcp://0.0.0.0:$registryPort");
        StreamRegistryControl::bind($registry);

        // 注意：dbFile 不能作为 argv 传入——Workerman Windows 的
        // forkOneWorkerForWindows 会把所有存在的 argv 文件当成 worker 脚本
        // fork 一份（php registry.db -q → 崩溃）。所以 dbFile 由
        // worker.php 用环境变量 MP_REGISTRY_DB 传入。

        \Workerman\Worker::runAll();
    }
}
