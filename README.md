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
4. **Configure:** first boot generates `server.properties` (name, motd, max-players, gamemode, difficulty, view-distance, white-list, pvp…) — edit and restart to apply.
5. **Worlds** live in `worlds/` (real Anvil/McRegion, auto-detected) and save automatically.

## Features

| Area | What works |
|---|---|
| **Networking** | Full RakNet connected layer — real 0.15.10 clients join, login → spawn → chunk streaming → movement round-trip |
| **World** | Infinite, streaming follows the player · multi-world · day/night cycle · weather + lightning · real Anvil `.mca` / McRegion `.mcr` persistence + autosave |
| **Survival** | Timed mining with tools + durability · block placement · inventory + armor · crafting (2×2 + table) · furnaces/smelting · chests · hunger + health regen · XP orbs + levels · death/respawn · bows/arrows · night-gated mob spawns with chase/attack AI |
| **Administration** | Console · `server.properties` · ops / bans / whitelist (persisted) · `/gamemode /tp /give /kill /time /weather /world /help` · `stop / save-all / list / op / ban / whitelist / plugins` |
| **Anti-cheat** | Movement validation (speed/fly/teleport, rubber-band + kick) · chat/command spam limits · login throttle + per-IP caps · max-players enforcement |
| **Plugin API (2.0.0)** | `plugin.yml` + `Plugin` base (directory or `.phar`, never `.jar`) · commands · permissions · **events across the full lifecycle** (join/leave/chat/command/move/interact/block/spawn/respawn/damage/death, cancellable) · scheduler · `KernelAccessor` (18 services + 6 ports) · `Player`/`World`/`Server`/`Block`/`ItemStack` facades · ECS `QueryBuilder` + custom `System` registration · auto-loaded from `plugins/` at boot |
| **Performance** | Parallel chunk generation · snooze-based worker threads (no busy-waiting) · ECS archetype storage · 5000-entity tick ~7 ms |

## Plugin development

Khronos has a brand-new, ECS-based plugin API — **not compatible with existing PocketMine plugins** (no `.jar`; `.phar` and folders only). For a full guide (plugin.yml, commands, events, permissions, scheduler, services): see **[docs/plugin-api/PLUGIN.md](docs/plugin-api/PLUGIN.md)**.

## Documentation

- [docs/PLAN.md](docs/PLAN.md) — architecture & phase plan
- [docs/PROGRESS.md](docs/PROGRESS.md) — full build history
- [docs/TODO.md](docs/TODO.md) — what's left (ores, caves, block light, mob pathfinding, redstone, enchanting, nether/end, LevelDB…)
- [docs/DECISIONS.md](docs/DECISIONS.md) — design decision log

## Development

- **Tests:** `bin/php7/bin/php tests/run.php` (30+ files, per-process isolation)
- **Static analysis:** `bin/php7/bin/php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon`
- **Benchmarks:** `measure_pipeline.php`, `measure_chunkgen.php`, `measure_memory.php`
- **Architecture at a glance:** ECS core (components → archetypes → systems) · ports & adapters (network/storage/worldgen/threading) · gameplay services · API facades · region-based threading (details in [docs/PLAN.md](docs/PLAN.md))

## Credits

Inspired by and grateful to the PocketMine-MP team (http://www.pocketmine.net/) for the protocol heritage. Built by the Khronos Devs.
