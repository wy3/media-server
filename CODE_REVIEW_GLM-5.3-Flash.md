# 代码审查报告

> 审查对象：`develop` 分支 @ `bb47327`（2026-09-01）
> 审查范围：`src/`（约 5,800 行）+ `public/admin/` + `tests/`，不含 `packages/SabreAMF`（第三方）
> 运行环境：PHP 8.4.25 / Workerman v4.1.9 / Windows 11
> 方法：逐文件通读 + 对可疑结论编写脚本实测验证（见文末"验证方法"）

---

## 一、总体评价

这是一个**完成度相当高**的自研流媒体服务器。以下几个部分实现质量明显超出"个人项目"水准：

| 模块 | 评价 |
|---|---|
| `Mp4Muxer` / `Mp4Parser` | **字节级正确**。box 偏移推算、变长描述符编码、trun data_offset 回填均经手工核算无误 |
| 回放区间聚合 I/O | 把数千次小块 `fread` 归并为数十次大块读，配合背压状态机，设计正确 |
| `RecordIndex` | 全参数化查询，事务 + WAL + busy_timeout，无 SQL 注入 |
| `public/admin/app.js` | 所有插值均经 `esc()` 转义（`app.js:365`），无 XSS |
| 整体 | 全项目 `declare(strict_types=1)`，在 PHP 8.4 下类型纪律严谨 |

**主要风险不在业务逻辑，而在：(1) 协议层对畸形输入的零防御，(2) 一个被低估的框架行为** —— 见 P0。

---

## 二、P0 严重（可被远程触发，导致整个进程退出）

### A1. 单个 RTMP 控制包即可打挂整个服务（除零）

**这是本次审查发现的最严重问题，且 `KNOWN_ISSUES.md` 未记录。**

链路如下：

```php
// src/Rtmp/RtmpControlHandlerTrait.php:16
case RtmpPacket::TYPE_SET_CHUNK_SIZE:
    list(, $this->inChunkSize) = unpack("N", $p->payload);   // ← 无任何范围校验
```

```php
// src/Rtmp/RtmpPacketTrait.php:122
} elseif (0 === $p->bytesRead % $this->inChunkSize) {        // ← inChunkSize=0 时除零
```

客户端发送 `SET_CHUNK_SIZE = 0` 后，`$p->bytesRead % 0` 抛出 `DivisionByZeroError`。

**致命放大点**在框架层：

```php
// vendor/workerman/workerman/Connection/TcpConnection.php:644-652
try {
    \call_user_func($this->onMessage, $this, $this->_recvBuffer);
} catch (\Exception $e) {
    Worker::stopAll(250, $e);      // ← 捕获后不是断连，而是停掉整个进程
} catch (\Error $e) {
    Worker::stopAll(250, $e);
}
```

Workerman 的兜底是 `Worker::stopAll()`，即**任何逃逸到 onMessage 之外的 Throwable 都会终止整个服务器进程**，而不是断开那一条连接。本项目是单进程模型（`start.php` 仅 `runAll()` 一个 Worker），因此：

> 一个未鉴权的 TCP 包（4 字节控制消息）→ 整站下线。可无限重复触发。

`MediaServer::verifyAuth()` 恒返回 `true`（`src/MediaServer.php:309-312`），攻击者无需任何凭据。

**修复**（`RtmpControlHandlerTrait.php:16`）：

```php
case RtmpPacket::TYPE_SET_CHUNK_SIZE:
    $v = strlen($p->payload) >= 4 ? unpack("N", $p->payload)[1] : null;
    if (is_int($v) && $v >= 1 && $v <= 0xFFFFFF) {
        $this->inChunkSize = $v;
    }
    break;
```

---

### A2. 畸形控制消息 → TypeError → 同样整进程退出

同一处代码，当 payload 少于 4 字节时 `unpack("N", ...)` 返回 `false`，`list()` 解包得到 `null`，而属性是强类型：

```php
// src/Rtmp/RtmpStream.php:56
protected int $inChunkSize = 128;
```

`null` 赋给 `int` 属性 → `TypeError` → 同样是 `Worker::stopAll()`。
`RtmpControlHandlerTrait.php:24`（`TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE`）存在完全相同的问题。

上面的修复建议已同时覆盖本条。

---

### A3. 录像路径目录穿越（KI-9）—— 实测确认，但影响范围需修正

```php
// src/Recorder/RecorderManager.php:37-43
public static function sanitizePath(string $path): string
{
    $path = trim($path, "/\\");
    $path = str_replace(["\\", " ", "\0"], "_", $path);
    $path = preg_replace('#/+#', '_', $path) ?? $path;
    return $path === '' ? 'default' : $path;
}
```

