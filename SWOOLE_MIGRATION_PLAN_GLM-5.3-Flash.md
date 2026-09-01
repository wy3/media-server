# Workerman → Swoole 改造方案

> 制定：2026-09-01 ｜ 分支：`feature/swoole-migration`（自 develop @ 27ea41d）
> 原则：**分阶段、每阶段可独立验证、业务协议逻辑（RTMP/FLV/MP4）尽量不动**

---

## 〇、先说两个必须知道的前提

### 1. Swoole 不支持 Windows —— 当前开发机跑不了

Swoole 是纯 Linux/macOS 扩展，**没有 Windows 版本**。当前开发环境是 Windows 11 + 便携 PHP，迁移后运行/调试环境三选一：

| 方案 | 说明 | 建议 |
|---|---|---|
| **WSL2** | Windows 内直接跑 Ubuntu + PHP + Swoole，文件可共享 | ⭐ 推荐，本地开发体验最好 |
| Docker | `phpswoole/swoole` 官方镜像，环境最标准 | CI/部署首选 |
| 纯 Linux 服务器 | 生产部署形态 | 上线最终形态 |

**这一项不定，后面任何阶段都无法验证。** 需要先确认用哪个。

### 2. 运行模型的关键差异

| 维度 | Workerman 4.1（现状） | Swoole 5.x（目标） |
|---|---|---|
| 事件回调 | `onMessage($connection, $data)`，连接是 `TcpConnection` 对象 | `onReceive($server, $fd, $reactorId, $data)`，连接以 **fd** 标识 |
| 连接挂状态 | 直接往 `$connection` 上挂属性/回调（本项目大量使用） | 无连接对象，需 **fd → 上下文对象** 的映射表 |
| HTTP | 自实现协议类（本项目还覆写了 `ExtHttpProtocol`） | `Swoole\Http\Server` 原生解析 + WebSocket 自动升级 |
| 背压 | `$connection->onBufferFull / onBufferDrain` | TCP：`$server->onBufferFull / onBufferEmpty`（按 fd）；HTTP Response 需借助 fd 映射 |
| 定时器 | `Timer::add() / Timer::del()` | `Swoole\Timer::tick/after/clear`（API 几乎一一对应） |
| 进程模型 | 单进程（`start.php` 只 runAll 一个 Worker） | 建议继续**单 worker**（`worker_num=1`），保住静态共享状态语义 |
| 并发原语 | 纯回调 + React Promise | 协程（可选，不强求第一步就用） |

好消息：**RTMP 侧是原始 TCP 字节流进回调**，Workerman 和 Swoole 在这一点上语义相同（都是"凑够一段就喂给你"），`RtmpStream` 的分片重组逻辑完全不用动；差异全部集中在连接对象和 HTTP 层。

---

## 一、现状耦合清单（全部 14 个文件的量化结论）

| 耦合点 | 文件 | 改造难度 |
|---|---|---|
| 启动与 Worker 装配 | `start.php` | 低 |
| **HTTP 路由总入口（extends Worker）** | `Http/HttpWMServer.php` | **高** —— 整个类要重写为 Swoole onRequest |
| 自定义 HTTP/WS 协议开关 | `Http/ExtHttpProtocol.php` | **直接删除** —— Swoole 原生支持 HTTP+WS 升级，整个类不复存在 |
| RTMP 连接字节流封装 | `Utils/WMBufferStream.php` | 中 —— 保接口换实现 |
| HTTP chunked 响应封装 | `Utils/WMHttpChunkStream.php` | 中 —— 改用 `$response->write()/end()` |
| WS-FLV 响应封装 | `Utils/WMWsChunkStream.php` | 中 —— 同上 |
| 回放背压状态机 | `Recorder/PlaybackServer.php` | **高** —— onBufferFull/onBufferDrain/onClose 语义重映射 |
| Timer ×4 处 | `Flv/FlvPublisherStream`、`Rtmp/RtmpStream`、`RtmpTrait`、`RtmpVideoHandlerTrait`、`RtmpInvokeHandlerTrait` | **极低** —— API 近乎同构 |
| React Promise/Stream 引用 | `FlvPublisherStream`、`RtmpInvokeHandlerTrait`、`HttpWMServer` | 低 —— 先保留（与 Workerman 无关），协程化阶段再评估移除 |

