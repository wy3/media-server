# 已知问题记录（Known Issues）

> 记录范围：`develop` 分支 @ `3876fb7`（2026-09-01 重置后基线）。
>
> 以下问题曾由提交 `802bab9` / `b56719b` / `d312d3d` 修复，但因这几笔提交
> 并非预期改动、已于 2026-09-01 全部移除（未推送，可通过 `git reflog` / `git cherry-pick`
> 找回）。因此**当前代码上这些问题均处于未修复状态**，本文档作为跟进清单。

- 相关源文件：
  - `src/Recorder/Mp4Recorder.php`（fMP4 录制器）
  - `src/Recorder/PlaybackServer.php`（时间回放服务）
  - `src/Recorder/RecorderManager.php`（录像路径安全化/目录管理）
  - `src/Utils/WMHttpChunkStream.php`（HTTP-FLV 直播分块输出）
  - `tests/playback_e2e.php`（E2E / 单元测试）

---

## 一、录制器：流中途切换（纯音频↔纯视频）分段损坏

### KI-1 纯音频中途加入视频 → 分段损坏（高）
- **现象**：推流先纯音频、中途加入视频后，当前分段头部 `moov` 只有音频 `trak`，
  后续视频 `moof/trun(track_id=1)` 引用缺失的视频 `trak/trex`，导致该分段解析失败/无法播放。
- **根因**：`Mp4Recorder` 在视频配置（`avcC` 序列头）首次到达时，没有把"当前仍是纯音频段"
  的段立即封装落盘并重启，继续往只有音频 moov 的段里写视频样本。
- **影响**：中途切流的录像段损坏，回放/转码读取失败。
- **修复方案（已移除，位于 `802bab9` + `b56719b`）**：
  视频配置首次到达且当前段为纯音频时，`finalizeFragment + finalizeSegment` 立即落盘，
  待下一次开片写出包含双轨的 `moov` 再写视频样本。
- **状态**：未修复。

### KI-2 纯视频中途加入音频 → 分段损坏（对称问题）（高）
- **现象**：与 KI-1 对称——先纯视频、中途加入音频时，段 `moov` 缺音频 `trak/trex`，
  音频 `trun` 无法正确解析。
- **根因**：`802bab9` 只补了"纯音→视频"分支；音频配置（`esds` 序列头）首次到达时的
  对称分支缺失（`b56719b` 才补上）。
- **影响**：纯视频流中途加声音的录像段损坏。
- **修复方案（已移除，位于 `b56719b`）**：与 KI-1 对称——音频配置首次到达且当前段为
  纯视频时，立即封装当前段并以双轨 `moov` 重启新段。
- **状态**：未修复。

### KI-3 中途加轨切换点附近丢帧（中）
- **现象**：执行"立即封装+重启新段"后，切换点之后、下一个关键帧到达之前到达的帧会被
  静默丢弃，画面/声音出现几帧断档。
- **根因**：`finalizeSegment()` 不重置 `fragmentStartDts / firstDts / segmentHasAudio`，
  残留状态使"等关键帧开片"判断失效，帧先被缓冲进 `videoSamples/audioSamples`，
  随后又被 `startSegment` 的 `$this->audioSamples = []` 整体清空丢弃。
- **修复方案（已移除，位于 `d312d3d`）**：`finalizeSegment` 重置 `fragmentStartDts/firstDts/segmentHasAudio`
  为初始值，使切换点后的新段干净开始。
- **状态**：未修复。

---

## 二、回放服务：连接重入 / 断连 / 跨段问题

### KI-4 回放 keep-alive 重入静默丢数据（高）
- **现象**：同一 keep-alive HTTP 连接上，第一个回放请求因背压（`bufferFull`）挂起期间，
  第二个回放请求被处理，覆盖了 `onBufferFull / onBufferDrain` 回调与 `bufferFull` 标记，
  导致第一路响应余下的字节静默丢失、客户端收到截断的 mp4。
- **根因**：`PlaybackServer::sendParts` 原为单任务直接执行，无连接级队列；同连接并发请求
  相互覆盖回调。
- **修复方案（已移除，位于 `802bab9` + `b56719b`）**：`sendParts` 拆为入队 + `drainPlaybackQueue`
  两段式状态机，连接级请求队列保证同连接多次回放请求按序串行发送。
- **状态**：未修复。

### KI-5 回放客户端中途断连 → 文件句柄未释放 / 回调残留（高）
- **现象**：回放进行中客户端断连，`PlaybackServer` 不释放正在 `fopen` 的源分段句柄，
  也不清空队列与回调，长期导致 FD 泄漏；同连接对象后续复用时会以旧闭包继续写数据。
- **根因**：原实现没有安装 `onClose` 钩子做资源清理。
- **修复方案（已移除，位于 `b56719b` + `d312d3d`）**：连接级 onClose 钩子——关 FH、清队列、
  清回调、重置 busy 状态；并链式调用上层既有 onClose（见 KI-8）。
- **状态**：未修复。

