# 混合推拉测试：同时有 N 路推流与 M 路拉流，测量相互影响。
#
# 为什么需要这个测试：
#   单测推流或单测拉流都会低估真实负载 —— 生产环境总是同时有推和拉。
#   而且两者的开销结构不同：
#     推流 = 解析 + GOP 缓存（内存密集，每路 1.17 MB）
#     拉流 = 封包 + 写 socket（每连接 36 KB）
#   混跑时 CPU 是叠加的，但内存与延迟的影响需要实测。
#
# 测试矩阵（按你的要求）：
#   固定拉流 500，推流递增：50/500, 100/500, 200/500, 500/500
#   固定推流 500，拉流递减：500/500, 500/200, 500/100, 500/50
#
# 统计项：
#   - 服务端 svc CPU / sys CPU / 内存
#   - 推流保持率、掉帧数（fps < 25 的路数）
#   - 拉流保持率
#   - 新增连接的首帧延迟（判断是否排队）
#
# 用法：
#   .\s5_mixed.ps1 -Matrix '50/500','100/500','200/500','500/500','500/200','500/100','500/50'

param(
    [ValidateSet('event', 'select')]
    [string]$EventLoop = 'event',

    # 每项格式 "推流数/拉流数"
    [string[]]$Matrix = @('50/500', '100/500', '200/500', '500/500', '500/200', '500/100', '500/50'),

    [int]$SecondsPerCase = 60,
    [int]$WarmupSeconds = 25,
    [int]$RtmpPort = 11935,
    [int]$HttpPort = 11980,
    [int]$PushChunk = 250
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

$rtmpstress = Resolve-RtmpStress
$sample = Join-Path $script:SamplesDir '1080p30_4m.flv'
if (-not (Test-Path $sample)) { throw "缺少样本 $sample，先跑 gen_samples.ps1" }

Write-Host "=== 混合推拉测试 ===" -ForegroundColor Cyan
Write-Host "事件循环: $EventLoop | 用例: $($Matrix -join ', ') | 每例 ${SecondsPerCase}s"

$cases = @()
foreach ($m in $Matrix) {
    if ($m -notmatch '^(\d+)/(\d+)$') { throw "矩阵项格式错误: $m（应为 '推流数/拉流数'）" }
    $cases += [pscustomobject]@{ Push = [int]$matches[1]; Pull = [int]$matches[2] }
}

$allResults = @()

foreach ($case in $cases) {
    $np = $case.Push
    $npull = $case.Pull
    $label = "$np/$npull"
    Write-Host ""
    Write-Host "════════ 推流 $np / 拉流 $npull ════════" -ForegroundColor Yellow

    $server = $null
    $pushProcs = @()
    $stressProc = @()
    $probePush = $null
    $probeProg = Join-Path $env:TEMP "mixed_probe_$($np)_$npull.txt"
    $aborted = $false

    try {
        $server = Start-BenchServer -EventLoop $EventLoop -RtmpPort $RtmpPort -HttpPort $HttpPort
        $token = Get-AdminToken -HttpPort $HttpPort

        # ── 起推流：np 路（分段，避免命令行过长）+ 1 路探针（带 -progress）
        # 探针流独立，用于测延迟，不计入 np
        if (Test-Path $probeProg) { Remove-Item $probeProg -Force }
        $probePush = Start-Process -FilePath 'ffmpeg' -ArgumentList @(
            '-hide_banner', '-loglevel', 'error'
            '-re', '-stream_loop', '-1', '-i', $sample
            '-c', 'copy', '-f', 'flv', "rtmp://127.0.0.1:$RtmpPort/live/probe"
            '-progress', $probeProg, '-stats_period', '0.02'
        ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $env:TEMP "mixed_probe.err")

        if ($np -gt 0) {
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
                ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $env:TEMP "mixed_push_${np}_$idx.err")
                $idx += $count
            }
        }

        # 等推流就绪（探针流也算在内，所以目标 +1）
        $deadline = (Get-Date).AddSeconds(40)
        $pushOk = $false
        while ((Get-Date) -lt $deadline) {
            Start-Sleep -Milliseconds 500
            $st = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $HttpPort -TimeoutSec 5
            if ($st -and @($st).Count -ge ($np + 1)) { $pushOk = $true; break }
        }
        if (-not $pushOk) { throw "推流未达目标（$np 路 + 1 探针）" }

        # ── 起拉流：npull 路
        if ($npull -gt 0) {
            $stressProc += Start-Process -FilePath $rtmpstress -ArgumentList @(
                '-c', $npull, '-i', "rtmp://127.0.0.1:$RtmpPort/live/probe"
            ) -PassThru -WindowStyle Hidden `
              -RedirectStandardOutput (Join-Path $env:TEMP "mixed_pull_${np}_$npull.out") `
              -RedirectStandardError (Join-Path $env:TEMP "mixed_pull_${np}_$npull.err")
        }

        Write-Host "  预热 ${WarmupSeconds}s..." -NoNewline
        Start-Sleep -Seconds $WarmupSeconds
        Write-Host " 完成"

        # ── 采样
        $server.Refresh()
        $cpu0 = $server.TotalProcessorTime.TotalSeconds
        $t0 = Get-Date
        $samples = @()
        $apiFail = 0
        $latencies = @()

        while (((Get-Date) - $t0).TotalSeconds -lt $SecondsPerCase) {
            Start-Sleep -Seconds 10
            $el = ((Get-Date) - $t0).TotalSeconds
            $server.Refresh()

            $pushList = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $HttpPort -TimeoutSec 8
            $pullCnt = Invoke-AdminApiSafe -Token $token -Name 'getPlayStreamCount' -Args @('/live/probe') -HttpPort $HttpPort -TimeoutSec 8

            if ($null -eq $pushList -or $null -eq $pullCnt) {
                $apiFail++
                if ($apiFail -ge 2) {
                    Write-Host "  API 连续无响应 → 事件循环卡死" -ForegroundColor Red
                    $aborted = $true
                    break
                }
                continue
            }
            $apiFail = 0

            $pushCount = @($pushList).Count - 1   # 减去探针流
            $lowFps = @($pushList | Where-Object { $_.videoFps -lt 25 -and $_.publishStreamPath -ne '/live/probe' }).Count

            $samples += [pscustomobject]@{
                t = [math]::Round($el, 0)
                svcCpu = [math]::Round(($server.TotalProcessorTime.TotalSeconds - $cpu0) / $el * 100, 2)
                sysCpu = Get-SystemCpuPct
                wsMB = [math]::Round($server.WorkingSet64 / 1MB, 1)
                pushCount = $pushCount
                lowFps = $lowFps
                pullCount = $pullCnt.count
            }
            Write-Host ("  [{0,3}s] svc={1,6:N1}% sys={2,4:N0}% ws={3,6:N0}MB 推={4}/{5} 拉={6}/{7} 低帧={8}" -f `
                $samples[-1].t, $samples[-1].svcCpu, $samples[-1].sysCpu, $samples[-1].wsMB, `
                $pushCount, $np, $pullCnt.count, $npull, $lowFps)
        }

        # ── 补测延迟（用探针流，在当前负载下新开一路拉流）
        $probeLat = $null
        if (-not $aborted) {
            $latList = @()
            for ($k = 1; $k -le 3; $k++) {
                $pushPts = $null
                $pc = Get-Content $probeProg -Raw -ErrorAction SilentlyContinue
                if ($pc) {
                    $mm = [regex]::Matches($pc, 'out_time_us=(\d+)')
                    if ($mm.Count) { $pushPts = [double]$mm[$mm.Count - 1].Groups[1].Value / 1e6 }
                }
                $pushWall = Get-Date
                $log = Join-Path $env:TEMP "mixed_lat_$k.log"
                if (Test-Path $log) { Remove-Item $log -Force }
                $p = Start-Process -FilePath 'ffmpeg' -ArgumentList @(
                    '-hide_banner', '-loglevel', 'info', '-copyts'
                    '-analyzeduration', '0', '-probesize', '32'
                    '-fflags', 'nobuffer', '-flags', 'low_delay'
                    '-i', "rtmp://127.0.0.1:$RtmpPort/live/probe"
                    '-frames:v', '3', '-vf', 'showinfo', '-f', 'null', '-'
                ) -PassThru -WindowStyle Hidden -RedirectStandardError $log

                $dl = (Get-Date).AddSeconds(30)
                $pts = $null
                while ((Get-Date) -lt $dl) {
                    Start-Sleep -Milliseconds 20
                    if (Test-Path $log) {
                        $cc = Get-Content $log -Raw -ErrorAction SilentlyContinue
                        if ($cc) {
                            $m2 = [regex]::Match($cc, 'pts_time:([0-9.]+)')
                            if ($m2.Success) { $pts = [double]$m2.Groups[1].Value; break }
                        }
                    }
                    if ($p.HasExited -and $null -eq $pts) { break }
                }
                if ($p -and -not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
                if ($null -ne $pts -and $null -ne $pushPts) {
                    $gap = ((Get-Date) - $pushWall).TotalSeconds
                    $latList += (($pushPts + $gap) - $pts) * 1000
                }
            }
            if ($latList.Count) {
                $probeLat = [math]::Round(($latList | Sort-Object)[[int][math]::Floor($latList.Count / 2)], 0)
            }
        }

        $valid = @($samples)
        $result = [pscustomobject]@{
            Push = $np
            Pull = $npull
            Aborted = $aborted
            SvcCpuAvg = if ($valid.Count) { [math]::Round(($valid | Measure-Object svcCpu -Average).Average, 1) } else { 0 }
            SvcCpuMax = if ($valid.Count) { ($valid | Measure-Object svcCpu -Maximum).Maximum } else { 0 }
            SysCpuAvg = if ($valid.Count) { [math]::Round(($valid | Measure-Object sysCpu -Average).Average, 1) } else { 0 }
            WsMBEnd = if ($valid.Count) { $valid[-1].wsMB } else { 0 }
            PushFinal = if ($valid.Count) { $valid[-1].pushCount } else { 0 }
            PullFinal = if ($valid.Count) { $valid[-1].pullCount } else { 0 }
            LowFpsMax = if ($valid.Count) { ($valid | Measure-Object lowFps -Maximum).Maximum } else { 0 }
            ProbeLatencyMs = $probeLat
        }
        $allResults += $result

        Write-Host ("  → svc={0:N1}% sys={1:N0}% ws={2:N0}MB 推={3}/{4} 拉={5}/{6} 低帧={7} 延迟={8}ms" -f `
            $result.SvcCpuAvg, $result.SysCpuAvg, $result.WsMBEnd, `
            $result.PushFinal, $np, $result.PullFinal, $npull, $result.LowFpsMax, $result.ProbeLatencyMs) -ForegroundColor Green

        if ($aborted) {
            Write-Host "  该用例已卡死，停止后续" -ForegroundColor Yellow
            break
        }
    }
    catch {
        Write-Host "  用例 $label 异常: $_" -ForegroundColor Red
        $allResults += [pscustomobject]@{
            Push = $np; Pull = $npull; Aborted = $true
            SvcCpuAvg = 0; SvcCpuMax = 0; SysCpuAvg = 0; WsMBEnd = 0
            PushFinal = 0; PullFinal = 0; LowFpsMax = 0; ProbeLatencyMs = $null
        }
        break
    }
    finally {
        foreach ($p in ($pushProcs + $stressProc + @($probePush))) {
            if ($p -and -not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
        }
        if ($server) { Stop-BenchServer -Process $server }
        Start-Sleep -Seconds 5
    }
}

Write-Host ""
Write-Host "=== 混合推拉汇总 ===" -ForegroundColor Cyan
Write-Host ("{0,8} {1,8} {2,10} {3,8} {4,10} {5,10} {6,8} {7,10}" -f `
    '推流', '拉流', 'svc CPU%', 'sys%', '内存MB', '推流保持', '低帧', '延迟ms')
Write-Host ('-' * 82)
foreach ($r in $allResults) {
    $flag = if ($r.Aborted) { ' [卡死]' } else { '' }
    Write-Host ("{0,8} {1,8} {2,10} {3,8} {4,10} {5,10} {6,8} {7,10}{8}" -f `
        $r.Push, $r.Pull, $r.SvcCpuAvg, $r.SysCpuAvg, $r.WsMBEnd, `
        ("{0}/{1}" -f $r.PushFinal, $r.Push), $r.LowFpsMax, $r.ProbeLatencyMs, $flag)
}

Save-BenchResult -Scenario 'mixed' -EventLoop $EventLoop -Data @{
    matrix = $Matrix
    secondsPerCase = $SecondsPerCase
    warmupSeconds = $WarmupSeconds
    results = $allResults
} | Out-Null