不受影响（零改动）：`packages/SabreAMF`（纯 AMF 库）、`Mp4Muxer/Mp4Parser/RecordIndex`（纯逻辑/IO）、`public/` 前端、`Rtmp/` 全部协议 trait（除 Timer 行）。

---

## 二、改造策略：先立"端口"，再换"实现"

核心思路是利用现有的缝隙类做抽象，让协议层代码不知道底下是哪个运行时：

```
                 ┌─ Workerman 实现（现保留，可回退）
RTMP 逻辑层 ──依赖──▶ ConnectionStream 接口 ◀──┤
RtmpStream 等        ChunkWriter 接口         └─ Swoole 实现（新增）
```

新增 4 个接口（放 `src/Contracts/`）：

| 接口 | 方法 | 对应现状 |
|---|---|---|
| `ConnectionStreamInterface` | `write/end/close/onClose/onBufferFull/onBufferEmpty` | `TcpConnection` 被用到的面 |
| `TimerInterface` | `add/del` | `Timer::` 的 5 处调用 |
| `HttpRequestInterface` / `HttpResponseInterface` | 覆盖 `Request/Response` 被用到的方法（method/path/query/cookie/header/write/end） | `HttpWMServer`、`PlaybackServer` |
| `ConnectionFactoryInterface` | 按 fd 创建/查找连接上下文 | 替代"往 connection 挂属性"的习惯 |

> 已存在的 `WMChunkStreamInterface` 是现成的缝，HTTP/WS 两个 Chunk 流类只需要各出一个 Swoole 版实现。

---

## 三、分阶段执行计划

### Phase 0 —— 环境搭建（前置阻塞项）
- [ ] 确定 WSL2 / Docker（见"前提 1"）
- [ ] PHP 8.2+ `ext-swoole`（5.x）、`ext-openssl`、`ext-sqlite3`
- [ ] 冒烟脚本：Swoole TCP echo + Http\Server + Timer + WebSocket 升级，验证四类原语可用
- **验收**：四原语 demo 全部跑通

### Phase 1 —— 抽象层落地（不改行为，Workerman 仍可用）
- [ ] 新增 `src/Contracts/` 四接口（按上面清单）
- [ ] `WMBufferStream / WMHttpChunkStream / WMWsChunkStream` 改为实现接口（签名不动）
- [ ] 5 处 `Timer::` 调用改走 `TimerInterface`（默认实现包一层 Workerman）
- [ ] 全量回归：ffmpeg 推流 → FLV 拉流 / 回放 / 管理后台 / 录像
- **验收**：`git diff` 不含行为变化，现有 e2e 全绿

### Phase 2 —— RTMP/TCP 层切 Swoole
- [ ] 新增 `SwooleConnectionStream`（fd + 上下文映射，替代挂属性）与 `SwooleTimer`
- [ ] 重写 `start.php`：`Swoole\Server(1935)` 的 `onConnect/onReceive/onClose` → 创建/销毁 `RtmpStream`
- [ ] **关键语义核对**：`onClose` 里 `RtmpTrait::stop()` 的资源释放（这是 B2 类问题的迁移点，别把已修的 P0 回归了）
- **验收**：ffmpeg RTMP 推流 + 录像落盘 + `recordings.db` 索引完整；断推无句柄残留

