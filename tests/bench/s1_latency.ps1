# 场景 1：时延测试（PTS 滞后法）
#
# 文章做法：OBS 推流 → 浏览器秒表 → 人工截屏比对（±0.2s，不可复现）
#
# 本脚本原理（最简单、无插值、无对齐）：
#   在**同一时刻**同时读两端的最新 PTS：
#       推流端最新 PTS = 服务端已收到的最新帧时间
#       拉流端首帧 PTS = 播放端刚收到的帧时间
#   两者之差就是端到端延迟。
#
#   因为两端 PTS 都在同一条 RTMP 时间轴上（已实测确认），
#   直接相减即可，不需要墙钟、不需要插值、不需要对齐。
#
# 关键参数（已实测，容易搞反）：
#   推流端：-progress，**不加** -copyts  → out_time_us 是原始 PTS
#   拉流端：-vf showinfo，**必须加** -copyts → pts_time 是原始 PTS
#   两者对 -copyts 反应相反，加错 PTS 会归零。
#
# 为什么不用「画面烧时间码」：
#   移动白条方案有三个致命问题：
#     1. 位置只有周期内相位，取模在边界必然翻转
#     2. 编码器时钟与墙钟 2~5% 漂移，误差无限累积
#     3. 像素量化 + 色度下采样，1px = 16.7ms
#
# 为什么不用「offset = wall - pts」：
#   推流端 -progress 是 50ms 粒度，记录墙钟的时刻比帧真正发出的时刻晚
#   平均 25ms，造成 -22ms 的系统性负偏差（实测）。
#   直接算 PTS 差没有这个问题。
#
# 前提：推流与拉流同机。

param(
    [ValidateSet('event', 'select')]
    [string]$EventLoop = 'event',
    [int]$Samples = 10,
    [int]$RtmpPort = 11935,
    [int]$HttpPort = 11980,
    [int]$WarmupSeconds = 15
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

Write-Host "=== 场景 1：时延测试（PTS 滞后法）===" -ForegroundColor Cyan
Write-Host "事件循环: $EventLoop | 采样: $Samples 次"

$server = $null
$pushProc = $null
$pushProg = Join-Path $env:TEMP 'bench_s1_push_progress.txt'

<#
读推流端 -progress 文件的最新 PTS（秒）。
文件是原地重写，直接取最后一个 out_time_us。
#>
function Get-PushLatestPts {
    param([string]$Path)
    if (-not (Test-Path $Path)) { return $null }
    $c = Get-Content $Path -Raw -ErrorAction SilentlyContinue
    if (-not $c) { return $null }
    $m = [regex]::Matches($c, 'out_time_us=(\d+)')
    if ($m.Count -eq 0) { return $null }
    return [double]$m[$m.Count - 1].Groups[1].Value / 1e6
}

<#
启动一个拉流进程，读它输出的第一个 pts_time。
返回 @{ pts; pullStart; firstFrameWall } 或 $null。

实现：拉流进程把 showinfo 写到 stderr 文件，
本函数轮询该文件直到出现 pts_time，记录当时的墙钟。
#>
function Get-PullFirstPts {
    param(
        [string]$Url,
        [string]$LogPath,
        [int]$TimeoutSeconds = 20
    )

    if (Test-Path $LogPath) { Remove-Item $LogPath -Force }

    $proc = Start-Process -FilePath 'ffmpeg' -ArgumentList @(
        '-hide_banner', '-loglevel', 'info'
        '-copyts'
        '-analyzeduration', '0', '-probesize', '32'
        '-fflags', 'nobuffer', '-flags', 'low_delay'
        '-i', $Url
        '-frames:v', '5'
        '-vf', 'showinfo'
        '-f', 'null', '-'
    ) -PassThru -WindowStyle Hidden -RedirectStandardError $LogPath

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    $pts = $null
    $wall = $null

    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 20
        if (Test-Path $LogPath) {
            $c = Get-Content $LogPath -Raw -ErrorAction SilentlyContinue
            if ($c) {
                # 用 [regex]::Match 而不是 -match：
                # -match 的 $matches 在函数作用域里不可靠（实测会报"空数组索引"）
                $m = [regex]::Match($c, 'pts_time:([0-9.]+)')
                if ($m.Success) {
                    $pts = [double]$m.Groups[1].Value
                    $wall = Get-Date
                    break
                }
            }
        }
        if ($proc.HasExited -and $null -eq $pts) { break }
    }

    if ($proc -and -not $proc.HasExited) {
        Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
    }

    if ($null -eq $pts) { return $null }
    return [pscustomobject]@{ pts = $pts; wall = $wall }
}

