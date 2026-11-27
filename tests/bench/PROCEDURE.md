# 基准测试流程

本文档说明如何复现 [RESULTS.md](./RESULTS.md) 中的全部数据。

## 一、环境准备

### 1.1 硬件与软件基线

记录这些信息是必要的 —— 换了机器，绝对数值就不可比。

| 项 | 参考值 | 如何获取 |
|---|---|---|
| CPU | AMD Ryzen 9 9900X（12C/24T） | `Get-CimInstance Win32_Processor \| Select Name,NumberOfCores,NumberOfLogicalProcessors` |
| 内存 | 63.6 GB | `(Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory/1GB` |
| OS | Windows | `(Get-CimInstance Win32_OperatingSystem).Caption` |
| PHP | 8.4.25 NTS | `php -v` |
| ffmpeg | 7.1 | `ffmpeg -version` |

脚本会自动把这些写入结果 JSON 的 `host` 字段。

### 1.2 安装 `event` 扩展（Windows 必须）

**为什么必须**：默认 `select` 事件循环在约 253 连接后**整个进程卡死**
（不是拒绝连接，是事件循环停摆，连 HTTP API 都不响应）。

```powershell
# 下载（注意必须匹配 PHP 版本与线程模型）
# 8.4 NTS x64 → php_event-3.2.0-8.4-nts-vs17-x64.zip
# https://downloads.php.net/~windows/pecl/releases/event/3.2.0/

# 解压后：
#   php_event.dll  → <PHP安装目录>\ext\
#   pthreadVC2.dll → <PHP安装目录>\        （与 php.exe 同级）
```

```ini
; php.ini —— 顺序不能反，php_event.dll 直接链接 php_sockets.dll
extension=sockets
extension=event
extension=shmop        ; 改版后推流写入 shm 帧总线，压测也依赖
```

> sockets 是 event 扩展的加载依赖，与业务是否使用 UDP 组播无关（组播已移除）。
> 压测脚本 `Start-BenchServer` 会用 `-d extension=...` 显式加载这三项，
> php.ini 里即使没启用也能跑。

验证：

```powershell
php -d extension=sockets -d extension=event -d extension=shmop -r "echo phpversion('event'), PHP_EOL;"
# 应输出 3.2.0
```

> **不要用 `ev`**：`ev`（libev）在 Windows 上能加载、定时器正常，
> 但监视 socket 时报 `Libev error(10038)` = `WSAENOTSOCK`。
> PHP stream 的 `(int)$stream` 是内部 fd 编号，不是 WinSock `SOCKET` 句柄。
> `event` 自带 `EventUtil::getSocketFd()` 做转换，所以能用。

### 1.3 安装 rtmpstress（拉流压测）

```powershell
# https://github.com/wenshui2008/rtmpstress/blob/master/dist/rtmpstress.exe
# 放到以下任一位置：
#   1. PATH 中
#   2. 设置环境变量 RTMPSTRESS=C:\path\to\rtmpstress.exe
#   3. tests\bench\tools\rtmpstress.exe
```

### 1.4 生成测试样本

```powershell
.\tests\bench\gen_samples.ps1
```

生成 `samples/1080p30_4m.flv`（1920×1080, 30fps, 4096 kb/s, H.264 + AAC 128k）。

**为什么用 `-c copy` 推流**：静态样本循环推流不重编码，
推流端 CPU 占用极低，测出的负载几乎全部来自服务端。
如果用 `-re -i x.mp4 -c:v libx264` 实时编码，推流端自己会占 1~2 核，
污染测量结果。

### 1.5 确认测试机没有其他重负载

```powershell
(Get-CimInstance Win32_PerfFormattedData_PerfOS_Processor | Where-Object Name -eq '_Total').PercentProcessorTime
```

建议 < 20%。脚本会在每次采样时记录 `sys%`（系统总占用），
事后可判断某次数据是否受环境干扰。

---

## 二、测试场景

| 场景 | 脚本 | 目的 |
|---|---|---|
| S1 时延 | `s1_latency.ps1` | 帧级转发延迟 |
| S2 拉流并发（单档） | `s2_pull.ps1` | 某一档的绝对表现与稳定性 |
| S3 推流并发（单档） | `s3_push.ps1` | 某一档的绝对表现与稳定性 |
| S4 逐级加压 | `s4_ramp.ps1` | **找容量拐点（主要依据）** |
| S5 混合推拉 | `s5_mixed.ps1` | 推拉共存时的相互影响 |

### 2.1 为什么 S4（逐级加压）是容量判定的主要依据

单点测试看不出容量拐点。举例：

- 只测 800 路：`select` 直接卡死，看不出「200 是好的、300 是死的」
- 只测 200 路：`event` 只用 24% CPU，看不出上限在哪

逐级加压能看到 **CPU 随路数的增长曲线** 与 **从哪一档开始劣化**。

