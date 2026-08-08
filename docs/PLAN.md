# Khronos — Clean Architecture & Performance Plan (v2)

**Target:** Minecraft Pocket Edition 0.15.10 (protocol 84) — **protocol layer frozen**
**PHP:** 8.2 · **Threading:** pmmp/ext-pmmpthread v6.3 · **Branch base:** `master`
**PHP binary:** `bin/php7/bin/php` (see README.md)

> This plan supersedes v1. v1 was written before the code existed and described a
> design that never materialized (`domain/` paths, legacy wrappers, strangler-fig
> migration). This version describes what is **actually built**, what is honestly
> **not yet wired**, and the concrete roadmap to the goal.

---

## 1. Vision

Replace the legacy PocketMine monolith with a **full clean architecture**:

- **Hexagonal core** — domain in the middle, infrastructure behind ports/adapters.
- **ECS domain kernel** — state is plain-data components, behavior is focused
  services/systems, plugins access everything through queries and `EntityRef`.
- **Best possible performance** — archetype storage, struct-of-arrays, binary-string
  block data, and **real multithreading**: region-based simulation + pipeline
  parallelism. Not the old model ("main thread + a few async tasks + a RakLib
  thread") — but genuinely parallel game logic that scales with cores.
- **Zero legacy** — no dead PocketMine code, no `// Simplified` stubs, no fake
  implementations. If a feature exists in the API, it works.

## 2. Architecture (as built)

```
src/pocketmine/
├── Kernel.php                  # Composition root + lifecycle (run/tick/shutdown)
├── core/                       # DOMAIN KERNEL — no external deps
│   ├── ecs/                    #   World, Entity, EntityRef, EntityBuilder,
│   │                           #   QueryBuilder, ComponentRegistry, ResourceRegistry,
│   │                           #   SystemScheduler (sequential/parallel/chunk-parallel)
│   ├── component/              #   Position, Velocity, Rotation, Health, Collision,
│   │                           #   Metadata, Effect, Attribute, Inventory, AIState,
│   │                           #   Path, ItemStack, tags/ (Player, Monster, ...)
│   ├── resource/               #   TickCounter, ServerConfig, SpatialIndex,
│   │                           #   WorldConfig, BlockRegistry, ItemRegistry, ChunkStore
│   ├── system/                 #   Movement, Physics, Effect, AI, ChunkUpdate
│   ├── service/                #   18 application services (join, chunk, block, combat, ...)
│   ├── region/                 #   RegionWorld (spatial ECS slice)
│   └── thread/                 #   CoordinationThread, RegionThread, SnoozeHandle
│                               #   (NetworkThread removed in 13.2 — the adapter owns the RakNet thread)
├── port/                       # PORT INTERFACES (no implementations)
│   ├── driven/                 #   Network, Storage, WorldGen, Threading + DTOs
│   └── driving/                #   Command, Event, Plugin
├── adapter/
│   ├── driven/                 #   Protocol84NetworkAdapter, AnvilStorageAdapter,
│   │                           #   ParallelGeneratorAdapter, PmmpThreadPool
│   └── driving/                #   ConsoleCommandAdapter, PluginManagerAdapter
├── api/                        # PLUGIN-FACING API (thin facades over ECS/services)
│   ├── entity/                 #   Entity, Player, Living, Monster, Animal, Zombie,
│   │                           #   Skeleton, Creeper, Pig, ItemEntity, EntityFactory
│   ├── world/                  #   World (WorldAccessor too)
│   ├── block/                  #   Block
│   ├── inventory/              #   Inventory, ItemStack
│   ├── command/                #   Command, CommandMap, CommandExecutor, senders
│   ├── event/                  #   EventBus, typed events
│   ├── permission/             #   Permission, PermissionManager
│   ├── plugin/                 #   Plugin, PluginManager, PluginDescription, Config, Logger
│   ├── scheduler/              #   Scheduler, tasks, handlers
│   └── server/                 #   Server
├── protocol/                   # PROTOCOL 84 — FROZEN
└── utils/                      # BinaryStream, TextFormat, ...
```

### Layer rules

| Rule | Enforcement |
|------|-------------|
| `core/` never touches protocol, API, or adapters | code review + PHPStan paths |
| `api/` is a thin facade over `core/` — no game logic inside | reviewed on change |
| Network serialization lives only in `adapter/driven/network/` + `protocol/` | grep for `network\protocol` outside those |
| Block/item behavior lives in `core/resource/` registries — never hard-coded | design convention |
| Threads exchange only thread-safe values (scalars, ThreadSafe, ThreadSafeArray) | pmmpthread v6.3 requirement |

## 3. Data layer (Phase 10 — complete this session)

| Resource | Role | Status |
|----------|------|--------|
| `ChunkStore` | In-memory block/meta/light/biome data per loaded chunk. Binary strings (1 byte/block), O(1) offset access; `ChunkData` DTOs remain the persistence format | ✅ wired into chunk services + World facade |
| `BlockRegistry` | Block property tables: hardness, resistance, light, opacity, flammability, required tool/tier, drops, XP | ✅ used by Block API + break/place services |
| `ItemRegistry` | Max stack sizes, durability, item names | ✅ used by ItemStack (api + core) |
| `WorldConfig` | World state: seed, time, spawn, difficulty, gamemode, generator, maxPlayers | ✅ used by World facade |

## 4. Multithreading — honest status

### Built (infrastructure)
- Real `pmmp\thread\Thread` workers: **CoordinationThread** (routes commands
  between regions), **RegionThread** (owns a snapshot store + sim tick), and the
  **RakLibServer thread** owned by `Protocol84NetworkAdapter` (since 13.2, the
  kernel's `NetworkThread` was removed — the network adapter now owns its own
  RakNet thread and speaks the full connected protocol).
- Thread-safe queues (`ThreadSafeArray`) for command/migration/sync flows; `Future`/
  `ThreadingPort` abstraction; region partition model (`RegionWorld`, 16×16 chunks).

### Honest status — what runs where
- **Main thread owns:** the ECS world, all entities, components, resources, and
  services. The kernel's tick loop drives: mirror → world tick → drain → balance
  → network flush.
- **RegionThread workers** (real `pmmp\thread\Thread`s): receive binary
  snapshots (diff-only mirror), integrate movement+gravity in lockstep, and
  stream results back through a seq-tagged sync queue — consumed by the kernel's
  drain (gate-compare or apply mode). Dynamic splits/merges and cross-region
  migration are wired (Phase 9, verified by `tests/05–09`).
- **RakLibServer thread** (owned by `Protocol84NetworkAdapter` since 13.2): full
  RakNet wire protocol — offline + connected handshake, `DATA_PACKET_*` framing,
  reliability windows, ACK/NACK, split reassembly. Decoded game packets stream to
  the main-thread `NetworkSessionService`; outbound game packets go back as
  reliable encapsulated frames.
- **Async chunk generation**: pure deterministic generator runs as `Runnable`s on
  a real pmmpthread `Pool` (Phase 9.4).
- `PmmpThreadPool::submit()` remains an inline executor (no caller currently
  needs parallel task offload beyond the region/chunkgen paths).

## 5. Roadmap

### Phase 9 — Wire the threading (the real parallelism)

| Step | Task | Notes |
|------|------|-------|
| 9.1 | **RegionThread snapshot pipeline** — serialize entities (Position+Velocity) into the worker each tick, integrate on the worker, merge results back into the ECS. | Verify **determinism** against the main-thread path before enabling. |
| 9.2 | **NetworkThread batching** — feed real outbound payloads, drain `sendQueue` on the main thread and send. | ✅ done — see status below. |
| 9.3 | **Async chunk generation** — real worker pool behind `ParallelGeneratorAdapter::generateChunk`. | ✅ done — see status below. |
| 9.4 | **Cross-region migration + load balancing** — migration queues wired (static column split); dynamic region splitting by entity density still ahead. | Partially done — see status below. |
| 9.5 | **Benchmark & scaling proof** — re-run `measure_baseline.php`; measure speedup vs the single-threaded baseline. | Success criteria in §7. |

#### Status — 9.1 done (lockstep region pipeline)

- **Feed:** every tick the kernel mirrors all entities with Position+Velocity into the owning `RegionThread` command queue (with despawn sync for removed entities).
- **Compute:** the worker integrates position from velocity **plus gravity** in lockstep — one integration per kernel `tick` command, matching the main thread's MovementSystem+PhysicsSystem order exactly.
- **Merge back:** the kernel drains the worker's `syncQueue` at a bounded sync point. Results carry a **tick sequence**; out-of-window results are dropped (`lagged`), never misapplied.
- **Gate (default):** main thread still simulates; worker results are compared bit-for-bit. **Zero mismatches** at ≤40 entities (`tests/05`), proving determinism.
- **Apply (experimental flag):** main-thread MovementSystem+PhysicsSystem disabled; worker is authoritative. Exact integration verified (`tests/06`).
- **Transport (9.2a, done):** JSON snapshots replaced with a compact **binary protocol** — one message per tick per region (not one per entity), each entity 52 bytes (id + 6 little-endian doubles). Floats round-trip bit-exactly. At 1000 entities the gate now receives **100,000/100,000 results in-window with 0 lagged and 0 mismatches** (was 3,000 in-window / 69,000 lagged), and gate tick time dropped from 130.9 ms to **10.1 ms**.
- **Archetype reconciliation (9.2b, done):** per-entity dirty flag (`Entity::set/remove`) lets `World::reconcileArchetypes` skip untouched entities with a single bool check — steady-state per-tick cost is O(entities) field reads instead of array_keys+sort per entity.
- **Diff-only mirror (9.2c, done):** the kernel tracks each entity's worker-stored snapshot and predicts the worker's post-integration state with a bit-exact `integrateOnce` (same op order as the worker). An unchanged entity is **not re-mirrored** — the worker keeps integrating its stored snapshot and stays bit-in-sync. In apply mode the drain records the applied state so the next mirror sees the entity as in-sync (no double integration). At 1000 entities over 100 ticks: **mirrored 1,000 (first tick only), skipped 99,000, 0 mismatches, 0 lagged** — gate tick time dropped from 10.1 ms to **4.9 ms**.
- **NetworkThread batching (9.3, done):** the adapter now uses the **kernel's single NetworkThread** (injected via `setNetworkThread`; the duplicate adapter-owned thread is gone) and outbound frames are **coalesced per destination** — a burst of frames to one player becomes a single batched datagram (`socket_sendto` per burst, not per packet). Verified: 100 frames → 2 datagrams, all bytes preserved.
- **Async chunk generation (9.4, done):** `ParallelGeneratorAdapter` runs the **pure, deterministic** generator (`generateChunkPure` — a static function) as a `ChunkGenerationTask` (`extends pmmp\thread\Runnable`) on a real pmmpthread `Pool` (lazy, capped at 4 workers — the empirically-optimal count on 8-core hardware). Results cross back via a serialized string in a `ThreadSafe` cell, decoded **inside the `Pool::collect()` callback** (the safe pmmp pattern — reading after `collect()` races a still-unwinding worker). `WorldGenPort::generateChunks()` submits **many chunks up front and awaits them all**; `generateChunk()` delegates to it.
  - **Noise fix (the big one):** the terrain generator's height noise multiplied un-masked integers past 2^63, so PHP silently converted to float and every `&`/`>>` cast those back — 46× slower than int math on one thread and ~1,000× slower when 8 workers ran it at once (the whole pool appeared to serialize). Rewritten with 31-bit-masked int hashing (`hash31`), chunk gen is int-only, deterministic, and the pool now genuinely parallelizes.
  - **Wired (9.4 followup):** `ChunkLoadService::loadChunks()` bulk-loads a set of chunks through the parallel `generateChunks()` path (shared `materializeChunk` helper); `loadChunk()` delegates to it.
  - **Benchmark (`measure_chunkgen.php`):** at 256 chunks **par 7.5–8.1× faster than sequential** (75 ms vs 560–635 ms) and ~3× faster than main-thread pure; 2048 chunks: **8.4× vs sequential** (558 ms vs 4.7 s), identical terrain across all three paths.
- **Cross-region migration (9.4b, done):** the kernel now creates `regionCount` column-split regions (default 1, matching pre-migration behavior; `bootstrap()` accepts a count). The diff-only mirror tracks each entity's owning region (`pipelineRegion`); when an entity crosses a boundary it is despawned from the old region and a **migration message** is pushed to the new region's `migrationQueue` before the tick command (the worker drains migrations at tick-processing time, so ordering is guaranteed). Verified by `tests/08`: entities crossing a boundary stay bit-exact, 0 mismatches/lagged.
- **Dynamic load balancing (9.4b, done):** region bounds live in a `ThreadSafe` so they stay mutable after the thread starts. Every mirror counts each region's entities and their chunk Xs; a region over `maxEntitiesPerRegion` splits into columns at the entity-weighted median chunk (both halves must stay above the merge floor, else the split is skipped — this prevents the split→merge oscillation that a naive median split caused: 23 splits/17 merges dropped to **6 splits/0 merges** for a stable 5000-entity workload). A region under the merge floor (threshold/5) is folded into its adjacent neighbor (bounds absorb the column, diff-only bookkeeping resets so entities re-mirror instead of despawning from a dying worker, then the worker thread is shut down and joined). Splits create a fresh region thread on the fly (`start(INHERIT_ALL)`); migration rides the existing despawn+migrate path so no entity is integrated twice or lost. `run()` is now resumable (`setAutoShutdownOnRun(false)`) so tests can split, merge, and assert between runs. Verified by `tests/09`: 1500 entities split to 4+ balanced regions with **0 mismatches, 0 lagged** through every transition, apply mode stays exact (`applied == count × ticks`), and despawning to a sparse population merges regions back down (count drops, 0 mismatches).
- **Scale proof (9.5, done):** `tests/07_scale_test.php` — **1000 entities** in apply mode over 10 ticks: every result applied (`applied == 10,000`), exact positions (integration formula to 1e-6), **0 mismatches, 0 lagged**; gate mode at 1000 entities stays exact with diff-only mirroring; async chunk gen deterministic across workers.
- **Scale proof with load balancing (9.4b, done):** `measure_pipeline.php apply 5000 120 1 1000` — 5000 entities in one region auto-split into **7 balanced regions (6 splits, 0 merges, 7,000 migrations)** and stay stable: **600,000 results applied (100% delivery), 0 mismatches, 0 lagged** at **15.7–17.4 ms mean/tick** (down from 26.9 ms, a 35–42% pipeline-speedup; machine load varies the absolute number) with per-phase profiling showing mirror 8.5–10 ms / tick 2.3 ms / drain 4.8–5 ms / balance 0.2 ms. Every entity crosses a region boundary exactly once during the splits.
- **Pipeline hot-path optimization (9.5b, done):** (1) the diff-only mirror's stored-state prediction went from an array-per-entity to **six flat number-keyed arrays** with an inline scalar `advanceStored()` — the per-entity mirror loop now allocates nothing; (2) a **per-entity (chunk, region) cache + epoch counter** lets the mirror skip the `ownsChunk()` ThreadSafe reads in steady state — the epoch bumps on every split/merge (the only way ownership changes without the entity moving), so the cache is re-verified exactly when region bounds change; (3) **balance bookkeeping is gated**: counts are only tracked when a split threshold is configured, and chunk-Xs are only collected for regions that were over the threshold last tick — post-convergence ticks collect nothing; (4) results moved to a **block wire layout** (header + all ids + all doubles), so the kernel decodes a whole batch with **two `unpack()` calls instead of one per entity**, and the worker integrates directly on packed doubles (one unpack + one pack per entity, no intermediate arrays) — this also eliminated the residual per-tick `lagged` (results now delivered 100%); (5) the per-region drain sync deadline was tuned to 8 ms (below that, split-burst workers dropped a batch as lagged).
- **Benchmark (`measure_pipeline.php`):** off 1.9 ms / gate 4.9 ms / apply 10.5 ms at 1000 entities, 0 mismatches, 0 lagged (pre-9.5b numbers). At 5000 entities apply mode dropped from 26.9 ms to **15.7–17.4 ms** after 9.5b (35–42% faster, 100% result delivery). `measure_pipeline` now also reports per-phase timings (`phases` block) via `setPhaseProfiling()`.

### Phase 10 — Complete the data & API layer

| Step | Task |
|------|------|
| 10.1 | Full block registry coverage (all protocol-84 block IDs), block-state metadata (slab/stairs/doors). | ✅ done — see status below. |
| 10.2 | **Unify Inventory types** — `api\inventory\Inventory` currently exposes `core\component\ItemStack`; make the facade consistently use the API `ItemStack`. |
| 10.3 | Held-slot single source of truth (core `InventoryComponent::$heldSlot` vs metadata `heldSlot`). | ✅ done — see status below. |
| 10.4 | World persistence round-trip: load → store → mutate → save → reload equality. | ✅ done — see status below. |

### Phase 11 — Tests & hardening

| Step | Task |
|------|------|
| 11.1 | Unit tests: ChunkStore, BlockRegistry, ItemRegistry, Inventory, services. | ✅ done — see status below. |
| 11.2 | Threading determinism tests (9.1 merge == main-thread result). | ✅ done — `tests/05` (gate bit-exact at ≤40 entities), `tests/06` (apply mode exact), `tests/07` (1000-entity scale, 0 mismatches/lagged). |
| 11.3 | Memory profiling (loaded-chunk budget, archetype arrays) + load tests. | ✅ done — see status below. |

### Phase 12 — Gameplay depth

| Step | Task |
|------|------|
| 12.1 | Real AI behaviors (attack/retreat cooldowns, pathfinding via ThreadingPort). | ✅ done — see status below. |
| 12.2 | Combat + damage integration across services/systems; death/drops/loot tables. | ✅ done — see status below. |
| 12.3 | Crafting/container recipes via registry data. | ✅ done — see status below. |
| 12.4 | Plugin loading (`plugin.yml` + base class, directory + `.phar`, never `.jar`) + unified server-wide command registration (`Server::dispatchCommand`). | ✅ done — see status below. |
| 12.5 | **Networking end-to-end** — real client login → spawn burst → chunk streaming → movement round-trip over the protocol-84 UDP path. | ✅ done — see status below. |
| 12.6 | **Permissions wired** — `plugin.yml` `permissions:` parsed into the `PermissionManager` and enforced by `Player::hasPermission` / command gating. | ✅ done — see status below. |

### Phase 13 — Performance push

| Step | Task |
|------|------|
| 13.1 | **No busy-waiting + ECS hot paths** — snooze (wait/notify) worker threads, world-map archetype resolution, archetype-array apply; 5000-entity tick <15ms. | ✅ done — see status below. |
| 13.2 | **RakNet connected-session layer (real-client joinability)** — the legacy `src/raklib/` codebase (SessionManager, Session with reliability windows, `DATA_PACKET_0–F`, ACK/NACK, EncapsulatedPacket, split reassembly) was **ported into the new architecture**: ThreadSafe-ified for pmmpthread v6.3 (RakLibServer is a `Thread` with only ThreadSafe properties; a new `ThreadSafeLogger` replaces `\ThreadedLogger`; all `\ClassLoader`/old `Config` deps dropped), cleaned up (strict types, no load-time exit, `registerPackets` table, `sessionLimit` enforcement), and rewired so the `Protocol84NetworkAdapter` is now a **`ServerInstance` bridge**: it owns the RakLibServer thread, answers the connected handshake (`CONNECTION_REQUEST` → `CONNECTION_REQUEST_ACCEPTED` → `NEW_INCOMING_CONNECTION`), decodes encapsulated game packets, and feeds them into `NetworkSessionService` (with proper disconnect handling). The kernel's `NetworkThread` is **gone** — the adapter owns its RakNet thread (preloads every raklib class the worker touches before `start()`, since pmmpthread v6.3 workers can't autoload). `tests/17` was rewritten: the fake client is now a **real RakNet client** (connected handshake, `DATA_PACKET_*` framing, reliability + message-index ordering, split reassembly) and proves the full flow end-to-end. | ✅ done — see status below. |

