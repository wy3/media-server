# 多进程诊断：N 路推 + M 路拉，逐进程输出 CPU / 内存 / 线程。
#
# 与 mp_mixed 的差异：mp_mixed 看总和找容量，mp_diag 看分布找瓶颈
# （比如 play 进程 CPU 远高于 ingest = 扇出开销；control CPU 高 = 注册风暴）。
#
# 用法：
#   .\mp_diag.ps1 -Streams 100 -Pulls 200

param(
    [int]$Streams = 100,
    [int]$Pulls = 200,
    [int]$MeasureSeconds = 30,
    [int]$WarmupSeconds = 20,
    [int]$PushChunk = 250,
    [int]$IngestRtmpPort = 11935,
    [int]$PlayRtmpPort = 11936
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

$rtmpstress = Resolve-RtmpStress
$sample = Join-Path $script:SamplesDir '1080p30_4m.flv'
if (-not (Test-Path $sample)) { throw "缺少样本 $sample，先跑 gen_samples.ps1" }

$server = $null
$pushProcs = @()
$stressProc = $null
try {
    $server = Start-MpServers -IngestRtmpPort $IngestRtmpPort -PlayRtmpPort $PlayRtmpPort
    $ports = $server.BenchPorts
    $adminPort = $ports.adminHttp
    $token = Get-AdminToken -HttpPort $adminPort

    # 推流
    $idx = 0
    while ($idx -lt $Streams) {
        $count = [math]::Min($PushChunk, $Streams - $idx)
        $slaves = (1..$count | ForEach-Object {
            "[f=flv]rtmp://127.0.0.1:$($ports.ingestRtmp)/live/d$($idx + $_)"
        }) -join '|'
        $pushProcs += Start-Process -FilePath 'ffmpeg' -ArgumentList @(
            '-hide_banner', '-loglevel', 'error'
            '-re', '-stream_loop', '-1', '-i', $sample
            '-c', 'copy', '-f', 'tee', '-map', '0', $slaves
        ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $ports.logDir "push_$idx.err")
        $idx += $count
    }

    $deadline = (Get-Date).AddSeconds(90)
    $pushOk = $false
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 800
        $st = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $adminPort -TimeoutSec 8
        if ($st -and @($st).Count -ge $Streams) { $pushOk = $true; break }
    }
    if (-not $pushOk) { throw "推流未达目标（$Streams）" }

    if ($Pulls -gt 0) {
        $stressProc = Start-Process -FilePath $rtmpstress -ArgumentList @(
            '-c', $Pulls, '-i', "rtmp://127.0.0.1:$($ports.playRtmp)/live/d1"
        ) -PassThru -WindowStyle Hidden `
          -RedirectStandardOutput (Join-Path $ports.logDir 'pull.out') `
          -RedirectStandardError (Join-Path $ports.logDir 'pull.err')
    }

    Write-Host "预热 ${WarmupSeconds}s..."
    Start-Sleep -Seconds $WarmupSeconds

    function Get-RoleProcs {
        Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
            Where-Object { $_.CommandLine -match 'worker\.php' } |
            ForEach-Object {
                $role = if ($_.CommandLine -match 'worker\.php.{0,10}ingest') { 'ingest' }
                    elseif ($_.CommandLine -match 'worker\.php.{0,10}play') { 'play' }
                    elseif ($_.CommandLine -match 'worker\.php.{0,10}control') { 'control' }
                    elseif ($_.CommandLine -match 'worker\.php.{0,10}admin') { 'admin' }
                    elseif ($_.CommandLine -match 'worker\.php.{0,10}record') { 'record' }
                    else { 'launcher' }
                $p = Get-Process -Id $_.ProcessId -ErrorAction SilentlyContinue
                if ($p) { [pscustomobject]@{ Role = $role; Pid = $_.ProcessId; Proc = $p } }
            }
    }

    $procs0 = Get-RoleProcs
    $cpu0 = @{}
    foreach ($p in $procs0) { $cpu0[$p.Pid] = $p.Proc.TotalProcessorTime.TotalSeconds }
    $t0 = Get-Date

    Start-Sleep -Seconds $MeasureSeconds

    $wall = ((Get-Date) - $t0).TotalSeconds
    Write-Host ""
    Write-Host ("{0,-10} {1,10} {2,10} {3,8}" -f '进程', 'CPU%', '内存MB', '线程数')
    Write-Host ('-' * 44)
    $totalCpu = 0.0
    foreach ($p in (Get-RoleProcs)) {
        $c0 = if ($cpu0.ContainsKey($p.Pid)) { $cpu0[$p.Pid] } else { 0 }
        $pct = ($p.Proc.TotalProcessorTime.TotalSeconds - $c0) / $wall * 100
        $totalCpu += $pct
        $p.Proc.Refresh()
        Write-Host ("{0,-10} {1,10:N1} {2,10:N0} {3,8}" -f `
            $p.Role, $pct, [math]::Round($p.Proc.WorkingSet64 / 1MB, 0), $p.Proc.Threads.Count) -ForegroundColor Green
    }
    Write-Host ("{0,-10} {1,10:N1}" -f '合计', $totalCpu) -ForegroundColor Cyan

    Save-BenchResult -Scenario 'mp_diag' -EventLoop 'multiprocess' -Data @{
        streams = $Streams
        pulls = $Pulls
        measureSeconds = $MeasureSeconds
    } | Out-Null
}
finally {
    foreach ($p in (@($stressProc) + @($pushProcs))) {
        if ($p -and -not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
    }
    if ($server) { Stop-MpServers -Launcher $server }
}
