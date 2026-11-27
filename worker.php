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
use MediaServer\ServerConfig;

if (extension_loaded('event')) {
    \Workerman\Worker::$eventLoopClass = '\Workerman\Events\Event';
}

$process = $argv[1] ?? '';

switch ($process) {
    case 'ingest':
        IngestProcess::run(
            (int)($argv[2] ?? ServerConfig::$ingestRtmpPort),
            $argv[5] ?? ServerConfig::$ingestRtmpHost,
            (int)($argv[3] ?? ServerConfig::$ingestHttpPort),
            $argv[6] ?? ServerConfig::$ingestHttpHost,
            $argv[4] ?? ServerConfig::$registryClientAddr
        );
        break;

    case 'play':
        PlayProcess::run(
            (int)($argv[2] ?? ServerConfig::$playRtmpPort),
            $argv[5] ?? ServerConfig::$playRtmpHost,
            (int)($argv[3] ?? ServerConfig::$playHttpPort),
            $argv[6] ?? ServerConfig::$playHttpHost,
            $argv[4] ?? ServerConfig::$registryClientAddr
        );
        break;

    case 'record':
        RecordProcess::run(
            $argv[2] ?? (__DIR__ . '/record'),
            $argv[3] ?? ServerConfig::$registryClientAddr
        );
        break;

    case 'control':
        ControlProcess::run(
            (int)($argv[2] ?? ServerConfig::$registryPort),
            $argv[3] ?? ServerConfig::$registryHost,
            getenv('MP_REGISTRY_DB') ?: (__DIR__ . '/record/registry.db')
        );
        break;

    case 'admin':
        AdminProcess::run(
            (int)($argv[2] ?? ServerConfig::$adminHttpPort),
            $argv[5] ?? ServerConfig::$adminHttpHost,
            $argv[3] ?? (__DIR__ . '/record'),
            $argv[4] ?? ServerConfig::$registryClientAddr
        );
        break;

    default:
        fwrite(STDERR, "Unknown process: $process (use ingest|play|record|control|admin)\n");
        exit(1);
}