不处理 `.` / `..`。我编写脚本对 10 组输入做了实测（`tests/tmp/probe_sanitize.php`）：

| 输入 | sanitizePath() | recordDir() | 是否逃逸 |
|---|---|---|---|
| `live/stream` | `live_stream` | `record\live_stream` | 否 |
| **`..`** | **`..`** | **`record\..`（= 项目根目录）** | **是，逃逸一级** |
| `.` | `.` | `record\.` | 否 |
| `/..` | `..` | `record\..` | **是，逃逸一级** |
| `../..` | `.._..` | `record\.._..` | 否 |
| `a/../../../../Windows` | `a_.._.._.._.._Windows` | 单层目录名 | 否 |
| `..\..\evil` | `.._.._evil` | 单层目录名 | 否 |
| `live/../../../etc/passwd` | `live_.._.._.._etc_passwd` | 单层目录名 | 否 |

**结论修正**：由于 `/` 和 `\` 都被替换为 `_`，**多段穿越实际上已被拦住**。真实影响是：当路径为**单个 `..` 分量**（无分隔符）时，可向上逃逸**一级**，把 `.mp4` 写到 `recordPath` 的父目录（即项目根目录）。

`KNOWN_ISSUES.md` 描述为"攻击者可构造 `..` 让录像 `.mp4` 写到 `recordPath` 之外"——方向正确，但"高"的严重度隐含了任意路径写，实际只能逃逸一级。**建议保持修复，严重度下调至中。**

修复（同时覆盖纵深防御）：

```php
$path = preg_replace('#[^A-Za-z0-9_-]+#', '_', $path) ?? '';   // 显式白名单
$path = trim($path, '_');
// recordDir() 追加 realpath 前缀校验兜底
```

---

## 三、P1 高

### B1. KI-4 回放 keep-alive 重入丢数据 —— 确认成立

`src/Recorder/PlaybackServer.php:330-388` 的 `sendParts` 是单任务状态机，**没有连接级队列**。同一 keep-alive 连接上，第一路回放因背压挂起期间若第二路请求到达，会直接覆盖 `$connection->onBufferFull / onBufferDrain`（:380-386）与第一路的闭包状态，第一路剩余字节静默丢失。

### B2. KI-5 断连不释放文件句柄 —— 确认成立

`sendParts` 未安装 `onClose`。`$state['fh']`（:336-342）仅在正常走完时关闭；客户端中途断连则句柄与回调一并残留。

### B3. 动态属性弃用（每次回放请求必触发）—— 文档未记录

```php
// src/Recorder/PlaybackServer.php:332
$connection->bufferFull = false;
```

`TcpConnection` 未声明 `bufferFull`，也没有 `#[\AllowDynamicProperties]`（已核实）。
在 PHP 8.2+ 每次回放请求都会产生：

```
Deprecated: Creation of dynamic property Workerman\Connection\TcpConnection::$bufferFull is deprecated
```

已在 PHP 8.4.25 实测确认。当前只是日志污染，但 **PHP 9 起动态属性将变为 Error** —— 结合 A1 说明的 `Worker::stopAll` 兜底行为，届时这会从"警告"升级为"整进程退出"。

修复：把背压标志放进 `sendParts` 自己的 `$state` 数组，不挂在 connection 上。

### B4. RTMP 重组缓冲区无界 —— 文档未记录

```php
// src/Rtmp/RtmpChunkHandlerTrait.php:63-68
if (!isset($this->allPackets[$csId])) {
    $p = new RtmpPacket();
    $p->chunkStreamId = $csId;
    $this->allPackets[$csId] = $p;      // ← csId 客户端可控（最大 65599），表项永不清理
}
```

配合 `RtmpPacketTrait.php:104` 的 `$p->payload .= $stream->readRaw($size)`（`length` 来自 int24，单包上限 16MB），攻击者可遍历大量 csId 建立大包描述后保持连接，使内存单调增长。
`RtmpTrait.php:64-98` 的 `stop()` 也**不清理** `allPackets` / `gopCacheQueue` / 序列头帧。

建议：限制 `allPackets` 条目数、限制 `length` 上限（如 8MB）、`stop()` 中显式释放。

### B5. 完全缺失推流 / 播放鉴权

`src/MediaServer.php:309-312` 的 `verifyAuth()` 恒返回 `true`；`RtmpInvokeHandlerTrait.php:73` 对 `app` 仅做 `str_replace('/', '', ...)`，`:130` 的 `streamName` **完全无过滤**：

```php
$this->publishStreamPath = '/' . $this->appName . '/' . $streamInfo[0];
```

任何人可以：推流占用任意路径、拉取任意流、并触发录像落盘。目前路径安全**单点依赖**下游 `RecorderManager::sanitizePath`（已证实不完备，见 A3）。建议实现 `verifyAuth` 并对 app/streamName 做白名单校验。

