# TODO — What's left

**Last updated:** 2026-08-21
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

### 5. Pressure plates (70/72/147/148)

Pressure plates need entity collision detection to activate when a player/mob
walks over them. The old-src uses `EntityBaseTick` to check if any entity's
bounding box overlaps the plate's block position.

- **Already in place:** `BlockIds` has all pressure plate IDs. `CollisionComponent`
  exists for entities.
- **Missing:** per-tick entity-over-block detection system, plate activation/deactivation
  logic, redstone signal emission (tied to redstone engine).

### 6. Falling sand / falling gravel

Sand and gravel should fall when the block below is removed, as a gravity entity
that lands on the next solid block.

- **Already in place:** `BlockIds::SAND = 12`, `BlockIds::GRAVEL = 13` in BlockRegistry.
- **Missing:** gravity check on block-break (if block below is air, spawn a FallingSand
  entity), FallingSand entity with gravity + collision, anvil fall sound on landing.

### 7. Cauldron (118)

Cauldrons hold water and can be filled/emptied with buckets.

- **Already in place:** `BlockIds::CAULDRON = 118` in BlockIds.
- **Missing:** cauldron state (empty/1/2/3 water levels), bucket interaction to
  fill/empty, splash/spell sounds on water interaction.

### 8. Redstone

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

### 10. Crafting (inventory 2x2 grid + crafting table)

The inventory/crafting screen now **opens crash-free** (client fix: the creative-items window must carry the exact legacy 0.15 list — a curated subset made the 0.15 client SIGSEGV in `CraftingContainerManagerModel::init()`), but actually crafting items is not wired up end-to-end.

- **Already in place:** `CraftingService::craft()` (grid → result), `RecipeRegistry` with shaped recipes, recipes sent at login via `CraftingDataPacket`.
- **Missing:** the wire round-trip — server-side result from the player's grid, `CraftingEventPacket` / result-slot handling, ingredient consumption, and recipe-list parity with the client (legacy sends 336 entries incl. shapeless + furnace recipes; new-src sends 9 shaped-only). A full recipes + `CreativeItems` audit against the legacy 0.15 data (wildcard ingredient damage `7fff` vs `ffff`, `cleanRecipes` flag, shapeless/furnace entry types).

### 11. Opening furnaces and other containers

Right-clicking a furnace, chest, brewing stand, etc. should open its container window with the inventory linked.

- **Already in place:** per-world block containers (`ChestStore`, `FurnaceStore`, `ContainerStore`, `BrewingStore`), tile-snapshot persistence, double-chest pairing (54-slot), `ContainerService::openContainer()`/`setContainerSlot()`.
- **Missing:** the client-side open flow — right-click interact dispatch → `ContainerOpenPacket` (the service only sets player metadata; the packet send is a comment), per-container window ids + slot mapping, `ContainerSetContent`/`ContainerSetSlot` sync for the container window, furnace smelting tick + fuel consumption, `ContainerClosePacket` handling.

---

## Chunk Streaming / Compression — Future Investigations

Chunk streaming has been optimized with compressed payload caching, L2 compression
level, and a global time-budget scheduler. The following are documented as future
investigations, NOT current requirements.

### Parallel/worker-based compression

Compression is currently synchronous on the main thread (~368 µs/chunk at L2).
With 81 chunks in a 30ms budget, this is the dominant cost. Moving compression
to worker threads could free main-thread CPU for entities/plugins.

**What to benchmark:** 2/4/8 worker pool, synchronization overhead, memory
copy cost, cache invalidation race conditions.

### Compression prefetching based on player movement

Currently chunks are compressed on-demand when sent. If player movement is
predictable (walking in a straight line), chunks could be pre-compressed
before they are needed.

**What to benchmark:** prediction accuracy, wasted compression work,
memory pressure from speculative compression.

### Alternative native compression libraries

zlib-ng and libdeflate offer faster compression at compatible wire formats.
The current `zlib_encode()` already calls native zlib, but alternative
libraries may be faster.

**What to benchmark:** wire compatibility, speed vs zlib, memory usage,
deployment complexity.

### Further cache optimization

The compressed cache is invalidated on any chunk mutation. Section-level
caching could reduce invalidation scope (only invalidate the modified 16×16×16
section instead of the whole chunk).

**What to benchmark:** invalidation frequency in real gameplay, section-level
compression overhead, protocol compatibility.

### Dirty-section/partial compression

If only a small portion of a chunk changes, the entire ~10KB compressed
payload is recompressed. Partial recompression or delta encoding could
reduce this cost.

**What to benchmark:** typical mutation size, delta encoding overhead,
protocol compatibility with existing clients.

### Saddle (item 329)

Right-clicking a pig with a saddle should make it rideable (player mounts,
WASD controls via PlayerInputPacket, saddle consumed). Requires extending
`VehicleSystem` with a `tickPig()` handler (like `tickBoat`/`tickMinecart`)
and adding the pig as a mountable entity type.

- **Already in place:** `VehicleSystem` (boats/minecarts), `SetEntityLinkPacket`
  for mounting, `PlayerInputPacket` handling, saddle item registered.
- **Missing:** `tickPig()` in VehicleSystem, saddle consumption on interact,
  pig-specific movement (slower than boats, no rails), unsaddle on death.

### Map cartography

MCPE 0.15 maps require a server-side map storage system (map id → pixel
buffer + decorations), `ClientboundMapItemDataPacket` wire support, crafting
recipe (compass + paper), and renderer integration. No map concept exists in
the engine at all.

- **Already in place:** nothing specific; paper/crafting infrastructure exists.
- **Missing:** everything — map data store, wire packet, crafting recipe,
  exploration tracking, decoration API.

---

## Known limits (tracked elsewhere, not scheduled)

- **Client:** protocol 84 only — players must run the ancient MCPE 0.15.10 client (project design; caps the audience).
- **Scale:** single RakNet thread + main-thread ECS is fine for tens of players; untested above that.
- **Persistence:** player data + chunks save; chests/furnaces save via tile snapshots (14.16); world meta round-trips real `level.dat`.
