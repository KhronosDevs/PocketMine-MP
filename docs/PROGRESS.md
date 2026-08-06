# Progress Tracker

**Plan Version:** docs/PLAN.md
**Last Updated:** 2026-08-06
**Current Phase:** 1 (ECS Components)
**Current Branch:** ecs-components-basic
**Base Commit:** ac5b861 (master after PR #18 merge)

---

## Phase Status Overview

| Phase | Status | Branch | PR | Notes |
|-------|--------|--------|-----|-------|
| 0: Foundation | ✅ Done | foundation-bootstrap | #18 | Bootstrap, ports, ECS core, threading port |
| 1: ECS Components | 🔄 In Progress | ecs-components-basic | — | Rotation, Collision, Effect, Attribute, Inventory, AIState, Path, EntityRef |
| 2: Adapters | ⏳ Pending | — | — | Network (protocol 84), storage, worldgen, plugin |
| 3: App Services | ⏳ Pending | — | — | Player, chunk, block, entity, inventory services |
| 4: Legacy Strangler | ⏳ Pending | — | — | Entity, Level, Server wrappers; plugin compat |
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
| 1.1 | Additional components: RotationComponent, CollisionComponent, EffectComponent, AttributeComponent, InventoryComponent, AIStateComponent, PathComponent | ✅ | 002c485 | Core gameplay components |
| 1.2 | EntityRef opaque handle for plugin API | ✅ | 002c485 | Stable reference across threads/migrations |
| 1.3 | Register all components in Kernel::registerBuiltinComponents() | ✅ | 002c485 | All 17 components registered |
| 1.4 | Component serialization helpers (for storage/network) | ✅ | 002c485 | ComponentSerializer with serialize/deserialize |
| 1.5 | New systems: EffectSystem, AISystem | ✅ | 002c485 | Parallel effect ticking, sequential AI |
| 1.6 | Update PROGRESS.md | 🔄 | — | Track progress |

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

---

## Branch/Commit/PR Tracking

| Branch | Base Commit | Current Commit | PR # | Status |
|--------|-------------|----------------|------|--------|
| foundation-bootstrap | eb32941 | ac5b861 | #18 | ✅ Merged |
| ecs-components-basic | ac5b861 | 002c485 | — | 🔄 Active (pushed) |

---

## Next Actions

1. ✅ Create `ecs-components-basic` branch with rollback anchor commit
2. ✅ Implement additional components (Rotation, Collision, Effect, Attribute, Inventory, AIState, Path)
3. ✅ Implement EntityRef opaque handle
4. ✅ Register all components in Kernel
5. ✅ Add component serialization helpers
6. ✅ Add EffectSystem and AISystem
7. 🔄 Create PR for ecs-components-basic