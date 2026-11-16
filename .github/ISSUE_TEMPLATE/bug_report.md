---
name: Bug report
about: 报告可复现的问题
title: '[Bug] '
labels: bug
assignees: ''
---

## 问题描述

简要说明遇到的问题。

## 复现步骤

1.
2.
3.

推流命令（如有）：

```bash
ffmpeg -re -i input.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1/live/stream
```

## 期望行为

## 实际行为

## 环境信息

- OS：
- PHP 版本（`php -v`）：
- Workerman 版本（`composer show workerman/workerman`）：
- 推流端 / 播放端：ffmpeg / OBS / vlc / 浏览器（含播放器名与版本）

## 日志

```
粘贴相关服务端日志
```

## 补充信息

是否涉及录像或回放？如果是，请附上 `record/` 目录结构或 `recordings.db` 中的分段信息。
