# 场景 2：拉流并发测试
#
# 文章做法：1 路推流（4096 kb/s）+ rtmpstress 800 路并发拉，10 分钟
# 本脚本：同样流程，但增加自动采样 CPU/内存/连接保持率
#
# 关键：Windows 默认 select 事件循环在 253 连接就撞墙，
#       必须用 event（见 docs/multiprocess/appendix-measurements.md）。
#
# 指标：
#   CPU    单核百分比（100% = 1 核跑满）
#   内存   RSS (MB)
#   保持率 实际拉流数 / 目标数

param(
    [ValidateSet('event', 'select')]
    [string]$EventLoop = 'event',
    [int]$Concurrency = 800,
    [double]$Minutes = 10,
    [int]$RtmpPort = 11935,
    [int]$HttpPort = 11980,
    [string]$StreamPath = 'live/bench',
    [int]$WarmupSeconds = 20,
    [int]$SampleInterval = 30
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

# API 调用加超时：select 事件循环在连接数超限时会整个卡死，
# 连 HTTP 都不响应，默认无限等待会让脚本挂死。
$script:ApiTimeoutSec = 10

$sample = Join-Path $script:SamplesDir '1080p30_4m.flv'
if (-not (Test-Path $sample)) {
    throw "缺少样本 $sample，先运行 .\tests\bench\gen_samples.ps1"
}
$rtmpstress = Resolve-RtmpStress

Write-Host "=== 场景 2：拉流并发测试 ===" -ForegroundColor Cyan
Write-Host "事件循环: $EventLoop | 并发: $Concurrency 路 | 时长: $Minutes 分钟"

if ($EventLoop -eq 'select' -and $Concurrency -gt 250) {
    Write-Host "警告: select 事件循环上限约 253 连接，$Concurrency 路会导致进程卡死（连 API 都不响应）" -ForegroundColor Yellow
    Write-Host "      本脚本会在检测到卡死时提前退出，数据不完整" -ForegroundColor Yellow
}

$server = $null
$pushProc = $null
$pullProc = $null

try {
    $server = Start-BenchServer -EventLoop $EventLoop -RtmpPort $RtmpPort -HttpPort $HttpPort
    Write-Host "服务端已启动 (pid=$($server.Id))" -ForegroundColor Green

    # 1 路推流（常驻，-stream_loop -1）
    $pushProc = Start-Process -FilePath 'ffmpeg' -ArgumentList @(
        '-hide_banner', '-loglevel', 'error'
        '-re', '-stream_loop', '-1'
        '-i', $sample
        '-c', 'copy'
        '-f', 'flv', "rtmp://127.0.0.1:$RtmpPort/$StreamPath"
    ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $env:TEMP 'bench_s2_push.err')

    # 等推流建立
    $token = $null
    $streams = $null
    $deadline = (Get-Date).AddSeconds(20)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 500
        try {
            if (-not $token) { $token = Get-AdminToken -HttpPort $HttpPort }
            $streams = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $HttpPort
            if ($streams.Count -gt 0 -and $streams[0].videoFps -ge 25) { break }
        } catch { }
    }
    if (-not $streams -or $streams.Count -eq 0) {
        $err = Get-Content (Join-Path $env:TEMP 'bench_s2_push.err') -Raw -ErrorAction SilentlyContinue
        throw "推流未建立。ffmpeg stderr:`n$err"
    }
    Write-Host ("推流就绪: {0} fps={1}" -f $streams[0].publishStreamPath, $streams[0].videoFps) -ForegroundColor Green

    # 启动并发拉流
    Write-Host "启动 $Concurrency 路拉流..." -NoNewline
    $pullProc = Start-Process -FilePath $rtmpstress -ArgumentList @(
        '-c', $Concurrency,
        '-i', "rtmp://127.0.0.1:$RtmpPort/$StreamPath"
    ) -PassThru -WindowStyle Hidden `
      -RedirectStandardOutput (Join-Path $env:TEMP 'bench_s2_pull.out') `
      -RedirectStandardError (Join-Path $env:TEMP 'bench_s2_pull.err')
    Write-Host " pid=$($pullProc.Id)"

    Write-Host "预热 $WarmupSeconds 秒（等待连接建立稳定）..." -NoNewline
    Start-Sleep -Seconds $WarmupSeconds
    Write-Host " 完成"

    # 记录初始连接数
    $initial = Invoke-AdminApiSafe -Token $token -Name 'getPlayStreamCount' -Args @("/$StreamPath") -HttpPort $HttpPort
    Write-Host ("实际建立连接: {0} / {1}" -f $initial.count, $Concurrency) -ForegroundColor Green

    # 持续采样
    $totalSeconds = $Minutes * 60
    $samples = @()
    $elapsed = 0
    $t0 = Get-Date
    $server.Refresh()
    $cpu0 = $server.TotalProcessorTime.TotalSeconds
    $apiFail = 0

    while ($elapsed -lt $totalSeconds) {
        Start-Sleep -Seconds $SampleInterval
        $elapsed = ((Get-Date) - $t0).TotalSeconds
        $server.Refresh()
        $cpu = $server.TotalProcessorTime.TotalSeconds

        $play = Invoke-AdminApiSafe -Token $token -Name 'getPlayStreamCount' -Args @("/$StreamPath") -HttpPort $HttpPort

        $s = [pscustomobject]@{
            t = [math]::Round($elapsed, 0)
            cpuCorePct = [math]::Round(($cpu - $cpu0) / $elapsed * 100, 2)
            sysCpuPct = Get-SystemCpuPct
            wsMB = [math]::Round($server.WorkingSet64 / 1MB, 1)
            connections = if ($play) { $play.count } else { -1 }
            serverAlive = -not $server.HasExited
            pullAlive = -not $pullProc.HasExited
        }
        $samples += $s
        Write-Host ("  [{0,4}s] svc={1,6:N1}% sys={2,5:N0}% ws={3,6:N1}MB conn={4}/{5}" -f `
            $s.t, $s.cpuCorePct, $s.sysCpuPct, $s.wsMB, $s.connections, $Concurrency)

        # 卡死检测：连续 2 次 API 无响应说明事件循环停摆
        if ($s.connections -lt 0) {
            $apiFail++
            if ($apiFail -ge 2) {
                Write-Host "  检测到服务端 API 连续无响应，事件循环已卡死，提前退出" -ForegroundColor Red
                Write-Host "  这通常是因为连接数超过事件循环上限（select 约 253）" -ForegroundColor Red
                break
            }
        } else {
            $apiFail = 0
        }
    }

    # 汇总
    $final = $samples[-1]
    $connSamples = @($samples | Where-Object { $_.connections -ge 0 } | Select-Object -ExpandProperty connections)
    $cpuSamples = @($samples | Select-Object -ExpandProperty cpuCorePct)
    $wsSamples = @($samples | Select-Object -ExpandProperty wsMB)

    $summary = [pscustomobject]@{
        TargetConcurrency = $Concurrency
        InitialConnections = $initial.count
        FinalConnections = $final.connections
        MinConnections = ($connSamples | Measure-Object -Minimum).Minimum
        RetentionPct = if ($Concurrency -gt 0) { [math]::Round($final.connections / $Concurrency * 100, 1) } else { 0 }
        CpuCorePctAvg = [math]::Round(($cpuSamples | Measure-Object -Average).Average, 2)
        CpuCorePctMax = ($cpuSamples | Measure-Object -Maximum).Maximum
        WsMBEnd = $final.wsMB
        WsMBMax = ($wsSamples | Measure-Object -Maximum).Maximum
        PerConnCpuPct = if ($final.connections -gt 0) { [math]::Round($final.cpuCorePct / $final.connections, 4) } else { 0 }
        ServerAlive = $final.serverAlive
        PullToolAlive = $final.pullAlive
    }

    Write-Host ""
    Write-Host "=== 汇总 ===" -ForegroundColor Cyan
    Write-Host ("  连接: 初始 {0} → 最终 {1} / 目标 {2}（保持率 {3}%）" -f `
        $summary.InitialConnections, $summary.FinalConnections, $Concurrency, $summary.RetentionPct)
    Write-Host ("  CPU:  均值 {0:N2}%  峰值 {1:N2}%  每连接 {2:N4}%" -f `
        $summary.CpuCorePctAvg, $summary.CpuCorePctMax, $summary.PerConnCpuPct)
    Write-Host ("  内存: 最终 {0:N1} MB  峰值 {1:N1} MB" -f $summary.WsMBEnd, $summary.WsMBMax)
    Write-Host ("  存活: 服务端={0} 拉流工具={1}" -f $summary.ServerAlive, $summary.PullToolAlive)

    Save-BenchResult -Scenario 's2_pull' -EventLoop $EventLoop -Data @{
        summary = $summary
        samples = $samples
        config = @{
            concurrency = $Concurrency
            minutes = $Minutes
            streamPath = $StreamPath
            warmupSeconds = $WarmupSeconds
            sampleInterval = $SampleInterval
            resolution = '1920x1080'
            bitrateKbps = 4096
            pullTool = 'rtmpstress'
        }
    } | Out-Null
}
finally {
    if ($pullProc -and -not $pullProc.HasExited) { Stop-Process -Id $pullProc.Id -Force -ErrorAction SilentlyContinue }
    if ($pushProc -and -not $pushProc.HasExited) { Stop-Process -Id $pushProc.Id -Force -ErrorAction SilentlyContinue }
    if ($server) { Stop-BenchServer -Process $server }
}
