# Contributing

Thanks for your interest in PHP Media Server. This document describes how to set up the project locally and what to check before opening a pull request.

## Requirements

- PHP >= 8.2 (`json` extension; `pdo_sqlite` for recording tests)
- Composer
- Optional: `ffmpeg` / `ffprobe` (only needed for the playback E2E suite)

## Local Setup

```bash
composer install
php tests/smoke.php      # should print "ALL ... CHECK(S) PASSED"
```

## Before Opening a PR

Run the same checks CI runs:

```bash
composer validate --no-check-publish --strict
php tests/smoke.php
```

If your change touches recording or playback, also run the E2E suite with a server up:

```bash
php start.php start      # in one terminal
php tests/playback_e2e.php
```

### Syntax check

CI runs `php -l` over every file under `src/`, `packages/`, `tests/` and `public/`. On Windows PowerShell:

```powershell
Get-ChildItem src,packages,tests,public -Recurse -Filter *.php |
  ForEach-Object { php -l $_.FullName }
```

## Coding Style

- All files start with `declare(strict_types=1);`
- PSR-4 namespace `MediaServer\` maps to `src/`
- Public methods carry JSDoc-style `/** ... */` docblocks; keep them in Chinese as the rest of the codebase does
- Two-space-free tabs are not used — follow the surrounding file's indentation exactly

### ⚠️ Avoid handle-passing APIs (antivirus compatibility)

Do **not** use these in production code, and be careful even in test scripts:

| API | Why |
|---|---|
| `socket_wsaprotocol_info_export()` / `_import()` | `WSADuplicateSocket` — flagged as `VirTool:PHP/Remeshelsz.A` (Severity 5) by Windows Defender; **the file gets erased** |
| `socket_sendmsg()` with `SCM_RIGHTS` | Same detection class on the POSIX side |
| `proc_open(PHP_BINARY . ' ... script')` patterns combined with the above | Classic PHP backdoor signature |

Defender erases the offending file's contents (size stays the same, content becomes whitespace),
so the symptom looks like "my file was mysteriously corrupted". If a file you just wrote appears
blank, check Windows Security → Protection history.

**Use plain stream I/O instead.** For cross-process work, prefer `stream_socket_client` /
`stream_socket_server` and ordinary `fread` / `fwrite`. See
[docs/multiprocess/](docs/multiprocess/README.md) for the rationale and measured numbers.

## Commit Messages

Write the subject in Chinese, imperative mood, one line. Body optional. Examples:

```
修复回放末尾几秒无声
新增管理后台录像列表
```

## Pull Requests

- Keep changes focused; one concern per PR
- Describe **what** changed and **why**
- If you change behaviour, update both `README.md` and `README_ZH.md`
- Link the related issue when applicable

## Reporting Bugs

Please include:

- PHP version and OS
- Steps to reproduce (including the ffmpeg command you used)
- Expected vs actual behaviour
- Relevant server log output

## License

By contributing you agree that your contributions are licensed under the [MIT License](LICENSE).