try {
    $server = Start-BenchServer -EventLoop $EventLoop -RtmpPort $RtmpPort -HttpPort $HttpPort
    Write-Host "服务端已启动 (pid=$($server.Id))" -ForegroundColor Green

    # 推流端：静态样本 -c copy（不重编码，排除编码器时钟漂移）
    if (Test-Path $pushProg) { Remove-Item $pushProg -Force }
    $pushProc = Start-Process -FilePath 'ffmpeg' -ArgumentList @(
        '-hide_banner', '-loglevel', 'error'
        '-re', '-stream_loop', '-1'
        '-i', (Join-Path $script:SamplesDir '1080p30_4m.flv')
        '-c', 'copy'
        '-f', 'flv', "rtmp://127.0.0.1:$RtmpPort/live/lat"
        '-progress', $pushProg
        '-stats_period', '0.02'    # 20ms 粒度，降低 PTS 滞后误差
    ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $env:TEMP 'bench_s1_push.err')

    $token = $null
    $streams = $null
    $deadline = (Get-Date).AddSeconds(20)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 300
        try {
            if (-not $token) { $token = Get-AdminToken -HttpPort $HttpPort }
            $streams = Invoke-AdminApi -Token $token -Name 'listPushStream' -HttpPort $HttpPort
            if ($streams.Count -gt 0 -and $streams[0].videoFps -ge 25) { break }
        } catch { }
    }
    if (-not $streams -or $streams.Count -eq 0) {
        throw "推流未建立。$(Get-Content (Join-Path $env:TEMP 'bench_s1_push.err') -Raw -ErrorAction SilentlyContinue)"
    }
    Write-Host ("推流就绪: fps={0} {1}x{2}" -f $streams[0].videoFps, $streams[0].videoWidth, $streams[0].videoHeight) -ForegroundColor Green

    Write-Host "预热 $WarmupSeconds 秒..." -NoNewline
    Start-Sleep -Seconds $WarmupSeconds
    Write-Host " 完成"

    $results = @()
    for ($i = 1; $i -le $Samples; $i++) {
        Write-Host ("  采样 {0}/{1}..." -f $i, $Samples) -NoNewline

        $pullLog = Join-Path $env:TEMP "bench_s1_pull_$i.log"

        # 先读推流端 PTS（此刻服务端最新帧）
        $pushPts = Get-PushLatestPts -Path $pushProg
        $pushWall = Get-Date

        # 再拉流，拿首帧 PTS
        $pullInfo = Get-PullFirstPts -Url "rtmp://127.0.0.1:$RtmpPort/live/lat" -LogPath $pullLog
        if ($null -eq $pullInfo) {
            Write-Host " 拉流失败" -ForegroundColor Yellow
            Remove-Item $pullLog -Force -ErrorAction SilentlyContinue
            continue
        }

        # 两次读 PTS 之间的时间差也要算进去：
        # 拉流耗时期间推流端 PTS 又前进了，应加回
        $gap = ($pullInfo.wall - $pushWall).TotalSeconds
        $pushPtsAtPull = if ($null -ne $pushPts) { $pushPts + $gap } else { $null }

        if ($null -eq $pushPtsAtPull) {
            Write-Host " 推流 PTS 不可用" -ForegroundColor Yellow
            Remove-Item $pullLog -Force -ErrorAction SilentlyContinue
            continue
        }

        # 延迟 = 推流端已发出 - 拉流端刚收到
        #
        # 理论值应 > 0，但实测会出现 ±25ms 的抖动，来源：
        #   - 推流端 -progress 有 20ms 更新粒度
        #   - 拉流端 showinfo 行写入文件后才被读到，有 ms 级延迟
        #   - 拉流进程从启动到首帧本身要 ~1s，期间推流端 PTS 已前进（已加回 gap）
        # 小幅负值代表「测量噪声范围内的近零延迟」，不是逻辑错误。
        # 这里保留原值，由统计阶段判断。
        $latencyMs = ($pushPtsAtPull - $pullInfo.pts) * 1000

        $results += [pscustomobject]@{
            Sample = $i
            LatencyMs = [math]::Round($latencyMs, 1)
            PushPts = [math]::Round($pushPtsAtPull, 3)
            PullPts = [math]::Round($pullInfo.pts, 3)
            PullGapMs = [math]::Round($gap * 1000, 0)
        }
        Write-Host (" latency={0:N1}ms pushPts={1:N2} pullPts={2:N2}" -f `
            $latencyMs, $pushPtsAtPull, $pullInfo.pts) -ForegroundColor Green
        Remove-Item $pullLog -Force -ErrorAction SilentlyContinue
    }

    if ($results.Count -eq 0) { throw "没有成功的采样" }

    $vals = @($results | Select-Object -ExpandProperty LatencyMs | Sort-Object)
    $stats = [pscustomobject]@{
        Count = $results.Count
        MinMs = $vals[0]
        MaxMs = $vals[-1]
        MedianMs = $vals[[int][math]::Floor($vals.Count / 2)]
        AverageMs = [math]::Round(($vals | Measure-Object -Average).Average, 1)
        P95Ms = $vals[[math]::Min($vals.Count - 1, [int][math]::Floor($vals.Count * 0.95))]
    }

    Write-Host ""
    Write-Host "=== 时延统计（毫秒）===" -ForegroundColor Cyan
    Write-Host ("  最小 {0:N0}  中位 {1:N0}  均值 {2:N0}  P95 {3:N0}  最大 {4:N0}" -f `
        $stats.MinMs, $stats.MedianMs, $stats.AverageMs, $stats.P95Ms, $stats.MaxMs)

    Save-BenchResult -Scenario 's1_latency' -EventLoop $EventLoop -Data @{
        samples = $results
        stats = $stats
        method = 'pts-lag'
        config = @{
            warmupSeconds = $WarmupSeconds
            resolution = '1920x1080'
            bitrateKbps = 4096
            pushMode = 'copy'
            pushArgs = '-progress (no -copyts)'
            pullArgs = '-copyts -vf showinfo'
            pushProgressPeriodMs = 20
        }
    } | Out-Null
}
finally {
    if ($pushProc -and -not $pushProc.HasExited) { Stop-Process -Id $pushProc.Id -Force -ErrorAction SilentlyContinue }
    if ($server) { Stop-BenchServer -Process $server }
}
