# PHP Media Server

简体中文 | [English](README_EN.md)

[![CI](https://github.com/wy3/media-server/actions/workflows/ci.yml/badge.svg)](https://github.com/wy3/media-server/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Latest Release](https://img.shields.io/github/v/release/wy3/media-server?color=green)](https://github.com/wy3/media-server/releases)

> 基于 [Workerman](https://www.workerman.net) 实现的纯 PHP 流媒体服务器：RTMP / HTTP-FLV 推流接入，RTMP / HTTP-FLV / WS-FLV 播放，fMP4 录像与指定时间回放，内置管理后台。

## 目录

- [功能特性](#功能特性)
- [环境要求](#环境要求)
- [快速开始](#快速开始)
- [配置](#配置)
- [录像与回放](#录像与回放)
- [管理 API](#管理-api)
- [测试](#测试)
- [目录结构](#目录结构)
- [已知限制](#已知限制)
- [致谢](#致谢)

## 功能特性

### 直播

- 推流接入
  - RTMP：`rtmp://<host>:1935/<app>/<stream>`
  - HTTP-FLV：`POST http://<host>:18080/<app>/<stream>`，请求体为 FLV 数据
- 播放分发
  - RTMP：`rtmp://<host>:1935/<app>/<stream>`
  - HTTP-FLV：`http://<host>:18080/<app>/<stream>.flv`
  - WS-FLV：`ws://<host>:18080/<app>/<stream>.flv`
  - 支持查询参数过滤：`disableAudio`、`disableVideo`、`disableGop`
- 编解码支持：H.264 视频、AAC 音频

### 录像

- H.264/AAC 实时封装为 fMP4（`ftyp + moov + moof/mdat`）
- 按关键帧边界切分片（`fragmentDurationMs`，默认 2000 ms）
- 按关键帧边界轮转分段（`segmentDurationMs`，默认 60000 ms），每段一个独立 `.mp4`
- 纯视频流、纯音频流、音视频流均可录制
- 每个分段自带完整 `moov`，可单独播放，也可被 `ffprobe` / 浏览器直接解析
- 分段索引存于 SQLite（`recordings.db`，WAL 模式），支持按推流路径检索

### 指定时间回放

- `GET /playback/{publishPath}?start={毫秒}&end={毫秒}`
- `start` / `end` 为毫秒墙钟时间戳；省略 `end` 表示播放到末尾
- 服务端跨分段切片并重新封装为新的 fMP4，以 `video/mp4` 流式返回
- 视频从 `start` 之前最近的关键帧起播，音频起点跟随视频关键帧，保证音画时长一致
- 分段边界按解码时间轴连续续接
- `mdat` 数据按块从源分段文件流式发送，内存占用与回放时长无关

### 管理后台

- 入口：`http://<host>:18080/admin/`
- Token 登录鉴权（进程内 token，默认有效期 24 小时，服务重启后失效）
- 面板：仪表盘、直播管理、录像管理（含指定时间回放）、系统设置

### E2E 测试

- 数据驱动用例集，覆盖 推流 → 录像 → 回放 → 解码校验 全链路

## 环境要求

- PHP >= 8.2（已在 PHP 8.4 验证），扩展：`json`
- Composer
- 可选：`event` / `ev` 扩展（Linux 下提升事件循环性能）
- 运行 E2E 测试需要 `ffmpeg` / `ffprobe`

## 快速开始

```bash
composer install
php start.php start
```

默认监听端口：

| 服务 | 地址 |
|---|---|
| RTMP | `0.0.0.0:1935` |
| HTTP / WebSocket | `127.0.0.1:18080` |

> HTTP 服务默认绑定 `127.0.0.1`。若需外部访问，请把 `start.php` 中的监听地址改为 `\\MediaServer\\Http\\ExtHttpProtocol://0.0.0.0:18080`。

### 推流

```bash
ffmpeg -re -stream_loop 1 -i file.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1/live/stream
```

### 播放

| 协议 | 地址 |
|---|---|
| RTMP | `rtmp://127.0.0.1/live/stream` |
| HTTP-FLV | `http://127.0.0.1:18080/live/stream.flv` |
| WS-FLV | `ws://127.0.0.1:18080/live/stream.flv` |

Web 播放可配合 [mpegts.js](https://github.com/xqq/mpegts.js)、[Aliplayer](http://player.alicdn.com/aliplayer/setting/setting.html)；桌面端可用 `vlc` / `ffplay`。

### 管理后台

浏览器打开 `http://127.0.0.1:18080/admin/`，默认账号在 `start.php` 中配置。

## 配置

编辑 `start.php`：

```php
// 录像
\MediaServer\Recorder\RecorderManager::$enabled = true;                     // 录像开关
\MediaServer\Recorder\RecorderManager::$recordPath = __DIR__ . '/record';   // 录像根目录
\MediaServer\Recorder\RecorderManager::$fragmentDurationMs = 2000;          // fMP4 分片时长
\MediaServer\Recorder\RecorderManager::$segmentDurationMs = 60000;          // 单个分段文件时长

// 管理后台
\MediaServer\Admin\AdminAuth::$username = 'admin';
\MediaServer\Admin\AdminAuth::$password = 'admin123';
\MediaServer\Admin\AdminAuth::$tokenTtl = 86400;                            // token 有效期（秒）
```

生产环境请务必修改默认账号密码，并将管理后台与回放接口置于反向代理的访问控制之后。

## 录像与回放

录像目录结构：

```
record/
├── recordings.db           # SQLite 索引（streams + segments）
└── live_stream/            # 每个推流路径一个目录（"/" 归一为 "_"）
    ├── xxx.mp4
    └── yyy.mp4
```

- 推流路径会安全化为目录名，例如 `/live/stream` → `live_stream`
- `recordings.db` 保存每段的起止墙钟时间、时长与编码元数据
- 回放请求在服务端完成切片与重封装：

```http
GET /playback/live/stream?start=1788165249473&end=1788165554005
```

响应为带 `Content-Length` 的 `video/mp4`，可直接赋给 `<video src>`。

## 管理 API

所有管理调用统一走一个入口：

```http
POST /api
Content-Type: application/json
X-Auth-Token: <token>

{"name": "listPushStream", "args": []}
```

除 `login` 外的调用都必须携带有效 token（`X-Auth-Token` 头、`Authorization: Bearer <token>`，或 `token` 查询参数），否则返回 401。

| name | 说明 | args |
|---|---|---|
| `login` | 登录，返回 `{token, expires}` | `[username, password]` |
| `logout` | 使当前 token 立即失效 | `[token]` |
| `getServerStats` | 运行时长、推流/播放数、内存占用 | `[]` |
| `listPushStream` | 直播流列表，可按路径过滤 | `[path?]` |
| `listRecordFiles` | 录像分段列表，可按路径过滤 | `[path?]` |
| `getPlayStreamCount` | 各路径的播放连接数 | `[path?]` |
| `getSettings` | 当前配置（不含敏感信息） | `[]` |

只读调用也支持 `GET /api?name=...&args=[...]`。

## 测试

仓库提供两套测试：

**冒烟测试**（不启动服务、不需要 ffmpeg，适合 CI）：

```bash
php tests/smoke.php
```

覆盖自动加载、MP4 box 字节结构、RTMP 握手回显、AVC SPS 解析、录像路径归一与 SQLite 索引读写。

**回放链路 E2E 测试**（需服务器已启动，且 `ffmpeg` / `ffprobe` 可用）：

```bash
php tests/playback_e2e.php            # 运行全部用例
php tests/playback_e2e.php aonly      # 只运行名称匹配的用例
```

用例为数据驱动，新增场景只需在 `tests/playback_e2e.php` 的 `$CASES` 中加一项。每个用例会清理旧录像、用 ffmpeg 推流、等待分段落盘、发起回放请求，再用 `ffprobe` 校验轨道与时长、用 `ffmpeg` 做全量解码校验。

CI 在 GitHub Actions 上对 PHP 8.2 / 8.3 / 8.4 执行语法检查与冒烟测试。

## 目录结构

```
src/
├── Admin/        管理后台鉴权（token）
├── Flv/          FLV 发布/播放流
├── Http/         HTTP 服务器（直播/回放/后台/静态文件路由）
├── MediaReader/  FLV 标签解析（AVC/AAC 包）
├── PushServer/   推流/播放流接口
├── Recorder/     fMP4 封装与录像、回放切片、MP4 解析、SQLite 索引
├── Rtmp/         RTMP 协议处理
└── Utils/        二进制/HTTP/WS 流工具
public/admin/     管理后台 SPA（原生 JS）
packages/         vendored SabreAMF
tests/            回放链路 E2E 测试
record/           录像输出目录（已 git 忽略）
```

## 已知限制

- 仅支持 H.264 + AAC 编码流，其它编码可能无法正常工作
- 单进程模型，不支持多进程部署
- 推流过程中增删轨道未做处理（中途切换轨道会损坏当前分段）
- 回放请求的样本表在内存中构建，超长时间范围建议限制 `end`，避免内存与耗时膨胀
- 部分播放器可能存在加载缓慢或播放异常
- 特定环境下可能出现内存溢出，生产环境请配合进程守护与监控

## 致谢

- [Workerman](https://www.workerman.net)
- [Node-Media-Server](https://github.com/illuspas/Node-Media-Server)
- [sabreamf](https://code.google.com/archive/p/sabreamf/)

## License

[MIT](LICENSE)
   
