<?php

declare(strict_types=1);

namespace MediaServer\Recorder;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 录制配置与生命周期管理。
 *
 *  - recordPath：录制文件根目录
 *  - fragmentDurationMs：fMP4 分片时长（关键帧边界处切分）
 *  - segmentDurationMs：单个 .mp4 文件时长（到时在关键帧处轮转新文件）
 *
 * 每个分段文件附带一个同名 .json 索引，记录原始推流路径与起止墙钟时间，
 * 供指定时间回放定位使用。
 */
class RecorderManager
{
    public static bool $enabled = true;

    public static string $recordPath = '';

    public static int $fragmentDurationMs = 2000;

    public static int $segmentDurationMs = 60000;

    public static function isEnabled(string $path): bool
    {
        return self::$enabled && self::$recordPath !== '';
    }

    /**
     * 将推流路径安全化为目录名（如 /live/cam1 -> live_cam1）。
     */
    public static function sanitizePath(string $path): string
    {
        $path = trim($path, "/\\");
        $path = str_replace(["\\", " ", "\0"], "_", $path);
        $path = preg_replace('#/+#', '_', $path) ?? $path;
        return $path === '' ? 'default' : $path;
    }

    public static function recordDir(string $path): string
    {
        return rtrim(self::$recordPath, '/\\') . DIRECTORY_SEPARATOR . self::sanitizePath($path);
    }

    /**
     * 列出录像索引。可按推流路径过滤；返回按起始时间升序排列的索引数组。
     */
    public static function listRecordFiles(?string $path = null): array
    {
        $root = rtrim(self::$recordPath, '/\\');
        if ($root === '' || !is_dir($root)) {
            return [];
        }

        $target = ($path !== null && $path !== '') ? self::sanitizePath($path) : null;
        $result = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            $data = json_decode((string)file_get_contents($file->getPathname()), true);
            if (!is_array($data)) {
                continue;
            }
            if ($target !== null && self::sanitizePath((string)($data['path'] ?? '')) !== $target) {
                continue;
            }
            $result[] = $data;
        }

        usort($result, fn(array $a, array $b): int => ((int)($a['start'] ?? 0)) <=> ((int)($b['start'] ?? 0)));
        return $result;
    }
}
