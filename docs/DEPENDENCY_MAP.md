# Khronos — Dependency Map & Modernization Plan

Analysis artifact produced before any refactoring begins (module 0).
Baseline: branch `php-update` @ `dcdacdf`, tag `checkpoint-before-changes`.

## 1. Project overview

- PocketMine-MP 2.0.0 (Genisys fork) based Minecraft server for MCPE **v0.15.10 alpha**,
  branded "Khronos" (`KHRONOS_VERSION 3.0.0`, `API_VERSION 2.0.0`, codename Khronos).
- 1,069 PHP files, ~110,900 lines of code.
- Code style is 100% legacy (PHP 5/7 era): **0** `declare(strict_types=1)`, **0** typed
  properties, **0** `readonly`, **0** `match`. Lots of untyped params/returns and docblocks.
- External plugin ecosystem; `pocketmine` API surface must remain compatible.

## 2. Runtime environment (validated)

- PHP **8.2.32 (ZTS)** bundled at `bin/php7/bin/php` (wrapper script exec'ing `php-bin`).
- Required extensions present: pmmpthread, sockets, curl, yaml, sqlite3, zlib, openssl,
  mbstring, ctype, json.
- Baseline boot test: server reaches running state — `Done (0.26s)! For help, type "help" or "?"`
  — with **no** PHP errors, warnings, fatals, or crashes. Cleanup of the threaded process
  requires `pkill -9` on the process group (threads hold the stdout pipe open).

## 3. Entry point & startup flow (`src/pocketmine/PocketMine.php`)

1. Define `pocketmine\PATH`, `DATA`, `PLUGIN_PATH`; require PHP >= 8.2.0 + pmmpthread.
2. Register `CompatibleClassLoader` (spl/ClassLoader + spl/BaseClassLoader) over `src` + `src/spl`.
3. `Terminal::init()` → `pocketmine\ANSI`; create `MainLogger` (`server.log`).
4. Timezone detection (`Utils::getOS` / `/etc/timezone` / ip-api.com fallback).
5. Extension checks: sockets, curl, yaml, sqlite3, zlib (fatal if missing).
6. `Installer` wizard if no `server.properties`.
7. `ThreadManager::init()` → `new Server(...)`.
8. Bootstrap: `createDefaultFolders` → `about` → `Timings::init` → `createDefaultConfigs`
   → `setupGenisysConfig` → `loadAdvancedConfig` → `setConfigBool('online-mode', false)`
   → `setupLanguage` → `setupAsyncWorkers` → `setupPocketmineProperties` → `setupBanLists`
   → `setupDebugMode` → `setupMinecraftRelatedStuff` → `setupPlugins` → `setupNetwork`
   → `setupShutdownHandler` → `setupWorlds` → `setupDServer` → `start()`.
9. Shutdown: quit all threads in `ThreadManager`, start `ServerKiller(8)` watchdog,
   shutdown logger.

## 4. Module inventory (files per namespace)

| Module dir | Files | Notes |
|---|---|---|
| `block` | 196 | world blocks, mostly one class per file |
| `item` | 160 | items, one class per file |
| `level` | 159 | Level, chunks, generators, format/anvil, tiles, weather, particles |
| `event` | 118 | event classes + Timings |
| `entity` | 70 | Entity, Player base (Human), mobs, projectiles |
| `network` | 68 | RakLibInterface, protocol packets (0.15.10), query, rcon |
| `command` | 65 | command framework + defaults |
| `inventory` | 36 | inventories, recipes, transactions |
| `utils` | 28 | **module 1** |
| `spl` | 19 | ClassLoader + Logger hierarchy, SplFixedByteArray, exceptions |
| `tile` | 18 | tiles (chest, furnace, signs...) |
| `nbt` | 15 | NBT serialization |
| `plugin` | 13 | phar/folder/script loaders, PluginManager |
| `scheduler` | 12 | ServerScheduler, AsyncPool/AsyncWorker, tasks |
| `permission` | 10 | permissions, bans |
| `metadata` | 7 | metadata stores |
| `math` | 6 | **module 1**: Math, Vector2/3, VectorMath, AxisAlignedBB, Matrix |
| `snooze`, `promise`, `wizard`, `lang`, `resources` | 5+3+2+1+0 | small support systems |
| `raklib` (own namespace) | ~20 | threaded UDP session manager (network layer) |

## 5. Dependency map (core systems)

```
spl/ClassLoader ← spl/BaseClassLoader ← pocketmine/CompatibleClassLoader   (autoload everything)
spl/Logger ← spl/ThreadedLogger ← spl/AttachableThreadedLogger ← utils/MainLogger
math/Vector3 ← level/Position ← Player Location
math/* — used by virtually every gameplay class (Player: 127 direct ->x/y/z refs, Level: 117)
utils/Binary + utils/BinaryStream — packet & chunk serialization backbone (protocol format!)
utils/Config — YAML/JSON/properties config (pocketmine.yml, genisys.yml, khronos.yml)
utils/TextFormat — chat/console formatting (constants + helpers)
utils/Utils, UUID, Random, VersionString, Git, Stream, Color, Terminal, Process ...
Server (god object) — owns levels, plugins, network, scheduler, permissions, config
raklib/* (threaded) ↔ network/RakLibInterface ↔ Network ↔ Player
scheduler/ServerScheduler + AsyncPool + AsyncWorker — async tasks (chunk gen, compression)
level/Level ← chunk format (anvil) ← generators; tiles, entities, weather
nbt/NBT — world & entity serialization
plugin/* — loaders + PluginManager; event/* + Timings; command/*, permission/*, metadata/*
```

## 6. Critical execution paths

1. **Startup sequence** — any fatal/TypeError here prevents the server from running.
2. **Tick loop** — `Server::tick` → scheduler heartbeat → `Level::tick` → entity/block ticking.
3. **Network loop** — RakLib thread → SessionManager → RakLibInterface → packet handlers.
4. **Packet serialization** — `Binary`/`BinaryStream`; format is protocol 0.15.10, **must not change**.
5. **Chunk I/O** — anvil format load/save, NBT-based entity/tile serialization.
6. **Async pool** — chunk generation & packet compression on worker threads.

## 7. Public API surface (plugin compatibility — must not break)

- `math/Vector3::$x/$y/$z` — public, directly accessed everywhere (plugins too).
- `item/Item`, `Item::get(id, meta, count)` + per-item classes.
- `entity/Entity`, `entity/Human`, `Player` (huge API), `level/Level`, `Position`, `Location`.
- `inventory/*`, `event/*`, `permission/*`, `plugin/*`, `command/*`, `metadata/*`.
- Configuration keys in `pocketmine.yml` / `genisys.yml` / `khronos.yml`.
- `spl/Logger` method signatures (plugins log via `$logger->info(...)` etc.).

## 8. Known risks

- **Typed properties on pmmpthread `Thread`/`Threaded` subclasses** (MainLogger, ServerKiller,
  AsyncWorker, raklib classes) — pmmpthread serialization is sensitive; treat conservatively.
- **strict_types at file boundaries** changes argument coercion (a previous full-project attempt
  was reverted for exactly this reason). Policy: per-module, leaf modules first, each validated
  by boot test. Callers in weak-mode files still coerce, so leaf-module strictness is low risk.
- **Protocol / serialization / config formats must not change** — Binary, BinaryStream, NBT, anvil.
- **Server.php god object** — largest file; refactor only by careful extraction.
- **Reflection-based code** (MemoryManager, CrashDump, Patchable, MonkeyPatch) — PHP 8.2 semantics.
- **PHP 8.2+ deprecations** — dynamic properties, implicit nullable params, `${}` interpolation.

## 9. Validation procedure (after every module)

1. `php -l` on every changed file (bundled PHP 8.2).
2. Boot test: `setsid bin/php7/bin/php src/pocketmine/PocketMine.php --no-wizard`, wait ~45 s,
   assert `Done (...)!` in the log, scan for error/fatal/warning lines, then
   `pkill -9 -f 'PocketMine.php'` / `pkill -9 -f 'php-bin'` to clean up threads.
3. Targeted runtime smoke tests (`php -r`) for serialization / math round-trips where useful.
4. Commit a checkpoint after each completed module (revertible via `git revert`).

## 10. Module plan (in order)

1. **Core / Utils / Math** — `utils/` (28) + `math/` (6)  ← current
2. **Network** — `network/` + `raklib/`
3. **Data structures** — `spl/` (SplFixedByteArray etc.), iterators, queues
4. **Items** — `item/`
5. **Blocks** — `block/`
6. **Entities** — `entity/`
7. **Level / World** — `level/` + `tile/` + `nbt/`
8. **Inventory** — `inventory/`
9. **Player** — `Player.php`, `OfflinePlayer.php`, `IPlayer.php`
10. **Commands** — `command/`
11. **Plugins** — `plugin/` + `event/` + `permission/` + `metadata/`
12. **Remaining systems** — scheduler, snooze, promise, wizard, lang, timings, Server core

Priorities: Stability > Compatibility > Performance > Maintainability > Modern PHP.
