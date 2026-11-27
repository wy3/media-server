# 场景 2：拉流并发测试（多进程架构）
#
# 流程：1 路推流（4096 kb/s）→ rtmpstress N 路并发拉 play 进程 → 持续采样
#
# 指标：
#   CPU    全部 php 服务进程之和（可 > 100%，100% = 合计 1 核）
#   内存   进程树 WorkingSet 之和（MB），另记 Commit
#   保持率 实际拉流数 / 目标数

param(
    [int]$Concurrency = 800,
    [double]$Minutes = 10,
    [int]$IngestRtmpPort = 11935,
    [int]$PlayRtmpPort = 11936,
    [string]$StreamPath = 'live/bench',
    [int]$WarmupSeconds = 20,
    [int]$SampleInterval = 30
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

$script:ApiTimeoutSec = 10

$sample = Join-Path $script:SamplesDir '1080p30_4m.flv'
if (-not (Test-Path $sample)) {
    throw "缺少样本 $sample，先运行 .\tests\bench\gen_samples.ps1"
}
$rtmpstress = Resolve-RtmpStress

Write-Host "=== 场景 2：拉流并发测试（每功能一进程）===" -ForegroundColor Cyan
Write-Host "并发: $Concurrency 路 | 时长: $Minutes 分钟"

$server = $null
$pushProc = $null
$pullProc = $null

try {
    $server = Start-MpServers -IngestRtmpPort $IngestRtmpPort -PlayRtmpPort $PlayRtmpPort
    $ports = $server.BenchPorts
    $adminPort = $ports.adminHttp
    Write-Host "服务端已启动（进程树见 launcher pid=$($server.Id)）" -ForegroundColor Green

    # 1 路推流（常驻，-stream_loop -1）
    $pushProc = Start-Process -FilePath 'ffmpeg' -ArgumentList @(
        '-hide_banner', '-loglevel', 'error'
        '-re', '-stream_loop', '-1'
        '-i', $sample
        '-c', 'copy'
        '-f', 'flv', "rtmp://127.0.0.1:$($ports.ingestRtmp)/$StreamPath"
    ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $ports.logDir 's2_push.err')

    # 等推流建立（API 代理到 control 注册表）
    $token = Get-AdminToken -HttpPort $adminPort
    $streams = $null
    $deadline = (Get-Date).AddSeconds(60)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 500
        try {
            $streams = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $adminPort
            if ($streams.Count -gt 0 -and $streams[0].videoFps -ge 25) { break }
        } catch { }
    }
    if (-not $streams -or $streams.Count -eq 0) {
        $err = Get-Content (Join-Path $ports.logDir 's2_push.err') -Raw -ErrorAction SilentlyContinue
        throw "推流未建立。ffmpeg stderr:`n$err"
    }
    Write-Host ("推流就绪: {0} fps={1}" -f $streams[0].publishStreamPath, $streams[0].videoFps) -ForegroundColor Green

    # 启动并发拉流（拉 play 进程）
    Write-Host "启动 $Concurrency 路拉流..." -NoNewline
    $pullProc = Start-Process -FilePath $rtmpstress -ArgumentList @(
        '-c', $Concurrency,
        '-i', "rtmp://127.0.0.1:$($ports.playRtmp)/$StreamPath"
    ) -PassThru -WindowStyle Hidden `
      -RedirectStandardOutput (Join-Path $ports.logDir 's2_pull.out') `
      -RedirectStandardError (Join-Path $ports.logDir 's2_pull.err')
    Write-Host " pid=$($pullProc.Id)"

    Write-Host "预热 $WarmupSeconds 秒（等待连接建立稳定）..." -NoNewline
    Start-Sleep -Seconds $WarmupSeconds
    Write-Host " 完成"

    $initial = Invoke-AdminApiSafe -Token $token -Name 'getPlayStreamCount' -Args @("/$StreamPath") -HttpPort $adminPort
    Write-Host ("实际建立连接: {0} / {1}" -f $initial.count, $Concurrency) -ForegroundColor Green

    # 持续采样（进程树口径）
    $totalSeconds = $Minutes * 60
    $samples = @()
    $elapsed = 0
    $t0 = Get-Date
    $base = Get-MpTreeUsage
    $apiFail = 0

    while ($elapsed -lt $totalSeconds) {
        Start-Sleep -Seconds $SampleInterval
        $elapsed = ((Get-Date) - $t0).TotalSeconds
        $now = Get-MpTreeUsage

        $play = Invoke-AdminApiSafe -Token $token -Name 'getPlayStreamCount' -Args @("/$StreamPath") -HttpPort $adminPort

        $s = [pscustomobject]@{
            t = [math]::Round($elapsed, 0)
            svcCpuPct = [math]::Round(($now.CpuSeconds - $base.CpuSeconds) / $elapsed * 100, 2)
            sysCpuPct = Get-SystemCpuPct
            wsMB = $now.WsMB
            commitMB = $now.CommitMB
            procs = $now.Count
            connections = if ($play) { $play.count } else { -1 }
            pullAlive = -not $pullProc.HasExited
        }
        $samples += $s
        Write-Host ("  [{0,4}s] svc={1,6:N1}% sys={2,5:N0}% ws={3,6:N1}MB procs={4} conn={5}/{6}" -f `
            $s.t, $s.svcCpuPct, $s.sysCpuPct, $s.wsMB, $s.procs, $s.connections, $Concurrency)

        # 卡死检测：连续 2 次 API 无响应说明服务停摆
        if ($s.connections -lt 0) {
            $apiFail++
            if ($apiFail -ge 2) {
                Write-Host "  检测到服务端 API 连续无响应，提前退出" -ForegroundColor Red
                break
            }
        } else {
            $apiFail = 0
        }
    }

    # 汇总
    $final = $samples[-1]
    $connSamples = @($samples | Where-Object { $_.connections -ge 0 } | Select-Object -ExpandProperty connections)
    $cpuSamples = @($samples | Select-Object -ExpandProperty svcCpuPct)
    $wsSamples = @($samples | Select-Object -ExpandProperty wsMB)

    $summary = [pscustomobject]@{
        TargetConcurrency = $Concurrency
        InitialConnections = $initial.count
        FinalConnections = $final.connections
        MinConnections = ($connSamples | Measure-Object -Minimum).Minimum
        RetentionPct = if ($Concurrency -gt 0) { [math]::Round($final.connections / $Concurrency * 100, 1) } else { 0 }
        SvcCpuPctAvg = [math]::Round(($cpuSamples | Measure-Object -Average).Average, 2)
        SvcCpuPctMax = ($cpuSamples | Measure-Object -Maximum).Maximum
        WsMBEnd = $final.wsMB
        WsMBMax = ($wsSamples | Measure-Object -Maximum).Maximum
        CommitMBEnd = $final.commitMB
        Procs = $final.procs
        PerConnCpuPct = if ($final.connections -gt 0) { [math]::Round($final.svcCpuPct / $final.connections, 4) } else { 0 }
        PullToolAlive = $final.pullAlive
    }

    Write-Host ""
    Write-Host "=== 汇总 ===" -ForegroundColor Cyan
    Write-Host ("  连接: 初始 {0} → 最终 {1} / 目标 {2}（保持率 {3}%）" -f `
        $summary.InitialConnections, $summary.FinalConnections, $Concurrency, $summary.RetentionPct)
    Write-Host ("  CPU:  均值 {0:N2}%  峰值 {1:N2}%  每连接 {2:N4}%  (进程树合计)" -f `
        $summary.SvcCpuPctAvg, $summary.SvcCpuPctMax, $summary.PerConnCpuPct)
    Write-Host ("  内存: 最终 {0:N1} MB (ws) / {1:N1} MB (commit)  峰值 {2:N1} MB" -f `
        $summary.WsMBEnd, $summary.CommitMBEnd, $summary.WsMBMax)
    Write-Host ("  进程: {0}  拉流工具存活: {1}" -f $summary.Procs, $summary.PullToolAlive)

    Save-BenchResult -Scenario 's2_pull' -EventLoop 'multiprocess' -Data @{
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
    if ($server) { Stop-MpServers -Launcher $server }
}
