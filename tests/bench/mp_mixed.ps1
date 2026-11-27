# 多进程混合推拉加压测试（对照旧单进程 s5_mixed 数据）。
#
# 架构：ingest 进程（只收推流→写shm）+ play 进程（组播→扇出播放端）
# 对照基线：tests/bench/RESULTS.md S5 —— 单进程 500/500 = 98.5% 单核 / 661MB
#
# 测量口径与旧脚本一致：svc CPU = php 服务进程树 TotalProcessorTime 增量 ÷ 墙钟，
# 100% = 用满 1 核（多进程时是所有 php 服务进程之和，可 > 100%）。
#
# 用法：
#   .\mp_mixed.ps1 -Matrix '500/500','1000/1000' -IngestCount 1 -PlayCount 1

param(
    # 每项格式 "推流数/拉流数"
    [string[]]$Matrix = @('500/500', '1000/1000'),

    [int]$SecondsPerCase = 60,
    [int]$WarmupSeconds = 25,
    [int]$PushChunk = 250,          # 每 ffmpeg tee 进程推的流数（命令行长度限制）
    [int]$IngestCount = 1,          # ingest 进程数（推流全部进第一个 ingest 的端口）
    [int]$PlayCount = 1,            # play 进程数（拉流全部进第一个 play 的端口）
    [int]$RtmpPort = 11935,         # ingest RTMP publish
    [int]$PlayRtmpPort = 11936,     # play RTMP playback
    [int]$HttpPort = 11980,         # play HTTP-FLV
    [int]$IngestHttpPort = 11981,   # ingest HTTP-FLV publish（管理 API 走这个）
    [string]$ShmFrameSlots = '0',   # MP_SHM_FRAMESLOTS（0=默认 200）
    [string]$ShmDataKb = '0',       # MP_SHM_DATAKB（0=默认 2048KB）
    [switch]$NoRecord               # 不起录像进程（对齐旧测试：录像关闭）
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

$rtmpstress = Resolve-RtmpStress
$sample = Join-Path $script:SamplesDir '1080p30_4m.flv'
if (-not (Test-Path $sample)) { throw "缺少样本 $sample，先跑 gen_samples.ps1" }
$repoRoot = $script:RepoRoot

# ── 进程树 CPU 采集 ──
# launcher(proc_open) 的子进程不进 Win32_Process 的 ParentProcessId 关联时会有孤儿，
# 所以直接按命令行特征匹配 php 进程（worker.php role=xxx / start_multiprocess.php）。
function Get-MpServerProcs {
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
        Where-Object {
            $_.CommandLine -match 'start_multiprocess\.php' -or
            $_.CommandLine -match 'worker\.php'
        }
}

function Get-TreeCpu {
    # 返回 [pscustomobject]@{ CpuSeconds; WsMB; CommitMB; Count }
    # WsMB 会把 shm 共享页在每个进程重复计入；CommitMB（提交内存）更接近真实占用
    $procs = Get-MpServerProcs
    $cpu = 0.0
    $ws = 0
    $commit = 0
    foreach ($p in $procs) {
        try {
            $proc = Get-Process -Id $p.ProcessId -ErrorAction Stop
            $cpu += $proc.TotalProcessorTime.TotalSeconds
            $ws += $proc.WorkingSet64
            $commit += $proc.PagedMemorySize64
        } catch { }
    }
    [pscustomobject]@{ CpuSeconds = $cpu; WsMB = [math]::Round($ws / 1MB, 1); CommitMB = [math]::Round($commit / 1MB, 1); Count = @($procs).Count }
}

Write-Host "=== 多进程混合推拉测试（control 中心 + 单 GOP shm）===" -ForegroundColor Cyan
Write-Host "ingest=$IngestCount play=$PlayCount record=$(if($NoRecord){0}else{1}) | 用例: $($Matrix -join ', ') | 每例 ${SecondsPerCase}s"
$AdminApiPort = 18099   # admin 进程 API（代理到 control）

$allResults = @()

foreach ($m in $Matrix) {
    if ($m -notmatch '^(\d+)/(\d+)$') { throw "矩阵项格式错误: $m" }
    $np = [int]$matches[1]
    $npull = [int]$matches[2]
    Write-Host ""
    Write-Host "════════ 推流 $np / 拉流 $npull ════════" -ForegroundColor Yellow

    $launcher = $null
    $pushProcs = @()
    $stressProc = $null
    $tmp = Join-Path $env:TEMP "mp_bench_$($np)_$npull"
    New-Item -ItemType Directory -Force -Path $tmp | Out-Null
    $aborted = $false

    try {
        # ── 启动多进程服务端 ──
        $extDir = Resolve-PhpExtensionDir
        $env:MP_LOG_DIR = $tmp
        $env:MP_SHM_FRAMESLOTS = $ShmFrameSlots
        $env:MP_SHM_DATAKB = $ShmDataKb
        $recordCount = if ($NoRecord) { 0 } else { 1 }
        $phpArgs = @()
        if ($extDir) { $phpArgs += @('-d', "extension_dir=$extDir", '-d', 'extension=shmop', '-d', 'extension=sockets', '-d', 'extension=event') }
        $phpArgs += @(
            (Join-Path $repoRoot 'start_multiprocess.php'),
            "$IngestCount", "$PlayCount", "$recordCount",
            "$RtmpPort", "$PlayRtmpPort", "$HttpPort", "$IngestHttpPort"
        )
        $launcher = Start-Process -FilePath 'php' -ArgumentList $phpArgs -PassThru -WindowStyle Hidden `
          -RedirectStandardOutput (Join-Path $tmp 'launcher.out') `
          -RedirectStandardError (Join-Path $tmp 'launcher.err')

        # 等 ingest + play + admin 端口就绪
        $deadline = (Get-Date).AddSeconds(20)
        $ready = $false
        while ((Get-Date) -lt $deadline) {
            if ($launcher.HasExited) { throw "launcher 退出: $(Get-Content (Join-Path $tmp 'launcher.err') -Raw)" }
            $ok1 = Test-NetConnection -ComputerName 127.0.0.1 -Port $RtmpPort -InformationLevel Quiet -WarningAction SilentlyContinue
            $ok2 = Test-NetConnection -ComputerName 127.0.0.1 -Port $PlayRtmpPort -InformationLevel Quiet -WarningAction SilentlyContinue
            $ok3 = Test-NetConnection -ComputerName 127.0.0.1 -Port $AdminApiPort -InformationLevel Quiet -WarningAction SilentlyContinue
            if ($ok1 -and $ok2 -and $ok3) { $ready = $true; break }
            Start-Sleep -Milliseconds 400
        }
        if (-not $ready) { throw "服务端口 20s 内未就绪" }
        $t0Proc = Get-TreeCpu
        Write-Host ("  服务端就绪: {0} 个 php 进程, 基线 CPU={1}s ws={2}MB" -f $t0Proc.Count, $t0Proc.CpuSeconds, $t0Proc.WsMB) -ForegroundColor Green

        # ── 推流：np 路，每 PushChunk 路一个 ffmpeg tee 进程 ──
        $idx = 0
        while ($idx -lt $np) {
            $count = [math]::Min($PushChunk, $np - $idx)
            $slaves = (1..$count | ForEach-Object {
                "[f=flv]rtmp://127.0.0.1:$RtmpPort/live/m$($idx + $_)"
            }) -join '|'
            $pushProcs += Start-Process -FilePath 'ffmpeg' -ArgumentList @(
                '-hide_banner', '-loglevel', 'error'
                '-re', '-stream_loop', '-1', '-i', $sample
                '-c', 'copy', '-f', 'tee', '-map', '0', $slaves
            ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $tmp "push_$idx.err")
            $idx += $count
        }

        # 等推流就绪（管理 API 走 admin 进程，代理到 control 注册表）
        $token = Get-AdminToken -HttpPort $AdminApiPort
        $deadline = (Get-Date).AddSeconds(90)
        $pushOk = $false
        while ((Get-Date) -lt $deadline) {
            Start-Sleep -Milliseconds 800
            $st = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $AdminApiPort -TimeoutSec 8
            if ($st -and @($st).Count -ge $np) { $pushOk = $true; break }
        }
        if (-not $pushOk) {
            $got = 0
            try { $got = @(Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $AdminApiPort -TimeoutSec 8).Count } catch { }
            throw "推流未达目标（$got / $np）"
        }

        # ── 拉流：npull 路，拉 play 端口的 probe 流 ──
        # 多进程下推流全在 ingest，play 进程能看到所有流（shm 段共享），拉 m1 即可
        if ($npull -gt 0) {
            $stressProc = Start-Process -FilePath $rtmpstress -ArgumentList @(
                '-c', $npull, '-i', "rtmp://127.0.0.1:$PlayRtmpPort/live/m1"
            ) -PassThru -WindowStyle Hidden `
              -RedirectStandardOutput (Join-Path $tmp 'pull.out') `
              -RedirectStandardError (Join-Path $tmp 'pull.err')
        }

        Write-Host "  预热 ${WarmupSeconds}s..." -NoNewline
        Start-Sleep -Seconds $WarmupSeconds
        Write-Host " 完成"

        # ── 采样：全树 CPU 总量 / 拉流连接数 ──
        # admin API 的 getPlayStreamCount 是 play 进程 5s 上报的聚合值（有延迟），
        # 改从 rtmpstress 输出解析实时 Clients 数
        $tokenPlay = Get-AdminToken -HttpPort $AdminApiPort
        $cpu0 = (Get-TreeCpu).CpuSeconds
        $wall0 = Get-Date
        $samples = @()

        while (((Get-Date) - $wall0).TotalSeconds -lt $SecondsPerCase) {
            Start-Sleep -Seconds 10
            $el = ((Get-Date) - $wall0).TotalSeconds
            $cur = Get-TreeCpu
            # 从 rtmpstress stdout 解析最新 Clients
            $pullCount = -1
            $pullOut = Join-Path $tmp 'pull.out'
            if (Test-Path $pullOut) {
                $m = Select-String -Path $pullOut -Pattern 'Clients:\s+(\d+)' | Select-Object -Last 1
                if ($m) { $pullCount = [int]$m.Matches[0].Groups[1].Value }
            }

            $samples += [pscustomobject]@{
                t = [math]::Round($el, 0)
                svcCpu = [math]::Round(($cur.CpuSeconds - $cpu0) / $el * 100, 2)
                sysCpu = Get-SystemCpuPct
                wsMB = $cur.WsMB
                commitMB = $cur.CommitMB
                procs = $cur.Count
                pullCount = $pullCount
            }
            Write-Host ("  [{0,3}s] svc={1,6:N1}% sys={2,4:N0}% ws={3,6:N0}MB commit={4,6:N0}MB procs={5} 拉={6}/{7}" -f `
                $samples[-1].t, $samples[-1].svcCpu, $samples[-1].sysCpu, $samples[-1].wsMB, `
                $cur.CommitMB, $cur.Count, $samples[-1].pullCount, $npull)

            if ($samples[-1].pullCount -lt 0) {
                # stdout 缓冲未刷新（高码率下 rtmpstress 输出滞后），跳过本采样点
                $samples[-1].pullCount = -1
            }
        }

        $valid = @($samples | Where-Object { $_.pullCount -ge 0 })
        # 推流保持：admin API（代理到 control 注册表）
        $pushFinal = 0; $lowFps = 0
        try {
            $lst = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $AdminApiPort -TimeoutSec 8
            if ($lst) { $pushFinal = @($lst).Count }
        } catch { }

        $result = [pscustomobject]@{
            Push = $np
            Pull = $npull
            Aborted = $aborted
            SvcCpuAvg = if ($valid.Count) { [math]::Round(($valid | Measure-Object svcCpu -Average).Average, 1) } else { 0 }
            SvcCpuMax = if ($valid.Count) { ($valid | Measure-Object svcCpu -Maximum).Maximum } else { 0 }
            SysCpuAvg = if ($valid.Count) { [math]::Round(($valid | Measure-Object sysCpu -Average).Average, 1) } else { 0 }
            WsMBEnd = if ($valid.Count) { $valid[-1].wsMB } else { 0 }
            CommitMBEnd = if ($valid.Count) { $valid[-1].commitMB } else { 0 }
            Procs = if ($valid.Count) { $valid[-1].procs } else { 0 }
            PushFinal = $pushFinal
            LowFps = $lowFps
            PullFinal = if ($valid.Count) { $valid[-1].pullCount } else { 0 }
        }
        $allResults += $result

        Write-Host ("  → svc={0:N1}% sys={1:N0}% ws={2:N0}MB 推={3}/{4} 低帧={5} 拉={6}/{7}" -f `
            $result.SvcCpuAvg, $result.SysCpuAvg, $result.WsMBEnd, `
            $result.PushFinal, $np, $result.LowFps, $result.PullFinal, $npull) -ForegroundColor Green
    }
    catch {
        Write-Host "  用例 $m 异常: $_" -ForegroundColor Red
        $allResults += [pscustomobject]@{
            Push = $np; Pull = $npull; Aborted = $true
            SvcCpuAvg = 0; SvcCpuMax = 0; SysCpuAvg = 0; WsMBEnd = 0; CommitMBEnd = 0; Procs = 0
            PushFinal = 0; LowFps = 0; PullFinal = 0
        }
    }
    finally {
        foreach ($p in (@($stressProc) + $pushProcs)) {
            if ($p -and -not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
        }
        if ($launcher -and -not $launcher.HasExited) { Stop-Process -Id $launcher.Id -Force -ErrorAction SilentlyContinue }
        Start-Sleep -Seconds 2
        # 残余 php/ffmpeg 兜底清理
        Get-CimInstance Win32_Process -Filter "Name='php.exe' or Name='ffmpeg.exe'" |
            Where-Object { $_.CommandLine -match 'start_multiprocess\.php|worker\.php|live/m' } |
            ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Start-Sleep -Seconds 3
    }
}

Write-Host ""
Write-Host "=== 多进程混合汇总（对照单进程 s5：500/500=98.5% 单核 661MB）===" -ForegroundColor Cyan
Write-Host ("{0,8} {1,8} {2,12} {3,8} {4,10} {5,10} {6,10} {7,8} {8,8}" -f `
    '推流', '拉流', 'svc CPU%(树)', 'sys%', '内存WS', '内存Commit', '推流保持', '低帧', '拉流保持')
Write-Host ('-' * 92)
foreach ($r in $allResults) {
    $flag = if ($r.Aborted) { ' [卡死]' } else { '' }
    Write-Host ("{0,8} {1,8} {2,12} {3,8} {4,10} {5,10} {6,10} {7,8} {8,8}{9}" -f `
        $r.Push, $r.Pull, $r.SvcCpuAvg, $r.SysCpuAvg, $r.WsMBEnd, $r.CommitMBEnd, `
        ("{0}/{1}" -f $r.PushFinal, $r.Push), $r.LowFps, $r.PullFinal, $flag)
}

Save-BenchResult -Scenario 'mp_mixed' -EventLoop 'event' -Data @{
    matrix = $Matrix
    ingestCount = $IngestCount
    playCount = $PlayCount
    secondsPerCase = $SecondsPerCase
    warmupSeconds = $WarmupSeconds
    shmFrameSlots = $ShmFrameSlots
    shmDataKb = $ShmDataKb
    results = $allResults
} | Out-Null
