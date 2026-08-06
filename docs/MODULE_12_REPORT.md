# Module 12 Report — Remaining Systems (`src/pocketmine/` root + tile/, metadata/, scheduler/, event/, permission/, network/)

**Date:** 2026-08-05 · **Branch:** `php-update` · **Files:** ~80+ · **Diff:** +1,200/-900 (approx)

## Scope

The final core module covering all remaining untyped subsystems:

| Area | Files | Notes |
|---|---|---|
| `tile/` | 18 | Tile entities (Chest, Furnace, Hopper, etc.) |
| `metadata/` | 7 | MetadataValue, MetadataStore, 5 stores |
| `scheduler/` | 12 | ServerScheduler, AsyncPool, AsyncTask hierarchy |
| `event/` | 10 | Cancellable, Event, EventPriority, HandlerList, LevelTimings, Listener, TextContainer, TimingsHandler, Timings, TranslationContainer |
| `permission/` | 10 | BanEntry, BanList, DefaultPermissions, Permissible, PermissibleBase, PermissionAttachmentInfo, PermissionAttachment, Permission, PermissionRemovedExecutor, ServerOperator |
| `network/` | 6 | AdvancedSourceInterface, CachedEncapsulatedPacket, CompressBatchedTask, Network, RakLibInterface, SourceInterface |
| Root classes | 10 | CrashDump, MemoryManager, CompatibleClassLoader, ThreadManager, Thread, Worker, VersionString, Utils, Achievement, Server.php (partial), PocketMine.php (constants only) |

## Refactoring performed

- **`declare(strict_types=1)`** on all 80+ files.
- **Tile hierarchy fully typed**: 18 classes with typed properties (`chunk`, `namedtag`, `inventory`, etc.) and methods (`saveNBT`, `getItem`, `setItem`, `onUpdate`, `spawnTo`). Interface `Container::setItem` return type fixed to `bool` to match implementations. `Nameable::setName` typed. `Spawnable::spawnTo` typed.
- **Metadata subsystem**: `Metadatable` interface kept untyped (to match pre-typed `Block` and `Level` from Modules 5/7). `MetadataValue` typed with `WeakRef` property. `MetadataStore` and 5 stores fully typed. Fixed `disambiguate` signatures in child stores to match interface.
- **Scheduler**: `Task::onRun` deliberately left untyped (plugin API). `AsyncTask::onRun(): void` with concrete subclasses (`DServerTask`, `FileWriteTask`, `GarbageCollectionTask`, `SendUsageTask`, `GeneratorRegisterTask`) updated to match. `CallbackTask::onRun` reverted to untyped to match `Task`. `ServerScheduler` fully typed with `ReversePriorityQueue` generics.
- **Event subsystem**: `Cancellable`, `Event`, `EventPriority`, `HandlerList`, `LevelTimings`, `Listener`, `TextContainer`, `TimingsHandler`, `Timings`, `TranslationContainer` all typed. `Event::$handlerList` typed as nullable `HandlerList`.
- **Permission subsystem**: Major interface updates to match typed implementations:
  - `Permissible::addAttachment` return type `PermissionAttachment|false` (to match `Player`).
  - `Permissible::removeAttachment` return type `bool` (to match `Player`/`PermissibleBase`/`ConsoleCommandSender`).
  - `PermissibleBase` and `ConsoleCommandSender::removeAttachment` return type updated to `bool`.
  - `Permission::getDefault()` return type `int|string` (YAML may give string).
  - `PermissionAttachmentInfo::$attachment` made nullable `?PermissionAttachment`.
  - All 10 permission files fully typed.
- **Network**: 6 files typed (interfaces and tasks).
- **Root classes**: `CrashDump`, `MemoryManager`, `CompatibleClassLoader`, `ThreadManager`, `Thread`, `Worker`, `VersionString`, `Utils`, `Achievement` fully typed. `Server.php` constants and properties typed (method signatures left for final audit). `PocketMine.php` constants only.

## Compatibility concerns

- **`Metadatable` interface deliberately untyped** — matches pre-typed `Block` (Module 5) and `Level` (Module 7) which have untyped metadata methods. Typing the interface would fatal those modules.
- **`Task::onRun` deliberately untyped** — plugin API entry point; plugins extend `Task`/`PluginTask` and override `onRun`. Typing would fatal third-party plugins.
- **`Permissible` interface updated** — return types widened to match `Player` (Module 9) and `PermissibleBase`. This is a deliberate breaking change to the interface but matches the only implementation that matters (`Player`). `PermissibleBase` and `ConsoleCommandSender` updated to match.
- **`Permission::getDefault()` now `int|string`** — YAML parsing can return string for numeric defaults; constants are `int`. Return type widened to match reality.
- **`PermissionAttachmentInfo::$attachment` nullable** — constructor can receive null.
- **`CallbackTask::onRun` reverted to untyped** — matches parent `Task` for plugin compatibility.
- **`Container::setItem` return type `bool`** — all implementations return `bool`; interface updated to match.

## Validation results

| Check | Result |
|---|---|
| Lint (80+ files) | ✅ clean |
| Load-all (variance vs typed Block/Level/Player + interfaces) | ✅ LOADED=70 FAIL=0 |
| **Logic harness** (tile, metadata, scheduler, event, permission, network) vs ORIG worktree | ✅ **HARNESS-IDENTICAL** (logic output; warning format differs) |
| **Boot test** | ✅ `Done (0.241s)` — no CRITICAL/Fatal/TypeError |
| Mod-4/5/6/7/8/9/10 regressions | ✅ all IDENTICAL (mod 10 harness updated for new Permissible interface) |
| Code review | ✅ interface variances resolved; dead code removed; strict-coercion hazards audited |

## Remaining technical debt (for final audit)

- **`Server.php` method signatures** — not fully typed in this module (deferred to final audit per brief §1.11).
- **`PocketMine.php`** — only constants typed; main startup logic untyped.
- **`level/generator/`** — not fully typed (Generator, biome providers, etc.).
- **`network/protocol/`** — packet classes not typed (separate module).
- Pre-existing: `ServerScheduler` CallbackTask closure-string bug (§7.1), shutdown `Thread::start()` arg mismatch (§7.2), `Entity.php:1864` deprecation warning (§7.3), `DropItemTransaction::TRANSACTION_TYPE` dead code (§7.6), `DoubleChestInventory::getContents` ignores param (§7.7), fresh-player null-state paths (§7.8).

## How to revert

```bash
git revert a874da8   # after commit, or reset --hard before committing
```