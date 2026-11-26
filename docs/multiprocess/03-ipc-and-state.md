# 03. 进程间通讯与共享状态

媒体数据走共享内存（见 [02-shared-memory.md](./02-shared-memory.md)）。
本文只讲**控制面**。

## 1. 通讯分档

| 档次 | 频率 | 通道 | 用途 |
|---|---|---|---|
| 数据 | 每帧 | 共享内存 + 轮询 | 帧数据 |
| 状态 | 秒级 | SQLite（WAL） | token、录像索引、流注册 |
| 控制 | 事件级 | TCP loopback | 进程生命周期、故障通知 |

## 2. 为什么控制通道不需要密集

采用**共享内存轮询**后，进程间不需要为每一帧通讯：

| 项目 | 旧设计（socket 通知） | 本设计（轮询） |
|---|---|---|
| 每帧消息 | 需要 | **不需要** |
| 连接数 | N×M | **0（轮询）/ 少量（控制）** |
| 延迟 | 0.025 ms | < 2 ms（轮询间隔） |

控制通道只承担低频事件：进程上下线、流注册、故障通知。

## 3. 进程注册与发现

### 3.1 为什么需要注册

`SO_REUSEPORT` 池中的进程互相不知道对方存在，但需要：

- 播放池知道哪些流存在（虽然能靠确定性 key 直接 attach，但需要知道"该不该 attach"）
- 控制进程统计各进程状态
- 故障时摘除

### 3.2 用 SQLite 做注册表

避免进程间两两建连接，用 SQLite 作为共享注册表：

```sql
-- 进程注册
CREATE TABLE IF NOT EXISTS worker_processes (
    pid          INTEGER PRIMARY KEY,
    pool         TEXT NOT NULL,        -- ingest / play / record / control
    endpoint     TEXT NOT NULL,        -- host:port（用于控制消息）
    started_at   INTEGER NOT NULL,
    heartbeat_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_worker_pool ON worker_processes(pool);

-- 活跃流（由推流进程写入）
CREATE TABLE IF NOT EXISTS active_streams (
    path         TEXT PRIMARY KEY,
    shm_key      INTEGER NOT NULL,
    producer_pid INTEGER NOT NULL,
    started_at   INTEGER NOT NULL,
    updated_at   INTEGER NOT NULL
);
```

### 3.3 心跳

| 参数 | 建议值 |
|---|---|
| 心跳间隔 | 2 s |
| 摘除阈值 | 6 s |

各进程每 2s 更新自己的 `heartbeat_at`。超时的记录由控制进程清理。

**注意 SQLite 写入频率**：N 个进程 × 每 2s 一次 = 极低频，WAL 模式完全够用。

## 4. 播放进程如何找到流

有了确定性 key，播放进程**不需要查询**：

```php
$key = shmKeyForPath($path);          // 确定性推导
$shm = @shmop_open($key, 'w', 0, 0);  // 直接 attach
if (!$shm) {
    // 段不存在 = 该流未推流
    return 404;
}
// 校验段头里的 path 字段，防止哈希冲突
if ($header['path'] !== $path) {
    return 500;  // 哈希冲突，极罕见
}
```

**这是 `SO_REUSEPORT` 架构最大的简化**：零查询、零路由表。

### 4.1 对照旧设计

| 旧设计（Gateway 移交） | 本设计 |
|---|---|
| Gateway 维护路由表 | **无路由表** |
| 播放端查询 `path → worker` | **确定性 key 直接 attach** |
| 需要 `query_route` 消息 | **不需要** |

## 5. 控制消息（低频）

仅在以下场景使用 TCP loopback：

| 消息 | 场景 |
|---|---|
| `stream_started` | 推流开始，通知控制进程更新统计 |
| `stream_stopped` | 推流结束，触发段回收 |
| `recorder_lag` | 录像滞后告警 |
| `pool_ready` | 池启动完成 |

格式：`\n` 分帧 JSON。

```json
{"type":"stream_started","path":"/live/a","shmKey":1296507905,"pid":1234}
{"type":"recorder_lag","path":"/live/a","lagFrames":180,"lagMs":6000}
```

## 6. 共享状态（SQLite）

### 6.1 必须外移的状态

| 状态 | 现状 | 迁移原因 |
|---|---|---|
| **token** | [AdminAuth::$tokens](../../src/Admin/AdminAuth.php) 进程内数组 | 控制进程与其它进程不同，登录态需共享 |
| 启动时间 | [AdminAuth::$startTime](../../src/Admin/AdminAuth.php) | 各进程不同 |

### 6.2 不需要外移的状态

**重要简化**：由于推流池和播放池各自独立，且 HTTP 只在控制进程：

| 状态 | 处理 |
|---|---|
| `publishStream`（推流列表） | 控制进程从 `active_streams` 表读 |
| `playerStream`（播放计数） | 各播放进程上报到 `worker_processes` 或单独表 |

### 6.3 token 表

```sql
CREATE TABLE IF NOT EXISTS admin_tokens (
    token       TEXT PRIMARY KEY,
    expires_at  INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_tokens_expires ON admin_tokens(expires_at);
```

登录时顺带清理过期 token。

### 6.4 录像索引

**无需改造**。[recordings.db](../../src/Recorder/RecordIndex.php) 已是 WAL 模式：

```php
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA busy_timeout = 5000');
```

主进程只读（回放），录像进程只写（追加），冲突概率低。

### 6.5 Linux fork 的 PDO 陷阱

[RecordIndex::$pdo](../../src/Recorder/RecordIndex.php) 是 `static ?PDO` 惰性单例。

Linux `fork` 时父进程若已建连，子进程会**继承同一 fd**，多进程共用一条
SQLite 连接会导致协议错乱。

**处理**：

```php
// onWorkerStart 中显式重置，确保每个进程独立建连
RecordIndex::resetConnection();
```

需给 `RecordIndex` 增加重置方法。

> Windows 用 `proc_open` 拉起独立进程，无此问题。

## 7. 安全约束

控制消息**不要**包含句柄传递、二进制 socket 操作。

详见 [appendix-antivirus.md](./appendix-antivirus.md)——fd 移交方案因触发杀软被否决。

## 8. 端口分配

| 端口 | 归属 | reuseport |
|---|---|---|
| 1935 | 推流池 | ✅ 是 |
| 18080 | 播放池 | ✅ 是 |
| 18081 | 控制进程 | ❌ 否（单实例） |

**为什么控制进程不 reuseport**：管理后台需要一致的状态视图，
多实例会导致 token 校验、统计数字不一致。

## 9. 待决策

| 项 | 选项 |
|---|---|
| 播放计数如何聚合 | 各播放进程写 SQLite，控制进程汇总 |
| 段回收由谁触发 | 建议推流进程退出时标记，控制进程定期清理 |
| 控制进程崩溃的影响 | 仅管理功能不可用，媒体流转发不受影响 |
