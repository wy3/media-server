# 性能基准测试套件

对 media-server 做端到端性能基准测试，便于后续改造后重跑对比。

## 文档

| 文档 | 内容 |
|---|---|
| **README.md**（本文件） | 套件结构与使用说明 |
| [PROCEDURE.md](./PROCEDURE.md) | 完整测试流程：环境准备、指标口径、常见问题 |
| [RESULTS.md](./RESULTS.md) | 实测结果与分析（中文） |
| [RESULTS_EN.md](./RESULTS_EN.md) | Results and analysis (English) |

## 场景脚本

| 脚本 | 内容 | 主要指标 |
|---|---|---|
| **s1_latency.ps1** | 1 路推 + 1 路拉，测端到端延迟 | 帧级延迟（毫秒） |
| **s2_pull.ps1** / **s3_push.ps1** | 固定并发拉流 / 推流（单档，长时间稳定性） | CPU / 内存 / 保持率 |
| **s4_ramp.ps1** | **自动爬坡容量测试（主要依据）** | 容量、每路成本、拐点 |
| **s5_mixed.ps1** / **mp_mixed.ps1** | 混合推拉矩阵 | 相互影响、总容量 |
| **mp_pull.ps1** / **mp_diag.ps1** | 按进程角色拆分的拉流诊断 | 瓶颈定位 |

## 快速开始

```powershell
# 1. 生成样本（1920x1080 30fps 4096kb/s，仅首次需要）
.\tests\bench\gen_samples.ps1

# 2. 自动爬坡容量测试（核心：服务端只起一次，按进程独立饱和判定）
.\tests\bench\s4_ramp.ps1 -Scenario pull -PlayCount 6
.\tests\bench\s4_ramp.ps1 -Scenario push -IngestCount 1 -EnableJit

# 3. 固定并发 / 混合矩阵
.\tests\bench\s2_pull.ps1 -Concurrency 500 -Minutes 10
.\tests\bench\s3_push.ps1 -Streams 600 -Minutes 10
.\tests\bench\s5_mixed.ps1 -Matrix '50/500','100/500','500/500'
```

**自动爬坡策略（s4_ramp）**：服务端只启动一次，全程不重启。每 `RampIntervalSec`
（默认 5s）给**每个未饱和的目标进程**各追加 `RampBatch`（默认 100）路；
单进程 CPU 连续 ≥80% 维持 30s 即锁定该进程（不再给它加压）；若 CPU 回落到
阈值以下则解锁、可以继续加。所有目标进程都锁定后，保持负载 40s 做稳定性
考核（保持率 ≥95% 判定通过）。这样测出的容量是"所有进程都跑到饱和"的总容量，
而不是某个进程先到阈值就全局停。

### 压测服务端的架构

`Start-MpServers`（`lib/common.ps1`）启动完整的多进程服务端：

- **control**（注册中心）：流注册表，端口 12090（默认），DB 落在临时目录
- **ingest × N**：推流入口，端口 11935 起（多实例 `+10`）
- **play × N**：拉流入口，端口 11936 起（多实例 `+10`）
- **admin**：管理 API，端口 18099（默认），代理 control 数据
- 录像进程默认不启动（`-WithRecord` 可开）

CPU / 内存按**目标角色进程**统计（s4_ramp）：拉流看 play 进程合计除以进程数
（单进程平均），推流看 ingest 进程；进程树全量 CPU 作为参考值 `allCpu` 同时记录。
`-EnableJit` 给子进程加 `opcache.jit=tracing`（推流路径有收益，拉流无）。

## 前置准备

### 1. 安装 event 扩展（Windows 必须）

默认 `select` 事件循环在 **约 253 连接**就整个卡死（不是拒绝连接，是进程停摆）。
装 `event` 后可到 1000+ 路（见 [RESULTS.md](./RESULTS.md)）。

```powershell
# https://downloads.php.net/~windows/pecl/releases/event/3.2.0/php_event-3.2.0-8.4-nts-vs17-x64.zip
# 解压后：
#   php_event.dll  -> <PHP>\ext\
#   pthreadVC2.dll -> <PHP>\
```

```ini
; php.ini —— 顺序不能反，php_event.dll 直接链接 php_sockets.dll
extension=sockets
extension=event
extension=shmop        ; 帧总线（改版后推流写入 shm）
```

