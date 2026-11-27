<?php

declare(strict_types=1);

/**
 * Multi-process worker entry (router).
 *
 * php worker.php <process> [args...]
 * process: ingest | play | record | control | admin
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';

use MediaServer\Process\AdminProcess;
use MediaServer\Process\ControlProcess;
use MediaServer\Process\IngestProcess;
use MediaServer\Process\PlayProcess;
use MediaServer\Process\RecordProcess;

if (extension_loaded('event')) {
    \Workerman\Worker::$eventLoopClass = '\Workerman\Events\Event';
}

$process = $argv[1] ?? '';

switch ($process) {
    case 'ingest':
        IngestProcess::run(
            (int)($argv[2] ?? 1935),
            (int)($argv[3] ?? 18081),
            $argv[4] ?? '127.0.0.1:18090'
        );
        break;

    case 'play':
        PlayProcess::run(
            (int)($argv[2] ?? 1936),
            (int)($argv[3] ?? 18080),
            $argv[4] ?? '127.0.0.1:18090'
        );
        break;

    case 'record':
        RecordProcess::run(
            $argv[2] ?? (__DIR__ . '/record'),
            $argv[3] ?? '127.0.0.1:18090'
        );
        break;

    case 'control':
        ControlProcess::run(
            (int)($argv[2] ?? 18090),
            getenv('MP_REGISTRY_DB') ?: (__DIR__ . '/record/registry.db')
        );
        break;

    case 'admin':
        AdminProcess::run(
            (int)($argv[2] ?? 18099),
            $argv[3] ?? (__DIR__ . '/record'),
            $argv[4] ?? '127.0.0.1:18090'
        );
        break;

    default:
        fwrite(STDERR, "Unknown process: $process (use ingest|play|record|control|admin)\n");
        exit(1);
}