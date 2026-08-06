# Architectural Decisions Log

**Project:** PocketMine-MP 2.0.0 (Khronos) Optimization
**Started:** 2026-08-06

---

## D001: Manual Factory-Based Composition Root

**Date:** 2026-08-06
**Status:** Accepted

### Context
Need dependency injection for the new hexagonal architecture with ports/adapters and ECS domain kernel.

### Decision
Use manual factory-based composition root in `bootstrap.php` — no external DI library (PHP-DI, Symfony DI, etc.).

### Rationale
- **Zero dependencies** — keeps bootstrap minimal, no vendor lock-in
- **Explicit wiring** — factory functions make dependency graph visible and debuggable
- **PHP 8.2 features** — `readonly`, constructor promotion, attributes make factories clean
- **Performance** — no container compilation/overhead at runtime
- **Testability** — easy to swap implementations in tests by calling different factories

### Implementation Pattern
```php
// bootstrap.php
function createKernel(): Kernel {
    $networkPort = createNetworkPort();
    $storagePort = createStoragePort();
    $worldGenPort = createWorldGenPort();
    $threadingPort = createThreadingPort();
    $commandPort = createCommandPort();
    $eventPort = createEventPort();
    $pluginPort = createPluginPort();
    
    return new Kernel(
        $networkPort,
        $storagePort,
        $worldGenPort,
        $threadingPort,
        $commandPort,
        $eventPort,
        $pluginPort,
    );
}

function createNetworkPort(): NetworkPort {
    return new Protocol84NetworkAdapter(
        new PacketSerializer(),
        Server::getInstance()->getNetwork(), // legacy bridge during migration
    );
}
// ... etc
```

### Consequences
- More boilerplate than auto-wiring
- Manual updates when adding new dependencies
- Full control over object lifecycles (singleton vs transient)

---

## D002: PHPStan Level 5+ for New/Refactored Code Only

**Date:** 2026-08-06
**Status:** Accepted

### Context
Existing codebase has no static analysis baseline. Adding PHPStan to entire codebase would block progress.

### Decision
Configure PHPStan level 5 (or higher) **only for new/refactored code paths**: `src/pocketmine/domain/`, `src/pocketmine/adapter/`, `src/pocketmine/port/`. Legacy code in `src/pocketmine/legacy/` and untouched modules excluded.

### Rationale
- **Incremental adoption** — new code is clean from day one
- **No legacy noise** — avoids 1000s of errors from untouched code
- **Enforces architecture** — domain/adapter/port must be strictly typed
- **CI gate** — new PRs must pass PHPStan on changed files

### Configuration
```neon
# phpstan.neon
parameters:
    level: 5
    paths:
        - src/pocketmine/domain
        - src/pocketmine/adapter
        - src/pocketmine/port
    excludes_analyse:
        - src/pocketmine/legacy/*
        - src/pocketmine/entity/*  # legacy, being migrated
        - src/pocketmine/level/*   # legacy, being migrated
        - src/pocketmine/network/protocol/*  # FROZEN
    ignoreErrors:
        - '#^Unsafe usage of new static#'  # allow in legacy
```

### Consequences
- Legacy code remains unchecked (acceptable — being replaced)
- New code must be strictly typed
- Gradual expansion of analyzed paths as migration progresses

---

## D003: No Test Suite Initially

**Date:** 2026-08-06
**Status:** Accepted

### Context
No existing test suite. Building one from scratch would delay architecture work.

### Decision
Defer test infrastructure. Focus on:
1. Architecture correctness (enforced by PHPStan, architecture rules)
2. Manual verification via server startup + basic gameplay
3. Protocol compliance = "don't touch protocol code" rule

### Rationale
- **Architecture first** — tests for wrong architecture are waste
- **Protocol frozen** — protocol 84 compliance verified by not modifying it
- **ECS query model** — hard to unit test; integration testing via server more valuable
- **Threading** — requires running server under load; unit tests insufficient

### Future
- Add PHPUnit + integration test harness in Phase 3-4
- Property-based testing for ECS invariants (archetype consistency)
- Load testing for threading phases

---

## D004: pmmpthread v6.3.0

**Date:** 2026-08-06
**Status:** Accepted

### Context
Threading extension version confirmed.

### Decision
Target **pmmp/ext-pmmpthread v6.3.0** (PHP 8.1/8.2 compatible pthreads fork).

