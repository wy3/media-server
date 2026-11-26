# Benchmark Results: select vs event

[简体中文](./RESULTS.md) | English

End-to-end performance benchmarks for media-server.

> **Comparison caveat**: numbers from different machines, push methods, or
> measurement scopes are **not directly comparable**.
> Use these only for `select` vs `event` and before/after-refactor comparisons.

## Scenarios

| Scenario | Contents | Script |
|---|---|---|
| **S1** Latency | 1 push + 1 pull, frame-level forwarding latency | `s1_latency.ps1` |
| **S2** Pull concurrency | 1 push + N pulls, single-step retention | `s2_pull.ps1` |
| **S3** Push concurrency | N pushes, single-step retention | `s3_push.ps1` |
| **S4** Stepwise ramp | Ramp from low to high to find the knee | `s4_ramp.ps1` |
| **S5** Mixed push+pull | N pushes + M pulls concurrently | `s5_mixed.ps1` |

**S2/S3 vs S4**: S2/S3 are *single-point* tests (absolute behaviour at one level);
S4 is a *curve* test (multiple levels, finding the knee).
**S4 is the primary basis for capacity decisions**; S2/S3 supplement
long-run stability data.

---

## Test Environment

| Item | Value |
|---|---|
| CPU | AMD Ryzen 9 9900X (12 cores / 24 threads) |
| RAM | 63.6 GB |
| OS | Windows |
| PHP | 8.4.25 NTS |
| Workerman | 4.1.9 |
| Stream | 1920×1080 30fps 4096 kb/s H.264 + AAC 128k |
| Pusher | ffmpeg 7.1 `-c copy` (looped sample, no re-encode) |
| Puller | rtmpstress.exe (librtmp, multithreaded) |
| Recording | **disabled** (to exclude disk I/O) |

### CPU Metric

`svc%` is the **service process's own** CPU (`TotalProcessorTime` delta ÷ wall clock).
100% = one full core. It does **not** include other programs on the machine.

`sys%` is total system CPU (normalized over 24 cores).

Both are recorded because the test machine runs other workloads.
When `sys%` is high, the service may be starved of CPU — a low `svc%` then
reflects environment contention, not reduced capacity.
**Use `svc%` for capacity, `sys%` for environment sanity.**

## Summary

| Scenario | select | event | Delta |
|---|---|---|---|
| **S1 Latency** | median −6 ms | median −14 ms | ⚠️ **within noise, not usable** |
| **S2 Pull single-step** | 200 streams, 100% kept | 500 streams, 100% kept | — |
| **S3 Push single-step** | 200 streams, 100% kept | 500 streams, 100% kept | — |
| **S4 Pull ramp limit** | **200 streams** (dead at 300) | **1000 streams** (100% kept) | **5×** |
| **S4 Push ramp limit** | **200 streams** (dead at 300) | **1200 streams** (100% kept) | **6×** |
| **S5 Mixed 500/500** | — | **98.5% CPU, latency normal** | — |

**`select` does not gracefully refuse connections — the whole process hangs**,
including the HTTP admin API. This is `FD_SETSIZE=256` bitmap overflow,
far worse than a graceful degradation.

**On "is 1000 streams overloaded?"**: 100% CPU is only a capacity boundary.
Real overload shows up as latency. At 1000 streams the first-frame latency of a
new connection jumped from 8 ms to 1023 ms — **confirmed overload**.
The knee sits between 900 and 1000, and latency oscillates violently near saturation.

> **⚠️ S1 latency data is not usable for comparison**: measurement resolution is
> only ±30 ms (PTS frame granularity 33 ms + `-progress` granularity 20 ms), and
> physically impossible negative values appear, meaning noise swamps the signal.
> It can only detect **order-of-magnitude** degradation (8 ms → 1023 ms),
> not fine differences such as "event is 8 ms faster than select".

---

## S1: Latency

### Method

Both ends emit "frame PTS + wall clock"; for the same PTS, subtract the two wall clocks.

**Critical flags (easy to get backwards — wrong choice zeroes the PTS)**:

| Side | Flags | Note |
|---|---|---|
| Push | `-progress <file>`, **without** `-copyts` | `out_time_us` is the original PTS |
| Pull | `-vf showinfo`, **with** `-copyts` | `pts_time` is the original PTS |

