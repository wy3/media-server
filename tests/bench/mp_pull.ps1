# 拉流基准：1 路推流 + N 路拉流，测 play 进程 CPU（对照旧 S4 单进程数据）
param(
    [int]$Pulls = 500,
    [int]$MeasureSeconds = 30,
    [int]$WarmupSeconds = 20,
    [int]$PlayCount = 1          # play 进程数
)

$ErrorActionPull = 'Stop'
$repoRoot = 'F:\work-data\media-server'
$extDir = 'D:\php-8.4.25-nts-Win32-vs17-x64\ext'
$sample = Join-Path $repoRoot 'tests\bench\samples\1080p30_4m.flv'
$rtmpstress = Join-Path $env:TEMP 'rtmpstress\rtmpstress.exe'
$tmp = Join-Path $env:TEMP "pull_bench_$Pulls"
New-Item -ItemType Directory -Force -Path $tmp | Out-Null
Remove-Item "$tmp\*" -Recurse -Force -ErrorAction SilentlyContinue

$env:MP_LOG_DIR = $tmp
$env:MP_MEMORY_LIMIT = '2G'
Remove-Item Env:MP_DEBUG -ErrorAction SilentlyContinue

$launcher = Start-Process php -ArgumentList @(
    '-d',"extension_dir=$extDir",'-d','extension=shmop','-d','extension=sockets','-d','extension=event',
    'start_multiprocess.php','1',"$PlayCount",'0',
    '11935','11936','11980','11981'
) -PassThru -WindowStyle Hidden -RedirectStandardOutput "$tmp\l.out" -RedirectStandardError "$tmp\l.err"

$push = $null; $stressProcs = @()
try {
    # 等端口
    $deadline = (Get-Date).AddSeconds(20)
    while ((Get-Date) -lt $deadline) {
        if ((Test-NetConnection 127.0.0.1 -Port 11935 -InformationLevel Quiet -WarningAction SilentlyContinue) -and
            (Test-NetConnection 127.0.0.1 -Port 11936 -InformationLevel Quiet -WarningAction SilentlyContinue)) { break }
        Start-Sleep -Milliseconds 400
    }

    $push = Start-Process ffmpeg -ArgumentList @(
        '-hide_banner','-loglevel','error','-re','-stream_loop','-1','-i',$sample,
        '-c','copy','-f','flv','rtmp://127.0.0.1:11935/live/pullbench'
    ) -PassThru -WindowStyle Hidden -RedirectStandardError "$tmp\push.err"

    # 等推流就绪
    Start-Sleep -Seconds 5

    # 拉流分摊到各 play 实例（端口 11936 + i*10）
    $perPlay = [math]::Ceiling($Pulls / [math]::Max(1, $PlayCount))
    for ($g = 0; $g -lt $PlayCount; $g++) {
        $n = [math]::Min($perPlay, $Pulls - $g * $perPlay)
        if ($n -le 0) { break }
        $port = 11936 + $g * 10
        $stressProcs += Start-Process $rtmpstress -ArgumentList @('-c',$n,'-i',"rtmp://127.0.0.1:$port/live/pullbench") `
            -PassThru -WindowStyle Hidden -RedirectStandardOutput "$tmp\pull_$g.out" -RedirectStandardError "$tmp\pull_$g.err"
    }

    Write-Host "预热 ${WarmupSeconds}s..."
    Start-Sleep -Seconds $WarmupSeconds

    # 采样 play 进程 + ingest 进程
    function Get-WorkerProcs {
        Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
            Where-Object { $_.CommandLine -match 'worker\.php.{0,10}(ingest|play)' } |
            ForEach-Object {
                $role = if ($_.CommandLine -match 'worker\.php.{0,10}ingest') { 'ingest' } else { 'play' }
                $p = Get-Process -Id $_.ProcessId -ErrorAction SilentlyContinue
                if ($p) { [pscustomobject]@{ Role = $role; Pid = $_.ProcessId; Proc = $p } }
            }
    }

    $procs0 = Get-WorkerProcs
    $cpu0 = @{}
    foreach ($p in $procs0) { $cpu0[$p.Pid] = $p.Proc.TotalProcessorTime.TotalSeconds }
    $t0 = Get-Date

    Start-Sleep -Seconds $MeasureSeconds

    $wall = ((Get-Date) - $t0).TotalSeconds
    foreach ($p in (Get-WorkerProcs)) {
        $c0 = if ($cpu0.ContainsKey($p.Pid)) { $cpu0[$p.Pid] } else { 0 }
        $pct = ($p.Proc.TotalProcessorTime.TotalSeconds - $c0) / $wall * 100
        $ws = [math]::Round($p.Proc.WorkingSet64 / 1MB, 0)
        Write-Host ("  [{0}] CPU={1,6:N1}%  ws={2}MB" -f $p.Role, $pct, $ws) -ForegroundColor Green
    }

    # 连接保持检查
    $playProc = Get-WorkerProcs | Where-Object { $_.Role -eq 'play' } | Select-Object -First 1
    if ($playProc) {
        try {
            $body = @{ name='login'; args=@('admin','admin123') } | ConvertTo-Json -Compress
            $r = Invoke-RestMethod -Uri "http://127.0.0.1:11980/api" -Method Post -ContentType 'application/json' -Body $body
            $cnt = Invoke-RestMethod -Uri "http://127.0.0.1:11980/api" -Method Post -ContentType 'application/json' `
                -Headers @{ 'X-Auth-Token' = $r.token } `
                -Body (@{ name='getPlayStreamCount'; args=@('/live/pullbench') } | ConvertTo-Json -Compress)
            Write-Host ("  拉流连接: {0}/{1}" -f $cnt.count, $Pulls) -ForegroundColor Green
        } catch {
            Write-Host "  API 查询失败: $_" -ForegroundColor Yellow
        }
    }
}
finally {
    foreach ($pl in $stressProcs) { if ($pl -and -not $pl.HasExited) { Stop-Process -Id $pl.Id -Force -ErrorAction SilentlyContinue } }
    if ($push -and -not $push.HasExited) { Stop-Process -Id $push.Id -Force -ErrorAction SilentlyContinue }
    if ($launcher -and -not $launcher.HasExited) { Stop-Process -Id $launcher.Id -Force -ErrorAction SilentlyContinue }
    Start-Sleep 2
    Get-CimInstance Win32_Process -Filter "Name='php.exe' or Name='ffmpeg.exe'" |
        Where-Object { $_.CommandLine -match 'worker\.php|start_multiprocess|pullbench' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}
