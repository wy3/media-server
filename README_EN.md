# PHP Media Server

[简体中文](README.md) | English

[![CI](https://github.com/wy3/media-server/actions/workflows/ci.yml/badge.svg)](https://github.com/wy3/media-server/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Latest Release](https://img.shields.io/github/v/release/wy3/media-server?color=green)](https://github.com/wy3/media-server/releases)

> A pure-PHP media server built on [Workerman](https://www.workerman.net): RTMP/HTTP-FLV ingest, RTMP / HTTP-FLV / WS-FLV playback, fMP4 recording and time-range playback, plus a built-in admin console.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Recording and Playback](#recording-and-playback)
- [Admin API](#admin-api)
- [Tests](#tests)
- [Project Layout](#project-layout)
- [Known Limitations](#known-limitations)
- [Acknowledgements](#acknowledgements)

## Features

### Live streaming

- Ingest
  - RTMP push to `rtmp://<host>:1935/<app>/<stream>`
  - HTTP-FLV push via `POST http://<host>:18080/<app>/<stream>` with an FLV body
- Playback
  - RTMP: `rtmp://<host>:1935/<app>/<stream>`
  - HTTP-FLV: `http://<host>:18080/<app>/<stream>.flv`
  - WS-FLV: `ws://<host>:18080/<app>/<stream>.flv`
  - Playback filters via query string: `disableAudio`, `disableVideo`, `disableGop`
- Supported codecs: H.264 video, AAC audio

### Recording

- Real-time muxing of H.264/AAC into fragmented MP4 (`ftyp + moov + moof/mdat`)
- Fragments are cut on keyframe boundaries (`fragmentDurationMs`, default 2000 ms)
- Segment rotation on keyframe boundaries (`segmentDurationMs`, default 60000 ms), one `.mp4` per segment
- Video-only, audio-only and audio+video streams are all supported
- Each segment carries a complete `moov`, so it plays standalone and is readable by `ffprobe` / browsers
- Segment index stored in SQLite (`recordings.db`, WAL mode), queryable per publish path

### Time-range playback

- `GET /playback/{publishPath}?start={ms}&end={ms}`
- `start` / `end` are wall-clock timestamps in milliseconds; omitting `end` plays through to the end
- The server slices across segments, re-muxes into a new fMP4 and streams it back as `video/mp4`
- Video starts at the nearest keyframe at or before `start`; audio start follows the video keyframe so both tracks stay aligned in duration
- Segment boundaries are stitched on a continuous decode timeline
- `mdat` payloads are streamed in chunks from the source files, so memory usage is independent of playback length

### Admin console

- Served at `http://<host>:18080/admin/`
- Token-based login (in-process token, default TTL 24 hours, invalidated on restart)
- Panels: dashboard, live streams, recordings (with time-range playback), settings

### E2E tests

- Data-driven suite covering push → record → playback → decode verification

## Requirements

- PHP >= 8.2 (tested on PHP 8.4), extension: `json`
- Composer
- Optional: `event` / `ev` extension for a faster event loop on Linux
- `ffmpeg` / `ffprobe` to run the E2E tests

## Quick Start

```bash
composer install
php start.php start
```

Default listeners:

| Service | Address |
|---|---|
| RTMP | `0.0.0.0:1935` |
| HTTP / WebSocket | `127.0.0.1:18080` |

> The HTTP listener binds to `127.0.0.1` by default. Change the socket in `start.php` to `\\MediaServer\\Http\\ExtHttpProtocol://0.0.0.0:18080` if you need external access.

### Publish

```bash
ffmpeg -re -stream_loop 1 -i file.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1/live/stream
```

### Play

| Protocol | URL |
|---|---|
| RTMP | `rtmp://127.0.0.1/live/stream` |
| HTTP-FLV | `http://127.0.0.1:18080/live/stream.flv` |
| WS-FLV | `ws://127.0.0.1:18080/live/stream.flv` |

For web playback use [mpegts.js](https://github.com/xqq/mpegts.js) or [Aliplayer](http://player.alicdn.com/aliplayer/setting/setting.html); on desktop `vlc` / `ffplay` work out of the box.

### Admin console

Open `http://127.0.0.1:18080/admin/` in a browser. The default account is configured in `start.php`.

## Configuration

Edit `start.php`:

```php
// Recording
\MediaServer\Recorder\RecorderManager::$enabled = true;                     // enable recording
\MediaServer\Recorder\RecorderManager::$recordPath = __DIR__ . '/record';   // recording root
\MediaServer\Recorder\RecorderManager::$fragmentDurationMs = 2000;          // fMP4 fragment duration
\MediaServer\Recorder\RecorderManager::$segmentDurationMs = 60000;          // segment file duration

// Admin console
\MediaServer\Admin\AdminAuth::$username = 'admin';
\MediaServer\Admin\AdminAuth::$password = 'admin123';
\MediaServer\Admin\AdminAuth::$tokenTtl = 86400;                            // token TTL in seconds
```

In production, always change the default credentials and put the admin console plus the playback endpoint behind a reverse proxy with access control.

## Recording and Playback

Recording layout:

```
record/
├── recordings.db           # SQLite index (streams + segments)
└── live_stream/            # one directory per publish path ("/" becomes "_")
    ├── xxx.mp4
    └── yyy.mp4
```

- Publish paths are sanitized into directory names, e.g. `/live/stream` → `live_stream`
- `recordings.db` keeps per-segment wall-clock start/end times, duration and codec metadata
- Playback requests are sliced and re-muxed server-side:

```http
GET /playback/live/stream?start=1788165249473&end=1788165554005
```

The response is `video/mp4` with a `Content-Length` header, so it can be assigned directly to a `<video src>`.

## Admin API

All management calls go through one endpoint:

```http
POST /api
Content-Type: application/json
X-Auth-Token: <token>

{"name": "listPushStream", "args": []}
```

Every call except `login` requires a valid token, supplied via `X-Auth-Token`, `Authorization: Bearer <token>`, or a `token` query parameter (401 otherwise).

| name | Description | args |
|---|---|---|
| `login` | Log in, returns `{token, expires}` | `[username, password]` |
| `logout` | Invalidate the current token | `[token]` |
| `getServerStats` | Uptime, publish/play counts, memory usage | `[]` |
| `listPushStream` | Live stream list, optionally filtered by path | `[path?]` |
| `listRecordFiles` | Recording segment list, optionally filtered by path | `[path?]` |
| `getPlayStreamCount` | Playback connection count per path | `[path?]` |
| `getSettings` | Current configuration (no secrets) | `[]` |

`GET /api?name=...&args=[...]` is also supported for read-only calls.

## Tests

Two suites are provided.

**Smoke tests** — no running server, no ffmpeg required (this is what CI runs):

```bash
php tests/smoke.php
```

Covers autoloading, MP4 box byte layout, RTMP handshake echo behaviour, AVC SPS parsing, recording path sanitization and SQLite index read/write.

**Playback E2E tests** — requires a running server plus `ffmpeg` / `ffprobe`:

```bash
php tests/playback_e2e.php            # run all cases
php tests/playback_e2e.php aonly      # run cases whose name matches
```

Cases are data-driven — add an entry to `$CASES` in `tests/playback_e2e.php`. Each case cleans old recordings, pushes with ffmpeg, waits for segments to land, issues playback requests, then validates tracks and duration with `ffprobe` and performs a full decode pass with `ffmpeg`.

CI runs syntax checks and the smoke suite on PHP 8.2 / 8.3 / 8.4 via GitHub Actions.

## Project Layout

```
src/
├── Admin/        Admin console auth (token based)
├── Flv/          FLV publish/play streams
├── Http/         HTTP server (live, playback, admin, static routing)
├── MediaReader/  FLV tag parsing (AVC/AAC packets)
├── PushServer/   Publish/play stream interfaces
├── Recorder/     fMP4 muxer/recorder, playback slicer, MP4 parser, SQLite index
├── Rtmp/         RTMP protocol handling
└── Utils/        Binary/HTTP/WS stream helpers
public/admin/     Admin console SPA (vanilla JS)
packages/         Vendored SabreAMF
tests/            Playback E2E tests
record/           Recording output (git-ignored)
```

## Known Limitations

- Only H.264 + AAC streams are supported; other codecs may not work
- Single-process model — multi-process deployment is not supported
- Streams that add or drop a track mid-flight are not handled (a mid-stream track switch can corrupt the active segment)
- The sample table for a playback request is built in memory, so very long ranges are best avoided; always bound `end` for long recordings
- Fast start / slow loading may be noticeable for some players
- Memory exhaustion is possible in specific environments — supervise the process in production

## Acknowledgements

- [Workerman](https://www.workerman.net)
- [Node-Media-Server](https://github.com/illuspas/Node-Media-Server)
- [sabreamf](https://code.google.com/archive/p/sabreamf/)

## License

[MIT](LICENSE)
