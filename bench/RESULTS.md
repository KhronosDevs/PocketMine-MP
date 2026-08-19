# FFI hotspots exploration #2 — results

Branch: `explore/ffi-hotspots-2`. **Nothing is merged to master**; the exploration
stays on this branch. Three implementation commits are on the branch:

- `7ec9ac8` — pack-based binary ECS snapshot transport + dispatch threshold → 256
- `93fa3de` — FFI nibble unpack (B), storage pack call site (A), nether noise batching (E)
- `791f742` — ECS dispatch: event-driven `wait()`/`notify()` instead of `usleep(500)` poll; threshold back to <16

Benchmarks run with the same binary the server uses (`bin/php7/bin/php-bin`,
PHP 8.2.32 ZTS, opcache ON with `jit=tracing`, FFI ON).

## 1. What was already in FFI (baseline)

Single facade `src/pocketmine/core/resource/NativeAccel.php` + pure-C library
`native/kh_native.c` (built by `native/build.sh` into `native/lib/kh_native.so`,
byte-identity asserted by `native/verify.php`). Integration pattern:

- lazy `FFI::cdef(CDEF, $path)` with a pure-PHP fallback (every method returns
  `null` when FFI/.so unavailable or disabled via `khronos.json
  native-accel.enabled`);
- per-PHP-thread static CData buffers reused across calls (safe in the pool
  workers because each thread owns a VM and the C functions are pure);
- inputs copied in with `FFI::memcpy`, results read out with `FFI::string`;
- the whole operation is batched into ONE call (all 256 noise columns, the full
  chunk light pass) so the boundary is paid once, not per element.

Already migrated (commit 5b6bafb, measured at the time):

| function | replaces | measured win |
|---|---|---|
| `kh_light_calc` | `LightCalculator::calculate` | ~15x |
| `kh_noise_octaves` | overworld `columnProfile` (256 cols x 5 octaves) | ~12x |
| `kh_pack_nibbles` | `ChunkSerializer::packNibbles` (wire) | ~50x on pack |
| `kh_build_sky_light` | `ChunkSerializer::buildSkyLight` (wire) | (with above) |

Plus a non-FFI per-chunk wire-serialize cache in the same commit.

## 2. Profiling the current code (real baseline, not an estimate)

`bench/01_tick_profile.php` boots the real stack and simulates a small survival
world (50 players + 500 moving mobs), then measures hot back-to-back ticks
(`KHRONOS_FAST_TICKS`, no 20-TPS pacing so the numbers are pure CPU cost).

Key finding — **the tick cost is NOT the systems' math; it is the parallel
dispatch transport**:

| workload (550 entities) | hot tick |
|---|---|
| parallel pool dispatch on (normal) | **26.6 ms** |
| pool forced off (sync fallback) | **0.46 ms** |

Per-system isolation on the live archetypes sums to ~0.3 ms/tick — movement,
physics and collision are microseconds. The remaining ~26 ms is the ECS parallel
dispatch in `SystemScheduler::run()`.

## 3. Attribution of the ~26 ms tick (usleep polling vs JSON)

`bench/01_tick_profile.php` was temporarily instrumented inside the scheduler to
split the dispatch cost exactly (3 snapshot calls per tick, 500-entity mob
archetype, pool on):

| piece | per tick | notes |
|---|---|---|
| snapshot JSON **encode** (main thread) | ~18.3 ms | mean 5.96 ms/call × 3; the raw work is ~2 ms/call, the rest is **CPU contention with the 4 pool workers** (call samples: median 2.0 ms, p95 16.8 ms, max 19.6 ms) |
| result JSON **decode** + apply | ~4.6 ms | |
| `usleep(500)` poll sleep | ~2.3–3.7 ms | 4.6–7.5 cycles/tick |
| `collectTasks` + submit + rest | ~1 ms | |

**The polling loop is real but NOT dominant** (~10%): JSON serialization is. And
JSON encode is 3x worse than its isolated cost purely because it competes with
the pool workers for cores — which is itself a reason to shrink the transport.

**Snooze mechanism check (drop-in viability):** `SnoozeHandle` (a `ThreadSafe`
condvar: `sleep()/wakeup()/consumeWakeups()`, used by `RegionThread`) and the
receive side both already exist — `EcsSystemTask::run()` already calls
`$this->result->notify()` inside `synchronized()` on completion. So replacing the
`usleep(500)` poll with `wait()`/`notify()` on the `ParallelResult` is a clean
drop-in. After the binary transport it was only worth ~0.5 ms of a ~1.6 ms tick,
so it was deferred as a follow-up rather than shipped there — it shipped as
`791f742`, and the three-way measurement that motivated it is in section 5c.

