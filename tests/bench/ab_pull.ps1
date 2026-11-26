# A/B 精确对比：同一档位交替跑「基线 / 优化」，用进程 CPU 时间总量而非采样。
#
# 为什么要这样：ramp 脚本按 10s 采样再取平均，单次测量的噪声可达 ±3~7 个百分点，
# 足以掩盖 3% 量级的真实差异。这里直接读 TotalProcessorTime 增量 / 墙钟，
# 并交替多轮，把机器状态漂移摊平。

param(
    [int]$Streams = 1000,
    [int]$MeasureSeconds = 60,
    [int]$WarmupSeconds = 20,
    [int]$Rounds = 2,
    [int]$RtmpPort = 11935,
    [int]$HttpPort = 11980
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

$rtmpstress = Resolve-RtmpStress
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$results = @()

function Run-OneRound {
    param([string]$Label, [int]$Round)

    $server = $null
    $pushProc = $null
    $pullProc = $null
    try {
        $server = Start-BenchServer -EventLoop event -RtmpPort $RtmpPort -HttpPort $HttpPort
        $token = Get-AdminToken -HttpPort $HttpPort

        $pushProc = Start-Process -FilePath 'ffmpeg' -ArgumentList @(
            '-hide_banner', '-loglevel', 'error'
            '-re', '-stream_loop', '-1'
            '-i', (Join-Path $script:SamplesDir '1080p30_4m.flv')
            '-c', 'copy', '-f', 'flv', "rtmp://127.0.0.1:$RtmpPort/live/bench"
        ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $env:TEMP "ab_push.err")

        $deadline = (Get-Date).AddSeconds(30)
        $ready = $false
        while ((Get-Date) -lt $deadline) {
            Start-Sleep -Milliseconds 500
            if (Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $HttpPort -TimeoutSec 5) { $ready = $true; break }
        }
        if (-not $ready) { throw "推流未就绪" }

        $pullProc = Start-Process -FilePath $rtmpstress -ArgumentList @(
            '-c', $Streams, '-i', "rtmp://127.0.0.1:$RtmpPort/live/bench"
        ) -PassThru -WindowStyle Hidden `
          -RedirectStandardOutput (Join-Path $env:TEMP "ab_pull.out") `
          -RedirectStandardError (Join-Path $env:TEMP "ab_pull.err")

        Start-Sleep -Seconds $WarmupSeconds

        # 确认连接数稳定
        $cnt = Invoke-AdminApiSafe -Token $token -Name 'getPlayStreamCount' -Args @('/live/bench') -HttpPort $HttpPort -TimeoutSec 8
        $active = if ($cnt) { $cnt.count } else { -1 }

        $server.Refresh()
        $cpu0 = $server.TotalProcessorTime.TotalSeconds
        $t0 = [DateTime]::UtcNow
        Start-Sleep -Seconds $MeasureSeconds
        $server.Refresh()
        $cpu1 = $server.TotalProcessorTime.TotalSeconds
        $wall = ([DateTime]::UtcNow - $t0).TotalSeconds

        $pct = ($cpu1 - $cpu0) / $wall * 100
        $ws = [math]::Round($server.WorkingSet64 / 1MB, 1)
        Write-Host ("  [{0} R{1}] active={2}/{3}  CPU={4:N2}%  wall={5:N1}s  ws={6}MB" -f `
            $Label, $Round, $active, $Streams, $pct, $wall, $ws) -ForegroundColor Green
        return [pscustomobject]@{ Label = $Label; Round = $Round; Active = $active; CpuPct = [math]::Round($pct, 2); WsMB = $ws }
    }
    finally {
        foreach ($p in @($pullProc, $pushProc)) {
            if ($p -and -not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
        }
        if ($server) { Stop-BenchServer -Process $server }
        Start-Sleep -Seconds 4
    }
}

Write-Host "=== A/B 精确对比（$Streams 路拉流，每轮 ${MeasureSeconds}s）===" -ForegroundColor Cyan
Write-Host "交替执行：优化 → 基线 → 优化 → 基线（各 $Rounds 轮），摊平机器漂移`n"

# 交替跑，避免「先跑的那组机器更闲」这种系统性偏差
for ($r = 1; $r -le $Rounds; $r++) {
    $results += Run-OneRound -Label '优化' -Round $r

    Write-Host "  切换到基线（git stash）..." -ForegroundColor Yellow
    Push-Location $repoRoot
    try {
        git stash push -q -m "ab-baseline-$r" -- src/ 2>&1 | Out-Null
        $results += Run-OneRound -Label '基线' -Round $r
    }
    finally {
        git stash pop -q 2>&1 | Out-Null
        Pop-Location
        Write-Host "  已恢复改动" -ForegroundColor Yellow
    }
}

Write-Host "`n=== 汇总 ===" -ForegroundColor Cyan
$opt = @($results | Where-Object { $_.Label -eq '优化' })
$base = @($results | Where-Object { $_.Label -eq '基线' })
Write-Host ("优化: " + (($opt | ForEach-Object { "$($_.CpuPct)%" }) -join ', '))
Write-Host ("基线: " + (($base | ForEach-Object { "$($_.CpuPct)%" }) -join ', '))

if ($opt.Count -and $base.Count) {
    $om = ($opt | Measure-Object CpuPct -Average).Average
    $bm = ($base | Measure-Object CpuPct -Average).Average
    $oMin = ($opt | Measure-Object CpuPct -Minimum).Minimum
    $oMax = ($opt | Measure-Object CpuPct -Maximum).Maximum
    $bMin = ($base | Measure-Object CpuPct -Minimum).Minimum
    $bMax = ($base | Measure-Object CpuPct -Maximum).Maximum
    Write-Host ""
    Write-Host ("优化均值 {0:N2}%  (范围 {1:N2}~{2:N2}%)" -f $om, $oMin, $oMax)
    Write-Host ("基线均值 {0:N2}%  (范围 {1:N2}~{2:N2}%)" -f $bm, $bMin, $bMax)
    Write-Host ("差值 {0:+0.00;-0.00}%  (相对 {1:+0.0;-0.0}%)" -f ($om - $bm), (($om - $bm) / $bm * 100))
    $overlap = ($oMin -le $bMax) -and ($bMin -le $oMax)
    Write-Host ("两组范围是否重叠: " + $(if ($overlap) { '是 → 差异不可区分' } else { '否 → 差异显著' }))
}

$outFile = Join-Path $script:ResultsDir ("ab_pull_" + (Get-Date -Format 'yyyyMMdd_HHmmss') + ".json")
$results | ConvertTo-Json -Depth 5 | Set-Content -Path $outFile -Encoding UTF8
Write-Host "`n结果已保存: $outFile"
