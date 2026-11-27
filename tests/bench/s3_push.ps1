# 场景 3：推流并发测试（多进程架构）
#
# 流程：N 路 ffmpeg tee 并发推 ingest 进程 → 持续采样
#
# 指标：
#   CPU    全部 php 服务进程之和（可 > 100%，100% = 合计 1 核）
#   内存   进程树 WorkingSet 之和（MB），另记 Commit
#   保持率 实际推流数 / 目标数

param(
    [int]$Streams = 600,
    [double]$Minutes = 10,
    [int]$IngestRtmpPort = 11935,
    [string]$StreamPrefix = 'live/p',
    [int]$WarmupSeconds = 20,
    [int]$SampleInterval = 30,
    [int]$PushChunk = 250     # 每 ffmpeg tee 进程推的流数（Windows 命令行长度限制）
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

$script:ApiTimeoutSec = 10

$sample = Join-Path $script:SamplesDir '1080p30_4m.flv'
if (-not (Test-Path $sample)) {
    throw "缺少样本 $sample，先运行 .\tests\bench\gen_samples.ps1"
}

Write-Host "=== 场景 3：推流并发测试（每功能一进程）===" -ForegroundColor Cyan
Write-Host "推流: $Streams 路 | 时长: $Minutes 分钟"

$server = $null
$pushProcs = @()

try {
    $server = Start-MpServers -IngestRtmpPort $IngestRtmpPort
    $ports = $server.BenchPorts
    $adminPort = $ports.adminHttp
    Write-Host "服务端已启动（进程树见 launcher pid=$($server.Id)）" -ForegroundColor Green

    $token = Get-AdminToken -HttpPort $adminPort

    # N 路推流，每 PushChunk 路一个 ffmpeg 进程（命令行长度限制）
    $idx = 0
    while ($idx -lt $Streams) {
        $count = [math]::Min($PushChunk, $Streams - $idx)
        $slaves = (1..$count | ForEach-Object {
            "[f=flv]rtmp://127.0.0.1:$($ports.ingestRtmp)/$StreamPrefix$($idx + $_)"
        }) -join '|'
        $pushProcs += Start-Process -FilePath 'ffmpeg' -ArgumentList @(
            '-hide_banner', '-loglevel', 'error'
            '-re', '-stream_loop', '-1'
            '-i', $sample
            '-c', 'copy'
            '-f', 'tee', '-map', '0', $slaves
        ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $ports.logDir "s3_push_$idx.err")
        $idx += $count
    }

    # 等推流建立
    Write-Host "等待 $Streams 路推流建立..." -NoNewline
    $initial = 0
    $deadline = (Get-Date).AddSeconds(120)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 800
        try {
            $st = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $adminPort
            $initial = if ($st) { @($st).Count } else { 0 }
            if ($initial -ge $Streams) { break }
        } catch { }
    }
    Write-Host " ($initial / $Streams)"
    if ($initial -lt $Streams) { Write-Host "警告: 推流未全部建立" -ForegroundColor Yellow }

    Write-Host "预热 $WarmupSeconds 秒..." -NoNewline
    Start-Sleep -Seconds $WarmupSeconds
    Write-Host " 完成"

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

        $st = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $adminPort
        $active = if ($st) { @($st).Count } else { -1 }

        if ($active -lt 0) {
            $apiFail++
            if ($apiFail -ge 2) {
                Write-Host "  检测到服务端 API 连续无响应，提前退出" -ForegroundColor Red
                break
            }
        } else { $apiFail = 0 }

        $s = [pscustomobject]@{
            t = [math]::Round($elapsed, 0)
            svcCpuPct = [math]::Round(($now.CpuSeconds - $base.CpuSeconds) / $elapsed * 100, 2)
            sysCpuPct = Get-SystemCpuPct
            wsMB = $now.WsMB
            commitMB = $now.CommitMB
            procs = $now.Count
            active = $active
        }
        $samples += $s
        Write-Host ("  [{0,4}s] svc={1,6:N1}% sys={2,5:N0}% ws={3,6:N1}MB procs={4} push={5}/{6}" -f `
            $s.t, $s.svcCpuPct, $s.sysCpuPct, $s.wsMB, $s.procs, $active, $Streams)
    }

    # 汇总
    $final = $samples[-1]
    $pushSamples = @($samples | Where-Object { $_.active -ge 0 } | Select-Object -ExpandProperty active)
    $cpuSamples = @($samples | Select-Object -ExpandProperty svcCpuPct)

    $summary = [pscustomobject]@{
        TargetStreams = $Streams
        InitialStreams = $initial
        FinalStreams = $final.active
        MinStreams = if ($pushSamples) { ($pushSamples | Measure-Object -Minimum).Minimum } else { -1 }
        RetentionPct = if ($Streams -gt 0) { [math]::Round($final.active / $Streams * 100, 1) } else { 0 }
        SvcCpuPctAvg = [math]::Round(($cpuSamples | Measure-Object -Average).Average, 2)
        SvcCpuPctMax = ($cpuSamples | Measure-Object -Maximum).Maximum
        WsMBEnd = $final.wsMB
        CommitMBEnd = $final.commitMB
        Procs = $final.procs
        PerStreamCpuPct = if ($final.active -gt 0) { [math]::Round($final.svcCpuPct / $final.active, 4) } else { 0 }
    }

    Write-Host ""
    Write-Host "=== 汇总 ===" -ForegroundColor Cyan
    Write-Host ("  推流: 初始 {0} → 最终 {1} / 目标 {2}（保持率 {3}%）" -f `
        $summary.InitialStreams, $summary.FinalStreams, $Streams, $summary.RetentionPct)
    Write-Host ("  CPU:  均值 {0:N2}%  峰值 {1:N2}%  每路 {2:N4}%  (进程树合计)" -f `
        $summary.SvcCpuPctAvg, $summary.SvcCpuPctMax, $summary.PerStreamCpuPct)
    Write-Host ("  内存: 最终 {0:N1} MB (ws) / {1:N1} MB (commit)" -f $summary.WsMBEnd, $summary.CommitMBEnd)
    Write-Host ("  进程: {0}" -f $summary.Procs)

    Save-BenchResult -Scenario 's3_push' -EventLoop 'multiprocess' -Data @{
        summary = $summary
        samples = $samples
        config = @{
            streams = $Streams
            minutes = $Minutes
            streamPrefix = $StreamPrefix
            warmupSeconds = $WarmupSeconds
            sampleInterval = $SampleInterval
            pushChunk = $PushChunk
            resolution = '1920x1080'
            bitrateKbps = 4096
        }
    } | Out-Null
}
finally {
    foreach ($p in $pushProcs) {
        if ($p -and -not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
    }
    if ($server) { Stop-MpServers -Launcher $server }
}
