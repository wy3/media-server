<?php

declare(strict_types=1);

namespace MediaServer\Recorder;

use Throwable;

/**
 * 录制配置与生命周期管理。
 *
 *  - recordPath：录制文件根目录
 *  - fragmentDurationMs：fMP4 分片时长（关键帧边界处切分）
 *  - segmentDurationMs：单个 .mp4 文件时长（到时在关键帧处轮转新文件）
 *
 * 每个推流路径的录像时间线索引集中存储在 SQLite（recordPath/recordings.db），
 * 由 RecordIndex 提供追加、查询与统计能力，供管理列表与指定时间回放定位使用。
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
     * 向录像索引追加一个已完成分段（含流元数据）。
     *
     * @param array $meta 编码元数据（video/audio），可选
     * @param array{file:string,start:int,end:int,duration:int,size?:int} $segment
     * @return bool 索引写入是否成功
     */
    public static function appendSegmentToIndex(string $path, array $meta, array $segment): bool
    {
        return RecordIndex::appendSegment($path, $meta, $segment);
    }

    /**
     * 列出录像索引。可按推流路径过滤；返回按起始时间升序排列的索引数组。
     *
     * @return array<int, array{path:string,file:string,start:int,end:int,duration:int,size:int}>
     */
    public static function listRecordFiles(?string $path = null): array
    {
        try {
            return RecordIndex::listSegments($path);
        } catch (Throwable $e) {
            logger()->error('list record index fail {msg}', ['msg' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 读取某推流路径的完整索引（含元数据），不存在返回 null。
     *
     * @return array{version:int,path:string,updated_at:int,meta:array,segments:array}|null
     */
    public static function getStreamIndex(string $path): ?array
    {
        try {
            return RecordIndex::getStreamIndex($path);
        } catch (Throwable $e) {
            logger()->error('get record index fail {msg}', ['msg' => $e->getMessage()]);
            return null;
        }
    }
}