### Phase 3 —— HTTP/WS 层切 Swoole
- [ ] 新增 `SwooleHttpServer`（不 extends 任何 Worker），路由逻辑从 `HttpWMServer` 平移
- [ ] 删除 `ExtHttpProtocol`；WS-FLV 走 Swoole 原生握手（`onHandShake/onMessage`）
- [ ] `SwooleHttpChunkStream`：`$response->write()` + detach，映射背压事件
- [ ] 管理后台静态文件 + API 路由平移
- **验收**：浏览器 FLV 拉流（http + ws 两种）、管理后台全功能

### Phase 4 —— 回放与背压重映射
- [ ] `PlaybackServer` 的 `sendParts` 状态机保持算法不变，把 `onBufferFull/onBufferDrain` 换成 `$server->onBufferFull/onBufferEmpty`（按 fd 关联，注意沿用 Phase 2 的 fd 映射表）
- [ ] 顺手解决审查报告中的 B1/B2（重入队列 + onClose 清理）—— 换运行时时改这里成本最低
- **验收**：大范围回放（跨多段、含 keep-alive 重入）字节级与 Workerman 版输出一致（md5 对比）

### Phase 5 —— 清理与协程化（可选增强）
- [ ] 移除 `workerman/workerman` 依赖与 `WM*` 旧实现
- [ ] 评估协程收益：`PlaybackServer` 的回调状态机可简化为协程 + Channel；SQLite/文件 IO 开 runtime hook（注意 **pdo_sqlite 不在 Swoole hook 支持列表**，索引写入保持同步短事务即可，段文件写入走协程 + 文件 hook）
- [ ] React Promise 去留评估（publish 流程 PromiseInterface 可直接改同步返回）
- **验收**：composer 无 workerman；压测对比（并发拉流数、内存曲线）

### 不建议做的
- ❌ 一步到位全重写、两条路径并行长期维护 —— 项目规模小，Phase 1 的接口抽象就是全部保险
- ❌ 多 worker / task 进程拆分 —— 现有静态流表（`MediaServer` 的 stream 注册）全在进程内存，拆进程要引入共享结构，收益配不上复杂度
- ❌ 迁移期间顺手改协议行为 —— 回归对比必须以 Workerman 版输出为基准

---

## 四、风险清单

| 风险 | 等级 | 应对 |
|---|---|---|
| Swoole 无 Windows 版，开发流程变化 | 🔴 阻塞 | Phase 0 先行，WSL2 优先 |
| HTTP 背压语义差异导致 FLV 分发卡顿/丢帧 | 🟠 高 | Phase 4 单独验收 + md5 对比 |
| fd 生命周期与连接上下文泄漏（对应当前 B2/B4） | 🟠 高 | 迁移时以 `onClose` 为唯一清理点，配压测长跑验证 |
| `onReceive` 数据边界与 Workerman 协议层不等价（如粘包处理位置变化） | 🟡 中 | RTMP 侧本就自己处理粘包，仅冒烟验证；HTTP 侧交给 Swoole |
| Swoole hook 不支持 pdo_sqlite，索引写入阻塞事件循环 | 🟡 中 | 保持短事务（现状已如此），必要时移 task 进程 |
| PHP 9 动态属性问题（B3）在迁移中残留 | 🟡 中 | Phase 1 接口化时一并消灭挂属性写法 |

---

## 五、工作量粗估（按阶段，非承诺）

| 阶段 | 相对规模 |
|---|---|
| Phase 0 环境 | 半天（视 WSL2/Docker 熟悉度） |
| Phase 1 抽象层 | 1~2 天 |
| Phase 2 RTMP/TCP | 1~2 天 |
| Phase 3 HTTP/WS | 2~3 天（最大头，HttpWMServer 重写） |
| Phase 4 回放背压 | 1~2 天 |
| Phase 5 清理/协程 | 按需，可选 |

总计约 **1~2 周的专注工作量**。建议按 Phase 逐个提交，每个 Phase 都停在"Workerman 版可作为对照基准"的可验证状态。
