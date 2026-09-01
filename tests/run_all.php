<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

// RTMP 会话级测试需要事件循环对象（TcpConnection 构造器会引用它），
// 用 Select 循环初始化但不启动（无需 run()，纯数据驱动）
if (\Workerman\Worker::$globalEvent === null) {
    \Workerman\Worker::$globalEvent = new \Workerman\Events\Select();
}

/**
 * 单元测试总入口。零外部依赖：不需要运行中的服务器、不需要 ffmpeg。
 *
 * 用法：
 *   php tests/run_all.php            # 全部单元测试
 *   php tests/run_all.php rtmp       # 仅跑文件名含 rtmp 的套件
 *
 * 集成/E2E（需要先启动服务器 + ffmpeg）：php tests/playback_e2e.php
 */

$filter = $argv[1] ?? '';
$suites = glob(__DIR__ . '/unit/*_test.php') ?: [];
if ($filter !== '') {
    $suites = array_values(array_filter($suites, fn($f) => str_contains(basename($f), $filter)));
}

$totalFail = 0;
foreach ($suites as $file) {
    require $file;
    $totalFail += run_batch(basename($file));
}

$total = 0;
echo "\n";
if ($totalFail === 0) {
    echo "ALL SUITES PASSED (" . count($suites) . " suite files)\n";
} else {
    exit("{$totalFail} TEST(S) FAILED\n");
}
