# 压测公共函数。
#
# 所有脚本 dot-source 本文件后使用其中的函数。
# 约定：不使用全局状态，所有参数显式传入。

$ErrorActionPreference = 'Stop'

# 路径基准（tests/bench 的绝对路径）
$script:BenchRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$script:RepoRoot = Split-Path -Parent (Split-Path -Parent $script:BenchRoot)
$script:SamplesDir = Join-Path $script:BenchRoot 'samples'
$script:ResultsDir = Join-Path $script:BenchRoot 'results'

if (-not (Test-Path $script:ResultsDir)) {
    New-Item -ItemType Directory -Force -Path $script:ResultsDir | Out-Null
}

<#
.SYNOPSIS
    解析 PHP 扩展目录的绝对路径。
.DESCRIPTION
    php.ini 里的 extension_dir 常写成相对路径（如 "ext"），
    此时从任意工作目录启动 php 会找不到 dll，报
    "Unable to load dynamic library ..."。
    这里把相对路径按 php.ini 所在目录解析成绝对路径，
    供 Start-BenchServer 以 -d extension_dir=... 覆盖。
    解析失败返回 $null（保持 php 原有行为）。
.OUTPUTS
    [string] 绝对路径，或 $null
#>
function Resolve-PhpExtensionDir {
    try {
        $iniPath = (php --ini 2>$null | Select-String -Pattern 'Loaded Configuration File:\s*(.+)$').Matches.Groups[1].Value
        $raw = (php -r "echo ini_get('extension_dir');" 2>$null)
    } catch {
        return $null
    }
    if (-not $raw) { return $null }
    if ([System.IO.Path]::IsPathRooted($raw)) {
        return (Resolve-Path $raw -ErrorAction SilentlyContinue).Path
    }
    if ($iniPath -and (Test-Path $iniPath)) {
        $candidate = Join-Path (Split-Path -Parent $iniPath) $raw
        if (Test-Path $candidate) {
            return (Resolve-Path $candidate).Path
        }
    }
    return $null
}

<#
.SYNOPSIS
    启动压测服务端。
.DESCRIPTION
    用给定事件循环启动 server_bench.php，返回进程对象。
    会等待端口就绪，超时则抛错。
.PARAMETER EventLoop
    'event' 或 'select'
.PARAMETER RtmpPort
.PARAMETER HttpPort
.PARAMETER MemoryLimit
    默认 4G（4K 多路时 2G 会 OOM）
.PARAMETER LogPath
    服务端 stdout 重定向路径；$null 表示丢弃（压测推荐）
#>
function Start-BenchServer {
    param(
        [ValidateSet('event', 'select')]
        [string]$EventLoop = 'event',
        [int]$RtmpPort = 11935,
        [int]$HttpPort = 11980,
        [string]$MemoryLimit = '4G',
        [string]$LogPath = $null
    )

    $phpArgs = @('-d', "memory_limit=$MemoryLimit")
    if ($EventLoop -eq 'event') {
        # php.ini 里 extension_dir 常写成相对路径 "ext"，从其他工作目录启动时
        # 会找不到 dll（报 Unable to load dynamic library）。这里解析成绝对路径再传。
        $extDir = Resolve-PhpExtensionDir
        if ($extDir) {
            $phpArgs += @('-d', "extension_dir=$extDir")
        }
        $phpArgs += @('-d', 'extension=sockets', '-d', 'extension=event')
    }
    $phpArgs += (Join-Path $script:BenchRoot 'lib\server_bench.php')
    $phpArgs += @($EventLoop, $RtmpPort, $HttpPort)

    $stdout = if ($LogPath) { $LogPath } else { 'NUL' }
    $proc = Start-Process -FilePath 'php' -ArgumentList $phpArgs -PassThru -WindowStyle Hidden `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError (Join-Path $env:TEMP "bench_srv_$RtmpPort.err")

    # 等端口就绪（最多 15s）
    $deadline = (Get-Date).AddSeconds(15)
    while ((Get-Date) -lt $deadline) {
        if ($proc.HasExited) {
            $errFile = Join-Path $env:TEMP "bench_srv_$RtmpPort.err"
            $errText = if (Test-Path $errFile) { Get-Content $errFile -Raw } else { '' }
            throw "服务端启动失败（退出码 $($proc.ExitCode)）：$errText"
        }
        if (Test-NetConnection -ComputerName 127.0.0.1 -Port $RtmpPort -InformationLevel Quiet -WarningAction SilentlyContinue) {
            return $proc
        }
        Start-Sleep -Milliseconds 300
    }
    Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
    throw "服务端端口 $RtmpPort 在 15s 内未就绪"
}

<#
.SYNOPSIS
    停止服务端进程树。
#>
function Stop-BenchServer {
    param([System.Diagnostics.Process]$Process)
    if ($Process -and -not $Process.HasExited) {
        Stop-Process -Id $Process.Id -Force -ErrorAction SilentlyContinue
    }
}

<#
.SYNOPSIS
    登录管理 API 并返回 token。
#>
function Get-AdminToken {
    param([int]$HttpPort = 11980, [string]$User = 'admin', [string]$Pass = 'admin123')
    $body = @{ name = 'login'; args = @($User, $Pass) } | ConvertTo-Json -Compress
    $r = Invoke-RestMethod -Uri "http://127.0.0.1:$HttpPort/api" -Method Post -ContentType 'application/json' -Body $body
    return $r.token
}

<#
.SYNOPSIS
    调用管理 API。
#>
function Invoke-AdminApi {
    param(
        [Parameter(Mandatory)][string]$Token,
        [Parameter(Mandatory)][string]$Name,
        [object[]]$Args = @(),
        [int]$HttpPort = 11980
    )
    $body = @{ name = $Name; args = $Args } | ConvertTo-Json -Compress -Depth 5
    return Invoke-RestMethod -Uri "http://127.0.0.1:$HttpPort/api" -Method Post `
        -ContentType 'application/json' -Headers @{ 'X-Auth-Token' = $Token } -Body $body
}

