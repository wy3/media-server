# 逐级加压测试：从低到高逐档跑，每档记录一组数据。
#
# 为什么逐档跑而不是只测一个点：
#   单点数据看不出「容量拐点」。逐档加压能看到：
#     - CPU 随路数是否线性增长（线性 = 还有余量；翘起 = 接近瓶颈）
#     - 从哪一档开始掉帧/断连
#     - 事件循环上限在哪里撞墙
#
# 用法：
#   .\s4_ramp.ps1 -Scenario pull -EventLoop event -Steps 100,200,400,600,800,1000
#   .\s4_ramp.ps1 -Scenario push -EventLoop select -Steps 100,200,300
#
# 每档默认跑 60 秒（可调），档间自动清理。

param(
    [Parameter(Mandatory)]
    [ValidateSet('pull', 'push')]
    [string]$Scenario,

    [ValidateSet('event', 'select')]
    [string]$EventLoop = 'event',

    [Parameter(Mandatory)]
    [int[]]$Steps,

    [int]$SecondsPerStep = 60,
    [int]$WarmupSeconds = 20,
    [int]$RtmpPort = 11935,
    [int]$HttpPort = 11980
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

$rtmpstress = if ($Scenario -eq 'pull') { Resolve-RtmpStress } else { $null }

Write-Host "=== 逐级加压：$Scenario / $EventLoop ===" -ForegroundColor Cyan
Write-Host "档位: $($Steps -join ', ') | 每档 ${SecondsPerStep}s"

$allResults = @()

foreach ($n in $Steps) {
    Write-Host ""
    Write-Host "──────── 档位 $n ────────" -ForegroundColor Yellow

    $server = $null
    $pushProc = $null
    $pullProc = $null
    $pushProcs = @()
    $aborted = $false

    try {
        $server = Start-BenchServer -EventLoop $EventLoop -RtmpPort $RtmpPort -HttpPort $HttpPort
        $token = Get-AdminToken -HttpPort $HttpPort

        # 起推流（pull 场景是 1 路；push 场景是 N 路）
        #
        # push 场景为什么分段：Windows 命令行长度上限约 32KB，
        # 1000 路 tee 的 slave 列表会超过（实测报"文件名或扩展名太长"）。
        # 因此每 250 路起一个 ffmpeg 进程，各推各的。
        if ($Scenario -eq 'pull') {
            $pushProc = Start-Process -FilePath 'ffmpeg' -ArgumentList @(
                '-hide_banner', '-loglevel', 'error'
                '-re', '-stream_loop', '-1'
                '-i', (Join-Path $script:SamplesDir '1080p30_4m.flv')
                '-c', 'copy', '-f', 'flv', "rtmp://127.0.0.1:$RtmpPort/live/bench"
            ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $env:TEMP "ramp_push_$n.err")
            $pushProcs = @($pushProc)
        } else {
            $chunkSize = 250
            $pushProcs = @()
            $idx = 0
            while ($idx -lt $n) {
                $count = [math]::Min($chunkSize, $n - $idx)
                $slaves = (1..$count | ForEach-Object {
                    $id = $idx + $_
                    "[f=flv]rtmp://127.0.0.1:$RtmpPort/live/p$id"
                }) -join '|'
                $pushProcs += Start-Process -FilePath 'ffmpeg' -ArgumentList @(
                    '-hide_banner', '-loglevel', 'error'
                    '-re', '-stream_loop', '-1'
                    '-i', (Join-Path $script:SamplesDir '1080p30_4m.flv')
                    '-c', 'copy', '-f', 'tee', '-map', '0', $slaves
                ) -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $env:TEMP "ramp_push_${n}_$idx.err")
                $idx += $count
            }
            $pushProc = $pushProcs[0]
        }

        # 等推流就绪
        $deadline = (Get-Date).AddSeconds(30)
        $ready = $false
        while ((Get-Date) -lt $deadline) {
            Start-Sleep -Milliseconds 500
            $st = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $HttpPort -TimeoutSec 5
            if ($st) { $ready = $true; break }
        }
        if (-not $ready) { throw "推流未就绪" }

        # 起拉流
        if ($Scenario -eq 'pull') {
            $pullProc = Start-Process -FilePath $rtmpstress -ArgumentList @(
                '-c', $n, '-i', "rtmp://127.0.0.1:$RtmpPort/live/bench"
            ) -PassThru -WindowStyle Hidden `
              -RedirectStandardOutput (Join-Path $env:TEMP "ramp_pull_$n.out") `
              -RedirectStandardError (Join-Path $env:TEMP "ramp_pull_$n.err")
        }

        Write-Host "  预热 ${WarmupSeconds}s..." -NoNewline
        Start-Sleep -Seconds $WarmupSeconds
        Write-Host " 完成"

        # 采样
        $server.Refresh()
        $cpu0 = $server.TotalProcessorTime.TotalSeconds
        $t0 = Get-Date
        $samples = @()
        $apiFail = 0

        while (((Get-Date) - $t0).TotalSeconds -lt $SecondsPerStep) {
            Start-Sleep -Seconds 10
            $el = ((Get-Date) - $t0).TotalSeconds
            $server.Refresh()

            if ($Scenario -eq 'pull') {
                $cnt = Invoke-AdminApiSafe -Token $token -Name 'getPlayStreamCount' -Args @('/live/bench') -HttpPort $HttpPort -TimeoutSec 8
                $active = if ($cnt) { $cnt.count } else { -1 }
            } else {
                $lst = Invoke-AdminApiSafe -Token $token -Name 'listPushStream' -HttpPort $HttpPort -TimeoutSec 8
                $active = if ($lst) { @($lst).Count } else { -1 }
            }

            if ($active -lt 0) {
                $apiFail++
                if ($apiFail -ge 2) {
                    Write-Host "  API 连续无响应 → 事件循环卡死，本档终止" -ForegroundColor Red
                    $aborted = $true
                    break
                }
            } else { $apiFail = 0 }

            $samples += [pscustomobject]@{
                t = [math]::Round($el, 0)
                svcCpu = [math]::Round(($server.TotalProcessorTime.TotalSeconds - $cpu0) / $el * 100, 2)
                sysCpu = Get-SystemCpuPct
                wsMB = [math]::Round($server.WorkingSet64 / 1MB, 1)
                active = $active
            }
            Write-Host ("  [{0,3}s] svc={1,6:N1}% sys={2,5:N0}% ws={3,6:N1}MB active={4}/{5}" -f `
                $samples[-1].t, $samples[-1].svcCpu, $samples[-1].sysCpu, $samples[-1].wsMB, $active, $n)
        }

        $valid = @($samples | Where-Object { $_.active -ge 0 })
        $result = [pscustomobject]@{
            Target = $n
            Aborted = $aborted
            ActiveFinal = if ($valid.Count) { $valid[-1].active } else { -1 }
            ActiveMin = if ($valid.Count) { ($valid | Measure-Object active -Minimum).Minimum } else { -1 }
            RetentionPct = if ($valid.Count) { [math]::Round($valid[-1].active / $n * 100, 1) } else { 0 }
            SvcCpuAvg = if ($valid.Count) { [math]::Round(($valid | Measure-Object svcCpu -Average).Average, 2) } else { 0 }
            SvcCpuMax = if ($valid.Count) { ($valid | Measure-Object svcCpu -Maximum).Maximum } else { 0 }
            SysCpuAvg = if ($valid.Count) { [math]::Round(($valid | Measure-Object sysCpu -Average).Average, 1) } else { 0 }
            WsMBEnd = if ($valid.Count) { $valid[-1].wsMB } else { 0 }
            PerUnitCpu = if ($valid.Count -and $valid[-1].active -gt 0) {
                [math]::Round($valid[-1].svcCpu / $valid[-1].active, 4)
            } else { 0 }
        }
        $allResults += $result

        Write-Host ("  → 保持 {0}/{1} ({2}%)  svc={3:N1}%  sys={4:N0}%  ws={5:N0}MB  每路={6:N4}%" -f `
            $result.ActiveFinal, $n, $result.RetentionPct, $result.SvcCpuAvg, $result.SysCpuAvg, $result.WsMBEnd, $result.PerUnitCpu) -ForegroundColor Green

        # 卡死或掉到 0 就不再加压
        if ($aborted -or $result.ActiveFinal -le 0) {
            Write-Host "  该档已失效，停止后续加压" -ForegroundColor Yellow
            break
        }
    }
    catch {
        Write-Host "  档位 $n 异常: $_" -ForegroundColor Red
        $allResults += [pscustomobject]@{
            Target = $n; Aborted = $true; ActiveFinal = -1; ActiveMin = -1
            RetentionPct = 0; SvcCpuAvg = 0; SvcCpuMax = 0; SysCpuAvg = 0
            WsMBEnd = 0; PerUnitCpu = 0
        }
        break
    }
    finally {
        foreach ($p in (@($pullProc) + @($pushProcs))) {
            if ($p -and -not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
        }
        if ($server) { Stop-BenchServer -Process $server }
        Start-Sleep -Seconds 3
    }
}

Write-Host ""
Write-Host "=== 加压曲线汇总 ===" -ForegroundColor Cyan
Write-Host ("{0,8} {1,10} {2,8} {3,10} {4,8} {5,10} {6,10}" -f `
    '目标', '实际', '保持%', 'svc CPU%', 'sys%', '内存MB', '每路%')
Write-Host ('-' * 68)
foreach ($r in $allResults) {
    $flag = if ($r.Aborted) { ' [卡死]' } elseif ($r.RetentionPct -lt 100) { ' [掉线]' } else { '' }
    Write-Host ("{0,8} {1,10} {2,8} {3,10} {4,8} {5,10} {6,10}{7}" -f `
        $r.Target, $r.ActiveFinal, $r.RetentionPct, $r.SvcCpuAvg, $r.SysCpuAvg, $r.WsMBEnd, $r.PerUnitCpu, $flag)
}

Save-BenchResult -Scenario "ramp_$Scenario" -EventLoop $EventLoop -Data @{
    steps = $Steps
    secondsPerStep = $SecondsPerStep
    warmupSeconds = $WarmupSeconds
    results = $allResults
} | Out-Null