## 6. Thread-safety & ownership model

- **Main thread owns:** ECS World, all components/resources, SystemScheduler, ports.
- **Workers own nothing persistent** — they receive immutable input (serialized
  snapshots, chunk bytes, packet payloads) and produce immutable output.
- **No shared mutable state between threads** — message passing via `Future` /
  `ThreadSafeArray` queues only.
- **Sync point:** worker results are merged/applied on the main thread at a fixed
  point in the tick (after parallel systems, before network flush).

## 7. Success criteria

| Metric | Current | Phase 9 target | Phase 12 target |
|--------|---------|----------------|-----------------|
| Tick time (20 players, terrain world) | ~0.08 ms mean (empty world) | <5 ms | <5 ms |
| Entity tick (5000 entities) | main-thread 17.3 ms | region-parallel 15.7–17.4 ms (from 26.9) | **hot 7.0 ms / apply 1.95 ms** (<15 ✓) |
| Chunk generation (16 chunks) | 15 ms main-thread | parallel: 5.5 ms (4.6× vs sequential) | <10 ms |
| Scalability (cores) | 1 | 4-8 | 16+ |
| API stubs | 0 (this session) | 0 | 0 |
| PHPStan | 0 errors | 0 | 0 |

## 8. Validation & tooling

```bash
bin/php7/bin/php -l ...                    # lint
bin/php7/bin/php vendor/bin/phpstan analyse -c phpstan.neon --no-progress
bin/php7/bin/php measure_baseline.php      # benchmark (writes docs/BASELINE.md)
# functional suites: boot, full (worldgen/storage/tick), API, data layer
```

