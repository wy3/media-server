<?php

declare(strict_types=1);

/**
 * 验证：UDP socket 能否注册 EV_READ 到 Workerman event 循环。
 *
 * 用法：
 *   php verify_udp_evread.php server
 *   php verify_udp_evread.php client <n>
 */

$mode = $argv[1] ?? 'server';

if ($mode === 'client') {
    $n = (int)($argv[2] ?? 10);
    $u = stream_socket_client('udp://127.0.0.1:12986', $en, $es, 2);
    if (!$u) { fwrite(STDERR, "连接失败: $es\n"); exit(1); }
    for ($i = 0; $i < $n; $i++) {
        fwrite($u, pack('N', 1000 + $i));
        usleep(1000);
    }
    fclose($u);
    echo "client sent $n UDP packets\n";
    exit(0);
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Events\EventInterface;

Worker::$eventLoopClass = '\Workerman\Events\Event';

$worker = new Worker('tcp://0.0.0.0:0');
$worker->count = 1;
$statFile = dirname(__DIR__) . '/tmp/udp_evread.json';
@mkdir(dirname($statFile), 0777, true);

$worker->onWorkerStart = function ($w) use ($statFile) {
    $udp = stream_socket_server('udp://127.0.0.1:12986', $en, $es, STREAM_SERVER_BIND);
    if (!$udp) { echo "UDP 创建失败: $es\n"; return; }
    stream_set_blocking($udp, false);

    $GLOBALS['udp_recv'] = 0;
    $ok = Worker::getEventLoop()->add($udp, EventInterface::EV_READ, function ($fd) {
        $data = fread($fd, 4096);
        $n = strlen($data) / 4;
        $GLOBALS['udp_recv'] += (int)$n;
        $keys = [];
        for ($i = 0; $i + 4 <= strlen($data); $i += 4) {
            $keys[] = unpack('N', substr($data, $i, 4))[1];
        }
        echo 'EV_READ: 收到 ' . count($keys) . " key: " . implode(',', array_slice($keys, 0, 5)) . "\n";
        file_put_contents($GLOBALS['statFile'], json_encode(['recv' => $GLOBALS['udp_recv']]));
    });
    $GLOBALS['statFile'] = $statFile;
    echo 'UDP EV_READ 注册: ' . ($ok ? 'OK' : 'FAIL') . "\n";
};

Worker::runAll();