### KI-6 跨段首段缺轨 → moov 空 trak / duration=0 / dts 空洞（中）
- **现象**：首段纯音频、次段音视频的跨段回放中，输出 `moov` 声明了双轨，但首段样本
  不含视频，导致视频轨为空 `trak`（`duration=0`），时间轴出现 dts 空洞。
- **根因**：回放输出 `moov` 无条件按"检测到的编解码配置"声明轨道，而非按"实际选中样本"
  声明轨道。
- **修复方案（已移除，位于 `b56719b`）**：`moov` 生成只声明有实际选中样本的轨道
  （`$videoSelected` / `$audioSelected` 非空才写对应 `trak`）。
- **状态**：未修复。

### KI-7 回放响应头不入队 → 管道化字节错乱（中）
- **现象**：同连接两个回放请求若前一路因背压挂起、后一路被处理，后一路的
  `HTTP/1.1 200...` 响应头会直接插进前一路未发完的 body 中间，字节流错乱。
- **根因**：`handlePlaybackRequest` 在 `sendParts`（已入队）之前同步发送响应头，
  队列只串行了 body 未串行 head。
- **修复方案（已移除，位于 `d312d3d`）**：新增 `sendResponse()` 将响应头作为首个
  `['bytes'=>head]` part 并入连接级队列，head/body 一体串行。
- **状态**：未修复。（注：HTTP/1.1 现实客户端几乎不管道化，实际暴露较低。）

### KI-8 回放与直播 FLV 的 onClose 互踩（中）
- **现象**：`WMHttpChunkStream` 构造时无条件覆盖 `$connection->onClose` 且不链式；
  `PlaybackServer` 也安装/恢复 `onClose`。同一连接（同端口 18080）上 FLV 与回放请求
  先后到达时互相覆盖，导致 FH 清理不执行或 `FlvPlayStream` 的 close 事件丢失
  （播放器未从 `MediaServer` 注销）。
- **根因**：Workerman `TcpConnection::$onClose` 是单槽位回调，多个模块各自覆盖。
- **修复方案（已移除，位于 `d312d3d`）**：新增 `src/Utils/ConnectionHooks.php`，用存于
  connection 的闭包数组链式分派 onClose（先调用原回调再按注册顺序调用各模块回调，
  单个异常不阻断），PlaybackServer 与 WMHttpChunkStream 均改用之。
- **状态**：未修复。（注：HTTP/1.1 客户端一般不重叠请求，实际暴露较低。）

---

## 三、路径安全：sanitizePath / 目录穿越

### KI-9 sanitizePath 目录穿越（高）
- **现象**：`RecorderManager::sanitizePath` 原实现只替换分隔符与 NUL，不处理
  `.` / `..` / 控制字符；配合 RTMP 推流路径（`app` 名取自 connect 命令且无白名单，
  `MediaServer::verifyAuth` 默认返回 true），攻击者可构造 `..` 让录像 `.mp4` 写到
  `recordPath` 之外。
- **根因**：路径安全化不彻底 + 无目录真实位置兜底。
- **修复方案（已移除，位于 `802bab9` + `b56719b`）**：
  - `sanitizePath` 将 `.` / `..` / 控制字符 / 非 `[A-Za-z0-9_-]` 统一替换为 `_`；
  - `recordDir` 增加 realpath 前缀校验的纵深防御兜底。
- **状态**：未修复。

### KI-10 legacy 兼容层重新引入目录穿越（KI-9 的回归）（高）
- **现象**：为兼容"旧 sanitize 规则（允许 `.`）落盘的旧录像目录"，新增的兼容层在
  新目录不存在时用 `legacySanitizePath`（**不过滤 `.`/`..`**）拼 `$legacyDir`，只要
  `is_dir` 就返回——`recordDir('..')` 实测返回 `recordPath` 的**父目录**，再次绕过 KI-9 的加固。
- **根因**：兼容层返回路径未做"必须位于 recordPath 之内"的 realpath 校验；同时
  `recordDir` 的纵深防御块三个条件写成 AND（应为 OR）且只校验新目录、从不校验 legacy 目录，
  形同死代码。
- **修复方案（已移除，位于 `d312d3d`）**：新增 `isWithinBase()` 统一 realpath 前缀校验，
  兼容层仅放行位于 `recordPath` 内的旧目录；纵深防御 AND→OR 使其真正生效。
- **状态**：未修复。（**优先级最高，建议优先处理**）

---

## 附：相关提交与测试

| 提交 | 覆盖的问题 | 测试（均已随提交移除） |
|---|---|---|
| `802bab9` | KI-1、KI-4、KI-9 | `unit_sanitize`、`unit_reentry`、E2E `aonly_to_video`、`reentry_http` |
| `b56719b` | KI-2、KI-5、KI-6、KI-9 | `unit_closehook`、`unit_legacy_sani`、`unit_moov_trak_selection`、E2E `vonly_to_audio` |
| `d312d3d` | KI-3、KI-7、KI-8、KI-10 | `unit_legacy_traversal`、`unit_head_queue`、`unit_connhooks` |

如需重新落地修复，可按上表提交逐个 `git cherry-pick`（它们仍在 git 对象库中），
或直接在本文档基础上按模块实现；建议修复后补回对应回归测试。
