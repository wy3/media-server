# 附录：安全软件兼容性

> ⚠️ **本文记录一次真实事故**：技术可行的方案被 Windows Defender 判定为后门，
> 文件内容被静默擦除。这不是误报可以忽略的问题——它直接否决了一条架构路线。

## 1. 事故经过

在验证「跨进程 socket 移交」方案时，编写的测试脚本被 Windows Defender 检测：

```
ThreatName : VirTool:PHP/Remeshelsz.A
SeverityID : 5（严重）
检测次数    : 8
动作       : 已处置成功
```

**后果**：文件内容被擦除为空格，大小不变。

| 文件 | 原始大小 | 擦除后 |
|---|---|---|
| `tools/verify-socket-transfer.php` | 10265 B | 10256 个空格（99.9% 空白） |
| `tools/_gen.py` | 9481 B | 9438 个空格（99.5% 空白） |

**排查时的误导性现象**：

1. 文件看起来"创建成功"，但运行无任何输出（因为内容全是空格）
2. 一度误判为「写入工具存在缺陷」，反复更换写入方式，浪费多轮排查
3. PowerShell here-string 报语法错误——实际是 **AMSI 拦截**，不是语法问题

```
AMSI 拦截记录：
2026/11/25 23:51:02  amsi:_\...\PowerShell\7\pwsh.exe
2026/11/25 23:51:09  amsi:_\...\PowerShell\7\pwsh.exe
2026/11/25 23:53:27  amsi:_\...\PowerShell\7\pwsh.exe
2026/11/25 23:53:35  amsi:_\...\PowerShell\7\pwsh.exe
```

## 2. 触发的特征组合

```php
proc_open(PHP_BINARY . ' ... child')          // 自我复制、拉子进程
socket_wsaprotocol_info_export($sock, $pid)   // 句柄跨进程传递
@socket_read(...)                             // 大量 @ 抑制错误
// + 二进制 socket 读写、无明显业务语义
```

这在静态特征上与 **PHP 反弹 shell / 远程控制工具**高度重合。

`VirTool:PHP/Remeshelsz.A` 的 `Remeshel` 即 "remote shell"。

## 3. 对架构的约束

| API | 风险 | 是否可用于生产 |
|---|---|---|
| `socket_wsaprotocol_info_export()` / `_import()` | **高**（已实测被拦） | ❌ |
| `socket_sendmsg()` + `SCM_RIGHTS` | 同类检测 | ❌ |
| `proc_open` + 句柄传递组合 | **高** | ❌ |
| 普通 `stream_socket_client` / `stream_socket_server` | 无 | ✅ |
| `shmop_open()` / `shmop_read()` / `shmop_write()` | 无 | ✅ |

**这不是"加白名单"能解决的问题**：

- 客户服务器若启用同类 EDR，文件会被静默擦除 → 服务起不来且无明显报错
- 金融/政务合规环境直接不可用
- 部署摩擦显著增加

## 4. 开发时的注意事项

在 Windows 上开发时，**即使写测试脚本也要注意**：

- 避免 `proc_open` + 句柄传递的组合
- 若必须验证此类能力，用最小化脚本，且事后检查文件是否被擦除
- 文件"创建成功但运行无输出"时，**先查 Windows 安全中心 → 保护历史**，不要先怀疑工具

### 快速检查文件是否被擦除

```powershell
$b = [System.IO.File]::ReadAllBytes("path\to\file.php")
$sp = ($b | Where-Object { $_ -eq 0x20 }).Count
"size=$($b.Length) spaces=$sp blank=$([math]::Round($sp/$b.Length*100,1))%"
```

正常 PHP 文件的空白率通常在 10~20%（缩进与空行）。若超过 90%，基本可判定被擦除。

### 查询最近的拦截记录

```powershell
Get-MpThreatDetection | Sort-Object InitialDetectionTime -Descending |
  Select-Object -First 10 InitialDetectionTime, ThreatID, Resources

Get-MpThreat | Select-Object ThreatID, ThreatName, SeverityID
```

## 5. 采用方案为什么安全

最终采用的**共享内存扇出**方案，代码特征：

```php
$shm = shmop_open($key, 'c', 0644, $size);   // 创建内存段
shmop_write($shm, $header . $payload, $offset);  // 写
shmop_read($shm, $offset, $length);              // 读
```

全是普通内存操作，**没有句柄传递、没有子进程自我复制**，不会触发同类检测。

## 6. 给贡献者的要求

在 [CONTRIBUTING.md](../../CONTRIBUTING.md) 中已加入对应约束。核心是：

- 不要在生产代码中使用 fd 移交类 API
- 跨进程通讯优先用普通 stream IO 或共享内存
- 遇到"文件内容变空白"先查杀软日志
