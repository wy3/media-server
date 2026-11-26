# 05. 落地阶段与风险

## 1. 分阶段推进

| 阶段 | 内容 | 平台 | 风险 | 验证方式 |
|---|---|---|---|---|
| **0** | 启用 `event` 扩展 + 事件循环（Windows） | Windows | 低 | 压测 300+ 路不掉帧 |
| **1** | 单点状态外移（token → SQLite） | 全平台 | 低 | 现有 53 项 smoke 全绿 |
| **2** | `FrameBusInterface` + `InProcessFrameBus` | 全平台 | 低 | 行为等同现状 |
| **3** | `SharedMemoryFrameBus` + 扇出验证 | Linux | 中 | 脱离业务单独测 |
| **4** | 录像池独立 | Linux | 中 | fMP4 输出可对比 |
| **5** | 推流池 / 播放池拆分 + `reuseport` | Linux | 高 | 需压测 |
| **6** | 录像池分片（按 path 哈希）+ 背压 + 关键帧回溯 | Linux | 高 | 慢盘模拟 |

### 阶段 0：Windows 启用 `event` 事件循环

**这一阶段独立于多进程改造，可立即做，收益立刻可见。**

问题：Windows 默认 `select` 循环在 **253 连接**就撞墙
（`FD_SETSIZE` 位图，见 [A.11 关键发现 1](./appendix-measurements.md)），
此时 CPU 才 35%。

实测解决：装 **`event` 3.2.0**（libevent 绑定），800 路 1080p30 全过：

```ini
; php.ini —— 顺序不能反，php_event.dll 直接链接 php_sockets.dll
extension=sockets
extension=event
```

```php
// start.php
\Workerman\Worker::$eventLoopClass = '\Workerman\Events\Event';
```

| 指标 | `select` | `event` |
|---|---|---|
| 连接上限 | **253** | **800+**（未测到顶） |
| 1080p30 × 200 CPU | 32.62% | **27.75%**（省 15%） |
| 4K30 × 200 CPU | 50.17% | **47.87%** |

**注意不要用 `ev`**：`ev`（libev）在 Windows 上加载成功、定时器正常，
但**监视 socket 时报 `Libev error(10038)` = `WSAENOTSOCK`**，
因为 PHP stream 的 `(int)$stream` 是内部 fd 编号，不是 WinSock `SOCKET` 句柄。
`event` 自带 `EventUtil::getSocketFd()` 做转换，所以能用。

**风险与应对**：

| 风险 | 应对 |
|---|---|
| 用户环境没装扩展 | 自动探测，没装就退回 `select` 并打警告 |
| 装错版本（PHP 8.4 NTS vs TS） | 文档写明必须 `8.4-nts-vs17-x64` |
| 忘记启用 `sockets` | `event` 加载失败会明确报错，不会静默降级 |

### 阶段 6 为什么必须包含「录像池分片」

单线程事件循环下，一路慢会拖慢全部（实测见 [附录 A.10](./appendix-measurements.md)）：

```
场景 1：三路都快  → A: 0.000s  B: 0.000s  C: 0.000s
场景 2：只有 A 慢  → A: 3.077s  B: 3.057s  C: 3.057s
```

如果只有一个录像进程，某路流的慢盘会阻塞该进程的事件循环，
导致**所有流的录像一起卡住**。

**必须按 `crc32(path) % recorderCount` 分片**，确保：

1. 同一路流固定落在一个录像进程（否则文件序号与索引冲突）
2. 一个进程的慢盘只影响它负责的那几路流

### 阶段 1：单点状态外移

范围严格限定：

1. `AdminAuth::$tokens` 改为 SQLite 存储
2. 给 `RecordIndex` 增加 `resetConnection()`（为阶段 3 的 fork 做准备）
3. 行为完全不变

**为什么最先做**：零风险，且后续所有阶段都依赖它。

### 阶段 2：FrameBus 抽象

引入 `FrameBusInterface`，单进程用 `InProcessFrameBus`。

**这是架构分水岭**：

- 单进程行为与现在**完全一致**
- 未来多进程只需换实现，上层零改动
- **即使最终不做多进程，这层抽象本身也有价值**（解耦发布/订阅）