`-progress` and `showinfo` react **oppositely** to `-copyts`.

### Results

| Event loop | Samples | Min | Median | Mean | P95 | Max |
|---|---|---|---|---|---|---|
| event | 10 | −25 ms | **−14 ms** | −6 ms | 26 ms | 26 ms |
| select | 10 | −38 ms | **−6 ms** | −6 ms | 21 ms | 21 ms |

### ⚠️ This latency data has no reference value yet

**Do not use these numbers for comparison or capacity decisions.**

| Problem | Impact |
|---|---|
| **Resolution only ±30 ms** | Cannot distinguish 10 ms from 40 ms — yet real differences may be exactly that size |
| **Negative values appear** | Physically impossible; noise has covered the true value |
| **PTS frame granularity 33 ms** | Sub-frame differences are unmeasurable |
| **`-progress` granularity 20 ms** | Push-side timestamps carry ±10 ms jitter |
| **Two independent samplers** | Capture moments are not synchronized |
| **Different scope** | Only measures "server forwarding", excluding encoder and player-side decode/render |

**The only reliable use of this data** is detecting **order-of-magnitude** degradation:

| Case | Latency | Verdict |
|---|---|---|
| 500 streams | 8 ms | Normal |
| 1000 streams | **1023 ms** | **Clear overload** (128× degradation) |

The 1023 ms figure is valid — it is far outside the noise floor and reflects real queuing.
But "event −14 ms vs select −6 ms" is **within noise and not trustworthy**.

**To obtain usable latency data later**, you would need to:

1. Sample both ends from a single process instead of launching ffmpeg twice
2. Use a finer clock source (e.g. `hrtime()` at frame arrival) instead of parsing log files
3. Separate "server forwarding latency" from "end-to-end latency" (the latter needs external clock sync)
4. Take a distribution over many runs rather than a single median

Until then, **S1 numbers are directional only, not conclusions**.

**Scope note**: these values exclude push-side encoding and player-side decode/render.
They only reflect "server receives frame → forwards to puller".
Measuring true end-to-end latency (including encoder and player buffers) would
require external clock synchronization.

---

## S2: Pull Concurrency (single step)

1 push + N concurrent pulls, 40 s per step.

