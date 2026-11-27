<?php

declare(strict_types=1);

/**
 * 验证 MulticastNotifier 发送端在 stream_socket_client 下是否真正发出。
 * receiver 用 notifier 的 initReceiver（stream）。
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
use MediaServer\Utils\MulticastNotifier;

$iso = ['group' => '239.183.88.66', 'port' => 13020, 'token' => 0xABCDEF];

$mode = $argv[1] ?? 'both';

if ($mode === 'both') {
    // 同进程收发
    $r = new MulticastNotifier($iso['group'], $iso['port'], $iso['token']);
    $s = new MulticastNotifier($iso['group'], $iso['port'], $iso['token']);
    $recv = $r->initReceiver();
    echo "receiver fd=" . (int)$recv . "\n";
    usleep(500000);
    $s->notify(7, '/live/x');
    usleep(300000);
    $count = $r->drain(function ($k, $p) { echo "drained key=$k path=$p\n"; });
    echo "count=$count\n";
}