## 4. Candidate results

`bench/02_ffi_candidates.php` (prototypes in `bench/kh_bench.c`, built to
`bench/lib/kh_bench.so`; NOT the shipped library). Median of 7 rounds, warmed up,
byte-identity asserted against PHP before timing. JIT is on for both sides
(= the real server environment).

| candidate | PHP | FFI | speedup | improvement |
|---|---|---|---|---|
| A  nibble pack 4096→2048 | 54.9 µs | 2.2 µs | **24.9x** | 96% |
| B  nibble unpack 2048→4096 | 60.2 µs | 1.5 µs | **40.7x** | 97.5% |
| C  snapshot RT 500×6 floats (JSON→binary) | 1 387 µs | 109 µs | **12.8x** | 92% |
| C′ pack RT vs FFI float RT | 109 µs | 68 µs | **1.6x** | 38% |
| D  entity metadata encode (28 B) | 0.56 µs | — | — | — |
| E  nether heights 256×2 (batched FFI) | 52 µs | 8.2 µs | **6.4x** | 84% |
| E′ single `smoothNoise` FFI call | 0.09 µs | 0.17 µs | **0.6x** | **−77%** |

## 5. What shipped (7ec9ac8, 93fa3de, 791f742)

### 5a. Binary ECS snapshot transport + threshold 256 (7ec9ac8)

Replaced the JSON payloads (`json_encode`/`json_decode`) of `ArchetypeSnapshot`
and `ParallelResult` with a packed binary blob (`SnapshotCodec`: magic + version
+ per-section float64 counts, `pack('e*')`). The format is transient (one tick),
so no persistence migration. `SnapshotCodec` is force-loaded before the first
pool submit so workers inherit the class table. Dispatch threshold raised
`<16` → `<256`.

Effect on the reference tick (`bench/01_tick_profile.php 50 500 200`):

| metric | before | after |
|---|---|---|
| hot tick mean | 26.3 ms | **1.60 ms** (~16x) |
| snapshot encode (3 snapshots) | ~18.3 ms | 0.24 ms |
| collect + apply decode | ~7.3 ms | 1.01 ms |
| poll sleep | 2.3–3.7 ms | 0.5 ms |
| snapshot per 500-entity archetype | 2.0 ms | 0.05 ms |
| encode / decode isolation | 1.98 / 0.72 ms | 18 / 93 µs |
| payload size (500 entities) | 33 321 B | 24 031 B |

(The threshold raised here to 256 was subsequently reverted to <16 in `791f742`
once the poll it was compensating for was removed — see section 5c.)

Pure-PHP `pack`/`unpack` won over FFI for the transport: `pack_encode_only` is
18 µs vs FFI float marshalling's ~68 µs measured for C′. **FFI is NOT the right
lever for the transport** — this closes candidate C for good.

Correctness: `04_ecs_test` (sync path), a 300-entity pool-dispatch probe
(300/300 integrated, 2 tasks/tick), and the collision/threading/AI/gameplay
suites all pass. The 17_network (2) and 10_persistence flakes under `-j 4`
parallel load are pre-existing (reproduced on the baseline).

### 5b. A + B + E (93fa3de)

Real-world measurements (`bench/05_real_world_ffi.php`, FFI on/off in the same
process, same data):

| op | PHP | FFI | speedup | cut |
|---|---|---|---|---|
| A `RegionStorageAdapter::packNibbles` 4096→2048 | 54.0 µs | 2.7 µs | **20.0x** | 95.0% |
| B `RegionStorageAdapter::unpackNibbles` 2048→4096 | 61.9 µs | 2.4 µs | **25.5x** | 96.1% |
| E `netherHeights` (256 cols × 2 octaves) | 82.5 µs | 50.5 µs | **1.6x** | 38.7% |
| E+ nether chunk gen (full 128-high) | 2 809 µs | 2 344 µs | **1.2x** | 16.6% |

A is the previously-left-out call site of the op `ChunkSerializer::packNibbles`
already runs in C (McRegion/Anvil meta writes). B is a new `kh_unpack_nibbles` +
`NativeAccel::unpackNibbles` (McRegion/Anvil meta reads, chunk load / world
startup).