## 9. Phase history

| Phase | Status | What it delivered |
|-------|--------|-------------------|
| 0-3 | ✅ | Foundation, ECS components, adapters, 18 services |
| 4 | ✅ | New ECS plugin API (breaking, no legacy compat) |
| 5-6 | ✅ scaffold | Archetype + region threading **infrastructure** (not wired) |
| 7 | ✅ | Struct-of-arrays, query caching, memory pooling, batching, benchmarks |
| 8 | ✅ | Complete API rewrite; 1,100+ legacy files removed; PHPStan clean |
| 8.5 | ✅ | `Level` API renamed to `World` |
| 9.1 | ✅ | **Lockstep region pipeline wired** — mirror → worker integrate (movement+gravity) → seq-tagged merge; determinism gate 0 mismatches; apply mode behind flag; `measure_pipeline.php` benchmark |
| 9.2a | ✅ | **Binary snapshot transport** — 52-byte/entity, one batched message per tick; gate 100,000/100,000 in-window, 0 lagged, 0 mismatches at 1000 entities; 13× faster gate |
| 9.2b | ✅ | **Archetype reconcile dirty-flag** — steady-state per-tick cost is O(entities) bool reads |
| 9.2c | ✅ | **Diff-only mirror** — kernel predicts worker state (`integrateOnce`); unchanged entities skip re-mirror. At 1000×100: mirrored 1,000 / skipped 99,000, 0 mismatches, 0 lagged; gate 10.1 ms → 4.9 ms |
| 9.3 | ✅ | **NetworkThread batching** — adapter uses the kernel's single NetworkThread (injected); outbound frames coalesced per destination (100 frames → 2 datagrams) |
| 9.4 | ✅ | **Async chunk generation** — pure static generator runs as a `Runnable` on a real pmmpthread `Pool` (nproc−2 workers); deterministic across workers (`tests/07`) |
| 9.5 | ✅ | **Scale proof** — `tests/07`: 1000 entities apply-mode exact (10,000 applied, 0 mismatches/lagged), gate-at-scale exact, async-gen determinism |
| 9.4b | ✅ | **Migration + dynamic load balancing** — column-split regions with cross-boundary migration (`tests/08`); regions auto-split at the entity-weighted median when over `maxEntitiesPerRegion` and fold back when idle (`tests/09`); both stay bit-exact. 5000 entities → 7 balanced regions, 0 mismatches, 0 lagged |
| 10 | ✅ (mostly) | Real data layer: ChunkStore, BlockRegistry, ItemRegistry, WorldConfig; zero stubs |
| 11.1-11.2 | ✅ | **`tests/` framework** (no deps, per-process isolation): 10 files, 35 tests, 596 assertions — incl. pipeline determinism, apply-mode correctness, 1000-entity scale, async-gen determinism, region split/merge balance, persistence round-trip |
| 10.4 | ✅ | **World persistence round-trip** — `tests/10`: full DTO equality through a fresh adapter instance (restart scenario), negative chunk coordinates (region-file naming), re-save idempotency, service-level load→mutate→save→reload, and cross-kernel persistence (kernel A saves, fresh kernel B reads the mutation back). **Fixed 3 real storage bugs the tests caught:** (1) region timestamp table was written at byte 8192 (over the first chunk's data sector), corrupting every chunk's length field on save; (2) `BinaryStream` lacked `getDouble`/`putDouble` (entity positions crashed); (3) entity/tile ids were cast to int and written as varints (`getVarInt`/`putVarInt` don't exist in this codebase) — ids now round-trip as strings, UUID-style ids survive |
| 10.2 | ✅ | **ItemStack unification** — api `Inventory`/`Block`/`World::dropItem`/`ItemEntity` speak `api\inventory\ItemStack` exclusively; `toCore()`/`fromCore()` convert at the boundary; core component type stays in the storage layer only |
| 9.5b | ✅ | **Pipeline hot-path optimization** — flat stored-state arrays (zero-alloc mirror loop), per-entity chunk/region cache + epoch (no ThreadSafe reads in steady state), gated balance bookkeeping, block wire layout (2 unpack calls per batch; worker integrates on packed doubles). 5000 entities apply: 26.9 → 15.7–17.4 ms, 100% result delivery, 0 mismatches/lagged |
| 10.3 | ✅ | **Held-slot single source of truth** — `InventoryComponent::$heldSlot` was declared but never used; all consumers read the metadata `heldSlot` key. Now the component property is the single source: the API facade, `InventoryService`, `BlockBreakService`, and `EntityInteractionService` all read/write it (metadata key gone). Policy centralized in a bound-checked `InventoryComponent::setHeldSlot(int): bool` (validated against inventory size) that both the facade and service call — the facade was previously unbounded while the service enforced a hotbar bound. Bonus: held slot now persists with the component via `ComponentSerializer` (it was metadata-only before). `tests/03` asserts the facade writes the component property and metadata has no `heldSlot`. **Phase 10 now complete.** |
| 11.3 | ✅ | **Memory profiling & budget** — loaded-chunk budget now *enforced* (was a dead constant + stub): `ChunkLoadService` enforces a configurable cap after every bulk load, `ChunkUnloadService::unloadUnusedChunks` evicts the **oldest** resident chunks FIFO (persisted to disk first, so nothing is lost — `tests/11` proves a marker block survives eviction + reload); `ChunkStore` gains `getOldestLoadedChunk()`/`getMemoryEstimate()`; **Archetype free-list leak fixed** (indices were tracked per-component-type but only the first type's list was ever popped → every other list accumulated duplicate stale entries forever; one shared flat list is correct); `getMemoryProfile()` on the kernel + `measure_memory.php` profiler (per-entity ~1–2 KB, ~160 KB/chunk, index high-water stays flat across despawn/respawn churn, 200 loaded chunks → exactly 64 resident at the 64-chunk budget). 4 tests / 20 assertions added |
| 12.x | ✅ | Gameplay depth (AI, combat, crafting, plugins) |
| 12.5 | ✅ | **Networking end-to-end** — `NetworkSessionService` drives login (protocol/version checks → spawn burst), radius ack + distance-sorted chunk streaming (with **heightmap-derived sky light** via `ChunkSerializer` — the old wire sent pitch-black zeros), and movement round-trip; adapter hands its loop to the kernel's single `NetworkThread`; `bootstrap.php` enables networking. `tests/17`: 11 tests / 53 assertions against a real UDP loopback client |
| 12.6 | ✅ | **Permissions wired** — `plugin.yml` `permissions:` parsed into `PermissionManager` (`parseYaml`, inheritance, defaults, **op-check bug fixed**), registered on enable / unregistered on disable, `Player::hasPermission` + command gating delegate through the manager; `KernelAccessor`/`Plugin` expose `getPermissionManager()`. `tests/18`: 7 tests / 25 assertions |
| 13.1 | ✅ | **No busy-waiting + ECS hot paths** — `SnoozeHandle` (ThreadSafe wait/notify condvar) replaces RegionThread's 200µs poll (5000 wakeups/sec idle) and NetworkThread's 1ms poll; `Query::archetypes()` uses the world's maintained entityArchetypes map; `applyPendingComponents` iterates archetype arrays (one `method_exists` per type). Raw 5000-entity tick 10.5 → **7.0 ms**; hot off-mode 7.04 ms, apply-mode 1.95 ms (pipeline offloads ~5ms/tick off the main thread). `measure_pipeline` now reports hot + cold ticks — the cold-vs-hot gap is a **CPU frequency-scaling artifact**, not code |
| 13.2 | ✅ | **RakNet connected-session layer ported** — legacy `src/raklib/` made thread-safe and clean (ThreadSafe-only RakLibServer thread, `ThreadSafeLogger`, no legacy deps, strict types, `registerPackets` table, `sessionLimit`); `Protocol84NetworkAdapter` is now a `ServerInstance` bridge owning its RakNet thread (preloading worker classes before `start()`) that answers the connected handshake and routes decoded game packets into `NetworkSessionService` with disconnect handling; kernel `NetworkThread` deleted; `tests/17` rewritten as a **real RakNet client** (handshake, `DATA_PACKET_*` framing, reliability/ordering, split reassembly) — **12 tests / 53 assertions, 0 failures**, stable across repeated runs; full suite 18 files / 104 tests / 1336 assertions, PHPStan 0 errors, boot smoke healthy. **The server is now joinable by a real 0.15.10 client** (pending the user's real-client verification) |
| 10.1 | ✅ | **Full block registry coverage** — all 189 real protocol-84 block IDs registered with 0.15 data (hardness/resistance/tool/flags/drops/XP), 191 explicit entries; block-state metadata layer for slabs/stairs/doors (variants, top bit, facing, open/half bits) with `applyPlacementMeta` wired into block placement; api Block facade state accessors |
