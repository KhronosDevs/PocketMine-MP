# Khronos — AI Continuation Guide (Project State & Operating Procedures)

> **Purpose:** This file is the single source of truth for any AI agent (or human)
> continuing the modernization of this project. It contains **the complete original
> brief verbatim** (everything the owner asked for), the **current progress** with
> revertible checkpoints, the **exact validation procedure** that has caught real
> regressions, and the **step-by-step path to completion**.
>
> Last updated: after Module 10 (Commands) — commit `9851c8c`.

---

## 0. TL;DR — Where we are and what's next

- **Project:** Khronos (fork of PocketMine-MP 2.0.0), a Minecraft: Pocket Edition
  server written in PHP, with an external plugin ecosystem.
- **Branch:** `php-update` · **Runtime:** custom PHP `bin/php7/bin/php` = **PHP 8.2.32 (ZTS)**.
- **Goal:** modernize to clean, maintainable PHP 8.2 **without changing behavior**,
  plugin compatibility, network protocols, packet formats, or serialization formats.
- **Progress: 10 of 12 modules done.** Each committed as its own revertible checkpoint.
- **Next module:** **Module 11 — Plugins** (`src/pocketmine/plugin/`, 13 files) — depends on the now-typed command/Player/Server APIs.

---

## 1. THE COMPLETE ORIGINAL BRIEF (verbatim — do not lose this)

The owner's task, reproduced in full. **Every instruction here is binding.**

### 1.1 Role & mission

> You are a senior PHP engineer specialized in legacy code modernization,
> high-performance servers, software architecture, and large-scale refactoring.
>
> Your task is to completely modernize and refactor this project while preserving
> exactly the same behavior.
>
> This project is based on PocketMine-MP 2.0.0. It is a Minecraft server
> implementation with an external plugin ecosystem. Compatibility, stability, and
> server performance are absolute priorities.
>
> The goal is NOT to rewrite the project into a new framework or redesign
> everything from scratch. The goal is to carefully modernize the existing
> codebase into a clean, maintainable, PHP 8.2-compatible project.

### 1.2 Before changing anything

> 1. Fully analyze the repository structure.
> 2. Understand the architecture.
> 3. Identify:
>    - main entry points
>    - core systems
>    - critical execution paths
>    - highly coupled classes
>    - performance-sensitive code
>    - public APIs used by plugins
>    - possible risks
> 4. Create a dependency map of the project. *(Done — see `docs/DEPENDENCY_MAP.md`.)*

### 1.3 Work incrementally, one module at a time

> Do not perform a massive refactor all at once.
> Work incrementally by modules and finish one module completely before moving to another.
>
> Recommended order:
> 1. Core / Utils / Math  ✅
> 2. Network             ✅
> 3. Data structures     ✅
> 4. Items               ✅
> 5. Blocks              ✅
> 6. Entities            ✅
> 7. Level / World       ✅
> 8. Inventory           ✅
> 9. Player              ✅
> 10. Commands           ✅
> 11. Plugins            ⏭️ NEXT
> 12. Remaining systems
>
> For every module:
> - Analyze the current implementation first.
> - Explain the problems found.
> - Refactor carefully.
> - Validate that nothing broke.
> - Run tests or startup checks.
> - Only continue when the module is stable.

### 1.4 The start.sh validation rule (IMPORTANT)

> IMPORTANT: This project contains a start.sh script used to start the server.
>
> After each important refactor:
> - Run ./start.sh.
> - Check for PHP errors.
> - Check for warnings.
> - Check for fatal errors.
> - Check for crashes during startup.
> - Check that the server reaches the normal running state.
> - Fix any introduced issues before continuing.
>
> If start.sh requires permissions or environment setup, handle that properly.

> **Note from practice:** `./start.sh` drops into an interactive console loop.
> The CI-friendly equivalent used throughout this project is:
> `timeout 30 bin/php7/bin/php src/pocketmine/PocketMine.php --no-wizard --disable-ansi`
> then confirm the log reaches `Done (...)!` with no fatal/warning/notice.

### 1.5 Modernization goals (PHP 8.2)

