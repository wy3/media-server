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

# ══════════════════════════════════════════════════════════════
# 多进程压测启动器：每个功能一个进程（control/ingest/play/admin）。
# 通过环境变量覆盖 ServerConfig，start_multiprocess.php 的每个子进程
# 都会 loadFromEnv() 继承同一组端口。录像默认不起（避免磁盘 IO 干扰）。
# ══════════════════════════════════════════════════════════════

<#
.SYNOPSIS
    启动完整的多进程服务端（每个功能一个进程）。
.DESCRIPTION
    启动 start_multiprocess.php launcher（内部 spawn control / ingest /
    play / admin 子进程；record 按 -WithRecord 决定），等待全部端口就绪。
    返回 launcher 进程对象（附 BenchPorts 属性记录实际端口）。
#>
function Start-MpServers {
    param(
        [int]$IngestRtmpPort = 11935,
        [int]$IngestHttpPort = 11981,
        [int]$PlayRtmpPort = 11936,
        [int]$PlayHttpPort = 11980,
        [int]$AdminHttpPort = 18099,
        [int]$RegistryPort = 12090,
        [int]$IngestCount = 1,
        [int]$PlayCount = 1,
        [int]$InstancePortStep = 10,
        [switch]$WithRecord,
        [switch]$EnableJit,
        [string]$JitBuffer = '100M',
        [string]$MemoryLimit = '1G',
        [string]$LogDir = $null
    )

    $logDir = if ($LogDir) { $LogDir } else { (Join-Path $env:TEMP "mp_bench_$RegistryPort") }
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null

    # ServerConfig::loadFromEnv() 让每个子进程继承同一组端口
    if ($EnableJit) {
        $env:MP_JIT = '1'
        $env:MP_JIT_BUFFER = $JitBuffer
    } else {
        Remove-Item Env:MP_JIT -ErrorAction SilentlyContinue
        Remove-Item Env:MP_JIT_BUFFER -ErrorAction SilentlyContinue
    }
    $env:MP_INGEST_RTMP_PORT = $IngestRtmpPort
    $env:MP_INGEST_HTTP_PORT = $IngestHttpPort
    $env:MP_PLAY_RTMP_PORT = $PlayRtmpPort
    $env:MP_PLAY_HTTP_PORT = $PlayHttpPort
    $env:MP_ADMIN_HTTP_PORT = $AdminHttpPort
    $env:MP_REGISTRY_PORT = $RegistryPort
    $env:MP_REGISTRY_CLIENT_ADDR = "127.0.0.1:$RegistryPort"
    $env:MP_REGISTRY_DB = Join-Path $logDir 'registry.db'
    $env:MP_LOG_DIR = $logDir
    $env:MP_INGEST_COUNT = $IngestCount
    $env:MP_PLAY_COUNT = $PlayCount
    if ($WithRecord) {
        $env:MP_RECORD_COUNT = '1'
    } else {
        $env:MP_RECORD_COUNT = '0'
    }

    $extDir = Resolve-PhpExtensionDir
    $phpArgs = @('-d', "memory_limit=$MemoryLimit")
    if ($extDir) {
        $phpArgs += @('-d', "extension_dir=$extDir", '-d', 'extension=shmop', '-d', 'extension=sockets', '-d', 'extension=event')
    }
    $phpArgs += (Join-Path $script:RepoRoot 'start_multiprocess.php')

    $proc = Start-Process -FilePath 'php' -ArgumentList $phpArgs -PassThru -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $logDir 'launcher.out') `
        -RedirectStandardError (Join-Path $logDir 'launcher.err')

    # 等全部端口就绪（launcher 存活 + 各监听可达）
    # start_multiprocess.php 多实例端口 = 基准 + i*InstancePortStep
    $playRtmpPorts = @(for ($i = 0; $i -lt $PlayCount; $i++) { $PlayRtmpPort + $i * $InstancePortStep })
    $ingestRtmpPorts = @(for ($i = 0; $i -lt $IngestCount; $i++) { $IngestRtmpPort + $i * $InstancePortStep })
    $ports = @($RegistryPort, $AdminHttpPort) + $ingestRtmpPorts + $playRtmpPorts
    $deadline = (Get-Date).AddSeconds(30)
    while ((Get-Date) -lt $deadline) {
        if ($proc.HasExited) {
            $errText = Get-Content (Join-Path $logDir 'launcher.err') -Raw -ErrorAction SilentlyContinue
            throw "launcher 退出（exit $($proc.ExitCode)）：$errText"
        }
        $allUp = $true
        foreach ($port in $ports) {
            if (-not (Test-NetConnection -ComputerName 127.0.0.1 -Port $port -InformationLevel Quiet -WarningAction SilentlyContinue)) {
                $allUp = $false
                break
            }
        }
        if ($allUp) {
            $proc | Add-Member -Force -NotePropertyName 'BenchPorts' -NotePropertyValue ([pscustomobject]@{
                ingestRtmp = $IngestRtmpPort; ingestRtmpPorts = $ingestRtmpPorts
                ingestHttp = $IngestHttpPort; ingestCount = $IngestCount
                playRtmp = $PlayRtmpPort; playRtmpPorts = $playRtmpPorts
                playHttp = $PlayHttpPort; playCount = $PlayCount
                adminHttp = $AdminHttpPort; registry = $RegistryPort
                logDir = $logDir
            })
            return $proc
        }
        Start-Sleep -Milliseconds 400
    }
    Stop-MpServers -Launcher $proc
    throw "服务端口 30s 内未全部就绪（$($ports -join ','))"
}

<#
.SYNOPSIS
    停止多进程服务端（launcher + 全部 worker 子进程）。
#>
function Stop-MpServers {
    param([System.Diagnostics.Process]$Launcher)
    Get-MpServerProcs | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    if ($Launcher -and -not $Launcher.HasExited) {
        Stop-Process -Id $Launcher.Id -Force -ErrorAction SilentlyContinue
    }
    Start-Sleep -Seconds 2
}

<#
.SYNOPSIS
    找全部服务端 php 进程（launcher + worker 子进程）。
#>
function Get-MpServerProcs {
    # 只统计 worker 子进程（control/ingest/play/admin/record）；
    # launcher（start_multiprocess.php）只是监控循环，排除在外。
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -match 'worker\.php' }
}

<#
.SYNOPSIS
    采集服务端进程树的 CPU / 内存。
.DESCRIPTION
    CPU 是全部 php 服务进程的 TotalProcessorTime 之和，100% = 合计用满 1 核，
    多进程时可 > 100%。内存同时记 WorkingSet（含 shm 共享页重复计入）
    与 Commit（更接近真实占用）。
#>
function Get-MpTreeUsage {
    param(
        # 只统计指定角色的进程（如 play/ingest）；为空则统计全部 worker
        [string[]]$Roles = @()
    )
    $cpu = 0.0; $ws = 0; $commit = 0; $count = 0
    $procs = Get-MpServerProcs
    if ($Roles.Count -gt 0) {
        $procs = $procs | Where-Object {
            $matched = $false
            foreach ($r in $Roles) {
                if ($_.CommandLine -match "worker\.php.{0,10}$r") { $matched = $true; break }
            }
            $matched
        }
    }
    foreach ($p in $procs) {
        try {
            $proc = Get-Process -Id $p.ProcessId -ErrorAction Stop
            $cpu += $proc.TotalProcessorTime.TotalSeconds
            $ws += $proc.WorkingSet64
            $commit += $proc.PagedMemorySize64
            $count++
        } catch { }
    }
    [pscustomobject]@{
        CpuSeconds = $cpu
        WsMB = [math]::Round($ws / 1MB, 1)
        CommitMB = [math]::Round($commit / 1MB, 1)
        Count = $count
    }
}

<#
.SYNOPSIS
    采集指定角色各进程的单进程 CPU 百分比（相对某基线）。
.DESCRIPTION
    返回 @{ Pid; Role; Pct } 列表；Pct = (当前累计CPU - 基线累计CPU) / 墙钟 × 100。
    用于"每个 play 进程独立判定饱和"：某进程超过阈值就不再给它加压。
#>
function Get-RoleProcCpu {
    param(
        [Parameter(Mandatory)][string]$Role,
        [Parameter(Mandatory)][hashtable]$Baseline,   # pid => CPU秒
        [double]$WindowSec = 3.0
    )
    $result = @()
    $procs = Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -match "worker\.php.{0,10}$Role" }
    foreach ($p in $procs) {
        try {
            $proc = Get-Process -Id $p.ProcessId -ErrorAction Stop
            $c0 = if ($Baseline.ContainsKey($p.ProcessId)) { $Baseline[$p.ProcessId] } else { $proc.TotalProcessorTime.TotalSeconds }
            $pct = [math]::Round(($proc.TotalProcessorTime.TotalSeconds - $c0) / $WindowSec * 100, 1)
            $result += [pscustomobject]@{ Pid = $p.ProcessId; Role = $Role; Pct = $pct; CpuSeconds = $proc.TotalProcessorTime.TotalSeconds }
        } catch { }
    }
    return $result
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
        [int]$RegistryPort = 12090,
        [string]$MemoryLimit = '4G',
        [string]$LogPath = $null
    )

    $phpArgs = @('-d', "memory_limit=$MemoryLimit")
    # shmop 用于帧总线（改版后推流写入 shm），两种事件循环都需要。
    # php.ini 里 extension_dir 常写成相对路径 "ext"，从其他工作目录启动时
    # 会找不到 dll（报 Unable to load dynamic library）。这里解析成绝对路径再传。
    $extDir = Resolve-PhpExtensionDir
    if ($extDir) {
        $phpArgs += @('-d', "extension_dir=$extDir")
    }
    $phpArgs += @('-d', 'extension=shmop')
    if ($EventLoop -eq 'event') {
        # 注意：php_event.dll 直接链接 php_sockets.dll，必须先加载 sockets 再加载 event，
        # 否则 event 会加载失败（Class "EventBase" not found）。
        # 这里的 sockets 是 event 扩展的依赖，与业务是否使用 UDP 组播无关。
        $phpArgs += @('-d', 'extension=sockets', '-d', 'extension=event')
    }
    # 改版后推流强制依赖 registry（onPublish → publish.req），必须另起 control。
    # Windows 下 Workerman 不支持一个脚本多个 Worker，control 只能独立进程。
    $dbFile = Join-Path $env:TEMP "bench_registry_$RtmpPort.db"
    $ctrlArgs = @('-d', "memory_limit=$MemoryLimit")
    if ($extDir) { $ctrlArgs += @('-d', "extension_dir=$extDir") }
    $ctrlArgs += @(
        (Join-Path $script:RepoRoot 'worker.php'), 'control',
        $RegistryPort
    )
    # dbFile 通过环境变量传（worker.php control 从 MP_REGISTRY_DB 读取），
    # 不能作为 argv 传——Workerman Windows 会把存在的 argv 文件当 worker 脚本 fork。
    $oldDbEnv = $env:MP_REGISTRY_DB
    $env:MP_REGISTRY_DB = $dbFile
    $ctrlProc = Start-Process -FilePath 'php' -ArgumentList $ctrlArgs -PassThru -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $env:TEMP "bench_ctrl_$RtmpPort.out") `
        -RedirectStandardError (Join-Path $env:TEMP "bench_ctrl_$RtmpPort.err")
    $env:MP_REGISTRY_DB = $oldDbEnv

    # 等 control 端口就绪
    $deadline = (Get-Date).AddSeconds(15)
    while ((Get-Date) -lt $deadline) {
        if ($ctrlProc.HasExited) {
            $errFile = Join-Path $env:TEMP "bench_ctrl_$RtmpPort.err"
            $errText = if (Test-Path $errFile) { Get-Content $errFile -Raw } else { '' }
            throw "control 启动失败（退出码 $($ctrlProc.ExitCode)）：$errText"
        }
        if (Test-NetConnection -ComputerName 127.0.0.1 -Port $RegistryPort -InformationLevel Quiet -WarningAction SilentlyContinue) {
            break
        }
        Start-Sleep -Milliseconds 300
    }

    $phpArgs += (Join-Path $script:BenchRoot 'lib\server_bench.php')
    $phpArgs += @($EventLoop, $RtmpPort, $HttpPort, "127.0.0.1:$RegistryPort")

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
            Stop-Process -Id $ctrlProc.Id -Force -ErrorAction SilentlyContinue
            throw "服务端启动失败（退出码 $($proc.ExitCode)）：$errText"
        }
        if (Test-NetConnection -ComputerName 127.0.0.1 -Port $RtmpPort -InformationLevel Quiet -WarningAction SilentlyContinue) {
            $proc | Add-Member -Force -NotePropertyName 'BenchControlProcess' -NotePropertyValue $ctrlProc
            return $proc
        }
        Start-Sleep -Milliseconds 300
    }
    Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
    Stop-Process -Id $ctrlProc.Id -Force -ErrorAction SilentlyContinue
    throw "服务端端口 $RtmpPort 在 15s 内未就绪"
}

<#
.SYNOPSIS
    停止服务端进程树。
#>
function Stop-BenchServer {
    param([System.Diagnostics.Process]$Process)
    if ($Process) {
        if (-not $Process.HasExited) {
            Stop-Process -Id $Process.Id -Force -ErrorAction SilentlyContinue
        }
        $ctrl = $Process.BenchControlProcess
        if ($ctrl -and -not $ctrl.HasExited) {
            Stop-Process -Id $ctrl.Id -Force -ErrorAction SilentlyContinue
        }
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
