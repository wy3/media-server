# 场景 3：推流并发测试
#
# 文章做法：OBS 多路推流（未明确路数），10 分钟，观察 CPU/内存/断连
# 本脚本：ffmpeg tee muxer 单进程推 N 路（避免 N 个进程的调度开销）
#
# 为什么用 tee 而不是 N 个 ffmpeg 进程：
#   实测 96 个 ffmpeg 进程会因 Windows 页面文件不足而启动失败，
#   且进程调度本身干扰 CPU 测量。tee 单进程推 800 路无压力。
#
# 注意：推流端（ffmpeg）自己会占满 1 核，这是测量环境的瓶颈，
#       不代表服务端上限。脚本会同时记录推流端 CPU 以便判断。

param(
    [ValidateSet('event', 'select')]
    [string]$EventLoop = 'event',
    [int]$Streams = 100,
    [double]$Minutes = 10,
    [int]$RtmpPort = 11935,
    [int]$HttpPort = 11980,
    [string]$StreamPrefix = 'push',
    [int]$WarmupSeconds = 20,
    [int]$SampleInterval = 30
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

$sample = Join-Path $script:SamplesDir '1080p30_4m.flv'
if (-not (Test-Path $sample)) {
    throw "缺少样本 $sample，先运行 .\tests\bench\gen_samples.ps1"
}

Write-Host "=== 场景 3：推流并发测试 ===" -ForegroundColor Cyan
Write-Host "事件循环: $EventLoop | 推流: $Streams 路 | 时长: $Minutes 分钟"

$server = $null
$pushProc = $null

try {
    $server = Start-BenchServer -EventLoop $EventLoop -RtmpPort $RtmpPort -HttpPort $HttpPort
    Write-Host "服务端已启动 (pid=$($server.Id))" -ForegroundColor Green

    # 用 tee muxer 单进程推 N 路
    $slaves = (1..$Streams | ForEach-Object {
        "[f=flv]rtmp://127.0.0.1:$RtmpPort/live/$StreamPrefix$_"
    }) -join '|'

    Write-Host "启动 $Streams 路推流（tee muxer）..." -NoNewline
    $pushProc = Start-Process -FilePath 'ffmpeg' -ArgumentList @(
        '-hide_banner', '-loglevel', 'error'
        '-re', '-stream_loop', '-1'
        '-i', $sample
        '-c', 'copy'
        '-f', 'tee', '-map', '0', $slaves
    ) -PassThru -WindowStyle Hidden `
      -RedirectStandardError (Join-Path $env:TEMP 'bench_s3_push.err')
    Write-Host " pid=$($pushProc.Id)"

    Write-Host "预热 $WarmupSeconds 秒（等待推流建立稳定）..." -NoNewline
    Start-Sleep -Seconds $WarmupSeconds
    Write-Host " 完成"

    $token = Get-AdminToken -HttpPort $HttpPort
    $initial = @(Invoke-AdminApi -Token $token -Name 'listPushStream' -HttpPort $HttpPort)
    Write-Host ("实际建立推流: {0} / {1}" -f $initial.Count, $Streams) -ForegroundColor Green

    $badFps = @($initial | Where-Object { $_.videoFps -lt 25 })
    if ($badFps.Count -gt 0) {
        Write-Host ("  警告: {0} 路 fps < 25" -f $badFps.Count) -ForegroundColor Yellow
    }

    # 持续采样
    $totalSeconds = $Minutes * 60
    $samples = @()
    $elapsed = 0
    $t0 = Get-Date
    $server.Refresh()
    $cpu0 = $server.TotalProcessorTime.TotalSeconds
    $pushProc.Refresh()
    $pushCpu0 = $pushProc.TotalProcessorTime.TotalSeconds

    while ($elapsed -lt $totalSeconds) {
        Start-Sleep -Seconds $SampleInterval
        $elapsed = ((Get-Date) - $t0).TotalSeconds
        $server.Refresh()
        $cpu = $server.TotalProcessorTime.TotalSeconds
        $pushProc.Refresh()
        $pushCpu = $pushProc.TotalProcessorTime.TotalSeconds

        $streams = $null
        try {
            # 用非 job 版本：job 序列化会截断大数组（200 条以上实测丢失）
            $streams = Invoke-AdminApi -Token $token -Name 'listPushStream' -HttpPort $HttpPort
        } catch { }

        $s = [pscustomobject]@{
            t = [math]::Round($elapsed, 0)
            cpuCorePct = [math]::Round(($cpu - $cpu0) / $elapsed * 100, 2)
            pushCpuCorePct = [math]::Round(($pushCpu - $pushCpu0) / $elapsed * 100, 2)
            sysCpuPct = Get-SystemCpuPct
            wsMB = [math]::Round($server.WorkingSet64 / 1MB, 1)
            streams = if ($streams) { @($streams).Count } else { -1 }
            lowFpsCount = if ($streams) { @($streams | Where-Object { $_.videoFps -lt 25 }).Count } else { -1 }
            serverAlive = -not $server.HasExited
            pushAlive = -not $pushProc.HasExited
        }
        $samples += $s
        Write-Host ("  [{0,4}s] svc={1,6:N1}% push={2,6:N1}% sys={3,5:N0}% ws={4,6:N1}MB streams={5}/{6} lowFps={7}" -f `
            $s.t, $s.cpuCorePct, $s.pushCpuCorePct, $s.sysCpuPct, $s.wsMB, $s.streams, $Streams, $s.lowFpsCount)
    }

    $final = $samples[-1]
    $cpuSamples = @($samples | Select-Object -ExpandProperty cpuCorePct)
    $streamSamples = @($samples | Where-Object { $_.streams -ge 0 } | Select-Object -ExpandProperty streams)
    $wsSamples = @($samples | Select-Object -ExpandProperty wsMB)

    $summary = [pscustomobject]@{
        TargetStreams = $Streams
        InitialStreams = $initial.Count
        FinalStreams = $final.streams
        MinStreams = if ($streamSamples.Count) { ($streamSamples | Measure-Object -Minimum).Minimum } else { 0 }
        RetentionPct = if ($Streams -gt 0) { [math]::Round($final.streams / $Streams * 100, 1) } else { 0 }
        CpuCorePctAvg = [math]::Round(($cpuSamples | Measure-Object -Average).Average, 2)
        CpuCorePctMax = ($cpuSamples | Measure-Object -Maximum).Maximum
        PushCpuCorePct = $final.pushCpuCorePct
        WsMBEnd = $final.wsMB
        WsMBMax = ($wsSamples | Measure-Object -Maximum).Maximum
        PerStreamCpuPct = if ($final.streams -gt 0) { [math]::Round($final.cpuCorePct / $final.streams, 4) } else { 0 }
        LowFpsStreams = $final.lowFpsCount
        ServerAlive = $final.serverAlive
        PushToolAlive = $final.pushAlive
    }

    Write-Host ""
    Write-Host "=== 汇总 ===" -ForegroundColor Cyan
    Write-Host ("  推流: 初始 {0} → 最终 {1} / 目标 {2}（保持率 {3}%）" -f `
        $summary.InitialStreams, $summary.FinalStreams, $Streams, $summary.RetentionPct)
    Write-Host ("  CPU:  均值 {0:N2}%  峰值 {1:N2}%  每路 {2:N4}%（推流端 {3:N1}%）" -f `
        $summary.CpuCorePctAvg, $summary.CpuCorePctMax, $summary.PerStreamCpuPct, $summary.PushCpuCorePct)
    Write-Host ("  内存: 最终 {0:N1} MB  峰值 {1:N1} MB" -f $summary.WsMBEnd, $summary.WsMBMax)
    Write-Host ("  掉帧: {0} 路 fps<25" -f $summary.LowFpsStreams)
    Write-Host ("  存活: 服务端={0} 推流端={1}" -f $summary.ServerAlive, $summary.PushToolAlive)

    if ($summary.PushCpuCorePct -gt 90) {
        Write-Host "  注意: 推流端已占满 1 核，服务端数据可能受客户端限制" -ForegroundColor Yellow
    }

    Save-BenchResult -Scenario 's3_push' -EventLoop $EventLoop -Data @{
        summary = $summary
        samples = $samples
        config = @{
            streams = $Streams
            minutes = $Minutes
            streamPrefix = $StreamPrefix
            warmupSeconds = $WarmupSeconds
            sampleInterval = $SampleInterval
            resolution = '1920x1080'
            bitrateKbps = 4096
            pushTool = 'ffmpeg tee'
        }
    } | Out-Null
}
finally {
    if ($pushProc -and -not $pushProc.HasExited) { Stop-Process -Id $pushProc.Id -Force -ErrorAction SilentlyContinue }
    if ($server) { Stop-BenchServer -Process $server }
}
