# Chunk Streaming Configuration

Configure chunk streaming behavior in `khronos.json` under the `chunk-streaming` section.

## Quick Reference

```json
{
    "chunk-streaming": {
        "compression-level": 2,
        "per-tick": 10,
        "time-budget-ms": 30,
        "use-time-budget": true
    }
}
```

## Options

### `compression-level`

- **Type:** integer
- **Valid range:** 1–9
- **Default:** 2
- **Restart required:** Yes

zlib compression level for chunk packets.

| Level | Time/chunk | Size | Use case |
|-------|-----------|------|----------|
| 1 | ~412 µs | 10349 B | Maximum CPU savings |
| 2 | ~466 µs | 9936 B | **Recommended** — best CPU/bandwidth tradeoff |
| 3 | ~634 µs | 9453 B | Balanced |
| 7 | ~3203 µs | 8207 B | Maximum compression (not recommended) |

**CPU impact:** Lower = faster. L2 is 1.4x faster than L3.

**Bandwidth impact:** Higher = smaller packets. L2 is only 5% larger than L3.

**Recommendation:** Use L2 (default). Only increase if bandwidth is severely constrained.

---

### `per-tick`

- **Type:** integer
- **Valid range:** ≥ 1
- **Default:** 10
- **Restart required:** Yes

Maximum chunks a single player/session can receive per tick.

This is a **per-session cap**, not the global processing limit. When time-budget mode is enabled:

```
actual chunks per player = min(per-tick, available global budget, chunks in queue)
```

**CPU impact:** Higher = more chunks per player per tick.

**Bandwidth impact:** Higher = more data sent per tick.

**Recommendation:** Keep at 10 for most servers. Increase for faster initial loads on small servers.

---

### `time-budget-ms`

- **Type:** float
- **Valid range:** > 0
- **Default:** 30.0
- **Restart required:** Yes

Global time budget (milliseconds) for chunk streaming per tick.

**This is shared across ALL players, not per-player.**

Example:
- 5 players × CPT=10 = 50 chunks potential
- At 466 µs/chunk = 23.3 ms total
- Time budget of 30 ms allows all 50 chunks

If total chunk work exceeds this budget, processing stops and resumes next tick.

**CPU impact:** Higher = more CPU time for chunks, less for other work.

**Bandwidth impact:** Indirect — more chunks processed = more data sent.

**Recommendation:** 30 ms for most servers. Reduce to 20–25 ms if CPU is constrained or plugins are heavy.

---

### `use-time-budget`

- **Type:** boolean
- **Default:** true
- **Restart required:** Yes

Enable time-budget scheduling.

| Mode | Behavior | Best for |
|------|----------|----------|
| **true** (time-budget) | Global 30ms budget shared across all players | Multi-player servers |
| **false** (fixed CPT) | Only per-player CPT cap applies | Single-player or testing |

**Why time-budget mode is recommended:**

Fixed CPT scales poorly:
- 5 players × CPT=10 = 50 chunks = 23.3 ms ✅
- 10 players × CPT=10 = 100 chunks = 46.6 ms ⚠️
- 20 players × CPT=10 = 200 chunks = 93.2 ms ❌

Time-budget mode:
- 5 players: 50 chunks = 23.3 ms ✅
- 10 players: 64 chunks = 29.8 ms ✅ (capped at budget)
- 20 players: 64 chunks = 29.8 ms ✅ (capped at budget)

**CPU impact:** true = stable tick latency; false = may cause tick spikes with many players.

**Recommendation:** Always true for multi-player servers.

---

## Configuration Profiles

### Small server (1–5 players)

```json
{
    "chunk-streaming": {
        "compression-level": 2,
        "per-tick": 10,
        "time-budget-ms": 30,
        "use-time-budget": true
    }
}
```

Fast initial loads, stable tick latency.

### Medium server (5–15 players)

```json
{
    "chunk-streaming": {
        "compression-level": 2,
        "per-tick": 8,
        "time-budget-ms": 25,
        "use-time-budget": true
    }
}
```

Slightly lower per-player cap to ensure headroom for entity processing.

### High-player server (15–30+ players)

```json
{
    "chunk-streaming": {
        "compression-level": 1,
        "per-tick": 6,
        "time-budget-ms": 20,
        "use-time-budget": true
    }
}
```

Maximum CPU savings. Lower time budget leaves more headroom for networking and plugins.

---

## Tradeoffs

### Increasing CPT (per-tick)

| Pros | Cons |
|------|------|
| Faster chunk delivery | More CPU per tick |
| Faster initial loading | Greater tick spikes if time-budget disabled |
| Players reach fully-loaded areas sooner | |

### Increasing compression level

| Pros | Cons |
|------|------|
| Smaller network packets | Significantly higher CPU usage |
| Lower bandwidth usage | Slower chunk delivery |

### Increasing time budget

| Pros | Cons |
|------|------|
| More chunks processed per tick | Less CPU headroom for entities/plugins |
| Faster chunk loading | May cause tick latency if too high |

### Lowering time budget

| Pros | Cons |
|------|------|
| More stable main-thread performance | Chunks take longer to arrive |
| More headroom for other work | Players may notice slower loading |

---

## Important Notes

1. **Time budget limits chunk-streaming work, not the entire server tick.** A plugin or another subsystem can still cause the complete tick to exceed 50 ms.

2. **Cache is always enabled.** The compressed payload cache is shared across viewers and cannot be disabled. It is invalidated when chunk data changes (block placement, lighting, etc.).

3. **Priority is always distance-based.** Chunks closest to the player are sent first. This is not configurable.

4. **Restart required.** All chunk-streaming settings are read at server startup. Changing them requires a server restart.

---

## Benchmark Reference

Measured with realistic terrain data (83 KB wire, ~10 KB compressed):

| Metric | Value |
|--------|-------|
| Per-chunk compression (L2) | 466 µs |
| Per-chunk compression (L3) | 634 µs |
| Per-chunk compression (L7) | 3203 µs |
| Compressed size (L2) | 9936 bytes |
| Compressed size (L3) | 9453 bytes |
| Compressed size (L7) | 8207 bytes |
| Max chunks/sec (L2, single core) | ~2146 |
| Time budget 30ms = max chunks | ~64 |
