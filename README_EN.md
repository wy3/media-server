# PHP Media Server

[简体中文](README.md) | English

> A pure-PHP streaming media server built on [Workerman](https://www.workerman.net): RTMP ingest, HTTP-FLV / WS-FLV playback, fMP4 recording with time-range playback, and a built-in admin console.

## Features

- **Live streaming**
  - Ingest via RTMP (`rtmp://`) and HTTP-FLV (`http://…/xxx.flv`)
  - Distribution via RTMP / HTTP-FLV / WS-FLV
- **Recording**
  - Real-time H.264/AAC muxing into fMP4 (`ftyp + moov + moof/mdat`); segment files are directly readable by ffprobe and browsers
  - 2-second fragments aligned to keyframes, automatic 60-second segment rotation
  - Supports video-only, audio-only, and audio+video streams
  - Segment index stored in SQLite (`recordings.db`), searchable by publish path
- **Time-range playback**
  - `GET /playback/{publishPath}?start={ms}&end={ms}` — server-side cross-segment slicing, re-muxed into fMP4 and streamed back
  - Video starts at the nearest keyframe before the requested start; audio start follows the video start so both tracks keep equal duration
  - Segment boundaries are stitched on a continuous decode timeline; responses are streamed with chunked backpressure, so memory usage is independent of playback length
- **Admin console** (`/admin`)
  - Token-based login, server status / live / recordings / settings panels
  - One-click playback of recorded segments (embedded player)
- **E2E tests**
  - Data-driven suite covering the full pipeline: push → record → playback → decode verification

## Requirements

- PHP >= 8.2 (verified on PHP 8.4), extension: `json`
- Composer
- Optional: `event` / `ev` extension (better event loop performance on Linux)
- `ffmpeg` / `ffprobe` for running tests

## Quick Start

```bash
composer install
php start.php start        # RTMP on 1935, HTTP on 18080
```

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

For web playback use [Aliplayer](http://player.alicdn.com/aliplayer/setting/setting.html) or [mpegts.js](https://github.com/xqq/mpegts.js); on desktop `vlc` / `ffplay` work out of the box.

### Admin console

Open `http://127.0.0.1:18080/admin/` in a browser. The default account is configured in `start.php` (see below).

## Configuration

Edit `start.php`:

```php
\MediaServer\Recorder\RecorderManager::$enabled = true;          // enable recording
\MediaServer\Recorder\RecorderManager::$recordPath = __DIR__ . '/record'; // recording directory
\MediaServer\Recorder\RecorderManager::$fragmentDurationMs = 2000;  // fMP4 fragment duration
\MediaServer\Recorder\RecorderManager::$segmentDurationMs = 60000;  // segment file duration
\MediaServer\Admin\AdminAuth::$username = 'admin';               // admin account
\MediaServer\Admin\AdminAuth::$password = 'admin123';            // admin password
```

For production, change the default credentials and put the admin console and playback endpoints behind reverse-proxy access control.

## Recording & Playback

- Recordings are written to `record/<publishPath>/` (`/` normalized to `_`); each segment is a standalone `.mp4` with a leading `moov`, directly playable
- The segment index lives in `record/recordings.db` (SQLite, WAL) with per-segment wall-clock start/end and codec metadata
- Playback slicing and re-muxing happen server-side; segments are stitched on a continuous decode timeline and returned as a streaming response:

```
GET /playback/live/stream?start=1788165249473&end=1788165554005
```

`start`/`end` are wall-clock timestamps in milliseconds; omitting `end` plays to the end. The response is `video/mp4` and can be assigned directly to `<video src>`.

## Admin API

`POST /api` with JSON body `{"name": "...", "args": [...]}`. All calls except `login` require the token returned by login (header `X-Auth-Token`):

| name | description |
|---|---|
| `login` | Log in, returns a token |
| `logout` | Log out |
| `getServerStats` | Server stats |
| `listPushStream` | Live stream list |
| `listRecordFiles` | Recorded segments |
| `getPlayStreamCount` | Playback connection count |
| `getSettings` | Server settings |

## E2E Tests

```bash
php tests/playback_e2e.php            # run all cases
php tests/playback_e2e.php aonly      # run cases matching a name
```

Cases are data-driven (add/remove entries in `$CASES` inside `tests/playback_e2e.php`) and cover audio+video / video-only / audio-only / cross-segment scenarios: automatic ffmpeg push, wait for segments, playback requests, ffprobe track/duration checks, and full-decode verification via ffmpeg.

## Directory Layout

```
src/
├── Admin/        Admin authentication
├── Flv/          FLV publisher stream
├── Http/         HTTP server (live/playback/console/static routing)
├── MediaReader/  FLV tag parsing (AVC/AAC packets)
├── PushServer/   Publisher interfaces
├── Recorder/     fMP4 recorder, playback slicing, MP4 parser/muxer, SQLite index
├── Rtmp/         RTMP protocol handling
└── Utils/        Binary stream and other utilities
public/admin/     Admin console SPA (vanilla JS)
tests/            Playback pipeline E2E tests
```

## Known Limitations

- H.264 + AAC streams only
- Single-process model, no multi-process support
- Playback sample-table memory/CPU grows linearly with the requested time range; prefer bounded ranges for long recordings
- Composition timestamp (CTS) sign handling for B-frame sources is incomplete; disable B-frames on the encoder side (e.g. `-bf 0`)

## Known Issues from AI Analysis

The issues below were found through code review (AI analysis). Full symptom / root cause / fix details are in [KNOWN_ISSUES.md](KNOWN_ISSUES.md).
Status markers: ❌ = not fixed, ✅ = fixed.

### Recorder: mid-stream track switching

| ID | Issue | Severity | Fixed? |
|---|---|---|---|
| KI-1 | Audio-only then video added mid-segment → corrupted segment (moov lacks video trak/trex) | High | ❌ Not fixed |
| KI-2 | Video-only then audio added mid-segment → corrupted segment (symmetric issue) | High | ❌ Not fixed |
| KI-3 | Frame drops around the mid-segment track-switch point | Medium | ❌ Not fixed |

### Playback server

| ID | Issue | Severity | Fixed? |
|---|---|---|---|
| KI-4 | Keep-alive re-entry silently loses data (callbacks / bufferFull overwritten) | High | ❌ Not fixed |
| KI-5 | Client disconnect leaks file handles / leaves stale callbacks | High | ❌ Not fixed |
| KI-6 | First segment without a track → empty moov trak / duration=0 / dts gap | Medium | ❌ Not fixed |
| KI-7 | Response headers not queued → interleaved bytes under pipelining | Medium | ❌ Not fixed |
| KI-8 | Playback and live-FLV onClose handlers clobber each other | Medium | ❌ Not fixed |

### Path security

| ID | Issue | Severity | Fixed? |
|---|---|---|---|
| KI-9 | Directory traversal via sanitizePath | High | ❌ Not fixed |
| KI-10 | Legacy compatibility layer reintroduces traversal (regression of KI-9) | High | ❌ Not fixed |

> Detailed entries (symptom / root cause / impact / fix / related commits) see [KNOWN_ISSUES.md](KNOWN_ISSUES.md).

## Acknowledgements

- [workerman](https://www.workerman.net)
- [Node-Media-Server](https://github.com/illuspas/Node-Media-Server)
- [sabreamf](https://code.google.com/archive/p/sabreamf/)

## License

[MIT](LICENSE)