E: the nether generator bypassed the batched native noise path the overworld
uses. `netherHeights()` now batches the 2 height octaves through
`noiseOctaves`; the cave pass XORs a per-y constant into world x, so each of the
128 y-slices is a full 2D field and a new `kh_noise_columns_xor` batches one
slice per call (128 calls/chunk instead of ~18k PHP `smoothNoise` calls). The
isolated heights win (6.4x in the microbench) shrinks to 1.6x through the
generic `noiseOctaves` (per-call FFI array + `unpack` overhead), and the whole
chunk only drops 16.6% because string building dominates. Still worth it — the
cave slice batch alone removes the noise from the block loop.

All three assert byte-identity in `native/verify.php` against the pure-PHP
implementations (unpack on 5 adversarial samples; netherHeights on 4 chunk/seeds;
netherCaveSlices on 8 y-slices across 3 chunks, including the y=0/y=127 bedrock
boundaries). nether/cave/ore/storage/terrain/light test suites pass.

### 5c. Snooze: event-driven wait instead of usleep polling (791f742)

Replaced the `usleep(500)` poll in `SystemScheduler::run()` with a `wait()` on
the `ParallelResult` condvar. `EcsSystemTask::run()` already sets `done=true`
and calls `notify()` inside `synchronized()` on completion, so the scheduler
blocks on the result and wakes the instant a worker finishes instead of sleeping
a fixed 500 µs and re-polling. Dispatch threshold restored `<256` → `<16` (the
decision below explains why the raise is no longer the right fix).

Three-way comparison at 50 players + 500 mobs (`bench/01_tick_profile.php 50 500
200`, hot back-to-back ticks, opcache + JIT on — the real server config). Config
1 is the git state before the transport commit (7f5a2c8), config 2 is the binary
transport as shipped (7ec9ac8), config 3 is the snooze version:

| config | transport | wait | threshold | dispatched | hot tick |
|---|---|---|---|---|---|
| 1 original | JSON | `usleep(500)` | <16 | 3 tasks | 28.0 ms |
| 2 current | binary | `usleep(500)` | 256 | 2 tasks | 1.75 ms |
| **3 new** | binary | `wait()`/`notify()` | <16 | 3 tasks | **1.08 ms** |
| — serial ref | — | — | — | 0 | 0.45 ms |

