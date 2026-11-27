# 自动爬坡加压：每 RampIntervalSec 追加 RampBatch 路，svc CPU 到达 CpuTarget
# 后停止追加，稳态考核 SecondsPerStep 秒——保持率 ≥95% 的最大路数 = 有效容量。
#
# 与固定档位的区别：不预设档位表，直接以"CPU 饱和 + 40s 保活"为准绳，
# 一次测出容量；推流/拉流同构（push 用 ffmpeg tee，pull 用 rtmpstress）。
#
# 架构：完整多进程服务端（control / ingest / play / admin 各一进程，
# 录像默认关闭），推流 → ingest，拉流 → play，管理 API → admin，全程不重启。
#
# 用法：
#   .\s4_ramp.ps1 -Scenario push
#   .\s4_ramp.ps1 -Scenario pull
#   .\s4_ramp.ps1 -Scenario push -CpuTarget 100 -RampBatch 100 -RampIntervalSec 5
#
# CPU 口径：全部 php 服务进程之和（可 > 100%，100% = 合计用满 1 核）。

param(
    [Parameter(Mandatory)]
    [ValidateSet('pull', 'push')]
    [string]$Scenario,

    [int]$SecondsPerStep = 40,   # CPU 饱和后的稳态考核时长
    [int]$IngestRtmpPort = 11935,
    [int]$PlayRtmpPort = 11936,
    [int]$IngestCount = 1,
    [int]$PlayCount = 1,
    [switch]$EnableJit,
    [int]$PushChunk = 100,       # 每 ffmpeg tee 进程推的流数（Windows 命令行长度限制）
    [int]$RampIntervalSec = 5,   # 每 N 秒追加一批
    [int]$RampBatch = 100,       # 每批追加路数
    [double]$CpuTarget = 80.0,   # 单进程平均 CPU 饱和阈值（%，100%=单进程用满 1 核）
    [int]$MaxStreams = 20000,   # 安全上限（防失控）
    [double]$MinRetention = 95.0 # 容量判定：稳态保持率下限
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

# 饱和判定口径：拉流看 play 进程合计，推流看 ingest 进程合计；
# 用「合计 CPU / 进程数」= 平均单进程 CPU 与 CpuTarget 比较，
# 这样 N 个进程时判定阈值仍是 CpuTarget%（而不是 N×100% 这种不现实的门槛）。
$targetRole = if ($Scenario -eq 'pull') { 'play' } else { 'ingest' }
$targetCount = if ($Scenario -eq 'pull') { $PlayCount } else { $IngestCount }

$rtmpstress = if ($Scenario -eq 'pull') { Resolve-RtmpStress } else { $null }
$sample = Join-Path $script:SamplesDir '1080p30_4m.flv'
if (-not (Test-Path $sample)) { throw "缺少样本 $sample，先跑 gen_samples.ps1" }

Write-Host "=== 自动爬坡加压：$Scenario / 多进程 ===" -ForegroundColor Cyan
Write-Host "每 ${RampIntervalSec}s 给未饱和的 ${targetRole} 进程各追加 ${RampBatch} 路 → 单进程 CPU ≥ ${CpuTarget}% 即停止给它加压 → 全部饱和后稳态考核 ${SecondsPerStep}s"
Write-Host "容量判定：稳态保持率 ≥ ${MinRetention}%"

$server = $null
$pushProcs = @()
$pullProcs = @()
$allSamples = @()
$aborted = $false

try {
    $server = Start-MpServers -IngestRtmpPort $IngestRtmpPort -PlayRtmpPort $PlayRtmpPort `
        -IngestCount $IngestCount -PlayCount $PlayCount -EnableJit:$EnableJit
    $ports = $server.BenchPorts
    $playPorts = $ports.playRtmpPorts
    Write-Host ("服务端就绪: ingest×{0} play×{1}（play 端口: {2}）" -f $IngestCount, $PlayCount, ($playPorts -join ',')) -ForegroundColor Green
    $adminPort = $ports.adminHttp
    $token = Get-AdminToken -HttpPort $adminPort
    Write-Host "服务端就绪（launcher pid=$($server.Id)）" -ForegroundColor Green

    # pull 场景先建 1 路推流，全程保留
    if ($Scenario -eq 'pull') {
        $pushProcs = @(Start-Process -FilePath 'ffmpeg' -ArgumentList @(
            '-hide_banner', '-loglevel', 'error'
            '-re', '-stream_loop', '-1'
            '-i', $sample
            '-c', 'copy', '-f', 'flv', "rtmp://127.0.0.1:$($ports.ingestRtmp)/live/bench"
        ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $ports.logDir 'ramp_push_base.err'))
        $deadline = (Get-Date).AddSeconds(60)
        $ready = $false
        while ((Get-Date) -lt $deadline) {
            Start-Sleep -Milliseconds 500
            $st = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $adminPort -TimeoutSec 5
            if ($st -and @($st).Count -ge 1) { $ready = $true; break }
        }
        if (-not $ready) { throw "基础推流未就绪" }
        Write-Host "基础推流 /live/bench 就绪" -ForegroundColor Green
    }

    # ── 爬坡阶段：只给未饱和的进程加流，直到全部进程饱和 ──
    $cum = 0            # 已追加的目标路数
    $cpuAtSat = $null   # 全部饱和时各进程 CPU
    $rampStart = Get-Date
    # 每个 play 端口（=进程）的饱和状态：CPU 连续 ≥ 阈值 30s 才锁定；
    # 中途回落到阈值以下则解锁、重新计时，可以继续给它加压。
    $saturated = @()      # 是否锁定
    $satSince = @()       # 开始饱和的时刻（DateTime），未饱和为 $null
    for ($i = 0; $i -lt $targetCount; $i++) { $saturated += $false; $satSince += $null }
    $satHoldSec = 30.0    # 饱和确认时长
    $achievedPrev = 0     # 当前实际达成的路数（峰值口径）
    $batchSeq = 0      # 已投递的 stress 进程数（Round-Robin 用）

    while ($cum -lt $MaxStreams) {
        # 追加一批：饱和中或已锁定的进程都暂停加流；只有 CPU 回落到阈值下的才继续加。
        # （satSince != $null 即处于饱和观察期或已锁定，不再给它投递新负载）
        $activePorts = @()
        for ($i = 0; $i -lt $playPorts.Count; $i++) {
            if ($null -eq $satSince[$i] -and -not $saturated[$i]) { $activePorts += $playPorts[$i] }
        }
        if ($activePorts.Count -eq 0) {
            Write-Host "  全部进程处于饱和观察/锁定状态，本轮暂停加流，进入 30s 饱和确认" -ForegroundColor Cyan
            # 等一个采样周期让饱和计时推进，不追加负载
            Start-Sleep -Seconds ([math]::Min(10, [math]::Max(2, [int]$satHoldSec / 3)))
            # 重新采样各进程 CPU，推进 satSince / saturated
            $baselineWait = @{}
            foreach ($p in (Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
                    Where-Object { $_.CommandLine -match "worker\.php.{0,10}$targetRole" })) {
                $proc = Get-Process -Id $p.ProcessId -ErrorAction SilentlyContinue
                if ($proc) { $baselineWait[$p.ProcessId] = $proc.TotalProcessorTime.TotalSeconds }
            }
            Start-Sleep -Seconds 3
            $waitSec = 3
            $perProcWait = @(Get-RoleProcCpu -Role $targetRole -Baseline $baselineWait -WindowSec $waitSec | Sort-Object Pid)
            $nowWait = Get-Date
            $satCountWait = 0
            for ($i = 0; $i -lt [math]::Min($perProcWait.Count, $saturated.Count); $i++) {
                $pct = $perProcWait[$i].Pct
                if ($pct -ge $CpuTarget) {
                    if ($null -eq $satSince[$i]) { $satSince[$i] = $nowWait }
                    $held = ($nowWait - $satSince[$i]).TotalSeconds
                    if ($held -ge $satHoldSec) { $saturated[$i] = $true }
                    $satCountWait++
                } else {
                    $saturated[$i] = $false
                    $satSince[$i] = $null
                }
            }
            Write-Host ("  饱和确认: {0}/{1} 进程 ≥ {2}% [{3}]" -f `
                $satCountWait, $targetCount, $CpuTarget, (($perProcWait | ForEach-Object { "$($_.Pid)=$($_.Pct)%" }) -join ',')) -ForegroundColor DarkCyan
            if ($satCountWait -ge $targetCount -and $targetCount -gt 0) {
                Write-Host "  所有进程连续饱和 ≥ ${satHoldSec}s，停止爬坡，进入稳态考核" -ForegroundColor Cyan
                $cum = $achievedPrev
                break
            }
            continue
        }

        if ($Scenario -eq 'push') {
            $batchEnd = $cum + $RampBatch
            $chunkIdx = $cum
            while ($chunkIdx -lt $batchEnd) {
                $count = [math]::Min($PushChunk, $batchEnd - $chunkIdx)
                $chunkStart = $chunkIdx + 1
                $chunkEnd = $chunkIdx + $count
                $slaves = ($chunkStart..$chunkEnd | ForEach-Object {
                    "[f=flv]rtmp://127.0.0.1:$($ports.ingestRtmp)/live/p$_"
                }) -join '|'
                $pushProcs += Start-Process -FilePath 'ffmpeg' -ArgumentList @(
                    '-hide_banner', '-loglevel', 'error'
                    '-re', '-stream_loop', '-1'
                    '-i', $sample
                    '-c', 'copy', '-f', 'tee', '-map', '0', $slaves
                ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $ports.logDir "ramp_push_$chunkStart.err")
                $chunkIdx += $count
            }
        } else {
            # 给每个未饱和的 play 端口各投一个 stress 进程
            foreach ($stressPort in $activePorts) {
                $pullProcs += Start-Process -FilePath $rtmpstress -ArgumentList @(
                    '-c', $RampBatch, '-i', "rtmp://127.0.0.1:$stressPort/live/bench"
                ) -PassThru -WindowStyle Hidden `
                  -RedirectStandardOutput (Join-Path $ports.logDir "ramp_pull_${stressPort}_$cum.out") `
                  -RedirectStandardError (Join-Path $ports.logDir "ramp_pull_${stressPort}_$cum.err")
                Write-Host ("  ramp 拉流 → play 端口 {0}" -f $stressPort) -ForegroundColor DarkGray
            }
            $cum += $RampBatch * $activePorts.Count
        }
        if ($Scenario -eq 'push') { $cum += $RampBatch }

        # 等本批注册/连接建立：读数 3 次稳定且达标才继续（rtmpstress/librtmp 建连慢，
        # 固定 90s 超时会误判；读数还在涨说明在建，继续等）
        #
        # 注意：getPlayStreamCount 走 5s 统计上报链，超载时读数可能短暂回 0，
        # 绝不能把 cum 重置成瞬时读数（否则"0/1200"会把已建的连接全抹掉，
        # 稳态考核 active/cum 也跟着全错）。用历史最大读数判断达成。
        $stable = 0
        $lastHave = -1
        $maxHave = 0
        $deadline = (Get-Date).AddSeconds(180)
        $have = 0
        while ((Get-Date) -lt $deadline) {
            Start-Sleep -Seconds 3
            if ($Scenario -eq 'push') {
                $st = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $adminPort -TimeoutSec 8
                $have = if ($st) { @($st).Count } else { 0 }
            } else {
                $cnt = Invoke-AdminApiSafe -Token $token -Name 'getPlayStreamCount' -Args @('/live/bench') -HttpPort $adminPort -TimeoutSec 8
                $have = if ($cnt) { $cnt.count } else { 0 }
            }
            if ($have -gt $maxHave) { $maxHave = $have }
            if ($maxHave -ge $cum) { $have = $maxHave; break }
            # 读数不变且 API 正常 → 建连停滞；连续 5 次（15s）算稳定
            if ($have -eq $lastHave -and $have -ge 0) { $stable++ } else { $stable = 0 }
            $lastHave = $have
            if ($stable -ge 5 -and $maxHave -lt $cum) {
                Write-Host ("  建连停滞在 {0}/{1}（历史峰值 {2}），本批未达标" -f $have, $cum, $maxHave) -ForegroundColor Yellow
                $have = $maxHave
                break
            }
        }
        $elapsed = [math]::Round(((Get-Date) - $rampStart).TotalSeconds, 0)

        # 用历史峰值作为实际达成，绝不回退 cum（避免统计基准被瞬时 0 抹掉）
        $achieved = $maxHave
        $achievedPrev = $achieved
        if ($achieved -lt $cum) {
            Write-Host ("  爬坡停止：负载建不满（峰值 {0}/{1}）@ {2}s" -f $achieved, $cum, $elapsed) -ForegroundColor Yellow
            $cum = $achieved
            $rampStalled = $true
        } else {
            $rampStalled = $false
        }

        # 逐进程采样：超过 CpuTarget 的 play 进程标记为饱和（不再给它们加压）
        $baseline = @{}
        foreach ($p in (Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
                Where-Object { $_.CommandLine -match "worker\.php.{0,10}$targetRole" })) {
            $proc = Get-Process -Id $p.ProcessId -ErrorAction SilentlyContinue
            if ($proc) { $baseline[$p.ProcessId] = $proc.TotalProcessorTime.TotalSeconds }
        }
        Start-Sleep -Seconds 3
        $perProc = Get-RoleProcCpu -Role $targetRole -Baseline $baseline -WindowSec 3.0
        # 按 PID 排序后与端口列表顺序对齐（launcher 按端口递增顺序 spawn）
        $perProc = @($perProc | Sort-Object Pid)
        $detail = @()
        $satCount = 0
        $nowTs = Get-Date
        for ($i = 0; $i -lt [math]::Min($perProc.Count, $saturated.Count); $i++) {
            $pct = $perProc[$i].Pct
            $detail += ("{0}={1}%" -f $playPorts[$i], $pct)
            if ($pct -ge $CpuTarget) {
                # CPU 达标：开始/延续饱和计时
                if ($null -eq $satSince[$i]) { $satSince[$i] = $nowTs }
                $held = ($nowTs - $satSince[$i]).TotalSeconds
                if (-not $saturated[$i] -and $held -ge $satHoldSec) {
                    $saturated[$i] = $true
                    Write-Host ("  进程 {0} ({1}) CPU={2}% 连续饱和 {3:N0}s → 锁定，停止加压" -f `
                        $playPorts[$i], $perProc[$i].Pid, $pct, $held) -ForegroundColor Yellow
                } elseif (-not $saturated[$i]) {
                    Write-Host ("  进程 {0} CPU={1}% 饱和中 {2:N0}/{3}s" -f `
                        $playPorts[$i], $pct, $held, $satHoldSec) -ForegroundColor DarkYellow
                }
            } else {
                # 回落到阈值以下：无论是否已锁定，都解除饱和、重置计时，可继续加压
                if ($saturated[$i] -or $null -ne $satSince[$i]) {
                    Write-Host ("  进程 {0} CPU={1}% 回落 < {2}% → 解除饱和，可继续加压" -f `
                        $playPorts[$i], $pct, $CpuTarget) -ForegroundColor Gray
                }
                $saturated[$i] = $false
                $satSince[$i] = $null
            }
            if ($saturated[$i]) { $satCount++ }
        }
        $totalPct = [math]::Round(($perProc | Measure-Object Pct -Sum).Sum, 1)
        $svc = if ($perProc.Count) { [math]::Round($totalPct / $perProc.Count, 1) } else { 0 }
        $cpuAtSat = $detail -join ' '

        Write-Host ("  ramp: {0} 路 @{1}s  {2}单进程[{3}] 饱和 {4}/{5}" -f `
            $cum, $elapsed, $targetRole, ($detail -join ','), $satCount, $targetCount) -ForegroundColor Gray

        if ($satCount -ge $targetCount -and $targetCount -gt 0) {
            Write-Host ("  所有 {0} 进程均饱和，停止追加，进入稳态考核" -f $targetCount) -ForegroundColor Cyan
            break
        }
        if ($rampStalled) {
            Write-Host "  本批建连停滞，停止追加，按当前达成数进入稳态考核" -ForegroundColor Yellow
            break
        }
    }

    if ($cum -ge $MaxStreams) {
        Write-Host "  达到安全上限 ${MaxStreams} 路，${targetRole}平均 svc 未达 ${CpuTarget}%" -ForegroundColor Yellow
    }

    Write-Host "  预热..." -NoNewline
    Start-Sleep -Seconds 10
    Write-Host " 完成"

    # ── 稳态考核：SecondsPerStep 秒内保持率 ≥ MinRetention ──
    $base = Get-MpTreeUsage -Roles @($targetRole)
    $baseAll = Get-MpTreeUsage
    $t0 = Get-Date
    $samples = @()
    $apiFail = 0
    $steadyMax = 0

    while (((Get-Date) - $t0).TotalSeconds -lt $SecondsPerStep) {
        Start-Sleep -Seconds 10
        $el = ((Get-Date) - $t0).TotalSeconds

        if ($Scenario -eq 'push') {
            $lst = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $adminPort -TimeoutSec 8
            $rawActive = if ($lst) { @($lst).Count } else { -1 }
        } else {
            $cnt = Invoke-AdminApiSafe -Token $token -Name 'getPlayStreamCount' -Args @('/live/bench') -HttpPort $adminPort -TimeoutSec 8
            $rawActive = if ($cnt) { $cnt.count } else { -1 }
        }

        if ($rawActive -lt 0) {
            $apiFail++
            if ($apiFail -ge 2) {
                Write-Host "  API 连续无响应 → 服务端卡死" -ForegroundColor Red
                $aborted = $true
                break
            }
            $active = $steadyMax   # API 无响应时沿用上次读数，不误判为 0
        } else {
            $apiFail = 0
            # 统计上报有 5s 延迟，瞬时 0 不代表连接掉了；用历史峰值兜底
            if ($rawActive -gt 0) { $steadyMax = $rawActive }
            $active = $rawActive
        }

        $now = Get-MpTreeUsage -Roles @($targetRole)
        $nowAll = Get-MpTreeUsage
        $totalPct = ($now.CpuSeconds - $base.CpuSeconds) / $el * 100
        $s = [pscustomobject]@{
            t = [math]::Round($el, 0)
            svcCpu = [math]::Round($totalPct / [math]::Max(1, $targetCount), 2)   # 平均单进程
            totalCpu = [math]::Round($totalPct, 2)                                # 角色合计
            allCpu = [math]::Round(($nowAll.CpuSeconds - $baseAll.CpuSeconds) / $el * 100, 2)
            sysCpu = Get-SystemCpuPct
            wsMB = $now.WsMB
            commitMB = $now.CommitMB
            procs = $now.Count
            active = $active
        }
        $samples += $s
        $allSamples += $s
        Write-Host ("  [{0,3}s] {1}平均={2,6:N1}%/{3}% 合计={4,6:N1}% all={5,6:N1}% sys={6,5:N0}% ws={7,6:N1}MB active={8}/{9}" -f `
            $s.t, $targetRole, $s.svcCpu, $CpuTarget, $s.totalCpu, $s.allCpu, $s.sysCpu, $s.wsMB, $active, $cum)
    }

    # ── 容量判定 ──
    $valid = @($samples | Where-Object { $_.active -ge 0 })
    $activeFinal = if ($valid.Count) { $valid[-1].active } else { -1 }
    $activeMin = if ($valid.Count) { ($valid | Measure-Object active -Minimum).Minimum } else { -1 }
    $retention = if ($valid.Count) { [math]::Round($valid[-1].active / $cum * 100, 1) } else { 0 }
    $svcAvg = if ($valid.Count) { [math]::Round(($valid | Measure-Object svcCpu -Average).Average, 2) } else { 0 }
    $wsEnd = if ($valid.Count) { $valid[-1].wsMB } else { 0 }
    $commitEnd = if ($valid.Count) { $valid[-1].commitMB } else { 0 }
    $perUnit = if ($activeFinal -gt 0) { [math]::Round($svcAvg / $activeFinal, 4) } else { 0 }
    $pass = -not $aborted -and $retention -ge $MinRetention

    $summary = [pscustomobject]@{
        Scenario = $Scenario
        CapacityStreams = $cum
        ActiveFinal = $activeFinal
        ActiveMin = $activeMin
        RetentionPct = $retention
        Pass = $pass
        CpuAtSaturation = $cpuAtSat
        SvcCpuAvg = $svcAvg
        WsMBEnd = $wsEnd
        CommitMBEnd = $commitEnd
        PerUnitCpu = $perUnit
        PerUnitMemMB = if ($activeFinal -gt 0) { [math]::Round($wsEnd / $activeFinal, 2) } else { 0 }
        Aborted = $aborted
    }

    Write-Host ""
    Write-Host "════════ 容量结论（$Scenario）════════" -ForegroundColor Cyan
    Write-Host ("  有效容量: {0} 路（{1}各进程饱和 CPU: {2}）" -f $cum, $targetRole, $cpuAtSat)
    Write-Host ("  稳态考核: 保持 {0}/{1}（{2}%）  最低 {3}" -f $activeFinal, $cum, $retention, $activeMin)
    Write-Host ("  {0}平均 CPU: {1}%   内存: {2} MB (ws) / {3} MB (commit)" -f $targetRole, $svcAvg, $wsEnd, $commitEnd)
    Write-Host ("  每路: {0}% CPU / {1} MB" -f $perUnit, $summary.PerUnitMemMB)
    if ($pass) {
        Write-Host ("  判定: ✓ 通过（保持率 ≥ {0}%）" -f $MinRetention) -ForegroundColor Green
    } else {
        Write-Host ("  判定: ✗ 未通过（保持率 < {0}% 或服务异常）" -f $MinRetention) -ForegroundColor Red
    }

    Save-BenchResult -Scenario "capacity_$Scenario" -EventLoop 'multiprocess-incremental' -Data @{
        summary = $summary
        ramp = @{ batch = $RampBatch; intervalSec = $RampIntervalSec; cpuTargetPerProcess = $CpuTarget; targetRole = $targetRole; targetProcesses = $targetCount }
        steadySeconds = $SecondsPerStep
        samples = $allSamples
    } | Out-Null
}
finally {
    foreach ($p in (@($pullProcs) + @($pushProcs))) {
        if ($p -and -not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
    }
    if ($server) { Stop-MpServers -Launcher $server }
}