> Update the codebase for PHP 8.2.
>
> Apply whenever safe:
> - declare(strict_types=1);
> - native type declarations
> - return types
> - typed properties
> - proper visibility modifiers
> - constructor property promotion
> - readonly properties when appropriate
> - final classes when extension is unnecessary
> - final methods when overriding should not happen
> - better encapsulation
>
> Use modern PHP features when they improve the code without breaking compatibility:
> - nullsafe operator
> - null coalescing operator
> - union types
> - intersection types
> - match expressions
> - enums only when they genuinely improve the design
> - attributes only when they have a clear purpose
>
> Do not force modern syntax just for the sake of modernization.

### 1.6 Code quality improvements

> Improve:
> - SOLID compliance
> - separation of responsibilities
> - class cohesion
> - dependency management
> - encapsulation
> - readability
> - maintainability
>
> Remove:
> - dead code
> - duplicated code
> - unnecessary comments
> - unnecessary complexity
> - unreachable code
>
> Improve:
> - variable names
> - method names
> - method organization
> - class organization
> - property organization
>
> Use native PHP features instead of custom solutions when appropriate.

### 1.7 Performance requirements

> This is a high-performance game server.
>
> Pay special attention to:
> - tick loop performance
> - memory usage
> - object allocation
> - unnecessary array copies
> - expensive loops
> - repeated calculations
> - unnecessary database/network operations
> - memory leaks
> - references that are no longer needed
> - caching opportunities
>
> Optimize carefully. Do not sacrifice readability for micro-optimizations unless
> there is a measurable benefit.

### 1.8 Compatibility restrictions (NEVER list)

> NEVER:
> - change external behavior
> - break plugin compatibility
> - remove public APIs without approval
> - change network protocols
> - change packet formats
> - change serialization formats
> - introduce external dependencies
> - replace systems only because you prefer another architecture
>
> If a breaking change is required, stop and explain why before applying it.

### 1.9 Architecture improvements

> Identify:
> - oversized classes
> - god objects
> - excessive coupling
> - bad abstractions
> - duplicated logic
> - poor dependency flow
>
> Improve architecture only when it can be done safely.
> Do not make large architectural rewrites without validation.

### 1.10 Testing & verification (per module)

> After every module: Provide a report containing:
> - Files changed
> - Refactoring performed
> - Bugs discovered
> - Compatibility concerns
> - Performance improvements
> - Remaining technical debt
> - Validation results from start.sh
>
> *(Reports live in `docs/MODULE_N_REPORT.md`.)*

### 1.11 Final audit & README (at the very end)

> At the end of the entire project: Perform a complete audit:
> - PHP 8.2 compatibility
> - runtime stability
> - memory usage
> - performance bottlenecks
> - architecture quality
> - plugin compatibility risks
> - possible hidden bugs
>
> Generate a README.md containing:
> - project modernization summary
> - PHP version requirements
> - important changes
> - migration notes
> - developer guidelines
> - maintenance recommendations
> - known limitations

### 1.12 Process rules

> Before any destructive or incompatible modification, ask for confirmation.
>
> Always prioritize:
> 1. Stability
> 2. Compatibility
> 3. Performance
> 4. Maintainability
> 5. Modern PHP practices

### 1.13 Checkpoint rule (owner's add-on instruction)

> Before making changes, create a safe checkpoint/commit of the current state.
> After each completed module, create another checkpoint so changes can be
> reverted individually.

---

## 2. Project facts (validated, do not re-derive)

| Fact | Value |
|---|---|
| Branch | `php-update` |
| Runtime | `bin/php7/bin/php` → **PHP 8.2.32 (ZTS)** (custom PocketMine build) |
| Entry point | `src/pocketmine/PocketMine.php` |
| Server class | `src/pocketmine/Server.php` |
| Boot test | `timeout 30 bin/php7/bin/php src/pocketmine/PocketMine.php --no-wizard --disable-ansi` → expect `Done (...)!` |
| Classloader | `src/pocketmine/CompatibleClassLoader.php` (register `src` + `src/spl`) |
| Start script | `./start.sh` (interactive console; not CI-friendly directly) |
| Configs | `khronos.yml`, `genisys.yml`, `pocketmine.yml`, world in `worlds/world` |
| Static inits (in order) | `Entity::init(); Tile::init(); InventoryType::init(); Block::init(); Enchantment::init(); Item::init(); Biome::init(); Effect::init(); Attribute::init(); EnchantmentLevelTable::init(); Color::init(); CraftingManager` |
| Dependency map | `docs/DEPENDENCY_MAP.md` (analysis phase, commit `8cb4549`) |
| Unrelated TODO file | `todo` contains 2 pre-existing TODOs (newer chunk system, Player::setImmobile) — leave them |