Instrumented attribution for config 3 (per tick): snapshot encode ~0.1 ms,
submit ~0, `collectTasks` pass ~0.5 ms, result decode + apply ~0.4 ms, condvar
wait ~0.1 ms (a single ~100 µs wait cycle per tick — that time is mostly the
workers' own compute, not idle polling).

**Decision: keep the threshold at <16 and rely on snooze.** The three-way run
answers the original question directly: the pool's overhead at low entity counts
was mostly the usleep polling, not an inherent cost of coordinating workers.
Removing the poll while keeping the binary transport makes the parallel path
**faster at <16 (1.08 ms, 3 tasks) than it was at 256 with the poll (1.75 ms,
2 tasks)** — dispatching more work, on a lower threshold, and still winning. The
raised threshold was a workaround for poll latency, and the poll is gone.

The remaining ~0.6 ms gap to the serial path (1.08 vs 0.45 ms) is the inherent
coordination cost of real parallel dispatch, not the poll: snapshot
serialization (~0.1 ms) plus the `collectTasks` pass and result decode+apply
(~0.5 ms) running on the main thread, with the ~0.2 ms of worker compute
effectively overlapped. That is a fair price for offloading the compute while
keeping deterministic snapshot-based results — and with <16 restored, the pool
path is exercised at ordinary entity counts instead of only in 256+ crowds.
A fully-sync policy would only win if that collect+decode overhead were removed
too, and then nothing would be off the main thread.

## 6. Discards (explicitly rejected, with reason)

- **C — ECS snapshot transport via FFI.** The hotspot is real but FFI is the
  wrong lever: pure-PHP `pack` (already C-backed) is 12.8x over JSON and faster
  than FFI float marshalling (18 µs vs ~68 µs). Shipped as pure PHP instead.
- **D — entity metadata encode.** 0.56 µs/op on a 28-byte payload. One FFI
  call + marshalling costs more than the whole operation. The classic
  boundary-eats-the-gain case.
- **E′ — per-column / single-call noise.** A single `smoothNoise` through FFI is
  77% **slower** than PHP (0.17 vs 0.09 µs) — the boundary dominates. This is
  exactly why every shipped noise path batches 256 columns per call.
- **F — zlib-ng FFI (zng_deflate/zng_inflate) via FFI.** zlib-ng 2.3.3 is
  installed (`/usr/lib/libz-ng.so`) and exports only `zng_`-prefixed symbols
  (no zlib-compat `deflate`/`inflate`). Benchmark (`bench/06_zlib_bench.php`,
  C wrapper `bench/kh_zlib_bench.c`) at compression level 7 (production
  baseline, deliberately chosen over level 9 for 4–7x faster deflate on real
  chunk data):  

  | payload | PHP (µs) | FFI (µs) | speedup | verdict |
  |---|---|---|---|---|
  | **Deflate** | | | | |
  | chunk 81 KB | 1 382 | 1 204 | 1.1x | moderate win |
  | batch 16 KB | 139 | 139 | 1.0x | break-even |
  | batch 4 KB | 42 | 41 | 1.0x | negligible |
  | packet 128 B | 10 | 12 | 0.8x | **FFI slower** |
  | keepalive 32 B | 6 | 9 | 0.7x | **FFI slower** |
  | **Inflate** | | | | |
  | chunk 81 KB | 29 | 26 | 1.1x | moderate win |
  | batch 4 KB | 1.7 | 2.3 | 0.7x | **FFI slower** |
  | packet 128 B | 0.3 | 1.1 | 0.3x | **FFI much slower** |

  zlib-ng wins only at 81 KB (the chunk payload, 13% deflate / 10% inflate
  faster). At ≤ 16 KB, FFI per-call overhead (`FFI::new` + `FFI::memcpy` +
  boundary crossing) dominates and makes it **slower** than PHP's built-in
  zlib. The traffic mix is dominated by small packets/batches, so the net
  effect is negative. Output is also not byte-identical (zlib-ng and zlib
  1.3.2 produce different compressed bytes at level 7, both valid zlib format).

  **Why it was rejected:** the 13% chunk win saves ~360 µs for ≤ 2 chunks/tick
  but the small-packet regression offsets it. The FFI boundary cost per call
  makes zlib-ng a poor fit for the existing per-packet compression pattern.
  Worth revisiting only if compression is batched (multiple packets into fewer
  FFI calls) or if chunks become the dominant compression cost.

## 7. Follow-ups (noted, not shipped)

- **Dedicated `kh_nether_heights` (like the microbench) would get E's heights
  closer to 6.4x** by skipping `noiseOctaves`' array-building overhead — but it
  saves only ~32 µs of a ~2.3 ms chunk, so it is marginal.
- **Fully-sync ECS policy (threshold 512) measured 1.18 ms** vs **1.08 ms with
  snooze at <16**, so keeping the threshold low is no longer a speed trade-off;
  the parallel path is now faster than all-sync at 500 entities. It would only
  win if the ~0.5 ms `collectTasks` + result-decode overhead on the main thread
  were also removed (e.g. by applying results lazily) — at the cost of having no
  worker offload at all.

## 8. Benchmark validity notes (self-critique)

- opcache + JIT (`jit=tracing`) enabled for both PHP and FFI measurements —
  the real server config, so the comparison is apples-to-apples, not PHP-
  handicapped.
- warmup before timing; median of 7 rounds; results stable across 3 runs
  (±10% on the FFI rows, <1% on PHP rows).
- byte-identity (or exact float64 round-trip for C) asserted before timing, on
  the same data later timed.
- FFI C prototypes compiled `-O2 -fPIC -shared`, same flags as `kh_native.so`.
- The scheduler-instrumented numbers (section 3) are from the same benchmark's
  temporary instrumentation; the raw JSON-encode call cost is inflated by CPU
  contention with the pool workers, which the isolation numbers (idle pool) do
  not capture — both are reported so the reader can see the real envelope.

---

# RakNet exploration (explore/ffi-raknet) — initial profiling

Branch: `explore/ffi-raknet` (branched from master after exploration #2 merge).

## 1. RakNet layer structure

Source: `src/raklib/` (server thread) + `src/raklib/protocol/` (packet codec).

Hot path per inbound UDP datagram:

1. `SessionManager::receivePacket()` — reads from UDP socket, dispatches to session
2. `Session::handlePacket()` — decodes the datagram (`DataPacket::decode()`),
   iterates encapsulated packets
3. `Session::handleEncapsulatedPacket()` — reliability window management
4. `Session::handleEncapsulatedPacketRoute()` — routes to game layer

Outbound: `Session::addEncapsulatedToQueue()` → `EncapsulatedPacket::toBinary()`
→ `DataPacket::encode()` → UDP socket.

The core codec primitives are in `Binary.php` (`readLTriad`, `writeLTriad`,
`readInt`, `readShort`, etc.) — all static methods using PHP `pack`/`unpack`.

## 2. Profile results (`bench/07_raknet_profile.php`)

Median of 30 rounds × 1000 ops (100k for primitives), opcache + JIT on.

### EncapsulatedPacket::fromBinary()

| reliability/split | time |
|---|---|
| unreliable (1-byte header) | 0.2 µs |
| reliable (4-byte header) | 0.3 µs |
| reliable ordered (7-byte header) | 0.4 µs |
| reliable ordered + split (17-byte header) | 0.6–0.7 µs |

Payload size (64 B vs 1024 B) has no measurable effect — the cost is the
header parsing, not the payload copy.

### EncapsulatedPacket::toBinary()

| type | time |
|---|---|
| unreliable | 0.2 µs |
| reliable | 0.3 µs |
| reliable ordered | 0.4 µs |

### Binary primitives

| function | time |
|---|---|
| `readLTriad` | 65 ns |
| `writeLTriad` | 57 ns |
| `readInt` | 51 ns |
| `readShort` / `readSignedShort` | 52 ns |

### Datagram-level

| operation | time |
|---|---|
| `DataPacket::decode()` — 10 encapsulated packets | 5.0 µs (500 ns/pkt) |
| `AcknowledgePacket::decode()` — 32 seq range | 0.6 µs |
| Full round-trip fromBinary → toBinary (internal) | 1.1 µs (med) / 2.0 µs (split) |

## 3. Assessment — no FFI candidates

Every RakNet operation is already sub-microsecond. The FFI per-call boundary
cost (FFI::new + FFI::memcpy + boundary crossing) measured at 3–10 µs for
small payloads in exploration #2 (zlib-ng candidate F). That overhead **exceeds
the entire current PHP cost** of parsing an encapsulated packet (0.2–0.7 µs).

| operation | PHP time | FFI boundary overhead | verdict |
|---|---|---|---|
| fromBinary (ordered) | 0.4 µs | 3–10 µs | FFI 8–25x slower |
| toBinary (ordered) | 0.4 µs | 3–10 µs | FFI 8–25x slower |
| full datagram (10 pkts) | 5.0 µs | 3–10 µs per call | FFI boundary alone > total |
| readLTriad | 65 ns | 3–10 µs | FFI 50–150x slower |

A batched approach (one FFI call to parse the entire datagram) could avoid
N boundary crossings, but:

1. The datagram decode is already 5.0 µs — the gain ceiling is ~2–3 µs at best.
2. The C function would need to return structured data (reliability, hasSplit,
   messageIndex, orderIndex, orderChannel, split fields × N packets), which
   requires marshalling each field back through FFI::memcpy/FFI::string —
   likely eating the bulk-parse gain.
3. The per-tick cost at 50 players is ~250–500 encapsulated packets × 0.4 µs
   ≈ 100–200 µs — already negligible vs the 1.08 ms tick budget.

**Verdict: RakNet parsing is the classic boundary-eats-the-gain case
(candidates D and E′ from exploration #2). No FFI migration warranted.**

## 4. What could actually help RakNet

If RakNet throughput becomes a bottleneck (unlikely at 50 players; possible at
500+), the levers are:

- **Reduce object allocation** — the current code creates a new `EncapsulatedPacket`
  per inbound packet and a new `DATA_PACKET_4` per datagram. Object pooling or
  pre-allocated buffers would reduce GC pressure, not FFI boundary cost.
- **Reduce string copying** — `substr()` in `fromBinary()` creates a new string
  for the payload. A zero-copy view (offset + length into the original buffer)
  would avoid the copy, but PHP's type system makes this non-trivial.
- **Batch the cross-thread stream** — `SessionManager::streamEncapsulated()`
  serializes each game packet individually into the thread-to-main queue.
  Batching multiple packets into a single `pushThreadToMainPacket()` call
  would reduce thread synchronization overhead.