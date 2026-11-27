<?php

declare(strict_types=1);

namespace MediaServer;

/**
 * 各服务监听地址与端口配置。
 *
 * 全部按多进程模式运行，推流（ingest）与拉流（play）的配置彼此独立。
 * 类似 RecorderManager / AdminAuth，在入口脚本中用静态属性覆盖，
 * 各 server 从这里读取，不再在代码里写死 host / port。
 */
final class ServerConfig
{
    // ── 进程数量 ──
    public static int $ingestCount = 1;
    public static int $playCount = 1;
    public static int $recordCount = 1;

    // ── 推流进程（ingest）：RTMP 推流 + HTTP-FLV 推流 ──
    public static string $ingestRtmpHost = '0.0.0.0';
    public static int $ingestRtmpPort = 1935;
    public static string $ingestHttpHost = '0.0.0.0';
    public static int $ingestHttpPort = 18081;

    // ── 拉流进程（play）：RTMP 拉流 + HTTP-FLV/WS-FLV 拉流 ──
    public static string $playRtmpHost = '0.0.0.0';
    public static int $playRtmpPort = 1936;
    public static string $playHttpHost = '0.0.0.0';
    public static int $playHttpPort = 18080;

    // ── 管理后台进程（admin）──
    public static string $adminHttpHost = '0.0.0.0';
    public static int $adminHttpPort = 18099;

    // ── 注册中心进程（control）──
    public static string $registryHost = '0.0.0.0';
    public static int $registryPort = 18090;
    /** 各进程连接 control 的地址 */
    public static string $registryClientAddr = '127.0.0.1:18090';

    /**
     * 用环境变量覆盖配置（压测 / 容器场景）。
     *
     * 每个子进程（worker.php 起的 control/ingest/play/admin/record）启动时
     * 都会调用，从而继承 launcher 设置的同一组端口。未设置的环境变量不覆盖。
     */
    public static function loadFromEnv(): void
    {
        $map = [
            'MP_INGEST_RTMP_HOST' => &self::$ingestRtmpHost,
            'MP_INGEST_RTMP_PORT' => &self::$ingestRtmpPort,
            'MP_INGEST_HTTP_HOST' => &self::$ingestHttpHost,
            'MP_INGEST_HTTP_PORT' => &self::$ingestHttpPort,
            'MP_PLAY_RTMP_HOST' => &self::$playRtmpHost,
            'MP_PLAY_RTMP_PORT' => &self::$playRtmpPort,
            'MP_PLAY_HTTP_HOST' => &self::$playHttpHost,
            'MP_PLAY_HTTP_PORT' => &self::$playHttpPort,
            'MP_ADMIN_HTTP_HOST' => &self::$adminHttpHost,
            'MP_ADMIN_HTTP_PORT' => &self::$adminHttpPort,
            'MP_REGISTRY_HOST' => &self::$registryHost,
            'MP_REGISTRY_PORT' => &self::$registryPort,
            'MP_REGISTRY_CLIENT_ADDR' => &self::$registryClientAddr,
            'MP_INGEST_COUNT' => &self::$ingestCount,
            'MP_PLAY_COUNT' => &self::$playCount,
            'MP_RECORD_COUNT' => &self::$recordCount,
        ];
        foreach ($map as $envName => &$prop) {
            $v = getenv($envName);
            if ($v === false || $v === '') {
                continue;
            }
            $prop = is_int($prop) ? (int)$v : $v;
        }
    }
}