### 2.2 为什么必须测 S5（混合）

单测推流或单测拉流都会**低估**真实负载：

| 项 | 推流 | 拉流 |
|---|---|---|
| 主要开销 | FLV 解析 + GOP 缓存 | FLV 封包 + 写 socket |
| 内存 | 1.17 MB/路 | 36 KB/连接 |
| 瓶颈 | CPU（单核） | CPU（单核） |

两者 CPU 是**叠加**的，但内存与延迟的影响需要实测。

---

## 三、执行步骤

### 3.1 单场景：逐级加压

```powershell
# 拉流：100 → 1000
.\tests\bench\s4_ramp.ps1 -Scenario pull -EventLoop event -Steps 100,200,400,600,800,1000 -SecondsPerStep 40 -WarmupSeconds 15

# 推流：100 → 1200
.\tests\bench\s4_ramp.ps1 -Scenario push -EventLoop event -Steps 100,200,400,600,800,1000,1200 -SecondsPerStep 40 -WarmupSeconds 20

# select 对照（只测到 300，超过会卡死）
.\tests\bench\s4_ramp.ps1 -Scenario pull -EventLoop select -Steps 100,200,300
.\tests\bench\s4_ramp.ps1 -Scenario push -EventLoop select -Steps 100,200,300
```

**参数说明**：

| 参数 | 含义 | 建议值 |
|---|---|---|
| `-SecondsPerStep` | 每档采样时长 | 40s（至少包含 4 个采样点） |
| `-WarmupSeconds` | 每档预热时长 | 拉流 15s，推流 20s（推流建立更慢） |
| `-Steps` | 档位列表 | 相邻档位差距 ≤ 2 倍 |

### 3.2 混合推拉

```powershell
.\tests\bench\s5_mixed.ps1 -EventLoop event `
    -Matrix '50/500','100/500','200/500','500/500','500/200','500/100','500/50' `
    -SecondsPerCase 45 -WarmupSeconds 25
```

**矩阵设计逻辑**（两个方向各测一遍，才能分离推流与拉流的贡献）：

| 方向 | 用例 | 目的 |
|---|---|---|
| 固定拉流，推流递增 | 50/500 → 500/500 | 推流的边际成本 |
| 固定推流，拉流递减 | 500/500 → 500/50 | 拉流的边际成本 |

### 3.3 时延

```powershell
.\tests\bench\s1_latency.ps1 -EventLoop event -Samples 10 -WarmupSeconds 15
```

### 3.4 超载判定（可选但推荐）

CPU 到 100% 只说明单核时间用完，是**容量边界**。
是否**超载**要看有没有排队 —— 在负载下测新连接的首帧延迟：

```
负载 0    → 延迟 −14 ms
负载 500  → 延迟   8 ms   ← 仍健康
负载 1000 → 延迟 1023 ms  ← 已超载（排队）
```

方法：在当前负载下另起一路拉流，读它首帧的 `pts_time`，
与推流端最新 PTS 相减（见 `s1_latency.ps1` 的实现）。

---

## 四、指标口径

### 4.1 CPU：进程自身，不是系统

```powershell
$cpu0 = $Process.TotalProcessorTime.TotalSeconds   # 该进程累计 CPU 时间
...
($Process.TotalProcessorTime.TotalSeconds - $cpu0) / $wall * 100
```

- `100%` = 用满 **1 个核**
- **只统计该进程**，与机器上其他程序无关
- 同时记录 `sys%`（系统总占用），用于判断环境干扰

**为什么这样算**：单线程事件循环只用一个核，
如果用「系统总占用」，在 24 核机器上 100% 会被稀释成 4%，看不出问题。

### 4.2 内存：RSS

`WorkingSet64`，单位 MB。用绝对值而不是百分比，避免受机器内存大小影响。

### 4.3 连接保持率

`实际保持数 / 目标数`。目标是 100%。

### 4.4 时延：PTS 对齐（⚠️ 精度不足）

推流端与拉流端各输出「帧 PTS + 当时墙钟」，对同一 PTS 取墙钟差。

**关键参数（容易搞反，加错 PTS 会归零）**：

| 端 | 参数 | 说明 |
|---|---|---|
| 推流 | `-progress <file>`，**不加** `-copyts` | `out_time_us` 才是原始 PTS |
| 拉流 | `-vf showinfo`，**必须加** `-copyts` | `pts_time` 才是原始 PTS |

`-progress` 与 `showinfo` 对 `-copyts` 的反应**相反**。这是踩过的坑：
不加对参数，拉流端 PTS 从 0 开始，与推流端永远对不上，
算出的"延迟"是推流已运行的时长（实测 30 秒）。

**⚠️ 当前方法的精度限制**：

| 限制 | 影响 |
|---|---|
| PTS 帧粒度 33ms | 小于 1 帧的差异测不出 |
| `-progress` 粒度 20ms | 推流端时间戳有 ±10ms 抖动 |
| 两端各自起进程采样 | 采集时刻不同步 |
| 出现负值 | 物理不可能，说明噪声已覆盖真实值 |

