# 生成基准测试样本。
#
# 对齐文章规格：1920x1080, 30fps, 4096 kb/s, H.264 + AAC 128k
# 用 -c copy 推流时，样本本身的质量决定测试负载。

param(
    [string]$OutputDir = $null
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')

if (-not $OutputDir) { $OutputDir = $script:SamplesDir }
New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

$samples = @(
    @{
        Name = '1080p30_4m.flv'
        Desc = '1920x1080 30fps 4096kb/s H.264+AAC（对齐文章）'
        Args = @(
            '-f', 'lavfi', '-i', 'testsrc2=size=1920x1080:rate=30'
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100'
            '-c:v', 'libx264', '-preset', 'veryfast', '-profile:v', 'high'
            '-b:v', '4096k', '-maxrate', '4096k', '-bufsize', '8192k'
            '-g', '60', '-keyint_min', '60', '-sc_threshold', '0'
            '-pix_fmt', 'yuv420p'
            '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2'
        )
        Duration = 30
    }
)

foreach ($s in $samples) {
    $out = Join-Path $OutputDir $s.Name
    Write-Host "生成 $($s.Name) — $($s.Desc)" -ForegroundColor Cyan
    $args = @('-y', '-hide_banner', '-loglevel', 'error') + $s.Args + @('-t', $s.Duration, '-f', 'flv', $out)
    & ffmpeg @args
    if ($LASTEXITCODE -ne 0) { throw "ffmpeg 失败: $($s.Name)" }
    $size = (Get-Item $out).Length / 1MB
    Write-Host ("  完成: {0:N2} MB" -f $size) -ForegroundColor Green
}

Write-Host ""
Write-Host "样本目录: $OutputDir" -ForegroundColor Green
Get-ChildItem $OutputDir -Filter '*.flv' | ForEach-Object {
    Write-Host ("  {0,-24} {1,8:N2} MB" -f $_.Name, ($_.Length / 1MB))
}