<#
.SYNOPSIS
    采样系统总 CPU 占用（百分比，0~100，已按全部逻辑核归一）。
.DESCRIPTION
    用于判断测量环境是否被其他程序干扰。
    服务进程的 CPU 是「进程自身」的（见 Measure-ProcessUsage），
    但当机器整体很忙时，服务进程拿不到足够 CPU，吞吐会下降。
    同时记录系统占用可以区分「服务端能力不足」与「机器被占满」。
#>
function Get-SystemCpuPct {
    try {
        $d = Get-CimInstance Win32_PerfFormattedData_PerfOS_Processor -ErrorAction Stop |
             Where-Object { $_.Name -eq '_Total' }
        if ($d) { return [double]$d.PercentProcessorTime }
    } catch { }
    return $null
}

<#
.SYNOPSIS
    采样进程 CPU（单核百分比）与内存。
.DESCRIPTION
    在指定秒数内采样，返回平均值。
    用 TotalProcessorTime 增量除以墙钟时间，结果 100% = 用满 1 个核。
    这是**进程自身**的占用，不含系统其他程序。
.PARAMETER Process
    目标进程
.PARAMETER Seconds
    采样时长
.PARAMETER IntervalSeconds
    采样间隔；每次采样记录一个点，返回均值/最大/最小
#>
function Measure-ProcessUsage {
    param(
        [Parameter(Mandatory)][System.Diagnostics.Process]$Process,
        [int]$Seconds = 20,
        [int]$IntervalSeconds = 2
    )

    $Process.Refresh()
    $cpu0 = $Process.TotalProcessorTime.TotalSeconds
    $ws0 = $Process.WorkingSet64
    $t0 = Get-Date

    $samples = @()
    $elapsed = 0
    while ($elapsed -lt $Seconds) {
        Start-Sleep -Seconds $IntervalSeconds
        $elapsed = ((Get-Date) - $t0).TotalSeconds
        $Process.Refresh()
        $cpu = $Process.TotalProcessorTime.TotalSeconds
        $samples += [pscustomobject]@{
            t = [math]::Round($elapsed, 2)
            cpuCorePct = [math]::Round(($cpu - $cpu0) / $elapsed * 100, 2)
            wsMB = [math]::Round($Process.WorkingSet64 / 1MB, 1)
        }
    }

    $Process.Refresh()
    $wall = ((Get-Date) - $t0).TotalSeconds
    $totalCpu = $Process.TotalProcessorTime.TotalSeconds - $cpu0

    return [pscustomobject]@{
        WallSeconds = [math]::Round($wall, 2)
        CpuCorePctAvg = [math]::Round($totalCpu / $wall * 100, 2)
        CpuCorePctMax = ($samples | Measure-Object cpuCorePct -Maximum).Maximum
        WsMBStart = [math]::Round($ws0 / 1MB, 1)
        WsMBEnd = [math]::Round($Process.WorkingSet64 / 1MB, 1)
        Samples = $samples
    }
}

