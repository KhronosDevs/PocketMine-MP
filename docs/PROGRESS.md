# Progress Tracker

**Plan Version:** docs/PLAN.md
**Last Updated:** 2026-08-07
**Current Phase:** 7 (Polish & Optimize)
**Current Branch:** polish-optimize
**Base Commit:** 942082e (master after PR #24 merge)

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
| 7: Polish & Optimize | 🔄 In Progress | polish-optimize | — | Storage, queries, memory, network batching, benchmarks |

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
| 7.1 | `optimize-archetype-storage` — Compact component arrays, reduce indirection | 🔄 | — | Struct-of-arrays, eliminate object overhead |
| 7.2 | `optimize-query-performance` — Query caching, archetype indexing | ⏳ | — | Query plan caching, archetype bitmap index |
| 7.3 | `optimize-memory-layout` — Struct-of-arrays, reduce object allocation | ⏳ | — | Flat arrays, object pooling, weak refs |
| 7.4 | `optimize-network-batching` — Batch NetworkSyncComponent flushes across regions | ⏳ | — | Cross-region packet batching |
| 7.5 | `benchmark-profile` — Full profiling, tick rate analysis, scalability testing | ⏳ | — | Load testing, flame graphs, regression tests |
| 7.6 | Update PROGRESS.md | ⏳ | — | Track progress |

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
| polish-optimize | 942082e | 6c291d9 | — | 🔄 Active |

---

## Next Actions

1. ✅ Phase 0, 1, 2, 3, 4, 5, 6 complete (PRs #18, #19, #20, #21, #22, #23, #24 merged)
2. ✅ Create `polish-optimize` branch with rollback anchor
3. 🔄 Implement archetype storage optimization (struct-of-arrays)
3. ⏳ Implement query performance optimization (caching, indexing)
4. ⏳ Implement memory layout optimization (flat arrays, pooling)
5. ⏳ Implement network batching optimization
6. ⏳ Run full profiling and benchmarking
7. ⏳ Update docs, commit, push, open PR