---

## 3. Progress — completed modules (revertible checkpoints)

Each module is an independent commit. **`git revert <hash>` cleanly undoes any one.**

| # | Module | Commit | Scope | Key results |
|---|---|---|---|---|
| 1 | Core/Utils/Math | `eddb025` | utils, math, nbt | strict_types leaf-first |
| 2 | Network | `0d5b742` | raklib, network/protocol | hostile-input robustness, fuzz-parity, boot-verified |
| 3 | Data structures | `a451815` | spl/ | loader, logger chain, SplFixedByteArray |
| 4 | Items | `5b6d2ba` | item/ (~160) | Item backbone, enchantments; 4 latent crashes fixed, harness-verified |
| 5 | Blocks | `f6e1956` | block/ (196) | Block backbone, int\|float coercion fix, harness zero-diff |
| 6 | Entities | `7753051` | entity/ (70) + Player.php | Effect duration null-fix, **cross-module Potion::getColor fix**, booted-probe-verified |
| 7 | Level/World | `cef654f` | level/ (159) + cross-module fixes | static-keyword restore, strict-mode float coercion parity, booted-probe-verified |
| 8 | Inventory | `c1b82d6` | inventory/ (36) | strict_types all, ~70 safe return types, DoubleChestInventory/BaseTransaction variance fatals fixed (latent in ORIG), booted-probe-verified |
| 9 | Player | `e89b7af` | Player.php (4331), OfflinePlayer, IPlayer, Achievement + Human.php fix | strict_types all 4 + ~60 return types, `getProtocol(): ?int` fix (probe-caught), `Human::getFloatingInventory(): ?FloatingInventory` latent fix, variance-verified vs typed Entity/Human/Living, booted-probe ALL-PASS |
| 10 | Commands | `9851c8c` | command/ (65) | strict_types all, Command backbone fully typed, execute() + interfaces deliberately untyped (plugin API), protected props left untyped (§6.2), signature-diff + HARNESS-IDENTICAL + booted-probe ALL-PASS (28 checks) |

Module reports: `docs/MODULE_1_REPORT.md` … `docs/MODULE_10_REPORT.md`.
Dependency map & plan: `docs/DEPENDENCY_MAP.md`.

---

## 4. What remains (modules 8–12) with current strict-types status

| Module | Path | Files | strict so far |
|---|---|---|---|
| 8. Inventory | `src/pocketmine/inventory/` | 36 | 36/36 ✅ |
| 9. Player | `src/pocketmine/Player.php` (+ offline player) | 2–3 | 4/4 ✅ |
| 10. Commands | `src/pocketmine/command/` | 65 | 65/65 ✅ |
| 11. Plugins | `src/pocketmine/plugin/` | 13 | 0/13 ⏭️ |
| 12. Remaining systems | `tile/` (18), `metadata/` (7), `scheduler/` (12), `event/`, `permission/`, `network/` leftovers, `pocketmine/` root classes (Server, PocketMine, CrashDump, etc.) | — | 0 |

### Suggested order & rationale (from the brief + observed coupling)

1. **Module 8 — Inventory ✅ (done, commit `c1b82d6`).**
2. **Module 9 — Player ✅ (done, commit `e89b7af`).** See `docs/MODULE_9_REPORT.md`
   for the two real fixes (`getProtocol(): ?int`, `Human::getFloatingInventory(): ?FloatingInventory`)
   and the variance strategy used (leave null-returning getters untyped or nullable).
3. **Module 10 — Commands ✅ (done, commit `9851c8c`).** See `docs/MODULE_10_REPORT.md`.
   Key decisions to keep for Module 11: `execute()` and plugin-facing interfaces stay
   untyped (plugin API); `protected` properties stay untyped (§6.2 invariance); type
   only `private` props + safe returns. Signature diff vs ORIG verified pure-type-additions.
4. **Module 11 — Plugins (NEXT):** Plugin/PluginBase/PluginLogger/PluginManager
   (13 files) — the heart of the plugin ecosystem. PluginBase extends
   CommandExecutor/Listener and is extended by EVERY plugin; keep public APIs
   untyped where plugins override (onEnable/onLoad/onDisable, onCommand).
   PluginManager is the biggest file; watch `loadPlugin`/`getPlugin` returns and
   the description loading paths.