> sockets 是 event 扩展的加载依赖（不是业务用 UDP 组播——组播已移除）；
> shmop 用于进程间帧总线。压测脚本会通过 `-d extension=...` 显式加载这三项，
> 不依赖 php.ini 是否启用。

### 2. 安装 rtmpstress（拉流压测）

```powershell
# https://github.com/wenshui2008/rtmpstress/blob/master/dist/rtmpstress.exe
# 放到 PATH，或设环境变量 RTMPSTRESS，或放到 tests\bench\tools\
$env:RTMPSTRESS = "C:\path\to\rtmpstress.exe"
```

### 3. 依赖

- `ffmpeg` / `ffprobe` 在 PATH
- PHP 8.2+

## 目录

```
tests/bench/
├── README.md                 本文件
├── PROCEDURE.md              完整测试流程
├── RESULTS.md                实测结果与分析（中文）
├── RESULTS_EN.md             Results and analysis (English)
├── gen_samples.ps1           生成测试样本
├── s1_latency.ps1            场景 1：时延
├── s2_pull.ps1               场景 2：拉流并发（单档）
├── s3_push.ps1               场景 3：推流并发（单档）
├── s4_ramp.ps1               场景 4：逐级加压（找容量拐点，推荐）
├── s5_mixed.ps1              场景 5：混合推拉
├── lib/
│   ├── common.ps1            公共函数
│   └── server_bench.php      压测用服务端（可选事件循环）
└── results/                  测试输出 JSON（已 gitignore）
```

## 指标定义

### CPU

- **`svc%`**：服务进程自身的 CPU（`TotalProcessorTime` 增量 ÷ 墙钟）
  - `100%` = 用满 **1 个核**，`200%` = 用满 2 个核
  - **只统计该进程**，与你机器上其他程序无关
- **`sys%`**：系统总占用（按全部逻辑核归一）
  - 用途：判断测试机是否被其他程序干扰
  - 当 `sys%` 高时，服务进程可能拿不到 CPU，`svc%` 会偏低 —— 这是环境问题，不是服务端能力下降

### 内存

RSS（Working Set），单位 MB。用绝对值而不是百分比，避免受机器内存大小影响。

### 连接保持率

`实际保持连接数 / 目标连接数`。目标是 **100%**。

实测 event 循环在 1000 路时保持 100%。

### 时延

**方法**：推流端 `-progress` 与拉流端 `-copyts + showinfo` 各输出「帧 PTS + 墙钟」，
对同一 PTS 取墙钟差。

**关键参数（容易搞反，加错 PTS 会归零）**：

| 端 | 参数 |
|---|---|
| 推流 | `-progress`，**不加** `-copyts` |
| 拉流 | `-vf showinfo`，**必须加** `-copyts` |

两者对 `-copyts` 的反应相反。

## 跨环境对比时的注意事项

**不同环境的绝对数值不能直接比**：

| 差异 | 影响 |
|---|---|
| 不同 CPU 核数 / 虚拟机 vs 物理机 | CPU 占用百分比不可比 |
| 编码推流 vs `-c copy` | 推流端开销不同 |
| 端到端延迟（含编码/播放缓冲）vs 帧级转发延迟 | 口径完全不同 |
| 不同语言 / 并发模型 | 架构差异客观存在 |

**有意义的对比**：

1. **改造前后自身对比**（同机、同脚本）
2. **`select` vs `event` 事件循环对比**
3. **为多进程架构规划进程数**（单进程容量 × 进程数 = 总容量）

## 已知限制

| 限制 | 说明 |
|---|---|
| Windows `select` 约 253 连接后卡死 | 必须装 `event` |
| 推流 1000 路需分段 | Windows 命令行长度上限约 32KB，`s4_ramp.ps1` 已按 250 路分段 |
| `rtmpstress` 依赖 librtmp | 长时间跑可能内存增长 |
| ffmpeg `tee` 单进程推 N 路 | 客户端自己也占 CPU，800 路以上会成为瓶颈 |
| 无网络损伤模拟 | 未注入丢包/抖动 |
| 时延含 ±30ms 噪声 | PTS 帧粒度 33ms + `-progress` 20ms 粒度 |