**Scope**: single-point test — absolute behaviour and stability at one level.
**For capacity decisions see [S4 Stepwise Ramp](#s4-stepwise-ramp-finding-the-knee).**

### select

| Target | Actual | Retention | svc CPU | sys CPU | Memory | Per conn |
|---|---|---|---|---|---|---|
| 100 | 100 | 100% | 13.4% | 4% | 23 MB | 0.133% |
| 200 | 200 | 100% | 25.1% | 7% | 25 MB | 0.122% |
| **300** | **—** | **0% (hang)** | — | — | — | — |

At 300 streams the process **hangs completely**; `getPlayStreamCount` fails twice
in a row and the script aborts.

### event

| Target | Actual | Retention | svc CPU | sys CPU | Memory | Per conn |
|---|---|---|---|---|---|---|
| 100 | 100 | 100% | 11.2% | 8% | 24 MB | 0.107% |
| 200 | 200 | 100% | 24.2% | 9% | 26 MB | 0.118% |
| 500 | 500 | 100% | ~52% | — | ~30 MB | 0.104% |

> Full step data in [S4 Pull ramp](#pull-ramp-select-vs-event).

### Single-step conclusions

| Loop | At 200 | At 300 |
|---|---|---|
| `select` | 25.1% CPU, 100% kept | **hangs** |
| `event` | 24.2% CPU, 100% kept | 100% kept |

**Memory is not the constraint**: 1000 connections use only 36 MB (~36 KB each).

### ⚠️ Is 1000 "overloaded" or just "saturated"? — judge by latency

100% CPU only means "single-core time is exhausted" — a **capacity boundary**.
Real overload means queuing, which shows up as degraded first-frame latency.

**Validation**: measure first-frame latency of a new connection at 0 / 500 / 1000 streams.

| Load | svc CPU | First-frame latency | Verdict |
|---|---|---|---|
| 0 | 0.6% | −14 ms | Idle |
| 500 | 52.6% | **8 ms** | Headroom remains |
| **1000** | **99.9%** | **1023 ms** | **Overloaded** |

**Latency jumped 128× (8 ms → 1023 ms) — conclusive evidence of queuing.**

**Conclusions**:

1. **The knee is between 900 and 1000**
2. Near saturation it is **highly unstable** — the same 1000-stream load measured 24 ms once and 1023 ms another time
3. That instability is itself a danger signal: production cannot tolerate "sometimes fine, sometimes not"

**So 1000 should not be a recommended value**, even though connections are retained.
Designing for 50% load and picking 500 is the safe choice.

> The finer sweep (600/700/800/900/950/1050) lives in
> [S4 Ramp curves](#ramp-curves-event).

---

## S3: Push Concurrency (single step)

N concurrent pushes, no pulls, 40 s per step.

**Scope**: single-point test. **For capacity decisions see [S4 Stepwise Ramp](#s4-stepwise-ramp-finding-the-knee).**

### select

| Target | Actual | Retention | svc CPU | Memory | Per stream |
|---|---|---|---|---|---|
| 100 | 100 | 100% | 13.2% | 150 MB | 0.132% |
| 200 | 200 | 100% | 25.0% | 275 MB | 0.129% |
| **300** | **—** | **0% (hang)** | — | — | — |

### event

| Target | Actual | Retention | svc CPU | sys CPU | Memory | Per stream |
|---|---|---|---|---|---|---|
| 100 | 100 | 100% | 12.7% | 11% | 151 MB | 0.133% |
| 200 | 200 | 100% | 26.0% | 13% | 274 MB | 0.133% |
| 500 | 500 | 100% | ~62% | — | ~640 MB | 0.124% |

> Full step data in [S4 Push ramp](#push-ramp-select-vs-event).

---

## S4: Stepwise Ramp (finding the knee)

**This is the primary basis for capacity decisions.** Ramping from low to high shows:
whether CPU grows linearly, at which step degradation begins, and where the event loop hits the wall.

### Why ramp instead of a single point

A single-point test cannot reveal the knee:

- At 800 streams only: `select` simply hangs — you cannot tell that "200 was fine and 300 was fatal"
- At 200 streams only: `event` uses just 24% CPU — the ceiling is invisible

### Pull ramp: select vs event

1 push + N pulls, 40 s per step.

| Target | select | event |
|---|---|---|
| 100 | ✅ 100% kept, 13.4% | ✅ 100% kept, 11.2% |
| 200 | ✅ 100% kept, 25.1% | ✅ 100% kept, 24.2% |
| **300** | ❌ **hangs** | ✅ 100% kept, ~33% |
| 400 | — | ✅ 100% kept, 43.6% |
| 600 | — | ✅ 100% kept, 54.0% |
| 800 | — | ✅ 100% kept, 85.4% |
| **1000** | — | ✅ **100% kept, 102.0%** |

**~0.10% single-core per connection, 36 KB memory per connection (1000 streams use only 36 MB).**

### Push ramp: select vs event

N concurrent pushes, 40 s per step.

| Target | select | event |
|---|---|---|
| 100 | ✅ 100% kept, 13.2% | ✅ 100% kept, 12.7% |
| 200 | ✅ 100% kept, 25.0% | ✅ 100% kept, 26.0% |
| **300** | ❌ **hangs** | ✅ 100% kept, ~38% |
| 400 | — | ✅ 100% kept, 50.8% |
| 600 | — | ✅ 100% kept, 72.0% |
| 800 | — | ✅ 100% kept, 82.6% |
| 1000 | — | ✅ 100% kept, 96.1% |
| **1200** | — | ✅ **100% kept, 102.8%** |

**~0.085–0.133% single-core per stream, 1.17 MB memory per stream (1200 streams need 1.4 GB).**

### Ramp curves (event)

```
Pull: 100 → 11.2%   400 → 43.6%   800 → 85.4%   1000 → 102.0%
Push: 100 → 12.7%   400 → 50.8%   800 → 82.6%   1200 → 102.8%
```

**Per-unit cost declines with scale** (pull 0.107% → 0.085%, push 0.133% → 0.085%),
showing fixed overhead (connection setup, timers) being amortized — **some headroom remains**.

### Fine sweep: locating the exact knee

Denser sampling between 600 and 1050, 5 first-frame latency samples per step:

| Load | svc CPU | First-frame latency |
|---|---|---|
| 600 | 60.4% | −10 ms |
| 700 | 72.2% | −10 ms |
| 800 | 74.9% | −28 ms |
| 900 | 84.3% | −3 ms |
| 950 | 88.0% | 3 ms |
| **1000** | 96.2% | **24 ms** (another run: **1023 ms**) |
| 1050 | 98.8% | −10 ms |

**Observations**:

1. Below 900 latency is stable (all within noise)
2. **At 1000 it starts oscillating** — two measurements of the same load differ by 40×
3. 1050 looks "normal" again — so it is not linear degradation but **random queuing near saturation**

That unpredictability is harder to handle than stable degradation:
**you cannot predict whether the next second collapses**.

### Capacity projection

| Scenario | Cost per unit | Full single core | **Recommended (50%)** |
|---|---|---|---|
| Pull | 0.10% / conn | 1000 conn | **500 conn** |
| Push | 0.085% / stream | 1200 streams | **600 streams** |

| Push load | Streams | Memory |
|---|---|---|
| 50% (recommended) | **600** | ~700 MB |
| 80% | 960 | ~1.1 GB |
| 100% (ceiling) | 1200 | ~1.4 GB |

**Memory is the real constraint for push** (unlike pull): about **1.17 MB/stream**.
Each push keeps a GOP cache in-process (`gopCacheQueue`):
2 s GOP × 30 fps × 4 Mbps ≈ 1 MB.

---

## S5: Mixed Push + Pull

**Why needed**: measuring push or pull alone understates real load —
production always has both. Their cost structures differ
(push is memory-heavy, pull is connection-heavy), so interaction must be measured.

### Method

Each case adds a dedicated **probe stream** (`/live/probe`, with `-progress`)
used solely to measure first-frame latency of a new connection under the current
load, without polluting the statistics.

### Results (event loop, 45 s per case)

| Push | Pull | svc CPU | sys | Memory | Push kept | Low fps | Latency |
|---|---|---|---|---|---|---|---|
| 50 | 500 | 57.4% | 6% | 95 MB | 50/50 | 0 | −44 ms |
| 100 | 500 | 60.5% | 10% | 158 MB | 100/100 | 0 | −23 ms |
| 200 | 500 | 68.9% | 7% | 288 MB | 200/200 | 0 | −11 ms |
| **500** | **500** | **98.5%** | 15% | 661 MB | 500/500 | 0 | −9 ms |
| 500 | 200 | 73.9% | 12% | 648 MB | 500/500 | 0 | −32 ms |
| 500 | 100 | 61.9% | 15% | 642 MB | 500/500 | 0 | −40 ms |
| 500 | 50 | 61.9% | 11% | 642 MB | 500/500 | 0 | −26 ms |

**All 100% retained, zero dropped frames, latency within one frame (33 ms).**

### Finding 1: push dominates CPU cost

Fixed pull 500, increasing push:

```
50/500  → 57.4%
100/500 → 60.5%   (+3.1)
200/500 → 68.9%   (+8.4)
500/500 → 98.5%   (+29.6)
```

Fixed push 500, decreasing pull:

```
500/500 → 98.5%
500/200 → 73.9%   (-24.6)
500/100 → 61.9%   (-12.0)
500/50  → 61.9%   (0)
```

**Dropping pull from 500 to 100 frees 36.6% CPU; dropping further to 50 frees nothing** —
pull's marginal cost is low (0.10% per connection), while push costs more per stream.

### Finding 2: memory is almost entirely driven by push

```
500/500 → 661 MB
500/200 → 648 MB
500/100 → 642 MB
500/50  → 642 MB
```

Dropping pull from 500 to 50 saves only 19 MB (36 KB × 450 ≈ 16 MB, matches).
**Push at 1.17 MB/stream dominates memory entirely.**

### Finding 3: 500/500 is the "full load but not degraded" boundary

At `500/500`, CPU is 98.5% while latency remains −9 ms (normal).
It can run at 98.5% without queuing, **but has no headroom left**.

**Production guidance (design for 50%)**:

| Profile | Push | Pull | Est. CPU |
|---|---|---|---|
| Conservative | 250 | 250 | ~50% |
| Balanced | 300 | 200 | ~55% |
| Push-heavy | 500 | 100 | ~62% |

---

## Key Findings

### 1. `select` fails by hanging, not by refusing

```
Step 300:
  [ 10s] svc=  40.4% sys=7% ws=26.5MB active=-1/300
  API unresponsive twice → event loop hung, aborting this step
```

The process is still alive (`ws=26.5MB`) but the **event loop stops scheduling** —
even HTTP stops responding.
Root cause: `Select.php:117` uses a 256-bit fd bitmap; on overflow `select()` errors,
Workerman only prints a warning and continues, effectively processing no events.

**This is more dangerous than refusing connections**: ops sees a live process
while the service is fully unavailable.

### 2. `event` reaches 1000+ streams

With `event` 3.2.0 (libevent binding), pull 1000 / push 1200 all retain 100%,
using just over one core.

**Do not use `ev`**: libev loads and timers work on Windows, but watching sockets
raises `Libev error(10038)` = `WSAENOTSOCK`. PHP stream's `(int)$stream` is an
internal fd, not a WinSock `SOCKET` handle. `event` ships
`EventUtil::getSocketFd()` to do the conversion, which is why it works.

### 3. Memory constraint lives on the push side

| Streams | Pull memory | Push memory |
|---|---|---|
| 100 | 24 MB | 151 MB |
| 400 | 28 MB | 540 MB |
| 800 | 35 MB | 963 MB |
| 1000 | 36 MB | 1174 MB |

Pull only keeps connection state (36 KB each); push caches GOPs (1.17 MB each).
**So push memory drives deployment sizing.**

### 4. Stepwise ramping is required to find the knee

Single-point tests miss the boundary. At 800 streams, `select` simply hangs —
you cannot tell that "200 was fine and 300 was fatal".

Event's per-stream cost also declines with stream count (0.111% → 0.100% → 0.085%),
showing fixed overhead being amortized and headroom remaining.

### 5. Conclusion: real single-process capacity

**With the `event` loop, one process at 1080p30 4 Mbps can handle:**

| Scenario | Full single core | Recommended (50%) |
|---|---|---|
| Pull | 1000 streams | **500 streams** |
| Push | 1200 streams | **600 streams** |
| Mixed | 500 push + 500 pull (98.5%) | **250 push + 250 pull** |

This directly informs multi-process sizing —
`total streams / recommended per process` gives the process count.

### Projected single-core capacity

| Scenario | Cost per unit | Full single core | **Recommended (50%)** |
|---|---|---|---|
| Pull | 0.10% / conn | 1000 conn | **500 conn** |
| Push | 0.085% / stream | 1200 streams | **600 streams** |

**Premise**: a single process uses one core (single-threaded event loop).
Real deployments must leave headroom (bursts, GC, logging) — design for 50%.

**On Windows you must switch to the `Event` loop**, otherwise it hangs past ~200
streams while CPU is nowhere near saturated.

---

## Reproducing

See [PROCEDURE.md](./PROCEDURE.md) for the full walkthrough.

```powershell
# Generate sample
.\tests\bench\gen_samples.ps1

# Stepwise ramp
.\tests\bench\s4_ramp.ps1 -Scenario pull -EventLoop event -Steps 100,200,400,600,800,1000
.\tests\bench\s4_ramp.ps1 -Scenario push -EventLoop event -Steps 100,200,400,600,800,1000,1200
.\tests\bench\s4_ramp.ps1 -Scenario pull -EventLoop select -Steps 100,200,300
.\tests\bench\s4_ramp.ps1 -Scenario push -EventLoop select -Steps 100,200,300

# Mixed push + pull
.\tests\bench\s5_mixed.ps1 -EventLoop event -Matrix '50/500','100/500','200/500','500/500','500/200','500/100','500/50'

# Single scenarios
.\tests\bench\s1_latency.ps1 -EventLoop event -Samples 10
.\tests\bench\s2_pull.ps1 -EventLoop event -Concurrency 800 -Minutes 10
.\tests\bench\s3_push.ps1 -EventLoop event -Streams 600 -Minutes 10
```

Raw data lands in `tests/bench/results/`.