4. **Module 11 — Plugins:** depends on command/Player; PluginLogger etc.
5. **Module 12 — Remaining systems:** tile (NBT roundtrips!), metadata,
   scheduler, event, permission, and the top-level bootstrap classes.
6. **Final audit + README.md** (per §1.11). Include the known pre-existing debt
   listed in §7.

---

## 5. The validation procedure (this is what caught every real regression)

Run **all** of these after EVERY module. Do not skip; each has caught real bugs:

### 5.1 Lint
```bash
for f in $(find src/pocketmine/<module> -name '*.php'); do
  bin/php7/bin/php -l $f 2>&1 | grep -v 'No syntax errors' && echo "LINT FAIL: $f"
done
```

### 5.2 Load-all (catches variance fatals at class load)
Use `/tmp/mod<N>-loadall.php` pattern: register the CompatibleClassLoader, force-load
core deps (`Block::init(); Item::init(); Entity::init();`), iterate every file in the
module, `class_exists()` each. Expect `LOADED=<N> FAIL=0`.
**Tip:** call statics with full `\pocketmine\...` prefix inside these harnesses — a
bare `Block::init()` in a file without `use` resolves to `\Block` and throws.

### 5.3 Logic parity harness vs ORIG worktree (the crown jewel)
1. `git worktree add /tmp/khronos-m<N> <pre-module-commit>`
2. Write `/tmp/mod<N>-harness.php` that exercises **pure-static surfaces only**
   (hash functions, noise, registries, binary roundtrips, math) and prints lines.
3. Run it against BOTH trees (via `MOD<N>_BASE` env var), normalize paths
   (`s/…/TREE/g`, `s/on line \d+/on line N/`), `diff`.
4. **Expect `HARNESS IDENTICAL`.** Any diff = real regression (e.g. mod 6 caught
   `Effect::$duration` null→0; mod 7 caught the biome/static issues).

### 5.4 Boot test
```bash
pkill -f 'PocketMine.php'; sleep 1
timeout 30 bin/php7/bin/php src/pocketmine/PocketMine.php --no-wizard --disable-ansi > /tmp/boot.log 2>&1 &
sleep 16
grep -a 'Done' /tmp/boot.log          # must appear
# then scan for CRITICAL/Fatal/TypeError/Uncaught/Parse error (log is binary: use grep -a + strip ANSI)
pkill -f 'PocketMine.php'; sleep 1; pgrep -f 'PocketMine.php' | wc -l   # expect 0 strays
```
Use `cat -v file | sed 's/\^\[\[[0-9;]*m//g'` to strip ANSI before grepping.

### 5.5 Booted-server probe plugin (catches runtime-only crashes)
Create a throwaway plugin under `plugins/<X>Probe/` (plugin.yml + Main) that:
- `onEnable()` schedules a delayed task (tick ~60) so the world is loaded
  (`PluginTask` subclass with owner; **do NOT use CallbackTask** — see §7 debt).
- Exercises live paths: `getDefaultLevel()`, `getSpawnLocation()`, chunk
  `isChunkLoaded/generateChunk`, `getBlock`, `getBiomeId`, `getHeightMap`,
  `getWeather()->getWeather()`, provider seed, and a tick-40 follow-up.
- Logs `PROBE: ...` lines; grep them; scan for CRITICAL.
- **Delete the plugin and re-boot before committing.**
This exact technique caught in mod 6: `Potion::getColor(): Color` returning an array
(every ThrownPotion kill broke the level tick). In mod 7: `getSpawn(): Position`
returning Vector3, and the blockHash float-coercion TypeErrors.

### 5.6 Prior-module regression harnesses
Re-run `/tmp/mod4-harness.php`, `/tmp/mod5-harness.php`, `/tmp/mod6-harness.php`
against the tree and diff vs their validated normalized baselines
(`/tmp/mod4-new-norm.txt`, `/tmp/mod5-recheck-norm.txt`, `/tmp/mod6-base-norm.txt`).
Expect byte-identical output.

### 5.7 Code review
Spawn a code reviewer after changes with a precise summary of what was typed and
where. Ask specifically about: variance with overrides, return-type accuracy
(things that `return false`/`return null`/return different classes), and
strict-coercion hazards.

### 5.8 Report + checkpoint commit
Write `docs/MODULE_N_REPORT.md` (files changed, refactoring, bugs, compatibility,
performance, debt, validation table) and commit the module with a descriptive
message. Commit ONLY the module's files + the report. Keep `git status` clean after.