**因此这组数据只能用于检测数量级劣化**（如 8ms → 1023ms 的排队现象），
**不能用于对比细微差异**（如 event 与 select 的 8ms 差距）。

需要更精确数据时，应改为：同一进程内同时标记两端到达时刻、
用 `hrtime()` 而非解析日志、多次测量取分布。

---

## 五、数据采集与校验

### 5.1 自动采集

脚本每档自动记录：

| 字段 | 来源 |
|---|---|
| `svcCpu` | 服务进程 `TotalProcessorTime` 增量 |
| `sysCpu` | `Win32_PerfFormattedData_PerfOS_Processor` |
| `wsMB` | 服务进程 `WorkingSet64` |
| `pushCount` / `pullCount` | 管理 API |
| `lowFps` | 管理 API 返回的 `videoFps < 25` 路数 |
| `probeLatencyMs` | 探针流 PTS 差 |

### 5.2 必须校验的三件事

| 校验项 | 方法 | 不合格表现 |
|---|---|---|
| 流真的在走 | `listPushStream` 的 `videoFps == 30` | fps 掉到 0 或 < 25 |
| 码率正常 | `bytesReadRate ≈ 524 KB/s`（4 Mbps） | 明显偏低 |
| 事件循环没卡死 | API 连续 2 次无响应即终止 | `active = -1` |

**不加校验的风险**：推流端 ffmpeg 可能启动失败或推流中断，
此时服务端 CPU 很低，看起来"容量很大"，实际什么都没测到。

### 5.3 结果落盘

每次运行写入 `tests/bench/results/<scenario>_<loop>_<timestamp>.json`，
含 `host` 字段记录机器信息。这些文件已 gitignore。

---

## 六、常见问题

### 6.1 推流 1000 路时报「文件名或扩展名太长」

Windows 命令行长度上限约 32KB。`s4_ramp.ps1` 与 `s5_mixed.ps1` 已按 250 路分段，
每段一个 ffmpeg 进程。如果用 `tee` 一次推 1000 路就会超限。

### 6.2 select 循环卡死

预期行为（`FD_SETSIZE=256` 位图溢出）。装 `event` 扩展解决。
脚本会自动检测并提前退出，不会挂死。

### 6.3 时延出现负值

不是错误。PTS 以帧为粒度（33ms），`-progress` 以 20ms 为粒度，
两者叠加产生 ±30ms 抖动。真实延迟落在 `[0, 40ms]`，即 1 帧以内。

### 6.4 数据抖动大

检查 `sys%`。如果测试机被其他程序占用，服务进程拿不到 CPU，
`svc%` 会偏低、延迟会偏高。建议测试期间关闭其他重负载程序。

### 6.5 与其他实现对比时注意

**不同实现的绝对数值不可直接比较**，原因：

| 差异 | 影响 |
|---|---|
| 不同 CPU 核数 / 虚拟机 vs 物理机 | CPU 占用百分比不可比 |
| 编码推流 vs `-c copy` | 推流端开销不同 |
| 端到端延迟（含编码/播放缓冲）vs 帧级转发延迟 | 口径完全不同 |
| 不同语言 / 不同并发模型 | 架构差异客观存在 |

**有意义的对比**：
1. 改造前后自身对比（同机、同脚本）
2. `select` vs `event` 对比
3. 为多进程架构规划进程数

---

## 七、快速复现清单

```powershell
# 0. 前置检查
php -d extension=sockets -d extension=event -d extension=shmop -r "echo phpversion('event');"   # 应输出 3.2.0
ffmpeg -version                                                              # 应有输出
$env:RTMPSTRESS = "$env:TEMP\rtmpstress\rtmpstress.exe"                       # 或放进 PATH

# 1. 生成样本
.\tests\bench\gen_samples.ps1

# 2. 拉流（event）
.\tests\bench\s4_ramp.ps1 -Scenario pull -EventLoop event -Steps 100,200,400,600,800,1000

# 3. 推流（event）
.\tests\bench\s4_ramp.ps1 -Scenario push -EventLoop event -Steps 100,200,400,600,800,1000,1200

# 4. 拉流 / 推流（select 对照，只到 300）
.\tests\bench\s4_ramp.ps1 -Scenario pull -EventLoop select -Steps 100,200,300
.\tests\bench\s4_ramp.ps1 -Scenario push -EventLoop select -Steps 100,200,300

# 5. 混合
.\tests\bench\s5_mixed.ps1 -Matrix '50/500','100/500','200/500','500/500','500/200','500/100','500/50'

# 6. 时延
.\tests\bench\s1_latency.ps1 -EventLoop event -Samples 10
```

总耗时约 40 分钟（每档 40s + 预热 + 清理）。
