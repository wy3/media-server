# 按进程拆分 CPU：诊断多进程压测时 ingest/play 各占多少
param(
    [int]$Streams = 500,
    [int]$Pulls = 500,
    [int]$Seconds = 40,
    [int]$WarmupSeconds = 20,
    [int]$IngestCount = 1,
    [int]$PlayCount = 1
)

$ErrorActionPreference = 'Stop'
$repoRoot = 'F:\work-data\media-server'
$extDir = 'D:\php-8.4.25-nts-Win32-vs17-x64\ext'
$sample = Join-Path $repoRoot 'tests\bench\samples\1080p30_4m.flv'
$rtmpstress = $env:TEMP + '\rtmpstress\rtmpstress.exe'
$tmp = Join-Path $env:TEMP "mp_diag_$Streams"
New-Item -ItemType Directory -Force -Path $tmp | Out-Null
Remove-Item "$tmp\*" -Recurse -Force -ErrorAction SilentlyContinue

$env:MP_LOG_DIR = $tmp
$env:MP_MEMORY_LIMIT = '2G'

function Get-MpProcs {
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -match 'worker\.php' } |
        ForEach-Object {
            $role = if ($_.CommandLine -match 'worker\.php.{0,10}(ingest|play|record|control)') { $matches[1] }
                    elseif ($_.CommandLine -match 'worker') { 'worker?' } else { '?' }
            $proc = Get-Process -Id $_.ProcessId -ErrorAction SilentlyContinue
            if ($proc) {
                [pscustomobject]@{ Role = $role; Pid = $_.ProcessId; Proc = $proc }
            }
        }
}

Write-Host "=== 按进程 CPU 拆分：推 $Streams / 拉 $Pulls ===" -ForegroundColor Cyan

$launcher = Start-Process -FilePath php -ArgumentList @(
    '-d',"extension_dir=$extDir",'-d','extension=shmop','-d','extension=sockets','-d','extension=event',
    '-d','memory_limit=2G',
    (Join-Path $repoRoot 'start_multiprocess.php'),"$IngestCount","$PlayCount",'0',
    '11935','11936','11980','11981'
) -PassThru -WindowStyle Hidden -RedirectStandardOutput "$tmp\l.out" -RedirectStandardError "$tmp\l.err"

$pushProcs = @()
$pulls = @()

try {
    # 等所有实例端口就绪
    $deadline = (Get-Date).AddSeconds(20)
    while ((Get-Date) -lt $deadline) {
        $ok = $true
        for ($i = 0; $i -lt $IngestCount; $i++) {
            if (-not (Test-NetConnection 127.0.0.1 -Port (11935 + $i * 10) -InformationLevel Quiet -WarningAction SilentlyContinue)) { $ok = $false }
        }
        for ($i = 0; $i -lt $PlayCount; $i++) {
            if (-not (Test-NetConnection 127.0.0.1 -Port (11936 + $i * 10) -InformationLevel Quiet -WarningAction SilentlyContinue)) { $ok = $false }
        }
        if ($ok) { break }
        Start-Sleep -Milliseconds 400
    }

    # 推流：按 ingest 实例均分（每 250 路一个 ffmpeg tee）
    $perIngest = [math]::Ceiling($Streams / [math]::Max(1, $IngestCount))
    for ($g = 0; $g -lt $IngestCount; $g++) {
        $n = [math]::Min($perIngest, $Streams - $g * $perIngest)
        if ($n -le 0) { break }
        $port = 11935 + $g * 10
        for ($i = 0; $i -lt $n; $i += 250) {
            $count = [math]::Min(250, $n - $i)
            $slaves = (1..$count | ForEach-Object { "[f=flv]rtmp://127.0.0.1:$port/live/m$($g * $perIngest + $i + $_)" }) -join '|'
            $pushProcs += Start-Process ffmpeg -ArgumentList @(
                '-hide_banner','-loglevel','error','-re','-stream_loop','-1','-i',$sample,
                '-c','copy','-f','tee','-map','0',$slaves
            ) -PassThru -WindowStyle Hidden -RedirectStandardError "$tmp\push_$g`_$i.err"
        }
    }
    Start-Sleep -Seconds 10

    # 拉流：按 play 实例均分
    if ($Pulls -gt 0) {
        $perPlay = [math]::Ceiling($Pulls / [math]::Max(1, $PlayCount))
        for ($g = 0; $g -lt $PlayCount; $g++) {
            $n = [math]::Min($perPlay, $Pulls - $g * $perPlay)
            if ($n -le 0) { break }
            $port = 11936 + $g * 10
            $pulls += Start-Process $rtmpstress -ArgumentList @(
                '-c',$n,'-i',"rtmp://127.0.0.1:$port/live/m1"
            ) -PassThru -WindowStyle Hidden -RedirectStandardOutput "$tmp\pull_$g.out" -RedirectStandardError "$tmp\pull_$g.err"
        }
    }

    Write-Host "预热 ${WarmupSeconds}s..."
    Start-Sleep -Seconds $WarmupSeconds

    # 采样：每进程 CPU 增量
    $procs0 = Get-MpProcs
    $cpu0 = @{}
    foreach ($p in $procs0) { $cpu0[$p.Pid] = $p.Proc.TotalProcessorTime.TotalSeconds }
    $t0 = Get-Date

    Start-Sleep -Seconds $Seconds

    $wall = ((Get-Date) - $t0).TotalSeconds
    $procs1 = Get-MpProcs
    Write-Host ""
    foreach ($p in $procs1) {
        $c0 = if ($cpu0.ContainsKey($p.Pid)) { $cpu0[$p.Pid] } else { 0 }
        $pct = ($p.Proc.TotalProcessorTime.TotalSeconds - $c0) / $wall * 100
        $ws = [math]::Round($p.Proc.WorkingSet64 / 1MB, 0)
        Write-Host ("  [{0}] pid={1}  CPU={2,7:N1}%  ws={3}MB" -f $p.Role, $p.Pid, $pct, $ws) -ForegroundColor Green
    }

    # 内存详情：PrivateMemorySize
    foreach ($p in $procs1) {
        $p.Proc.Refresh()
        Write-Host ("  [{0}] commit={1}MB" -f $p.Role, [math]::Round($p.Proc.PagedMemorySize64 / 1MB, 0))
    }
}
finally {
    foreach ($pp in $pushProcs) { if ($pp -and -not $pp.HasExited) { Stop-Process -Id $pp.Id -Force -ErrorAction SilentlyContinue } }
    foreach ($pl in $pulls) { if ($pl -and -not $pl.HasExited) { Stop-Process -Id $pl.Id -Force -ErrorAction SilentlyContinue } }
    if ($launcher -and -not $launcher.HasExited) { Stop-Process -Id $launcher.Id -Force -ErrorAction SilentlyContinue }
    Start-Sleep 2
    Get-CimInstance Win32_Process -Filter "Name='php.exe' or Name='ffmpeg.exe'" |
        Where-Object { $_.CommandLine -match 'worker\.php|start_multiprocess|live/m' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}