---

## 6. Hard-won lessons (repeat these on every module)

### 6.1 strict_types is behavior-changing — audit coercion sites
Adding `declare(strict_types=1)` to a file changes how **its own calls** treat
float→int (and other weak coercions). ORIG code was written in weak mode. Every
`blockHash(int...)`/`getBlockIdAt(int...)`-style call receiving a `Vector3` float
component (`$block->x` is `float` because Vector3 props are `public float $x`)
becomes a TypeError under strict types. **Fix = `(int)` cast at the call site** —
this is behavior-identical to weak truncation. After adding strict_types, sweep:
```bash
rg -g '*.php' -- '(getBlockIdAt|setBlockIdAt|getBlockDataAt|setBlockDataAt|getBlockLightAt|setBlockSkyLightAt|setBlockExtraDataAt|updateBlockLight|blockHash)\([^)]*->[xyz]' src/pocketmine/ | grep -v '(int)'
```
(`chunkHash($pos->x >> 4, …)` is safe — bitwise `>>` already yields int.)

### 6.2 Property & return-type variance is the #1 fatal risk
- **Properties are invariant.** Typing a parent property (`Entity::$width` etc.)
  fatals every subclass that redeclares it without a type. If the parent declares
  a property untyped, **leave subclasses' redeclaration untyped too** (mod 6 left
  `width/height/length/gravity/drag` untyped for exactly this reason).
- **Return types must match overrides exactly** (or be covariant). After typing a
  base class, run a load-all that also loads the subclasses; PHP fatals at class
  load if incompatible. Mod 6 typed 12 Player overrides to match Entity.

### 6.3 Methods that look void but return, or typed but return false
Before typing `: void`, grep the body for `return` with a value
(`WeatherManager::registerLevel` returned `true` — script typed it `void`; fixed).
Before typing `: T`, check for `return false;`/`return null;`/a different class
(`getSafeSpawn(): Position` → `Position|false`; `getSpawn(): Position` → actually
returns `Vector3`). The return-audit script pattern:
```python
# find "public function name(...) : Type {" and scan body for 'return false;' / 'return null;'
```

### 6.4 Scripts that rewrite signatures must preserve `static`
An automated typing pass stripped `static` from 21 methods (Biome, Generator,
Noise, Weather, WeatherManager, MovingObjectPosition), which would have crashed
boot at `Server.php` `Biome::init()`. **Always run a static-loss audit vs ORIG:**
```python
# for each file: regex '^\s*(public|protected|private)\s+(static\s+)?function\s+(\w+)\s*\(' (re.MULTILINE)
# ORIG static methods missing 'static' in NEW == loss
```
And if you "fix" with a script, **keep the method name** — an earlier restore
script accidentally deleted the names too (`function ) : void {`).

### 6.5 Return types that receive strings
`BaseFullChunk::getProvider()` legitimately returns `LevelProvider|string|null`
(the `Anvil::class` class-name fallback used by `getEmptyChunk`/`fromBinary`, then
`$provider::createChunkSection($Y)` is called statically on the string). Don't type
it `?LevelProvider`.

### 6.6 Reserved names & dead code under PHP 8.2
`abstract class Object` (file `generator/object/Object.php`) cannot parse under
8.2 — renamed to `ObjectBase.php` (class already said `ObjectBase`). Sweep for
`class Object`/reserved names before linting a module.

### 6.7 Harness bugs vs product bugs — verify identically in BOTH trees first
If a harness errors, run it in the ORIG worktree too. Same error in both =
harness bug (fix the harness). Different = real regression (fix the code).
Examples: `Biome::getBiomes()` doesn't exist; `ChunkSection` is at
`format/anvil/`; `getBlockXYZ` takes 4 args with by-refs; `getEmptyChunk` accepts a
null provider; `$am[Attribute::HEALTH]` returns the float value not the object;
`CompoundTag` has no `getValue()`.

### 6.8 The custom PHP binary quirks
- Logs are binary (ANSI): always `grep -a`, and `cat -v | sed 's/\^\[\[[0-9;]*m//g'`.
- `ReflectionProperty::isAccessible()` doesn't exist; private static reflection is
  quirky — prefer `ReflectionClass::getStaticPropertyValue()` (returns null in some
  cases) or closure binding; when writing test harnesses, prefer public APIs.
