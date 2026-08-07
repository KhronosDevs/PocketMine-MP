# Progress Tracker

**Plan Version:** docs/PLAN.md
**Last Updated:** 2026-08-07
**Current Phase:** 8 (API ECS Rewrite)
**Current Branch:** api-ecs-rewrite
**Base Commit:** 1cfc650 (master after PR #25 merge)

---

## Phase Status Overview

| Phase | Status | Branch | PR | Notes |
|-------|--------|--------|-----|-------|
| 0: Foundation | ✅ Done | foundation-bootstrap | #18 | Bootstrap, ports, ECS core, threading port |
| 1: ECS Components | ✅ Done | ecs-components-basic | #19 | 17 components, EntityRef, 4 systems |
| 2: Infrastructure Adapters | ✅ Done | adapter-implementation | #20 | Network, storage, worldgen, thread pool (NEW) |
| 3: Core Gameplay Services | ✅ Done | service-core-gameplay | #21 | Player, chunk, block, entity, combat, inventory |
| 4: **New Plugin API** | ✅ Done | api-ecs-plugin | #22 | **BREAKING** — EntityRef, Query, System, Event, Command, Scheduler |
| 5: Archetype Parallelism | ✅ Done | multithread-archetype-parallelism | #23 | Movement, Effect, Physics, AI parallel execution |
| 6: Region-Based Architecture | ✅ Done | region-based-architecture | #24 | RegionWorld, RegionThread, coordination, network threads |
| 7: Polish & Optimize | ✅ Done | polish-optimize | #25 | Storage, queries, memory, network batching, benchmarks |
| 8: **API ECS Rewrite** | ✅ Done | api-ecs-rewrite | #26 | **Complete ECS-based API rewrite** — Entity, World, Server, Inventory, Block, Scheduler, Command, Permission, Event, Plugin; PHPStan clean, legacy code fully removed |
| 8.5: World Rename | ✅ Done | api-ecs-rewrite | #26 | `Level` API renamed to `World` (Server getWorlds/getDefaultWorld/loadWorld, Block getWorld) |
| 9: Wire the Threading | ✅ Done | followups-9.2c-9.5 / 9.4b / 9.5b | #27 + #28 + next | RegionThread snapshot pipeline, NetworkThread batching, async chunk gen, migration + dynamic load balancing, pipeline hot-path optimization, scaling proof |
| 10: Complete Data & API Layer | ✅ Mostly | api-ecs-rewrite | #26 | ChunkStore, BlockRegistry, ItemRegistry, WorldConfig; all `// Simplified` stubs removed; real block/chunk/inventory/world APIs |

---

## Phase 0: Foundation — Detailed Steps

| Step | Task | Status | Commit | Notes |
|------|------|--------|--------|-------|
| 0.0 | Create branch `foundation-bootstrap` with rollback anchor | ✅ | eb32941 | `git checkout -b foundation-bootstrap && git commit --allow-empty -m "chore: rollback anchor for foundation-bootstrap"` |
| 0.1 | `bootstrap.php` + manual DI composition root + `Kernel.php` | ✅ | — | No external DI lib; factory functions in bootstrap |
| 0.2 | Port interfaces in `src/pocketmine/port/` | ✅ | — | NetworkPort, StoragePort, WorldGenPort, ThreadingPort, CommandPort, EventPort, PluginPort + DTOs |
| 0.3 | ECS core in `src/pocketmine/domain/ecs/` | ✅ | — | Component, Resource, System, World, Query, Archetype, ComponentRegistry, ResourceRegistry, SystemScheduler, Entity, EntityBuilder |
| 0.4 | ThreadingPort + PmmpThreadPool adapter | ✅ | — | WorkerThread, ThreadedTask, FutureImpl, PmmpThreadPool implementing ThreadingPort |
| 0.5 | Establish tick-profiling baseline | ✅ | — | Added to Kernel::run(); measure_baseline.php script created |
| 0.6 | PHPStan config (level 5) for new code only | ✅ | — | `phpstan.neon` with paths: domain/, adapter/, port/ |
| 0.7 | Update PROGRESS.md, DECISIONS.md | ✅ | — | Record decisions from clarifying questions |

---

## Phase 1: ECS Components — Detailed Steps

| Step | Task | Status | Commit | Notes |
|------|------|--------|--------|-------|
| 1.0 | Create branch `ecs-components-basic` with rollback anchor | ✅ | 55d0fee | `git checkout -b ecs-components-basic && git commit --allow-empty -m "chore: rollback anchor for ecs-components-basic"` |
| 1.1 | Additional components: Rotation, Collision, Effect, Attribute, Inventory, AIState, Path | ✅ | 002c485 | Core gameplay components |
| 1.2 | EntityRef opaque handle for plugin API | ✅ | 002c485 | Stable reference across threads/migrations |
| 1.3 | Register all components in Kernel::registerBuiltinComponents() | ✅ | 002c485 | All 17 components registered |
| 1.4 | Component serialization helpers (for storage/network) | ✅ | 002c485 | ComponentSerializer with serialize/deserialize |
| 1.5 | New systems: EffectSystem, AISystem | ✅ | 002c485 | Parallel effect ticking, sequential AI |
| 1.6 | Update PROGRESS.md | ✅ | — | Track progress |

---

## Phase 2: Infrastructure Adapters — Detailed Steps

| Step | Task | Status | Commit | Notes |
|------|------|--------|--------|-------|
| 2.0 | Create branch `adapter-implementation` with rollback anchor | ✅ | 2798a89 | `git checkout -b adapter-implementation && git commit --allow-empty -m "chore: rollback anchor for adapter-implementation"` |
| 2.1 | `adapter-network-protocol84` — **NEW** Protocol84NetworkAdapter (direct RakLib UDP, protocol 84) | ✅ | 6036942 | Own network thread, packet encode/decode, PlayerRef mapping |
| 2.2 | `adapter-storage-anvil` — **NEW** AnvilStorageAdapter (direct .mca I/O) | ✅ | 6036942 | Region files, chunk serialization, level.dat |
| 2.3 | `adapter-worldgen-parallel` — **NEW** ParallelGeneratorAdapter | ✅ | 6036942 | Chunk gen, populate, light calculation |
| 2.4 | `adapter-thread-pool` — PmmpThreadPool (round-robin, parallelMap) | ✅ | 6036942 | Work distribution patterns |
| 2.5 | Update PROGRESS.md | ✅ | — | Track progress |

---

## Phase 3: Core Gameplay Services — Detailed Steps

| Step | Task | Status | Commit | Notes |
|------|------|--------|--------|-------|
| 3.0 | Create branch `service-core-gameplay` with rollback anchor | ✅ | d6ac09d | `git checkout -b service-core-gameplay && git commit --allow-empty -m "chore: rollback anchor for service-core-gameplay"` |
| 3.1 | `service-player-lifecycle` — PlayerJoinService, PlayerLeaveService, PlayerRespawnService | ✅ | 7ce67ce | EntityRef, PositionComponent, HealthComponent, InventoryComponent |
| 3.2 | `service-chunk-management` — ChunkLoadService, ChunkUnloadService, ChunkSendService | ✅ | 7ce67ce | StoragePort, ChunkData, NetworkPort |
| 3.3 | `service-block-interaction` — BlockBreakService, BlockPlaceService, BlockUpdateService | ✅ | 7ce67ce | ECS commands, CollisionComponent, InventoryComponent |
| 3.4 | `service-entity-management` — EntitySpawnService, EntityDespawnService, EntityInteractionService | ✅ | 7ce67ce | EntityRef, EntityBuilder, QueryBuilder |
| 3.5 | `service-combat` — CombatService, DamageService, KnockbackService | ✅ | 7ce67ce | AttributeComponent, HealthComponent, VelocityComponent |
| 3.6 | `service-inventory` — InventoryService, CraftingService, ContainerService | ✅ | 7ce67ce | InventoryComponent, ItemStack |
| 3.7 | Update PROGRESS.md | ✅ | — | Track progress |

---

## Phase 4: New Plugin API — Detailed Steps

| Step | Task | Status | Commit | Notes |
|------|------|--------|--------|-------|
| 4.0 | Create branch `api-ecs-plugin` with rollback anchor | ✅ | 0723b5c | `git checkout -b api-ecs-plugin && git commit --allow-empty -m "chore: rollback anchor for api-ecs-plugin"` |
| 4.1 | `api-ecs-plugin` — Plugin base class using EntityRef, QueryBuilder, System registration | ✅ | b625564 | Kernel access, service access, ECS integration |
| 4.2 | `api-event-system` — Typed event bus (not string-based), priority via PHP attributes | ✅ | b625564 | Event classes, listener registration, async dispatch |
| 4.3 | `api-command-system` — Command registration via attributes, typed arguments, tab completion | ✅ | b625564 | Command classes, argument parsing, permission checks |
| 4.4 | `api-scheduler` — Task scheduling via System registration or async Task submission | ✅ | b625564 | Repeating/delayed tasks, async task submission |
| 4.5 | `api-permissions` — Permission system integrated with EntityRef metadata | ✅ | b625564 | Permission checks on EntityRef, default permissions |
| 4.6 | `api-world-access` — Chunk/Block/Entity access via Query, not Level/Entity getters | ✅ | b625564 | QueryBuilder, chunk iteration, block/entity access |
| 4.7 | Update PROGRESS.md | ✅ | — | Track progress |

---

## Phase 5: Archetype Parallelism — Detailed Steps

| Step | Task | Status | Commit | Notes |
|------|------|--------|--------|-------|
| 5.0 | Create branch `multithread-archetype-parallelism` with rollback anchor | ✅ | ca5ca63 | `git checkout -b multithread-archetype-parallelism && git commit --allow-empty -m "chore: rollback anchor for multithread-archetype-parallelism"` |
| 5.1 | `multithread-movement` — PositionComponent + VelocityComponent parallel updates | ✅ | 3f35600 | Double-buffered PositionComponent for parallel writes |
| 5.2 | `multithread-effects` — EffectComponent parallel tick | ✅ | 3f35600 | Already PARALLEL, optimize archetype iteration |
| 5.3 | `multithread-physics` — PhysicsSystem parallel broad-phase collision | ✅ | 3f35600 | SpatialIndex per archetype, double-buffered VelocityComponent |
| 5.4 | `multithread-ai` — AISystem parallel per archetype | ✅ | 3f35600 | Pathfinding via ThreadingPort, double-buffered AIState |
| 5.5 | `multithread-chunk` — ChunkParallelSystem for block updates | ✅ | 3f35600 | Per-chunk parallel block updates |
| 5.6 | `multithread-scheduler` — SystemScheduler parallel execution via ThreadingPort | ✅ | 3f35600 | AwaitAll on parallel systems |
| 5.7 | Update PROGRESS.md | ✅ | — | Track progress |

---

## Phase 6: Region-Based Architecture — Detailed Steps

| Step | Task | Status | Commit | Notes |
|------|------|--------|--------|-------|
| 6.0 | Create branch `region-based-architecture` with rollback anchor | ✅ | 37860e3 | `git checkout -b region-based-architecture && git commit --allow-empty -m "chore: rollback anchor for region-based-architecture"` |
| 6.1 | `region-world` — RegionWorld ECS World slice per spatial region (16×16 chunks) | ✅ | b33d6a1 | RegionWorld with spatial bounds, chunk ownership |
| 6.2 | `region-thread` — RegionThread per region with independent ECS tick loop | ✅ | b33d6a1 | Dedicated thread, command/sync queues |
| 6.3 | `region-coordination` — CoordinationThread for entity migration, global events | ✅ | b33d6a1 | Cross-region migration, plugin dispatch, chunk coordination |
| 6.4 | `region-network` — NetworkThread for RakLib I/O + packet encoding | ✅ | b33d6a1 | Dedicated network thread, batch encoding |
| 6.5 | `region-migration` — Cross-region entity migration with snapshots | ✅ | b33d6a1 | EntityRef transfer, component snapshot |
| 6.6 | `region-load-balancing` — Dynamic region splitting/merging | ✅ | — | Workload-aware partitioning |
| 6.7 | Update PROGRESS.md | ✅ | — | Track progress |

---

## Phase 7: Polish & Optimize — Detailed Steps

| Step | Task | Status | Commit | Notes |
|------|------|--------|--------|-------|
| 7.0 | Create branch `polish-optimize` with rollback anchor | ✅ | 6c291d9 | `git checkout -b polish-optimize && git commit --allow-empty -m "chore: rollback anchor for polish-optimize"` |
| 7.1 | `optimize-archetype-storage` — Compact component arrays, reduce indirection | ✅ | 04f0ea3 | Struct-of-arrays, eliminate object overhead |
| 7.2 | `optimize-query-performance` — Query caching, archetype indexing | ✅ | 04f0ea3 | Query plan caching, archetype bitmap index |
| 7.3 | `optimize-memory-layout` — Struct-of-arrays, reduce object allocation | ✅ | 04f0ea3 | Flat arrays, object pooling, weak refs |
| 7.4 | `optimize-network-batching` — Batch NetworkSyncComponent flushes across regions | ✅ | 04f0ea3 | Cross-region packet batching |
| 7.5 | `benchmark-profile` — Full profiling, tick rate analysis, scalability testing | ✅ | 04f0ea3 | Load testing, flame graphs, regression tests |
| 7.6 | Update PROGRESS.md | ✅ | — | Track progress |

---

## Phase 8: API ECS Rewrite — Detailed Steps

| Step | Task | Status | Commit | Notes |
|------|------|--------|--------|-------|
| 8.0 | Create branch `api-ecs-rewrite` with rollback anchor | ✅ | 29b5081 | `git checkout -b api-ecs-rewrite && git commit --allow-empty -m "chore: rollback anchor for api-ecs-rewrite"` |
| 8.1 | Entity API: Entity, Player, Living, Monster, Animal, Zombie, Skeleton, Creeper, Pig, ItemEntity, EntityFactory | ✅ | 2079019 | EntityRef-based with component accessors |
| 8.2 | Level API: Level with chunk/entity management via ECS services | ✅ | 2079019 | ChunkLoadService, EntitySpawnService, etc. |
| 8.3 | Server API: Server singleton with ECS integration, service access | ✅ | 2079019 | 18 services accessible via KernelAccessor |
| 8.4 | Inventory API: Inventory, ItemStack with full inventory management | ✅ | 2079019 | Stacking, NBT, enchantments |
| 8.5 | Block API: Block with level integration | ✅ | 2079019 | Block operations via Level |
| 8.6 | Scheduler API: Scheduler with task management and system registration | ✅ | 2079019 | Task management + System registration |
| 8.7 | Command API: Command, CommandMap, CommandSender, attributes | ✅ | 2079019 | Attribute-based commands |
| 8.8 | Permission API: Permission, PermissionManager with inheritance | ✅ | 2079019 | EntityRef metadata-based checks |
| 8.9 | Event API: EventBus, typed events (Player, Block, Entity), attributes | ✅ | 2079019 | Typed events with EventHandler attribute |
| 8.10 | Plugin API: Plugin base, PluginManager, PluginDescription, Logger, Config | ✅ | 2079019 | KernelAccessor provides full ECS/services/ports |
| 8.11 | World API: WorldAccessor with ECS query integration | ✅ | 2079019 | QueryBuilder integration |
| 8.12 | Update PROGRESS.md | ✅ | — | Track progress |

---

## Decision Log (links to docs/DECISIONS.md)

| ID | Decision | Date | Context |
|----|----------|------|---------|
| D001 | Manual factory-based composition root | 2026-08-06 | No external DI library |
| D002 | PHPStan level 5+ for new/refactored code only | 2026-08-06 | No baseline; fresh start |
| D003 | No test suite initially | 2026-08-06 | Focus on architecture first |
| D004 | pmmpthread v6.3.0 | 2026-08-06 | Confirmed version |
| D005 | Protocol 84 = frozen boundary, no tests | 2026-08-06 | Must remain untouched |
| D006 | Establish tick-profiling baseline early | 2026-08-06 | Before any threading work |
| D007 | **New Plugin API = breaking changes, no legacy compat** | 2026-08-07 | Clean ECS-based API (EntityRef, Query, System) |
| D008 | **Double-buffered components for parallel writes** | 2026-08-07 | PositionComponent, VelocityComponent, AIStateComponent |
| D009 | **Region size: 16×16 chunks (256×256 blocks)** | 2026-08-07 | Balance between parallelism and migration overhead |
| D010 | **Struct-of-arrays layout for archetype storage** | 2026-08-07 | Flat arrays for cache performance |

---

## Branch/Commit/PR Tracking

| Branch | Base Commit | Current Commit | PR # | Status |
|--------|-------------|----------------|------|--------|
| foundation-bootstrap | eb32941 | ac5b861 | #18 | ✅ Merged |
| ecs-components-basic | ac5b861 | 378400c | #19 | ✅ Merged |
| adapter-implementation | 3b66cf1 | 63a1b02 | #20 | ✅ Merged |
| service-core-gameplay | 49cf545 | 7ce67ce | #21 | ✅ Merged |
| api-ecs-plugin | 8f3a171 | fc1c65d | #22 | ✅ Merged |
| multithread-archetype-parallelism | 43996ce | 33f6ff6 | #23 | ✅ Merged |
| region-based-architecture | c7e445f | 1c1baf4 | #24 | ✅ Merged |
| polish-optimize | 942082e | 582c377 | #25 | ✅ Merged |
| api-ecs-rewrite | 1cfc650 | 2079019 | #26 | 🔄 Active (PR opened) |

---

## Next Actions

1. ✅ Phase 0, 1, 2, 3, 4, 5, 6, 7 complete (PRs #18, #19, #20, #21, #22, #23, #24, #25 merged)
2. ✅ Create `api-ecs-rewrite` branch with rollback anchor
3. ✅ Implement Entity API (Entity, Player, Living, Monster, Animal, Zombie, Skeleton, Creeper, Pig, ItemEntity, EntityFactory)
4. ✅ Implement Level API (Level with ECS services)
6. ✅ Implement Server API (Server singleton with ECS integration)
7. ✅ Implement Inventory API (Inventory, ItemStack)
8. ✅ Implement Block API (Block with level integration)
9. ✅ Implement Scheduler API (Scheduler with task management)
10. ✅ Implement Command API (Command, CommandMap, attributes)
11. ✅ Implement Permission API (Permission, PermissionManager)
13. ✅ Implement Event API (EventBus, typed events, attributes)
14. ✅ Implement Plugin API (Plugin, PluginManager, Logger, Config)
14. ✅ Implement World API (WorldAccessor)
15. ✅ Remove all legacy PocketMine code (1,100+ files: Player, Server, Entity, blocks, items, plugins, dead packets)
16. ✅ Fix threading layer for pmmpthread v6.3 (ThreadSafe-based FutureImpl, synchronous pool submit)
17. ✅ Fix runtime landmines (Info constants, Binary ENDIANNESS/bcmath, spawn() EntityRef, service layer)
18. ✅ PHPStan 0 errors (was 142); lint clean; boot/full/API tests pass; benchmark mean 0.077 ms
19. ✅ Update docs, commit, push, open PR #26
20. ✅ Rename API `Level` → `World` (Server world methods, Block getWorld, Events import cleanup)
21. ✅ **Phase 10: real data layer** — new `core/resource/` resources: ChunkStore (binary-string block store), BlockRegistry (~70 block property table), ItemRegistry (stack/durability/names), WorldConfig (time/seed/spawn/rules); ChunkLoad/Unload, BlockBreak/Place services operate on real chunk data; api World/Block/Inventory wired end-to-end
22. ✅ Remove **all** `// Simplified` and `// Would` placeholders (Server uptime/TPS/dispatch, Player sendMessage/kick via NetworkPort, PluginCommand executors, Animal owner/breed, Plugin config files, Scheduler ownership, AI attack, ItemStack registry lookups, `canAddItem` recursion bug)
23. ✅ **Tests framework** — `tests/` with zero dependencies (`tests/run.php` + `helpers.php`, per-process isolation): 6 files, 21 tests, 277 assertions; `composer test` wired. Covers ChunkStore, registries, gameplay services, ECS core, and the pipeline
24. ✅ **Phase 9.1: lockstep region pipeline** — kernel mirrors Position+Velocity entities to the `RegionThread` each tick; worker integrates movement+gravity exactly once per `tick` command (seq-tagged); results merged back with a bounded sync point, stale results dropped. Determinism gate: **0 mismatches** vs main-thread simulation; apply mode (worker authoritative) verified exact. Flags: `setRegionPipelineEnabled` / `setRegionPipelineApplyMode`; stats via `getRegionPipelineStats()`
25. ✅ **Fixed 3 real ECS bugs found by the new tests** — `EntityBuilder::at()` missing import; `Archetype::allocateIndex()` off-by-one (first entity at index -1, never processed by systems); missing archetype migration when an entity's component set changes after spawn (teleport broke system queries)
26. ✅ **Binary snapshot transport (9.2a)** — JSON replaced with a compact binary protocol (52 bytes/entity, one batched message per tick per region; floats round-trip bit-exactly). At 1000 entities the gate now receives **100,000/100,000 results in-window, 0 lagged, 0 mismatches** and drops from 130.9 ms → **10.1 ms** per tick; apply mode 10.5 ms, all results applied
27. ✅ **Archetype reconcile dirty-flag (9.2b)** — `Entity::set/remove` flip a per-entity flag so `World::reconcileArchetypes` skips untouched entities (O(entities) bool reads per tick instead of array_keys+sort)
28. ✅ **ItemStack unification (10.2)** — api `Inventory`, `Block`, `World::dropItem`, `ItemEntity` all speak `api\inventory\ItemStack`; `toCore()`/`fromCore()` convert at the boundary; core component type confined to the storage layer
29. ✅ **Diff-only mirror (9.2c)** — kernel tracks worker-stored snapshots and predicts post-integration state with bit-exact `integrateOnce`; unchanged entities skip re-mirror (no double integration in apply mode — drain records applied state). At 1000 entities × 100 ticks: **mirrored 1,000 (first tick only) / skipped 99,000, 0 mismatches, 0 lagged**; gate tick 10.1 ms → 4.9 ms
30. ✅ **NetworkThread batching (9.3)** — adapter uses the **kernel's single NetworkThread** (injected via `setNetworkThread`; adapter no longer spawns its own); outbound frames **coalesced per destination** into batched datagrams (100 frames → 2 sendto calls, all bytes preserved)
31. ✅ **Async chunk generation (9.4)** — `ParallelGeneratorAdapter` refactored to a **pure static** generator (`generateChunkPure`); each chunk is a `ChunkGenerationTask` (`extends pmmp\thread\Runnable`) on a **real pmmpthread `Pool`** (lazy, nproc−2 workers); results return via a serialized string in a `ThreadSafe` cell; deterministic across worker threads
32. ✅ **Scale-correctness test (`tests/07`)** — 1000 entities apply-mode over 10 ticks: all 10,000 results applied, exact integration positions, **0 mismatches / 0 lagged**; gate at 1000 entities stays exact with diff-only; async-gen determinism verified; suite now **7 files / 25 tests / 371 assertions**
33. ✅ **Batch gen wired (9.4)** — `ChunkLoadService::loadChunks()` bulk-loads through the parallel `WorldGenPort::generateChunks()` path (shared `materializeChunk`); `loadChunk()` delegates. Benchmark `measure_chunkgen.php`: **256 chunks par 7.5–8.1× vs sequential, ~3× vs main-thread pure; 2048 chunks 8.4×** (558 ms vs 4.7 s), identical terrain across pure/seq/par
34. ✅ **Noise perf fix** — terrain height noise multiplied un-masked ints past 2^63 → PHP float conversions (`&`/`>>` casts) were 46× slower single-threaded and ~1,000× slower under 8-worker concurrency (pool appeared serialized). Rewritten with 31-bit-masked int hashing (`hash31`); generator is int-only and the pool now genuinely parallelizes. Also: allocation-lean flat/terrain block builders (bit-identical output verified), `workerCount` capped at 4, results decoded inside the `Pool::collect()` callback (safe pmmp pattern; cells released per-task so big batches stay within 128M)
35. ✅ **Cross-region migration wired (9.4b)** — kernel creates `regionCount` column-split regions (default 1); diff-only mirror tracks owning region (`pipelineRegion`); boundary-crossers are despawned from the old region and pushed to the new region's `migrationQueue` before the tick (drained at tick-processing time, ordering guaranteed). `tests/08_migration_test.php`: entities crossing boundaries stay exact, 0 mismatches/lagged
36. ✅ **Dynamic region load balancing (9.4b)** — region bounds are `ThreadSafe` (mutable after start); a region over `maxEntitiesPerRegion` splits at the entity-weighted median chunk (both halves must clear the merge floor, preventing split/merge oscillation — 5000-entity workload: 23 splits/17 merges → 6 splits/0 merges); a region under threshold/5 folds into its adjacent neighbor (bounds absorb, bookkeeping resets, worker joined). Splits spawn a fresh `RegionThread` on the fly; migration rides the existing despawn+migrate path. `run()` resumable via `setAutoShutdownOnRun(false)`. `tests/09_region_balance_test.php`: 1500 entities split to 4+ balanced regions, 0 mismatches/lagged; apply mode exact; sparse population merges back down
37. ✅ **Pipeline hot-path optimization (9.5b)** — flat stored-state arrays (mirror loop allocates nothing), per-entity (chunk, region) cache + epoch (skips `ownsChunk` ThreadSafe reads in steady state), gated balance bookkeeping (counts only when a split threshold is set; chunk-Xs only over threshold), block results wire layout (kernel: 2 `unpack` per batch; worker integrates on packed doubles). `measure_pipeline` gains per-phase profiling. Scale: **5000 entities apply → 15.7–17.4 ms (from 26.9), 600,000/600,000 applied, 0 mismatches, 0 lagged**; gate 1000 entities stays exact with diff-only mirroring (1,000 mirrored / 99,000 skipped)
36. ⏳ **Next** — dynamic region splitting by entity density (load balancing); memory profiling; gameplay depth (see docs/PLAN.md §5/§11-12)