---

## 四、P2 中

| 编号 | 问题 | 位置 | 说明 |
|---|---|---|---|
| C1 | KI-6 跨段首段缺轨 → 空 trak | `PlaybackServer.php:250` | `moov` 按"检测到的编解码配置"声明轨道，而非"实际选中样本"。首段纯音频、次段音视频时，视频 trak 为空（duration=0） |
| C2 | KI-1/KI-2 中途加轨分段损坏 | `Mp4Recorder.php:93-100` | 收到 avcC/esds 时未判断当前段是否已开并重启，继续往单轨 moov 里写另一轨样本 |
| C3 | `onChunkData` 无限递归 | `RtmpChunkHandlerTrait.php:103-105` | `default` 分支递归调用自身，PHP 无尾调用优化；单个大 TCP 包含数千 chunk 即形成深栈 |
| C4 | `BASE_HEADER_SIZES` 常量错误 | `RtmpChunk.php:18` | 应为 `[2, 3]`（扩展 csid 基本头 2/3 字节），实际为 `[3, 4]`；且 `:35` 用原始 csid 字段索引、`:67` 用解析后 csId 索引，两处不一致。扩展 chunk stream id（≥64）的客户端解析会错位 |
| C5 | KI-7 / KI-8 | `PlaybackServer.php:69`；`WMHttpChunkStream.php:23` | 响应头在入队前同步发送；`WMHttpChunkStream` 构造时无条件覆盖 `$connection->onClose` 且不链式 |

---

## 五、P3 低

- `Mp4Parser.php:245` — `parseTrun` 的 `$count` 无边界校验，畸形文件可致大量循环 + 警告
- `RtmpPacketTrait.php:69-80` — 扩展时间戳未写回 `$p->timestamp`，`fmt=3` 续包会误读 4 字节（推流超 4.6 小时后出现）
- `RtmpControlHandlerTrait.php:19` — `TYPE_ABORT` 未实现，无法丢弃分片
- `RecordIndex::listSegments()` — 无 `LIMIT`，长索引会一次性取出全部行
- `vendor/workerman` v4.1.9 在 PHP 8.4 下 `Worker.php:518` 触发 `Constant E_STRICT is deprecated`；README 称"已在 PHP 8.4 验证"，与该告警略有出入

---

## 六、对 `KNOWN_ISSUES.md` 的一处更正

**KI-10（legacy 兼容层重新引入目录穿越）在当前代码中不成立。**

全项目 grep `legacy` 无任何匹配（`src/`、`tests/` 均无），`legacySanitizePath` 兼容层随那三笔提交一并移除，当前代码中并不存在。

而 `KNOWN_ISSUES.md:126` 将其标注为"**优先级最高，建议优先处理**"，README 中同样置顶。**按此执行会浪费修复精力在一个不存在的问题上。**

建议：将 KI-10 标记为"已随提交移除，当前代码不适用（N/A）"。

其余 KI-1 ~ KI-9 经复核**均成立**，其中 KI-9 的严重度建议按 A3 下调。

---

## 七、建议的修复顺序

1. **A1 / A2** —— 一行范围校验，消除整个进程被单包打挂的风险（最高性价比）
2. **A3** —— `sanitizePath` 改白名单 + `recordDir` 加 realpath 兜底
3. **B3** —— 背压标志移入 `$state`，解除 PHP 9 的定时炸弹
4. **B4** —— RTMP 缓冲区上限 + `stop()` 显式清理
5. **B1 / B2** —— 连接级请求队列 + `onClose` 资源清理
6. **B5** —— 实现 `verifyAuth`，app/streamName 白名单
7. **C 类** 按 KI 编号逐个处理

---

## 附：验证方法

本次审查对以下结论做了脚本/命令实测，而非仅靠阅读推断：

| 结论 | 验证方式 |
|---|---|
| A3 目录穿越的实际影响范围 | `tests/tmp/probe_sanitize.php`（10 组输入 + realpath 前缀判定） |
| B3 动态属性弃用 | PHP 8.4.25 下构造未声明属性的类实测 |
| A1/A2 的后果放大 | 通读 `vendor/workerman/.../TcpConnection.php:644-652` 确认 `stopAll` 兜底 |
| Mp4Muxer/Mp4Parser 一致性 | 手工核算 box 偏移：`avc1` width@24 / avcC@78、`mp4a` channels@16 / samplerate@24、esds 描述符长度、trun data_offset 回填、变长 descriptorLength —— 全部一致 |
| KI-10 不成立 | 全项目 grep `legacy` |
| 前端无 XSS | 核实 `app.js:365` 的 `esc()` 及各插值点 |
