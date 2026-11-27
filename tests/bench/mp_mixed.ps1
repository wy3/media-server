# 多进程混合推拉加压测试（对照旧 S5 单进程数据）。
#
# 架构：完整多进程服务端（每功能一进程，录像关闭）。
# CPU 口径：全部 php 服务进程之和（可 > 100%）。
#
# 用法：
#   .\mp_mixed.ps1 -Matrix '50/500','100/500','500/500'

param(
    # 每项格式 "推流数/拉流数"
    [string[]]$Matrix = @('500/500', '1000/1000'),

    [int]$SecondsPerCase = 60,
    [int]$WarmupSeconds = 25,
    [int]$PushChunk = 250,          # 每 ffmpeg tee 进程推的流数（命令行长度限制）
    [int]$IngestRtmpPort = 11935,
    [int]$PlayRtmpPort = 11936,
    [string]$ShmFrameSlots = '0',   # MP_SHM_FRAMESLOTS（0=默认 200）
    [string]$ShmDataKb = '0'        # MP_SHM_DATAKB（0=默认 2048KB）
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

$rtmpstress = Resolve-RtmpStress
$sample = Join-Path $script:SamplesDir '1080p30_4m.flv'
if (-not (Test-Path $sample)) { throw "缺少样本 $sample，先跑 gen_samples.ps1" }

$env:MP_SHM_FRAMESLOTS = $ShmFrameSlots
$env:MP_SHM_DATAKB = $ShmDataKb

$allResults = @()
$server = $null

foreach ($m in $Matrix) {
    if ($m -notmatch '^(\d+)/(\d+)$') { throw "矩阵项格式错误: $m" }
    $np = [int]$matches[1]
    $npull = [int]$matches[2]
    Write-Host ""
    Write-Host "════════ 推流 $np / 拉流 $npull ════════" -ForegroundColor Yellow

    $pushProcs = @()
    $stressProc = $null
    $aborted = $false

    try {
        $server = Start-MpServers -IngestRtmpPort $IngestRtmpPort -PlayRtmpPort $PlayRtmpPort
        $ports = $server.BenchPorts
        $adminPort = $ports.adminHttp
        $token = Get-AdminToken -HttpPort $adminPort

        # ── 推流：np 路，每 PushChunk 路一个 ffmpeg tee 进程 ──
        $idx = 0
        while ($idx -lt $np) {
            $count = [math]::Min($PushChunk, $np - $idx)
            $slaves = (1..$count | ForEach-Object {
                "[f=flv]rtmp://127.0.0.1:$($ports.ingestRtmp)/live/m$($idx + $_)"
            }) -join '|'
            $pushProcs += Start-Process -FilePath 'ffmpeg' -ArgumentList @(
                '-hide_banner', '-loglevel', 'error'
                '-re', '-stream_loop', '-1', '-i', $sample
                '-c', 'copy', '-f', 'tee', '-map', '0', $slaves
            ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $ports.logDir "push_$idx.err")
            $idx += $count
        }

        # 等推流就绪
        $deadline = (Get-Date).AddSeconds(90)
        $pushOk = $false
        while ((Get-Date) -lt $deadline) {
            Start-Sleep -Milliseconds 800
            $st = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $adminPort -TimeoutSec 8
            if ($st -and @($st).Count -ge $np) { $pushOk = $true; break }
        }
        if (-not $pushOk) {
            $got = 0
            try { $got = @(Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $adminPort -TimeoutSec 8).Count } catch { }
            throw "推流未达目标（$got / $np）"
        }

        # ── 拉流：拉 play 进程的 RTMP 播放端口 ──
        if ($npull -gt 0) {
            $stressProc = Start-Process -FilePath $rtmpstress -ArgumentList @(
                '-c', $npull, '-i', "rtmp://127.0.0.1:$($ports.playRtmp)/live/m1"
            ) -PassThru -WindowStyle Hidden `
              -RedirectStandardOutput (Join-Path $ports.logDir 'pull.out') `
              -RedirectStandardError (Join-Path $ports.logDir 'pull.err')
        }

        Write-Host "  预热 ${WarmupSeconds}s..." -NoNewline
        Start-Sleep -Seconds $WarmupSeconds
        Write-Host " 完成"

        $base = Get-MpTreeUsage
        $t0 = Get-Date
        $samples = @()
        $apiFail = 0

        while (((Get-Date) - $t0).TotalSeconds -lt $SecondsPerCase) {
            Start-Sleep -Seconds 10
            $el = ((Get-Date) - $t0).TotalSeconds

            $st = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $adminPort -TimeoutSec 8
            $pushes = if ($st) { @($st).Count } else { -1 }
            $cnt = Invoke-AdminApiSafe -Token $token -Name 'getPlayStreamCount' -Args @('/live/m1') -HttpPort $adminPort -TimeoutSec 8
            $pulls = if ($cnt) { $cnt.count } else { -1 }

            if ($pushes -lt 0 -or $pulls -lt 0) {
                $apiFail++
                if ($apiFail -ge 2) {
                    Write-Host "  API 连续无响应 → 服务端卡死，本例终止" -ForegroundColor Red
                    $aborted = $true
                    break
                }
            } else { $apiFail = 0 }

            $now = Get-MpTreeUsage
            $samples += [pscustomobject]@{
                t = [math]::Round($el, 0)
                svcCpu = [math]::Round(($now.CpuSeconds - $base.CpuSeconds) / $el * 100, 2)
                sysCpu = Get-SystemCpuPct
                wsMB = $now.WsMB
                commitMB = $now.CommitMB
                procs = $now.Count
                pushes = $pushes
                pulls = $pulls
            }
            Write-Host ("  [{0,3}s] svc={1,6:N1}% sys={2,5:N0}% ws={3,6:N0}MB procs={4} push={5}/{6} pull={7}/{8}" -f `
                $samples[-1].t, $samples[-1].svcCpu, $samples[-1].sysCpu, $samples[-1].wsMB, $samples[-1].procs, $pushes, $np, $pulls, $npull)
        }

        $valid = @($samples | Where-Object { $_.pushes -ge 0 -and $_.pulls -ge 0 })
        $result = [pscustomobject]@{
            Matrix = $m
            Aborted = $aborted
            PushFinal = if ($valid.Count) { $valid[-1].pushes } else { -1 }
            PullFinal = if ($valid.Count) { $valid[-1].pulls } else { -1 }
            PushRetentionPct = if ($valid.Count) { [math]::Round($valid[-1].pushes / $np * 100, 1) } else { 0 }
            PullRetentionPct = if ($valid.Count -and $npull -gt 0) { [math]::Round($valid[-1].pulls / $npull * 100, 1) } else { 0 }
            SvcCpuAvg = if ($valid.Count) { [math]::Round(($valid | Measure-Object svcCpu -Average).Average, 2) } else { 0 }
            SysCpuAvg = if ($valid.Count) { [math]::Round(($valid | Measure-Object sysCpu -Average).Average, 1) } else { 0 }
            WsMBEnd = if ($valid.Count) { $valid[-1].wsMB } else { 0 }
            CommitMBEnd = if ($valid.Count) { $valid[-1].commitMB } else { 0 }
            Procs = if ($valid.Count) { $valid[-1].procs } else { 0 }
        }
        $allResults += $result

        Write-Host ("  → push {0}/{1} ({2}%)  pull {3}/{4} ({5}%)  svc={6:N1}%  ws={7:N0}MB  procs={8}" -f `
            $result.PushFinal, $np, $result.PushRetentionPct, $result.PullFinal, $npull, $result.PullRetentionPct,
            $result.SvcCpuAvg, $result.WsMBEnd, $result.Procs) -ForegroundColor Green

        if ($aborted -or $result.PushFinal -le 0) {
            Write-Host "  该例已失效，停止后续矩阵" -ForegroundColor Yellow
            break
        }
    }
    catch {
        Write-Host "  用例 $m 异常: $_" -ForegroundColor Red
        $allResults += [pscustomobject]@{
            Matrix = $m; Aborted = $true; PushFinal = -1; PullFinal = -1
            PushRetentionPct = 0; PullRetentionPct = 0; SvcCpuAvg = 0; SysCpuAvg = 0
            WsMBEnd = 0; CommitMBEnd = 0; Procs = 0
        }
        break
    }
    finally {
        foreach ($p in (@($stressProc) + @($pushProcs))) {
            if ($p -and -not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
        }
        if ($server) { Stop-MpServers -Launcher $server }
        $server = $null
        Start-Sleep -Seconds 3
    }
}

Write-Host ""
Write-Host "=== 混合矩阵汇总 ===" -ForegroundColor Cyan
Write-Host ("{0,12} {1,10} {2,10} {3,10} {4,8} {5,10} {6,8}" -f `
    '推/拉', 'push保持%', 'pull保持%', 'svc CPU%', 'sys%', '内存MB', '进程数')
Write-Host ('-' * 76)
foreach ($r in $allResults) {
    $flag = if ($r.Aborted) { ' [卡死]' } else { '' }
    Write-Host ("{0,12} {1,10} {2,10} {3,10} {4,8} {5,10} {6,8}{7}" -f `
        $r.Matrix, $r.PushRetentionPct, $r.PullRetentionPct, $r.SvcCpuAvg, $r.SysCpuAvg, $r.WsMBEnd, $r.Procs, $flag)
}

Save-BenchResult -Scenario 'mp_mixed' -EventLoop 'multiprocess' -Data @{
    matrix = $Matrix
    secondsPerCase = $SecondsPerCase
    warmupSeconds = $WarmupSeconds
    results = $allResults
} | Out-Null
