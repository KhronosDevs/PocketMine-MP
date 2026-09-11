# Khronos

*A from-scratch, ECS-based Minecraft server for MCPE 0.15.10.*

**API:** 2.0.0 · **Protocol:** 84 (MCPE 0.15.10) · **License:** LGPL-3.0

> ⚠️ **This is a brand-new API.** Khronos is a complete rewrite — existing PocketMine plugins **will not run** on it without being rewritten against the new ECS-based API (see [Plugin development](#plugin-development)).

## What is Khronos?

Khronos is a Minecraft server written from scratch in PHP 8.2, inspired by PocketMine-MP but with its own clean, ECS-driven architecture. It is wire-compatible with the MCPE 0.15.10 client (protocol 84) and can be played today on a small server, while the plugin API is ready for early adopters.

*Status: playable, young — the API is not frozen yet, and the gameplay roadmap lives in [docs/TODO.md](docs/TODO.md).*

## Quick start

1. **Requirements:** PHP 8.2+ (ZTS) with `pmmpthread`, `sockets`, `zlib`, `yaml`, `openssl`, `mbstring`, `ctype`, `json`. Prebuilt PHP 8.2 binaries with everything included are available at **[KhronosDevs/php-binaries](https://github.com/KhronosDevs/php-binaries)** and are bundled at `bin/php7/bin/php`.
2. **Run the server:** `./start.sh` (Linux/macOS) or `start.cmd` / `start.ps1` (Windows). It binds UDP **19132** by default and gives you an interactive console (`help`, `stop`, `op`, …).
3. **Connect** with the **MCPE 0.15.10** client to `your-server-ip:19132`.
4. **Configure:** first boot generates `server.properties` (name, motd, max-players, gamemode, difficulty, view-distance, white-list, pvp…) and `khronos.json` (port, memory, anti-cheat, chunk streaming, nether) — edit and restart to apply. See [Configuration](#configuration) for details.
5. **Worlds** live in `worlds/` (real Anvil/McRegion, auto-detected) and save automatically.

## Configuration

The server uses two config files — edit and restart to apply:

| File | Purpose |
|---|---|
| `server.properties` | Legacy key=value: server-name, motd, max-players, gamemode, difficulty, view-distance, server-port, white-list, pvp… |
| `khronos.json` | Structured JSON: port, memory, anti-cheat, chunk streaming, nether, spawn override — see below |

### Key khronos.json settings

```json
{
    "port": null,
    "memory-limit": null,
    "default-world": "world",
    "max-loaded-chunks": 1200,
    "chunk-streaming": {
        "compression-level": 2,
        "per-tick": 2,
        "time-budget-ms": 30,
        "use-time-budget": true
    },
    "nether": { "enabled": true, "world": "nether" },
    "anti-cheat": { "enabled": true, ... }
}
```

| Key | Type | Default | Description |
|---|---|---|---|
| `port` | int\|null | `null` | UDP port to bind. `null` = use `server.properties` (default 19132). Set to override. |
| `memory-limit` | string\|null | `null` | PHP memory limit (e.g. `"512M"`, `"1G"`). `null` = use start script default (512M). |
| `max-loaded-chunks` | int | 1200 | Resident chunk budget. Raise on high-RAM hosts. |
| `chunk-streaming.compression-level` | 1-9 | 2 | zlib level. Lower = faster CPU, larger packets. |
| `chunk-streaming.per-tick` | int | 2 | Max chunks per player per tick (do not raise above 8). |
| `chunk-streaming.time-budget-ms` | float | 30 | Global chunk-streaming budget per tick (shared across all players). |

### Examples

**Change the port to 25565:**
```json
{ "port": 25565 }
```

**Give the server 2 GB of RAM:**
```json
{ "memory-limit": "2G" }
```

**Both at once:**
```json
{
    "port": 25565,
    "memory-limit": "2G"
}
```

Restart the server after editing either config file.

### Region pipeline (experimental)

The region pipeline offloads entity movement+gravity simulation to worker threads, dramatically improving performance with many entities:

| Entities | Main thread only | Pipeline apply |
|---:|---:|---:|
| 1,000 | 1.67 ms | ~1 ms |
| 5,000 | 8.25 ms | ~1.7 ms |
| 10,000 | 16.77 ms | 3.69 ms |

Enable in `khronos.json`:

```json
{
    "pipeline": {
        "enabled": true,
        "apply-mode": true
    }
}
```

| Key | Default | Description |
|---|---|---|
| `pipeline.enabled` | `false` | Enable the region pipeline. Workers mirror entity snapshots each tick. |
| `pipeline.apply-mode` | `false` | Workers are authoritative for movement+gravity. Requires `enabled: true`. |

**How it works:**
- **Gate mode** (`enabled: true, apply-mode: false`): Main thread still simulates. Workers independently simulate the same entities and results are compared for determinism. Use this to verify correctness before enabling apply mode.
- **Apply mode** (`enabled: true, apply-mode: true`): Workers are authoritative. The main thread's `MovementSystem` and `PhysicsSystem` are disabled. Workers integrate position/velocity and the main thread applies results.

**When to use:**
- Servers with many mobs (500+): pipeline prevents entity simulation from eating into the 50ms tick budget
- Servers with few entities: leave it off — the overhead of mirroring snapshots isn't worth it

**Stability:** The pipeline passes all existing tests with 0 determinism mismatches. It is experimental — enable it and monitor for issues. If you see entity position glitches, disable it and report the issue.

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

Measured with the **native-accel FFI library enabled** (production config: light calc, terrain noise and nibble packing run in C via `native/kh_native.so`) on the benchmark scripts in `bench/`. The 20 TPS tick budget is **50 ms** — everything below runs well inside it.

Latest run: **2026-09-11** (master `43d2dd2`, includes the in-place RakNet header parse and the ECS EntityRef eviction fix).

| Benchmark | Result |
|---|---|
| Tick, 10 players + 100 mobs | **0.34 ms** mean (p99 0.57 ms) |
| Tick, 50 players + 500 mobs | **1.27 ms** mean (p99 1.77 ms) |
| Steady-state tick with a connected client (incl. network flush) | **0.17 ms** (0.09 ms net overhead) |
| Inbound move packet processing | **~24,300 pkts/s** (~41 µs each) |
| Chunk streaming to a client | **176 chunks/s** — radius 8 (289 chunks, 22.9 MB) fully delivered; login → spawn 1.64 s |
| Parallel chunk generation (16-chunk batch) | **8.8 ms** (5.5× faster than sequential, 2.6× faster than pure PHP) |
| Memory per entity | **~1.0 KB** (loaded-chunk budget enforced) |
| Resident chunk memory | **~160 KB/chunk** (200 loaded → 64 resident at budget, 12 MB total) |
| Entity spawn cost | 8,000 entities in 753 ms; archetype index reuse flat (freed indices recycled) |

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
- **Benchmarks:** `bench/01_tick_profile.php <players> <mobs> <ticks>`, `bench/measure_chunkgen.php`, `bench/measure_memory.php`, `bench/measure_network.php` — run with `KHRONOS_FAST_TICKS=1` (and the same FFI flags as production) to match the table above
- **Architecture at a glance:** ECS core (components → archetypes → systems) · ports & adapters (network/storage/worldgen/threading) · gameplay services · API facades · region-based threading (details in [docs/PLAN.md](docs/PLAN.md))

## Credits

Inspired by and grateful to the PocketMine-MP team (http://www.pocketmine.net/) for the protocol heritage. Built by the Khronos Devs.