- The `Deprecated: Optional parameter … declared before required parameter`
  warning is pre-existing (Entity.php:1864) — path-dependent, ignore in diffs.

### 6.9 Plugin probes: PluginTask, not CallbackTask
`CallbackTask` registration hits a pre-existing string-conversion bug in
`ServerScheduler` (`"Callback#" . $callable` on a Closure → CRITICAL). Use an
anonymous/named `PluginTask` subclass carrying its own level reference.

---

## 7. Known pre-existing debt (defer to the final audit; do not "fix" casually)

1. **`ServerScheduler.php` CallbackTask closure-string bug** — `"Callback#" . $callable`
   (line ~193) throws when `settings.deprecated-verbose` is on and a plugin uses
   `CallbackTask` with a Closure. Identical in ORIG.
2. **`PocketMine.php:513` shutdown `Thread::start()` arg mismatch** — only fires on
   graceful shutdown, never in the normal boot test. Identical in ORIG.
3. **`Entity.php:1864` "Optional parameter declared before required parameter"**
   deprecation warning (path-dependent in harness diffs).
4. **`todo` file** — 2 pre-existing TODOs (newer chunk system; Player::setImmobile).
5. Deferred deliberately during modules: `Level::getSeed()` (`int|string`),
   metadata interface methods, movement packet senders, `setLinked` deprecation.
6. **`DropItemTransaction::TRANSACTION_TYPE` const is dead code** — the
   constructor never sets `$this->transactionType`, so `getTransactionType()`
   returns `TYPE_NORMAL` (0) instead of `TYPE_DROP_ITEM`. Identical in ORIG;
   harness asserts the quirk. One-line fix candidate for the final audit.
7. **`DoubleChestInventory::getContents($withAir)` ignores the param** — semantics
   differ from `ChestInventory`'s withAir handling. Intentional (matches ORIG
   PHP-7 extra-arg behavior; the class couldn't even load under 8.2).
8. **Fresh-player paths touch null state** — `getName(): string` fatals on a
   never-logged-in player (username null; mod-6 typing, real login always sets it),
   and `getExp()`/attributeMap is null until login init. Probe had to skip
   `setGamemode`, `isOp`, `hasPermission`, `canInteract` on synthetic players.
   Identical in ORIG (mod 6). One-line-null-guard candidates for the final audit.

---

## 8. Environment & tooling notes

- **PHPTerminal**: `bin/php7/bin/php` — always use it (system `php` may differ).
- **Lint**: `bin/php7/bin/php -l file.php`.
- **Worktrees for ORIG baselines**: `git worktree add /tmp/khronos-m<N> <commit>`,
  then `git worktree remove /tmp/khronos-m<N> --force` when done. Check
  `git worktree list` — mod 5's worktree (`/tmp/khronos-m5`) may still exist.
- **Harness scripts** live in `/tmp/` (`/tmp/modN-harness.php`, `/tmp/modN-run.sh`,
  `/tmp/modN-loadall.php`, validated baselines `/tmp/modN-*-norm.txt`). They persist
  across sessions — reuse them for cross-module regression checks.
- **Long inline shell commands with heavy escaping fail JSON parsing** — write a
  script file to `/tmp/` and run `bash /tmp/script.sh` instead.
- **Never** run `git push`, `git commit` (except module checkpoints the owner
  explicitly wants), or anything that alters production, without being asked.
  Checkpoint commits for modules are explicitly requested by the owner (§1.13).

---

## 9. Completion checklist (end of project)

- [x] Modules 1–10 (done, commits in §3)
- [ ] Module 11 — Plugins
- [ ] Module 12 — Remaining systems (tile, metadata, scheduler, event, permission, bootstrap)
- [ ] Final complete audit (§1.11): PHP 8.2 compat, runtime stability, memory,
      perf bottlenecks, architecture quality, plugin-compat risks, hidden bugs
- [ ] Generate final `README.md` with: modernization summary, PHP version
      requirements, important changes, migration notes, developer guidelines,
      maintenance recommendations, known limitations
- [ ] Every module has: validation passing (lint/load-all/harness/boot/probe/
      regressions) + `docs/MODULE_N_REPORT.md` + checkpoint commit

**End state:** clean PHP 8.2 codebase, zero behavior drift, plugin-compatible,
each module individually revertible, final README + audit delivered.
