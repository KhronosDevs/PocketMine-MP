# TODO — What's left

**Last updated:** 2026-08-14
**Companion docs:** docs/PLAN.md (architecture & phase plan), docs/PROGRESS.md (completed work)

The server is playable today for a small creative/casual-survival server (see the
production-readiness review for what works). Administration (Blocker 1) and
anti-cheat (Blocker 2) are done. This file tracks everything that is **not** done,
in rough priority order, so anyone picking up a task knows the seams to build on.

---

## Pending from the ops plan (Blocker 4) — do these before inviting strangers

| Item | Status | What's already in place | Scope |
|------|--------|-------------------------|-------|
| **Wire events into services** | ⏳ Event **classes** exist (`api/event/`), but only `EntityDamageEvent` (via `CombatService`) actually fires | `EventBus`/`EventPort` working, cancellable events proven by `tests/12` + `tests/13` | Fire `PlayerJoinEvent`/`PlayerQuitEvent` on login/disconnect, `PlayerChatEvent` (cancellable) + `PlayerCommandPreprocessEvent` (already fires, see 12.4) on chat/commands, `PlayerMoveEvent` on validated moves, `PlayerInteractEvent` on interact, `BlockBreakEvent`/`BlockPlaceEvent` (cancellable) in the services — with the service respecting cancellation |
| **Auto-load plugins at boot** | ⏳ Not implemented — plugins load only via the test harness / manual `PluginManager` calls | `PluginManager` fully supports dir + `.phar` loading (12.4), `Kernel::getPluginManager()` exposes it | Scan `plugins/` at `bootstrap()`/`run()` start, load all `plugin.yml` dirs + `.phar` files, log load failures without crashing |

---

## Gameplay roadmap (Blocker 3) — deferred

### 1. ⭐ Ores (highest gameplay impact — mining has no reward yet)

**✅ DONE (PROGRESS #70)** — `populateChunkPure` places deterministic legacy-0.15 veins (coal any depth, iron ≤64, gold/lapis ≤32, redstone/diamond ≤16, emerald in extreme hills), stone-only, chunk-local, RNG-safe vs the tree pass. `tests/39` covers it.

### 2. Caves

**✅ DONE (PROGRESS #71)** — worm-carver pass in `generateChunkPure` (world-grid-anchored so caves connect across chunks), lava below Y=10, water in ocean-column caves, surface/bedrock intact, stone-only, chunk-local. `tests/40` covers it. Bonus: fixed a latent `EntityRef`-vs-`PlayerRef` broadcast bug in `FluidSystem`/`TNTExplosionSystem` that cave water exposed.

### 3. Block light (+ proper sky light shading)

`calculateLight` (`ParallelGeneratorAdapter`) is a stub: **every section is full sky light, zero block light**. Torches/lava emit nothing, cave interiors are pitch-… actually fully lit. A lit mine is the difference between "mining" and "Minecraft".

- **Already in place:** `LightData` wire format; the ChunkSerializer's heightmap-derived sky light; block light arrays round-trip through storage.
- **Missing:** (a) real sky light with heightmap falloff (0xFF at surface → 0 at depth, not uniform `\xff`), (b) block-light emission table (`BlockRegistry` already has light values per block: torches 14, lava 15, glowstone 15, lit furnaces 13…) and BFS propagation (or the legacy flood-fill) so placed torches/lit furnaces actually illuminate, (c) block updates when a light source is placed/broken, (d) light-dependent mob spawning.

### 4. Mob AI pathfinding

Hostile mobs chase players in **straight lines** and get stuck on hills and walls (12.1's AI is target-acquire + chase).

- **Already in place:** `AISystem` (sequential), `AIStateComponent` with per-mob stats, `SpatialIndex`, combat pipeline integration.
- **Missing:** navigation. Pragmatic scope: a lightweight jump-and-avoid — when a chase is blocked by a solid block ahead, step up a 1-block ledge or strafe around; no full A* navmesh needed for a 0.15-era feel. Add a `PathComponent`/waypoint follow so mobs can loop around obstacles.

### 5. Redstone

No circuit engine at all.

- **Already in place:** `BlockRegistry` covers redstone dust/wire, torches, repeaters, comparators, pistons, lamps, plates, buttons with full state metadata tables.
- **Missing:** the whole engine — a `RedstoneSystem` (sequential) with a wire/torch/component tick model: power propagation over dust, torch powering/unpowering, repeaters/comparators, pistons pushing blocks, lamps/doors responding, and `BlockUpdateService` wiring so placement/broken blocks trigger updates. Scope it as: dust + torches + repeaters + pistons first; comparators/lamps/plates second.

### 6. Enchanting / anvils / beacons

- **Already in place:** item meta as damage counter; XP orbs + player XP/levels (14.9); `ItemDurability`; enchanting table + anvil + beacon blocks in the registry.
- **Missing:** enchant table GUI + level-cost rolls + `Enchantment` effects on tools/armor (efficiency, fortune, protection, sharpness, power…), anvil repairs/renames, beacon buffs. This is a large chunk — split into "enchanting table + a few enchantments" then "anvils" then "beacons".

### 7. Nether / End dimensions

Single overworld generator only.

- **Already in place:** multi-world infrastructure (14.20) — `WorldComponent`, `WorldRegistry`, `/world` command, per-world `WorldConfig`/`ChunkStore`/`StoragePort` — so a second dimension is mostly a new generator + nether-specific blocks/portals.
- **Missing:** a nether generator (netherrack/caves/lava lakes, glowstone, nether quartz already in the registry), nether portal block + travel, bedrock ceiling/floor. The End (end stone island, obsidian pillars, dragon) can be a later separate task.

### 8. LevelDB storage provider

`LevelProviderManager` **auto-detects** LevelDB worlds (`db/` subfolder) and throws a clear "php-leveldb extension not available" error (14.20/28). PocketMine-era worlds on disk today are often LevelDB.

- **Already in place:** provider auto-detect + error path; `old-src/level/format/leveldb/` is a full reference implementation of the key layout (`chunkIndex`, `ENTRY_ENTITIES`, `ENTRY_TILES`, `ENTRY_EXTRA_DATA`).
- **Missing:** the php-leveldb extension in the build (bundled `bin/php7` doesn't have it) + a `LevelDBStorageAdapter` following the same `StoragePort` contract as `AnvilStorageAdapter`, with chunk/tile/entity key layout parity.

### 9. Audit the remaining block-state/container stores for the same cross-world leak

Chests and furnaces were fixed (per-world stores in `WorldRegistry`, PR #80) — but they were the only **block** containers audited. Verify nothing else that stores state by `(x, y, z)` can leak across worlds.

- **Done:** dispenser/hopper/brewing-stand containers added in PR #88 are per-world from day one (`ContainerStore` + `BrewingStore` follow the `ChestStore` pattern); double chests pair into one 54-slot window with break-spill of both halves. Remaining audit surface: any future beacon store, and any wire/container state cached on sessions that should key by `WorldComponent`.
- **Missing:** (a) audit for other coordinate-keyed stores or ad-hoc state beyond the audited set (beacon pending), (c) any wire/container state cached on sessions or entities that should key by `WorldComponent`.

---

## Known limits (tracked elsewhere, not scheduled)

- **Client:** protocol 84 only — players must run the ancient MCPE 0.15.10 client (project design; caps the audience).
- **Scale:** single RakNet thread + main-thread ECS is fine for tens of players; untested above that.
- **Persistence:** player data + chunks save; chests/furnaces save via tile snapshots (14.16); world meta round-trips real `level.dat`.
