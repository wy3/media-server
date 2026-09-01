# PHP Media Server

简体中文 | [English](README_EN.md)

> 基于 [Workerman](https://www.workerman.net) 实现的纯 PHP 流媒体服务器：RTMP 接入、HTTP-FLV / WS-FLV 播放、fMP4 录像与指定时间回放、内置管理后台。

## 功能特性

- **直播**
  - RTMP（`rtmp://`）与 HTTP-FLV（`http://…/xxx.flv`）推流接入
  - RTMP / HTTP-FLV / WS-FLV 播放分发
- **录像**
  - H.264/AAC 实时封装为 fMP4（`ftyp + moov + moof/mdat`），分段文件可直接被 ffprobe / 浏览器解析
  - 按 2 秒分片（关键帧对齐）、60 秒分段自动轮转
  - 纯视频流、纯音频流、音视频流均可录制
  - 分段索引入 SQLite（`recordings.db`），支持按推流路径检索
- **指定时间回放**
  - `GET /playback/{publishPath}?start={毫秒}&end={毫秒}`，服务端跨分段切片并重新封装为 fMP4 流式返回
  - 视频从请求起点前最近的关键帧对齐，音频起点跟随视频起点，保证音画时长一致
  - 分段边界按解码时间轴连续续接；发送侧采用分块背压流式输出，内存占用与回放时长无关
- **管理后台**（`/admin`）
  - 登录鉴权（token）、服务器状态 / 直播 / 录像 / 设置面板
  - 录像列表一键回放（内嵌播放器）
- **E2E 测试**
  - 数据驱动用例集：推流 → 录像 → 回放 → 解码全链路自动校验

## 环境要求

- PHP >= 8.2（已在 PHP 8.4 验证），扩展：`json`
- Composer
- 可选：`event` / `ev` 扩展（Linux 下提升事件循环性能）
- 测试需要 `ffmpeg` / `ffprobe`

## 快速开始

```bash
composer install
php start.php start        # RTMP 监听 1935，HTTP 监听 18080
```

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

Web 播放可配合 [Aliplayer](http://player.alicdn.com/aliplayer/setting/setting.html)、[mpegts.js](https://github.com/xqq/mpegts.js)；桌面端可用 `vlc` / `ffplay`。

### 管理后台

浏览器打开 `http://127.0.0.1:18080/admin/`，默认账号在 `start.php` 中配置（见下）。

## 配置

编辑 `start.php`：

```php
\MediaServer\Recorder\RecorderManager::$enabled = true;          // 录像开关
\MediaServer\Recorder\RecorderManager::$recordPath = __DIR__ . '/record'; // 录像目录
\MediaServer\Recorder\RecorderManager::$fragmentDurationMs = 2000;  // fMP4 分片时长
\MediaServer\Recorder\RecorderManager::$segmentDurationMs = 60000;  // 单个分段文件时长
\MediaServer\Admin\AdminAuth::$username = 'admin';               // 管理后台账号
\MediaServer\Admin\AdminAuth::$password = 'admin123';            // 管理后台密码
```

生产环境请务必修改默认账号密码，并将管理后台与回放接口置于反向代理的访问控制之后。

## 录像与回放

- 录像写入 `record/<推流路径>/`（路径中的 `/` 归一为 `_`），每个分段为独立 `.mp4`，文件头自带 `moov`，可直接播放
- 分段索引存于 `record/recordings.db`（SQLite，WAL），含每段起止墙钟时间与编码元数据
- 回放请求在服务端完成切片与重封装，按解码时间轴连续续接各分段，以流式响应返回：

```
GET /playback/live/stream?start=1788165249473&end=1788165554005
```

`start`/`end` 为毫秒墙钟时间戳；省略 `end` 表示播放到末尾。响应为 `video/mp4`，可直接赋给 `<video src>`。

## 管理 API

`POST /api`，JSON 体 `{"name": "...", "args": [...]}`，除 `login` 外均需携带登录返回的 token（请求头 `X-Auth-Token`）：

| name | 说明 |
|---|---|
| `login` | 登录，返回 token |
| `logout` | 退出登录 |
| `getServerStats` | 服务器状态 |
| `listPushStream` | 直播流列表 |
| `listRecordFiles` | 录像分段列表 |
| `getPlayStreamCount` | 播放连接数 |
| `getSettings` | 服务器配置 |

## E2E 测试

```bash
php tests/playback_e2e.php            # 运行全部用例
php tests/playback_e2e.php aonly      # 只运行名称匹配的用例
```

用例为数据驱动（在 `tests/playback_e2e.php` 的 `$CASES` 中增删即可），覆盖音视频 / 纯视频 / 纯音频 / 跨分段场景：自动 ffmpeg 推流、等待分段落盘、回放请求、ffprobe 轨道与时长校验、ffmpeg 全量解码校验。

## 目录结构

```
src/
├── Admin/        管理后台鉴权
├── Flv/          FLV 发布流
├── Http/         HTTP 服务器（直播/回放/后台/静态文件路由）
├── MediaReader/  FLV 标签解析（AVC/AAC 包）
├── PushServer/   推流接口
├── Recorder/     fMP4 录像器、回放切片、MP4 解析/封装、SQLite 索引
├── Rtmp/         RTMP 协议处理
└── Utils/        二进制流等工具
public/admin/     管理后台 SPA（原生 JS）
tests/            回放链路 E2E 测试
```

## 已知限制

- 仅支持 H.264 + AAC 编码流
- 单进程模型，不支持多进程
- 回放请求的样本表构建内存/耗时随回放时长线性增长，超长回放建议限定时间范围
- B 帧源的合成时间戳（CTS）符号处理未完善，建议推流端关闭 B 帧（如 `-bf 0`）

## AI 分析发现的问题

以下问题由代码审查（AI 分析）发现，完整的现象 / 根因 / 修复方案见 [KNOWN_ISSUES.md](KNOWN_ISSUES.md)。
状态标记：❌ = 未修复，✅ = 已修复。

### 录制器：流中途切换

| 编号 | 问题 | 严重度 | 是否修复 |
|---|---|---|---|
| KI-1 | 纯音频中途加视频 → 分段损坏（moov 缺视频 trak/trex） | 高 | ❌ 未修复 |
| KI-2 | 纯视频中途加音频 → 分段损坏（对称问题） | 高 | ❌ 未修复 |
| KI-3 | 中途加轨切换点附近丢帧 | 中 | ❌ 未修复 |

### 回放服务

| 编号 | 问题 | 严重度 | 是否修复 |
|---|---|---|---|
| KI-4 | keep-alive 重入静默丢数据（回调 / bufferFull 被覆盖） | 高 | ❌ 未修复 |
| KI-5 | 客户端断连未释放文件句柄 / 回调残留 | 高 | ❌ 未修复 |
| KI-6 | 跨段首段缺轨 → moov 空 trak / duration=0 / dts 空洞 | 中 | ❌ 未修复 |
| KI-7 | 回放响应头不入队 → 管道化字节错乱 | 中 | ❌ 未修复 |
| KI-8 | 回放与直播 FLV 的 onClose 互踩 | 中 | ❌ 未修复 |

### 路径安全

| 编号 | 问题 | 严重度 | 是否修复 |
|---|---|---|---|
| KI-9 | sanitizePath 目录穿越 | 高 | ❌ 未修复 |
| KI-10 | legacy 兼容层重新引入目录穿越（KI-9 的回归） | 高 | ❌ 未修复 |

> 详细条目（现象 / 根因 / 影响 / 修复方案 / 对应提交）见 [KNOWN_ISSUES.md](KNOWN_ISSUES.md)。

## 致谢

- [workerman](https://www.workerman.net)
- [Node-Media-Server](https://github.com/illuspas/Node-Media-Server)
- [sabreamf](https://code.google.com/archive/p/sabreamf/)

## License

[MIT](LICENSE)