### Implications
- Full `Thread`, `Worker`, `ThreadSafe`, `Synchronized`, `Condition`, `Threaded` API available
- `Worker::stack()`, `Thread::start()`, `Thread::join()` work as expected
- `Threaded` objects can be shared across threads (with synchronization)
- PHP 8.2 JIT may improve worker thread performance

### Version Pinning
```json
// composer.json (if extension managed via PECL/composer)
"require": {
    "pmmp/ext-pmmpthread": "^6.3.0"
}
```

---

## D005: Protocol 84 = Frozen Boundary, No Tests

**Date:** 2026-08-06
**Status:** Accepted

### Context
Server targets Minecraft PE 0.15.10 (protocol 84) specifically. Protocol implementation must not change.

### Decision
**Treat `src/pocketmine/network/protocol/` as a frozen external boundary.**
- No modifications, refactoring, "cleanup", or tests added
- No new code in `domain/`, `adapter/`, `port/` may `use pocketmine\network\protocol\*`
- All network serialization lives in `adapter/driven/network/Protocol84NetworkAdapter.php` only
- Enforcement: PHPStan rule + grep check in CI

### Rationale
- **Scope containment** — protocol is a known-working black box
- **Risk elimination** — zero chance of breaking client compatibility
- **Focus** — effort goes to architecture/threading, not protocol maintenance

### Enforcement Rules
1. `grep -r "network\\protocol" src/pocketmine/domain/` → must be empty
2. `grep -r "network\\protocol" src/pocketmine/adapter/` → only in `adapter/driven/network/`
3. `grep -r "network\\protocol" src/pocketmine/port/` → must be empty
4. PHPStan: `forbiddenClasses` for protocol types in domain/adapter/port

### Adapter Pattern
```php
// adapter/driven/network/Protocol84NetworkAdapter.php
final class Protocol84NetworkAdapter implements NetworkPort {
    private Network $legacyNetwork; // wraps existing Network.php
    
    public function sendPacket(PlayerRef $player, DataPacket $packet): void {
        // Translate domain DTO → protocol packet → legacyNetwork->send()
    }
}
```

---

## D006: Establish Tick-Profiling Baseline Early

**Date:** 2026-08-06
**Status:** Accepted

### Context
No performance baseline exists. Need measurements before/after threading work.

### Decision
Add **tick-profiling baseline measurement** as Step 0.5 in Phase 0, before any threading implementation.

### Method
- **Primary:** Manual high-resolution timing in `Server::tick()` using `hrtime(true)`
- **Secondary:** Xdebug profiler output for call graphs (optional, for deep analysis)
- **Tertiary:** Blackfire.io if available (not required)

### Implementation
```php
// In Server::tick() — temporary for baseline
private function tick(): bool {
    $start = hrtime(true);
    // ... existing tick logic ...
    $end = hrtime(true);
    $this->tickDurations[] = ($end - $start) / 1_000_000; // ms
    if (count($this->tickDurations) > 1000) array_shift($this->tickDurations);
    return true;
}

// Baseline measurement command
// php -r "require 'bootstrap.php'; $kernel = createKernel(); $kernel->run(1000); printStats();"
```

### Metrics to Capture
| Metric | Description |
|--------|-------------|
| Mean tick time (ms) | Average over 1000 ticks |
| P95/P99 tick time | Tail latency |
| Tick time std dev | Jitter |
| Memory growth | MB per 1000 ticks |
| Entity count | Active entities during test |
| Player count | Connected players during test |

### Test Scenarios
1. **Idle:** 0 players, flat world, 1000 ticks
2. **Light:** 5 players, flat world, 1000 ticks
3. **Medium:** 20 players, normal world, 1000 ticks
4. **Chunk load:** 1 player moving fast (forces chunk gen), 1000 ticks

### Output
Document results in `docs/BASELINE.md` with:
- Raw numbers
- Hardware specs (CPU, RAM, PHP version)
- Server config (view distance, tick rate settings)
- Commit hash of baseline measurement

---

## Future Decisions (Placeholders)

| ID | Topic | Target Phase |
|----|-------|--------------|
| D007 | Archetype storage format (struct-of-arrays vs array-of-objects) | Phase 1 |
| D008 | Query compilation strategy (JIT vs interpreted) | Phase 1 |
| D009 | Double-buffering vs copy-on-write for parallel components | Phase 5 |
| D010 | Region partitioning algorithm (fixed grid vs quadtree vs density) | Phase 6 |
| D011 | Cross-region entity migration protocol (sync vs async) | Phase 6 |
| D012 | Plugin event ordering across regions (total order vs causal) | Phase 6 |