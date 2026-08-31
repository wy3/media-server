<?php

declare(strict_types=1);

namespace MediaServer\Recorder;

use PDO;

/**
 * 录像索引的 SQLite 存储层。
 *
 *  - 单库文件（recordPath/recordings.db），streams + segments 两表
 *  - 惰性建连：每个 Worker 进程首次使用时才打开 PDO，避免主进程 fork 前
 *    持有句柄被多个子进程共享
 *  - WAL + busy_timeout：多进程并发读不阻塞写，写冲突自动等待重试
 *  - 按 (stream_path, file) 唯一约束幂等去重，重复追加不产生脏数据
 */
class RecordIndex
{
    protected static ?PDO $pdo = null;

    public static function dbFile(): string
    {
        return rtrim(RecorderManager::$recordPath, '/\\')
            . DIRECTORY_SEPARATOR . 'recordings.db';
    }

    /**
     * 规范化推流路径：统一以单前导斜杠存储与查询，
     * 避免写入端（/live/xxx）与回放/管理端（live/xxx）形态不一致导致精确匹配失败。
     */
    protected static function normalizePath(string $path): string
    {
        $path = trim($path, "/ \t\n\r\0\x0B");
        return $path === '' ? '/' : '/' . $path;
    }

    protected static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $file = self::dbFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("mkdir record index dir fail: {$dir}");
        }

        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::initSchema($pdo);

        return self::$pdo = $pdo;
    }

    protected static function initSchema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS streams (
                path       TEXT PRIMARY KEY,
                dir        TEXT NOT NULL,
                meta       TEXT NOT NULL DEFAULT '{}',
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS segments (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                stream_path TEXT NOT NULL,
                file        TEXT NOT NULL,
                start       INTEGER NOT NULL,
                end         INTEGER NOT NULL,
                duration    INTEGER NOT NULL DEFAULT 0,
                size        INTEGER NOT NULL DEFAULT 0,
                UNIQUE(stream_path, file)
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_segments_stream_start ON segments(stream_path, start)');
    }

    /**
     * 追加一个已完成分段；同时 upsert 流元数据（空 meta 不覆盖已有值）。
     *
     * @param array $meta 编码元数据（video/audio），可选
     * @param array{file:string,start:int,end:int,duration:int,size?:int} $segment
     * @return bool 写入成功返回 true；失败记日志并返回 false（分段文件已落盘但未入索引）
     */
    public static function appendSegment(string $path, array $meta, array $segment): bool
    {
        $path = self::normalizePath($path);
        $file = (string)($segment['file'] ?? '');
        $pdo = null;

        try {
            $pdo = self::pdo();
            $ts = timestamp();

            $pdo->beginTransaction();
            if ($meta !== []) {
                $stmt = $pdo->prepare(
                    'INSERT INTO streams (path, dir, meta, updated_at)
                     VALUES (:path, :dir, :meta, :ts)
                     ON CONFLICT(path) DO UPDATE SET meta = :meta, updated_at = :ts'
                );
                $stmt->execute([
                    ':path' => $path,
                    ':dir' => RecorderManager::sanitizePath($path),
                    ':meta' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ':ts' => $ts,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO streams (path, dir, meta, updated_at)
                     VALUES (:path, :dir, \'{}\', :ts)
                     ON CONFLICT(path) DO UPDATE SET updated_at = :ts'
                );
                $stmt->execute([
                    ':path' => $path,
                    ':dir' => RecorderManager::sanitizePath($path),
                    ':ts' => $ts,
                ]);
            }

            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO segments (stream_path, file, start, end, duration, size)
                 VALUES (:p, :f, :s, :e, :d, :z)'
            );
            $stmt->execute([
                ':p' => $path,
                ':f' => $file,
                ':s' => (int)($segment['start'] ?? 0),
                ':e' => (int)($segment['end'] ?? 0),
                ':d' => (int)($segment['duration'] ?? 0),
                ':z' => (int)($segment['size'] ?? 0),
            ]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo !== null && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable $ignore) {
                    // 回滚失败不掩盖原始错误
                }
            }
            logger()->error('record index append fail {path} {file} {msg}', [
                'path' => $path,
                'file' => $file,
                'msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 列出分段（可选按流过滤），按 start 升序；结构兼容旧版 listRecordFiles。
     *
     * @return array<int, array{path:string,file:string,start:int,end:int,duration:int,size:int}>
     */
    public static function listSegments(?string $path = null): array
    {
        $pdo = self::pdo();

        if ($path !== null && $path !== '') {
            $path = self::normalizePath($path);
            $stmt = $pdo->prepare(
                'SELECT stream_path AS path, file, start, end, duration, size
                 FROM segments WHERE stream_path = :p ORDER BY start ASC'
            );
            $stmt->execute([':p' => $path]);
            return $stmt->fetchAll();
        }

        return $pdo->query(
            'SELECT stream_path AS path, file, start, end, duration, size
             FROM segments ORDER BY start ASC'
        )->fetchAll();
    }

    /**
     * 读取单流的完整索引（含元数据），不存在返回 null。
     *
     * @return array{version:int,path:string,updated_at:int,meta:array,segments:array}|null
     */
    public static function getStreamIndex(string $path): ?array
    {
        $pdo = self::pdo();

        $stmt = $pdo->prepare('SELECT path, meta, updated_at FROM streams WHERE path = :p');
        $stmt->execute([':p' => self::normalizePath($path)]);
        $stream = $stmt->fetch();
        if ($stream === false) {
            return null;
        }

        $meta = json_decode((string)$stream['meta'], true);
        return [
            'version' => 1,
            'path' => (string)$stream['path'],
            'updated_at' => (int)$stream['updated_at'],
            'meta' => is_array($meta) ? $meta : [],
            'segments' => self::listSegments($path),
        ];
    }
}
