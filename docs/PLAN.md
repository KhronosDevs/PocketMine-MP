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
| 9.2 | **NetworkThread batching** — feed real outbound payloads, drain `sendQueue` on the main thread and send. | Batch compression is already implemented on the worker. |
| 9.3 | **Async chunk generation** — real worker pool behind `ParallelGeneratorAdapter::generateChunk`. | Safest first win; per-chunk tasks are embarrassingly parallel. |
| 9.4 | **Cross-region migration + load balancing** — activate migration queues; dynamic region splitting by entity density. | Completes the region model (v1 step 6.6). |
| 9.5 | **Benchmark & scaling proof** — re-run `measure_baseline.php`; measure speedup vs the single-threaded baseline. | Success criteria in §7. |

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
| Chunk generation (16 chunks) | sequential | parallel (4+ workers) <50 ms | <30 ms |
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
| 9 | 🔄 **next** | Wire the threading (§5) |
| 10 | ✅ (mostly) | Real data layer: ChunkStore, BlockRegistry, ItemRegistry, WorldConfig; zero stubs |
| 11-12 | ⏳ | Tests/hardening; gameplay depth |
