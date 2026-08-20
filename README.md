# Khronos

*A from-scratch, ECS-based Minecraft server for MCPE 0.15.10.*

**API:** 2.0.0 · **Protocol:** 84 (MCPE 0.15.10) · **License:** LGPL-3.0

> ⚠️ **This is a brand-new API.** Khronos is a complete rewrite — existing PocketMine plugins **will not run** on it without being rewritten against the new ECS-based API (see [Plugin development](#plugin-development)).

## What is Khronos?

Khronos is a Minecraft server written from scratch in PHP 8.2, inspired by PocketMine-MP but with its own clean, ECS-driven architecture. It is wire-compatible with the MCPE 0.15.10 client (protocol 84) and can be played today on a small server, while the plugin API is ready for early adopters.

*Status: playable, young — the API is not frozen yet, and the gameplay roadmap lives in [docs/TODO.md](docs/TODO.md).*

## Quick start

1. **Requirements:** PHP 8.2+ (ZTS) with `pmmpthread`, `sockets`, `zlib`, `yaml`, `openssl`, `mbstring`, `ctype`, `json`. Prebuilt PHP 8.2 binaries with everything included are available at **[KhronosDevs/php-binaries](https://github.com/KhronosDevs/php-binaries)** and are bundled at `bin/php7/bin/php`.
2. **Run the server:** `./start.sh` (Linux/macOS) or `start.cmd` / `start.ps1` (Windows). It binds UDP **19132** and gives you an interactive console (`help`, `stop`, `op`, …).
3. **Connect** with the **MCPE 0.15.10** client to `your-server-ip:19132`.
4. **Configure:** first boot generates `server.properties` (name, motd, max-players, gamemode, difficulty, view-distance, white-list, pvp…) and `khronos.json` (anti-cheat thresholds, nether world, defaults) — edit and restart to apply.
5. **Worlds** live in `worlds/` (real Anvil/McRegion, auto-detected) and save automatically.

## Building a phar

The server can be compiled into a single portable `PocketMine-MP.phar` file for easy distribution.

```bash
# Build the phar (requires -d phar.readonly=0)
bin/php7/bin/php -d phar.readonly=0 build/make-phar.php
```

This produces `PocketMine-MP.phar` (~1.9 MB) containing all PHP source, composer autoload, and default config. The `start.sh` wrapper automatically detects and runs the phar when present.

### What's inside the phar

- `src/pocketmine/` — core server (ECS, services, systems, protocol, resources)
- `src/raklib/` — RakNet library
- `vendor/` — composer autoload
- `autoload.php` — PSR-4 autoloader
- `khronos.json` — default server config

### What stays outside (must be next to the phar)

| File/Dir | Purpose |
|---|---|
| `bin/php7/` | PHP 8.2 runtime (bundled or system PHP with required extensions) |
| `native/*.so` | FFI acceleration library — can't be loaded from inside a phar |
| `worlds/` | World data (created on first run) |
| `plugins/` | Plugin directory (created on first run) |
| `server.properties` | Server config (generated on first run) |
| `banned-*.txt`, `ops.txt`, `white-list.txt` | Admin lists |

### Running the phar

```bash
# Via start.sh (auto-detects the phar)
./start.sh

# Or directly
bin/php7/bin/php -d memory_limit=512M -d extension=ffi -d ffi.enable=1 PocketMine-MP.phar
```

## Features

| Area | What works |
|---|---|
| **Networking** | Full RakNet connected layer — real 0.15.10 clients join, login → spawn → chunk streaming → movement round-trip |
| **World** | Infinite, streaming follows the player · multi-world (`/world load/unload/list`, `/setspawn`) · day/night cycle · weather + lightning · **nether dimension + portals** (auto-created, travel both ways) · real Anvil `.mca` / McRegion `.mcr` persistence + autosave · foreign PocketMine-era worlds load with their original spawn |
| **Survival** | Timed mining with tools + durability + **block drops** · block placement · inventory + armor · crafting (2×2 + table) · furnaces/smelting · chests + double chests + dispenser/hopper · **brewing** · **enchanting table + anvils** (Sharpness/Power/Efficiency/Unbreaking) · hunger + health regen · XP orbs + levels · death/respawn · bows/arrows (in-flight rendering, stick in targets) · **ores + caves** (deterministic veins, carved caverns) · **block light** (torches really light up, mobs won't spawn in lit areas) · night-gated mob spawns with chase/attack AI |
| **Administration** | Console · `server.properties` + `khronos.json` · ops / bans / whitelist (persisted) · `/gamemode /tp /give /kill /time /weather /world /setspawn /help` · `stop / save-all / list / op / ban / whitelist / plugins` |
| **Anti-cheat** | Movement validation (speed/fly/teleport, rubber-band + kick) · chat/command spam limits · login throttle + per-IP caps · max-players enforcement |
| **Plugin API (2.0.0)** | `plugin.yml` + `Plugin` base (directory or `.phar`, never `.jar`) · commands · permissions · **47 events across the full lifecycle** (join/quit/chat/command/move/interact/block/spawn/respawn/damage/death/container/packet, cancellable) · scheduler · `KernelAccessor` (18 services + 7 ports) · `Player`/`World`/`Server`/`Block`/`ItemStack` facades · ECS `QueryBuilder` + custom `System` registration · auto-loaded from `plugins/` at boot |
| **Performance** | Parallel chunk generation · snooze-based worker threads (no busy-waiting) · ECS archetype storage · region-pipeline entity offload — see [Performance](#performance) |

## Performance

Measured with the **native-accel FFI library enabled** (production config: light calc, terrain noise and nibble packing run in C via `native/kh_native.so`) on the benchmark scripts in the repo (`measure_baseline.php`, `measure_pipeline.php`, `measure_network.php`, `measure_chunkgen.php`, `measure_memory.php`). The 20 TPS tick budget is **50 ms** — everything below runs well inside it.

| Benchmark | Result |
|---|---|
| Empty tick (no entities) | **0.32 ms** |
| 2,000 moving entities, hot tick | **1.74 ms** |
| 10,000 moving entities, hot tick (region pipeline) | **3.60 ms** |
| Steady-state tick with a connected client (incl. network flush) | **0.08 ms** |
| Inbound move packet processing | **~40,000 pkts/s** (~25 µs each) |
| Chunk streaming to a client | **189 chunks/s** — radius 8 (289 chunks) fully delivered in ~1.6 s |
| Parallel chunk generation | **~0.41 ms/chunk** (128 chunks in 52 ms across the pool) |
| Fluid simulation, 16k-cell ocean | **~0.01 ms/pass** active flow · ~0 steady state |
| Memory per entity | **~1 KB** (loaded-chunk budget enforced) |

## Plugin development

Khronos has a brand-new, ECS-based plugin API — **not compatible with existing PocketMine plugins** (no `.jar`; `.phar` and folders only). For a full guide (plugin.yml, commands, events, permissions, scheduler, services): see **[docs/plugin-api/PLUGIN.md](docs/plugin-api/PLUGIN.md)**.

## Documentation

- [docs/PLAN.md](docs/PLAN.md) — architecture & phase plan
- [docs/PROGRESS.md](docs/PROGRESS.md) — full build history
- [docs/TODO.md](docs/TODO.md) — what's left (mob pathfinding, redstone, LevelDB storage, nether mobs/fortresses, minor enchantment effects, light-dependent block updates…)
- [docs/DECISIONS.md](docs/DECISIONS.md) — design decision log

## Development

- **Tests:** `bin/php7/bin/php tests/run.php` (49 files, per-process isolation; `-j N` runs files in parallel, `--filter=substring` runs one test)
- **Static analysis:** `bin/php7/bin/php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon`
- **Benchmarks:** `measure_baseline.php`, `measure_pipeline.php`, `measure_chunkgen.php`, `measure_memory.php`, `measure_network.php` — run with the same FFI flags as production (`-d extension=ffi -d ffi.enable=1`) to match the table above
- **Architecture at a glance:** ECS core (components → archetypes → systems) · ports & adapters (network/storage/worldgen/threading) · gameplay services · API facades · region-based threading (details in [docs/PLAN.md](docs/PLAN.md))

## Credits

Inspired by and grateful to the PocketMine-MP team (http://www.pocketmine.net/) for the protocol heritage. Built by the Khronos Devs.
