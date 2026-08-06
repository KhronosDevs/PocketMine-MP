# Progress Tracker

**Plan Version:** docs/PLAN.md
**Last Updated:** 2026-08-07
**Current Phase:** 2 (Infrastructure Adapters)
**Current Branch:** (next: adapter-implementation)
**Base Commit:** 09efc4d (master after PLAN.md update)

---

## Phase Status Overview

| Phase | Status | Branch | PR | Notes |
|-------|--------|--------|-----|-------|
| 0: Foundation | ✅ Done | foundation-bootstrap | #18 | Bootstrap, ports, ECS core, threading port |
| 1: ECS Components | ✅ Done | ecs-components-basic | #19 | 17 components, EntityRef, 4 systems |
| 2: Infrastructure Adapters | 🔄 Next | adapter-implementation | — | Network (protocol 84), storage, worldgen, thread pool |
| 3: Core Gameplay Services | ⏳ Pending | — | — | Player, chunk, block, entity, combat, inventory |
| 4: **New Plugin API** | ⏳ Pending | — | — | **BREAKING** — EntityRef, Query, System, Event, Command, Scheduler |
| 5: Archetype Parallelism | ⏳ Pending | — | — | Chunk gen, block updates, pathfinding, AI, system scheduler |
| 6: Region-Based | ⏳ Pending | — | — | RegionWorld, RegionThread, coordination, network threads |
| 7: Polish & Optimize | ⏳ Pending | — | — | Storage, queries, memory, network batching, benchmarks |

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
| 2.5 | Update PROGRESS.md | 🔄 | — | Track progress |

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

---

## Branch/Commit/PR Tracking

| Branch | Base Commit | Current Commit | PR # | Status |
|--------|-------------|----------------|------|--------|
| foundation-bootstrap | eb32941 | ac5b861 | #18 | ✅ Merged |
| ecs-components-basic | ac5b861 | 378400c | #19 | ✅ Merged |
| adapter-implementation | 3b66cf1 | 6036942 | #20 | 🔄 Active (PR updated) |

---

## Next Actions

1. ✅ Phase 0 & 1 complete (PRs #18, #19 merged)
2. ✅ PLAN.md updated: Phase 4 = New Plugin API (breaking), removed Legacy Strangler Fig
3. ✅ Create `adapter-implementation` branch with rollback anchor
4. ✅ Implement **NEW** Protocol84NetworkAdapter (direct RakLib UDP, protocol 84)
5. ✅ Implement **NEW** AnvilStorageAdapter (direct .mca I/O)
6. ✅ Implement **NEW** ParallelGeneratorAdapter (chunk gen, populate, light)
7. ✅ Implement PmmpThreadPool improvements (round-robin, parallelMap)
8. ✅ Update docs, commit, push, PR #20 updated