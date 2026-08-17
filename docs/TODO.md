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
| **Wire events into services** | ✅ DONE (PROGRESS #77) — 33 event classes wired across the services | First wave (join/leave/respawn, break/place, spawn, interact/attack, chat, move, damage, death) + second wave (login, kick, drop, consume, gamemode, sneak/sprint, regen, shoot bow, teleport, despawn, container open/close, pickup, craft, weather, plugin enable/disable, server command, block update) | — |
| **Auto-load plugins at boot** | ✅ DONE — `Kernel::run()` scans `<dataPath>/plugins/` on first run (creates it when missing), loads every `plugin.yml` dir + `.phar`, skips `.jar` (12.4 + PROGRESS #77) | — | — |

---

## Gameplay roadmap (Blocker 3) — deferred

### 1. ⭐ Ores (highest gameplay impact — mining has no reward yet)

**✅ DONE (PROGRESS #70)** — `populateChunkPure` places deterministic legacy-0.15 veins (coal any depth, iron ≤64, gold/lapis ≤32, redstone/diamond ≤16, emerald in extreme hills), stone-only, chunk-local, RNG-safe vs the tree pass. `tests/39` covers it.

### 2. Caves

**✅ DONE (PROGRESS #71)** — worm-carver pass in `generateChunkPure` (world-grid-anchored so caves connect across chunks), lava below Y=10, water in ocean-column caves, surface/bedrock intact, stone-only, chunk-local. `tests/40` covers it. Bonus: fixed a latent `EntityRef`-vs-`PlayerRef` broadcast bug in `FluidSystem`/`TNTExplosionSystem` that cave water exposed.

### 3. Block light (+ proper sky light shading)

- **Done (PR #89):** the full light pipeline is in. `LightCalculator` computes per-chunk sky falloff (15 at surface → 0 at depth) and BFS block-light propagation from every emitter (torches 14, lava 15, glowstone 15, lit furnaces 13…). Place/break re-runs it; `ChunkStore::recalculateLight` now marks changed chunks light-dirty and the network layer re-sends them, so a placed torch **actually lights up on the client** (UpdateBlockPacket carries no light — a full chunk re-send is the only way protocol 84 delivers it). `ChunkStore` gained a light query API (`getSkyLightLevel`/`getBlockLightLevel`/`getLightLevel`) and `MobSpawnerSystem` now refuses to spawn hostile mobs at torch-lit positions — torches protect an area for real.
- **Remaining (minor):** light-dependent *block updates* beyond place/break (e.g. snow/ice melt, crop growth gating) — a `BlockUpdateSystem` nicety, not a wire/lighting gap.

### 4. Mob AI pathfinding

Hostile mobs chase players in **straight lines** and get stuck on hills and walls (12.1's AI is target-acquire + chase).

- **Already in place:** `AISystem` (sequential), `AIStateComponent` with per-mob stats, `SpatialIndex`, combat pipeline integration.
- **Missing:** navigation. Pragmatic scope: a lightweight jump-and-avoid — when a chase is blocked by a solid block ahead, step up a 1-block ledge or strafe around; no full A* navmesh needed for a 0.15-era feel. Add a `PathComponent`/waypoint follow so mobs can loop around obstacles.

### 5. Redstone

No circuit engine at all.

- **Already in place:** `BlockRegistry` covers redstone dust/wire, torches, repeaters, comparators, pistons, lamps, plates, buttons with full state metadata tables.
- **Missing:** the whole engine — a `RedstoneSystem` (sequential) with a wire/torch/component tick model: power propagation over dust, torch powering/unpowering, repeaters/comparators, pistons pushing blocks, lamps/doors responding, and `BlockUpdateService` wiring so placement/broken blocks trigger updates. Scope it as: dust + torches + repeaters + pistons first; comparators/lamps/plates second.

### 6. Enchanting / anvils (beacons removed — not in MCPE 0.15.10, added in 0.16)

- **Done (PR #90):** `EnchantmentRegistry` (full 0.15 catalogue: weights, max levels, slot masks, level ranges, conflicts, enchantability); `ItemStack` NBT enchant helpers (`ench` list, custom names, repair cost); `EnchantmentService` (bookshelf-boosted three-option rolls, lapis + level application, anvil combine/rename); enchanting-table + anvil windows on the wire (options ride `CraftingDataPacket` ENTRY_ENCHANT_LIST); effects wired in — Sharpness (melee), Power (arrows), Efficiency (mining), Unbreaking (durability).
- **Remaining (minor):** Fortune/Protection/Knockback/Fire-Aspect effects, more anvil edge cases (repair material costs), enchanted book items from loot.

### 7. Nether / End dimensions

- **Done (PR #91):** the nether is a real second dimension. `GeneratorType::Nether` + a deterministic 128-high `ParallelGeneratorAdapter::generateNetherChunk` (bedrock floor AND ceiling, netherrack mass with 3D cave noise, lava sea below y=32, `hasSky=false` so sky light stays 0 and the serializer sends dark nibbles — the nether is genuinely dark except lava/glowstone). Population pass: quartz veins, soul-sand/gravel patches, ceiling glowstone clusters, ground fire, occasional surface lava lakes. Portals: flint & steel lights a complete 4-23 x 5-23 obsidian frame (legacy detector, both orientations); standing inside a portal charges 80 ticks (survival) / instant (creative) and crosses via `ChangeDimensionPacket` (0x36) with the legacy wire format — the client shows the building-terrain screen and streams the nether's chunks. The nether world (`nether.enabled` / `nether.world` in khronos.json, default `nether`) auto-creates on first use; returning through a nether portal lands back at the saved overworld spot. Nether worlds get no mob spawns yet (spawner is overworld-only), and MCPE 0.15.10 has no End dimension.
- **Remaining (deferred):** nether mobs (zombie pigmen, ghasts, blazes — the entity types and AI hooks exist), nether fortresses, `nether.allow-nether=false` server property parity (khronos.json `nether.enabled=false` covers it), the End (does not exist in 0.15.10).

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
