# 性能基准测试套件

对 media-server 做端到端性能基准测试，便于后续改造后重跑对比。

## 文档

| 文档 | 内容 |
|---|---|
| **README.md**（本文件） | 套件结构与使用说明 |
| [PROCEDURE.md](./PROCEDURE.md) | 完整测试流程：环境准备、指标口径、常见问题 |
| [RESULTS.md](./RESULTS.md) | 实测结果与分析（中文） |
| [RESULTS_EN.md](./RESULTS_EN.md) | Results and analysis (English) |

## 三个场景

| 场景 | 内容 | 主要指标 |
|---|---|---|
| **S1 时延** | 1 路推 + 1 路拉，测端到端延迟 | 帧级延迟（毫秒） |
| **S2 拉流** | 1 路推 + N 路并发拉（单档） | CPU / 内存 / 连接保持率 |
| **S3 推流** | N 路并发推 + 不拉流（单档） | CPU / 内存 / 连接保持率 |
| **S4 逐级加压** | 从低到高逐档加压 | **容量拐点（主要依据）** |
| **S5 混合** | 同时 N 路推 + M 路拉 | 相互影响、总容量 |

## 快速开始

```powershell
# 1. 生成样本（1920x1080 30fps 4096kb/s）
.\tests\bench\gen_samples.ps1

# 2. 逐级加压（推荐，能看出容量拐点）
.\tests\bench\s4_ramp.ps1 -Scenario pull -EventLoop event -Steps 100,200,400,600,800,1000
.\tests\bench\s4_ramp.ps1 -Scenario push -EventLoop event -Steps 100,200,400,600,800,1000,1200

# 3. 混合推拉（更接近生产负载）
.\tests\bench\s5_mixed.ps1 -Matrix '50/500','100/500','200/500','500/500','500/200','500/100','500/50'

# 4. 或单场景
.\tests\bench\s1_latency.ps1 -EventLoop event -Samples 10
.\tests\bench\s2_pull.ps1 -EventLoop event -Concurrency 800 -Minutes 10
.\tests\bench\s3_push.ps1 -EventLoop event -Streams 600 -Minutes 10
```

**为什么推荐逐级加压**：单点测试看不出拐点。比如只测 800 路，
select 会直接卡死，看不出「200 路是好的、300 是死的」这条边界。

**为什么还要测混合**：单测推流或单测拉流都会低估真实负载，
且两者的开销结构不同（推流内存密集、拉流连接密集）。

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
```

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

