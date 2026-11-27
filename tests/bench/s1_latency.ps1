# 场景 1：时延测试（PTS 滞后法，多进程架构）
#
# 原理：在**同一时刻**同时读两端的最新 PTS：
#   推流端最新 PTS = 服务端已收到的最新帧时间（-progress，不加 -copyts）
#   拉流端首帧 PTS = 播放端刚收到的帧时间（-vf showinfo，必须加 -copyts）
# 两者之差就是端到端延迟。两端 PTS 同一条 RTMP 时间轴，直接相减。
#
# 用法：
#   .\s1_latency.ps1 -Samples 10

param(
    [int]$Samples = 10,
    [int]$IngestRtmpPort = 11935,
    [int]$PlayRtmpPort = 11936,
    [int]$WarmupSeconds = 15
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

Write-Host "=== 场景 1：时延测试（PTS 滞后法 / 每功能一进程）===" -ForegroundColor Cyan
Write-Host "采样: $Samples 次"

$server = $null
$pushProc = $null
$pushProg = Join-Path $env:TEMP 'bench_s1_push_progress.txt'

<#
读推流端 -progress 文件的最新 PTS（秒）。
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
启动一个拉流进程（拉 play 进程），读它输出的第一个 pts_time。
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
    $server = Start-MpServers -IngestRtmpPort $IngestRtmpPort -PlayRtmpPort $PlayRtmpPort
    $ports = $server.BenchPorts
    $adminPort = $ports.adminHttp
    Write-Host "服务端已启动（launcher pid=$($server.Id)）" -ForegroundColor Green

    # 推流端：静态样本 -c copy（不重编码，排除编码器时钟漂移）
    if (Test-Path $pushProg) { Remove-Item $pushProg -Force }
    $pushProc = Start-Process -FilePath 'ffmpeg' -ArgumentList @(
        '-hide_banner', '-loglevel', 'error'
        '-re', '-stream_loop', '-1'
        '-i', (Join-Path $script:SamplesDir '1080p30_4m.flv')
        '-c', 'copy'
        '-f', 'flv', "rtmp://127.0.0.1:$($ports.ingestRtmp)/live/lat"
        '-progress', $pushProg
        '-stats_period', '0.02'
    ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $ports.logDir 's1_push.err')

    $token = Get-AdminToken -HttpPort $adminPort
    $streams = $null
    $deadline = (Get-Date).AddSeconds(60)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 300
        try {
            $streams = Invoke-AdminApi -Token $token -Name 'listPushStream' -HttpPort $adminPort
            if ($streams.Count -gt 0 -and $streams[0].videoFps -ge 25) { break }
        } catch { }
    }
    if (-not $streams -or $streams.Count -eq 0) {
        throw "推流未建立。$(Get-Content (Join-Path $ports.logDir 's1_push.err') -Raw -ErrorAction SilentlyContinue)"
    }
    Write-Host ("推流就绪: fps={0} {1}x{2}" -f $streams[0].videoFps, $streams[0].videoWidth, $streams[0].videoHeight) -ForegroundColor Green

    Write-Host "预热 $WarmupSeconds 秒..." -NoNewline
    Start-Sleep -Seconds $WarmupSeconds
    Write-Host " 完成"

    $results = @()
    for ($i = 1; $i -le $Samples; $i++) {
        Write-Host ("  采样 {0}/{1}..." -f $i, $Samples) -NoNewline

        $pullLog = Join-Path $ports.logDir "s1_pull_$i.log"

        # 先读推流端 PTS（此刻服务端最新帧）
        $pushPts = Get-PushLatestPts -Path $pushProg
        $pushWall = Get-Date

        # 再拉流（play 进程），拿首帧 PTS
        $pullInfo = Get-PullFirstPts -Url "rtmp://127.0.0.1:$($ports.playRtmp)/live/lat" -LogPath $pullLog
        if ($null -eq $pullInfo) {
            Write-Host " 拉流失败" -ForegroundColor Yellow
            Remove-Item $pullLog -Force -ErrorAction SilentlyContinue
            continue
        }

        # 拉流耗时期间推流端 PTS 又前进了，加回 gap
        $gap = ($pullInfo.wall - $pushWall).TotalSeconds
        $pushPtsAtPull = if ($null -ne $pushPts) { $pushPts + $gap } else { $null }

        if ($null -eq $pushPtsAtPull) {
            Write-Host " 推流 PTS 不可用" -ForegroundColor Yellow
            Remove-Item $pullLog -Force -ErrorAction SilentlyContinue
            continue
        }

        # 延迟 = 推流端已发出 - 拉流端刚收到（±25ms 测量噪声，小幅负值=近零延迟）
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

    Save-BenchResult -Scenario 's1_latency' -EventLoop 'multiprocess' -Data @{
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
    if ($server) { Stop-MpServers -Launcher $server }
}
