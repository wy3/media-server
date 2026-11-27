# 拉流基准：1 路推流 + N 路拉流，按进程角色拆分 CPU（对照旧 S4 单进程数据）。
#
# 架构：完整多进程服务端（每功能一进程，录像关闭）。
# 特点：CPU 按 ingest / play 角色分别统计，能看出推流与拉流的开销分布。
#
# 用法：
#   .\mp_pull.ps1 -Pulls 500 -MeasureSeconds 30

param(
    [int]$Pulls = 500,
    [int]$MeasureSeconds = 30,
    [int]$WarmupSeconds = 20,
    [int]$IngestRtmpPort = 11935,
    [int]$PlayRtmpPort = 11936,
    [int]$PlayHttpPort = 11980
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

$rtmpstress = Resolve-RtmpStress
$sample = Join-Path $script:SamplesDir '1080p30_4m.flv'

$server = $null
$push = $null
$stressProcs = @()
try {
    $server = Start-MpServers `
        -IngestRtmpPort $IngestRtmpPort -PlayRtmpPort $PlayRtmpPort -PlayHttpPort $PlayHttpPort
    $ports = $server.BenchPorts
    $adminPort = $ports.adminHttp
    $token = Get-AdminToken -HttpPort $adminPort

    $push = Start-Process ffmpeg -ArgumentList @(
        '-hide_banner','-loglevel','error','-re','-stream_loop','-1','-i',$sample,
        '-c','copy','-f','flv',"rtmp://127.0.0.1:$($ports.ingestRtmp)/live/pullbench"
    ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $ports.logDir 'push.err')

    # 等推流就绪（API 代理到 control 注册表）
    $deadline = (Get-Date).AddSeconds(60)
    $ready = $false
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 500
        $st = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $adminPort -TimeoutSec 5
        if ($st -and @($st).Count -ge 1) { $ready = $true; break }
    }
    if (-not $ready) { throw "推流未就绪" }

    $stressProcs += Start-Process $rtmpstress -ArgumentList @(
        '-c',$Pulls,'-i',"rtmp://127.0.0.1:$($ports.playRtmp)/live/pullbench"
    ) -PassThru -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $ports.logDir 'pull.out') `
        -RedirectStandardError (Join-Path $ports.logDir 'pull.err')

    Write-Host "预热 ${WarmupSeconds}s..."
    Start-Sleep -Seconds $WarmupSeconds

    # 按角色采样 CPU（ingest / play 分开，能看出推拉各自开销）
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
    try {
        $cnt = Invoke-AdminApiSafe -Token $token -Name 'getPlayStreamCount' -Args @('/live/pullbench') -HttpPort $adminPort -TimeoutSec 8
        $n = if ($cnt) { $cnt.count } else { '?' }
        Write-Host ("  拉流连接: {0}/{1}" -f $n, $Pulls) -ForegroundColor Green
    } catch {
        Write-Host "  API 查询失败: $_" -ForegroundColor Yellow
    }

    Save-BenchResult -Scenario 'mp_pull' -EventLoop 'multiprocess' -Data @{
        pulls = $Pulls
        measureSeconds = $MeasureSeconds
        warmupSeconds = $WarmupSeconds
        ingestRtmpPort = $IngestRtmpPort
        playRtmpPort = $PlayRtmpPort
    } | Out-Null
}
finally {
    foreach ($pl in $stressProcs) { if ($pl -and -not $pl.HasExited) { Stop-Process -Id $pl.Id -Force -ErrorAction SilentlyContinue } }
    if ($push -and -not $push.HasExited) { Stop-Process -Id $push.Id -Force -ErrorAction SilentlyContinue }
    if ($server) { Stop-MpServers -Launcher $server }
}