### 阶段 3：共享内存实现

`SharedMemoryFrameBus` + 段头结构 + 轮询。

**可脱离业务验证**：写 N 帧，多消费者读，校验 `crc` 全一致。
现有实测脚本可复用。

### 阶段 4：录像池独立

**这是收益最大的一步**——录像写盘不再阻塞主事件循环。

验证方式：录制 fMP4 用 `ffprobe` + `ffmpeg -f null -` 全量解码校验。

### 阶段 5：推流池 / 播放池拆分

引入 `SO_REUSEPORT` 多进程。

**前置条件**：确认 Linux 上 `reuseport` 能正常分流（Windows 实测不分流）。

### 阶段 6：背压与关键帧

补齐"不丢帧"承诺所需的机制。

## 2. 风险清单

| 风险 | 严重度 | 说明 | 应对 |
|---|---|---|---|
| **Linux `reuseport` 未验证** | 高 | 只在 Windows 实测过（不分流） | 阶段 5 前先验证 |
| **Linux `shmop` 未验证** | 高 | 开发机为 Windows | 阶段 3 前先验证 |
| **录像背压实现** | 高 | 不丢帧要求阻塞生产者，且必须收敛到单路流 | 阶段 6 重点验证 |
| **录像池分片** | 高 | 不分片则一个慢盘拖慢所有流 | 按 path 哈希分片 |
| **关键帧回溯** | 中高 | GOP 超过缓冲深度时无关键帧 | 维护 `lastKeySeq`，不可用时等待 |
| **慢盘抖动导致超时** | 中高 | 超时会中断录像 | 缓冲按 5s 设计 + 监控告警 |
| **段回收正确性** | 中高 | 引用计数错误会导致段泄漏或提前删除 | 超时兜底 + 定期巡检 |
| 共享内存占用量 | 中 | 50 路 1080p ≈ 420 MB | 提供 `shmSlotsPerStream` 配置 |
| crc32 哈希冲突 | 低 | 不同路径算出同一 key | 段头存 path 做校验 |
| 跨机器扩展不支持 | 低 | 共享内存限本机 | 已明确 |

## 3. Linux 前置验证清单

**在进入阶段 3 之前，必须在 Linux 上验证**：

| 项 | 验证方式 |
|---|---|
| `shmop` 跨进程读写 | 父子进程双向读写脚本 |
| `ftok` / 自定义 key 稳定性 | 多进程算出同一 key |
| `SO_REUSEPORT` 分流 | 多进程绑同端口，观察连接分布 |
| 共享内存段残留与回收 | 进程退出后段是否可被删除 |

**这些都无法在 Windows 上验证**，必须在目标环境实测。

## 4. 回归保护

改动过程中必须保持：

- `php tests/smoke.php` 全绿（53 项断言）
- `php tests/playback_e2e.php` 全绿（5 个用例，含 H.264/H.265）
- 管理后台四个面板功能正常
- **Windows 单进程模式始终可用**

**原则**：每个阶段结束后单进程模式都应可用，任何阶段出问题都能回退。

## 5. 未验证项汇总

| 项 | 需要的环境 |
|---|---|
| Linux `shmop` 跨进程 | Linux |
| Linux `reuseport` 分流 | Linux |
| 录像背压在慢盘下的表现 | 压测环境（可限速 IO） |
| 50+ 路并发内存占用 | 压测环境 |
| `event` 在 Linux 上的表现 | Linux（应该也更快，但未测） |

## 6. 建议的第一步

**先做阶段 0**（Windows 换 `event` 事件循环）：

1. 在 `start.php` 检测 `extension_loaded('event')`，有则设 `$eventLoopClass`
2. 文档写明 php.ini 配置与 DLL 版本
3. 现有测试全绿 + 压测 300 路验证

**然后做阶段 1**：

1. `AdminAuth::$tokens` → SQLite
2. `RecordIndex::resetConnection()` 方法
3. 保持行为完全不变

两者都是零风险（逻辑等价），且能立刻验证。

**阶段 0 的收益是即时的**：不换事件循环，Windows 部署一过 253 连接就进不来新流，
而且这个问题在 CPU 只用 35% 时就会发生，很容易被误判为"性能不够"。
