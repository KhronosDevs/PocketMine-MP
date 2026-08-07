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
│   └── thread/                 #   CoordinationThread, RegionThread, NetworkThread
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
- Three real `pmmp\thread\Thread` workers: **CoordinationThread** (routes commands
  between regions), **RegionThread** (owns a snapshot store + sim tick), **NetworkThread**
  (batch compression pipeline).
- Thread-safe queues (`ThreadSafeArray`) for command/migration/sync flows; `Future`/
  `ThreadingPort` abstraction; region partition model (`RegionWorld`, 16×16 chunks).

### Not wired (the actual data path)
- The ECS world, all entities, services, and ports run on the **main thread**.
- `PmmpThreadPool::submit()` executes tasks **inline** — workers are not used.
- Worker outputs (region `syncQueue`, network `sendQueue`) are **never consumed**.

The scaffold was built first because pmmpthread v6.3 forbids non-thread-safe
properties on `Thread` subclasses — the safe order was a correct single-threaded
core, then parallelism behind the seams. **Phase 9 wires the seams.**

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
- **Scale proof with load balancing (9.4b, done):** `measure_pipeline.php apply 5000 120 1 1000` — 5000 entities in one region auto-split into **7 balanced regions (6 splits, 0 merges, 7,000 migrations)** and stay stable: **595,000 results applied, 0 mismatches** (the 5,000 `lagged` are one worker's batch arriving after its 10 ms drain deadline during the split/migration burst — dropped rather than misapplied by design; benign and self-healing in apply mode, where the next mirror re-mirrors the affected entities). Every entity crosses a region boundary exactly once during the splits (7,000 = 5,000 initial mirrors + re-mirrors on migration).
- **Benchmark (`measure_pipeline.php`):** off 1.9 ms / gate 4.9 ms / apply 10.5 ms at 1000 entities, 0 mismatches, 0 lagged. The mirror now costs ~nothing after the first tick (`mirrored` 1,000 vs `skipped` 99,000); the remaining apply-mode cost is the per-result component write-back.

### Phase 10 — Complete the data & API layer

| Step | Task |
|------|------|
| 10.1 | Full block registry coverage (all protocol-84 block IDs), block-state metadata (slab/stairs/doors). |
| 10.2 | **Unify Inventory types** — `api\inventory\Inventory` currently exposes `core\component\ItemStack`; make the facade consistently use the API `ItemStack`. |
| 10.3 | Held-slot single source of truth (core `InventoryComponent::$heldSlot` vs metadata `heldSlot`). |
| 10.4 | World persistence round-trip: load → store → mutate → save → reload equality. |

### Phase 11 — Tests & hardening

| Step | Task |
|------|------|
| 11.1 | Unit tests: ChunkStore, BlockRegistry, ItemRegistry, Inventory, services. |
| 11.2 | Threading determinism tests (9.1 merge == main-thread result). |
| 11.3 | Memory profiling (loaded-chunk budget, archetype arrays) + load tests. |

### Phase 12 — Gameplay depth

| Step | Task |
|------|------|
| 12.1 | Real AI behaviors (attack/retreat cooldowns, pathfinding via ThreadingPort). |
| 12.2 | Combat + damage integration across services/systems; death/drops/loot tables. |
| 12.3 | Crafting/container recipes via registry data. |
| 12.4 | Plugin jar loading + unified server-wide command registration (`Server::dispatchCommand`). |

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
| Entity tick (5000 entities) | main-thread | region-parallel <15 ms | <15 ms |
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
| 11.1-11.2 | ✅ | **`tests/` framework** (no deps, per-process isolation): 9 files, 30 tests, 412 assertions — incl. pipeline determinism, apply-mode correctness, 1000-entity scale, async-gen determinism, region split/merge balance |
| 10.2 | ✅ | **ItemStack unification** — api `Inventory`/`Block`/`World::dropItem`/`ItemEntity` speak `api\inventory\ItemStack` exclusively; `toCore()`/`fromCore()` convert at the boundary; core component type stays in the storage layer only |
| 11.3-12 | ⏳ | Memory profiling; gameplay depth |