<#
.SYNOPSIS
    等待目标进程退出或超时，返回是否存活。
#>
function Wait-ProcessOrTimeout {
    param(
        [Parameter(Mandatory)][System.Diagnostics.Process]$Process,
        [int]$TimeoutSeconds
    )
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if ($Process.HasExited) { return $false }
        Start-Sleep -Milliseconds 500
    }
    return $true
}

<#
.SYNOPSIS
    找 rtmpstress.exe。
.DESCRIPTION
    依次检查环境变量 RTMPSTRESS、PATH、常见位置。
#>
function Resolve-RtmpStress {
    if ($env:RTMPSTRESS -and (Test-Path $env:RTMPSTRESS)) {
        return $env:RTMPSTRESS
    }
    $cmd = Get-Command rtmpstress.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }

    $candidates = @(
        (Join-Path $env:TEMP 'rtmpstress\rtmpstress.exe'),
        (Join-Path $script:BenchRoot 'tools\rtmpstress.exe')
    )
    foreach ($c in $candidates) {
        if (Test-Path $c) { return $c }
    }
    throw "未找到 rtmpstress.exe。设置环境变量 RTMPSTRESS，或放到 tests\bench\tools\ 下。"
}

<#
.SYNOPSIS
    调用管理 API（带超时）。
.DESCRIPTION
    用 Start-Job 包一层实现超时，避免服务端事件循环卡死时脚本无限等待。
    实测：select 循环在连接数超限后会整个停摆，连 HTTP 都不响应。

    注意：结果经 job 序列化后可能被截断（大数组会丢失元素），
    因此只适合取单个标量（如 count）。需要完整数组时用 Invoke-AdminApi。
#>
function Invoke-AdminApiSafe {
    param(
        [Parameter(Mandatory)][string]$Token,
        [Parameter(Mandatory)][string]$Name,
        [object[]]$Args = @(),
        [int]$HttpPort = 11980,
        [int]$TimeoutSec = 10
    )
    $body = @{ name = $Name; args = $Args } | ConvertTo-Json -Compress -Depth 5
    $uri = "http://127.0.0.1:$HttpPort/api"

    $job = Start-Job -ScriptBlock {
        param($uri, $body, $token)
        Invoke-RestMethod -Uri $uri -Method Post -ContentType 'application/json' `
            -Headers @{ 'X-Auth-Token' = $token } -Body $body -TimeoutSec 8
    } -ArgumentList $uri, $body, $Token

    $done = Wait-Job -Job $job -Timeout $TimeoutSec
    if (-not $done) {
        Stop-Job -Job $job -ErrorAction SilentlyContinue
        Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
        return $null
    }
    try {
        $result = Receive-Job -Job $job -ErrorAction Stop
    } catch {
        $result = $null
    }
    Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
    return $result
}

<#
.SYNOPSIS
    保存测试结果到 JSON 并打印摘要。
#>
function Save-BenchResult {
    param(
        [Parameter(Mandatory)][string]$Scenario,
        [Parameter(Mandatory)][hashtable]$Data,
        [string]$EventLoop = 'event'
    )
    $Data['scenario'] = $Scenario
    $Data['eventLoop'] = $EventLoop
    $Data['timestamp'] = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    $Data['host'] = @{
        cpu = (Get-CimInstance Win32_Processor | Select-Object -First 1).Name
        cores = (Get-CimInstance Win32_Processor | Select-Object -First 1).NumberOfCores
        logicalProcessors = [Environment]::ProcessorCount
        os = (Get-CimInstance Win32_OperatingSystem).Caption
        php = (& php -r 'echo PHP_VERSION;')
    }

    $file = Join-Path $script:ResultsDir ("{0}_{1}_{2}.json" -f $Scenario, $EventLoop, (Get-Date -Format 'yyyyMMdd_HHmmss'))
    $Data | ConvertTo-Json -Depth 8 | Set-Content -Path $file -Encoding UTF8
    Write-Host ""
    Write-Host "结果已保存: $file" -ForegroundColor Green
    return $file
}
