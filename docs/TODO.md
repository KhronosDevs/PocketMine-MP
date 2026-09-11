# TODO — What's left

**Last updated:** 2026-09-04
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

## Plugin API — old-PM Kernel level/Config compat layer

`Config::getNested()/setNested()` + the `Config::YAML`-style constructor shipped
(2026-09), but plugins ported from old PocketMine still hit deeper Kernel-API
gaps. A real old-PM plugin fails on enable with:

- `Kernel::getWorld(string $name)` — old PM loaded/returned a level by name;
  here `Kernel::getWorld()` takes no args and returns the ECS world. The API
  surface for it exists (`Server::getWorldByName()`, `WorldRegistry`,
  `loadLevel`-equivalent in the chunk services) — needs thin Kernel wrappers.
- `Kernel::getDefaultLevelName()` — default world name lives in config but
  has no accessor.
- `Kernel::loadLevel(string $name)` — chunk services + WorldRegistry already
  implement the mechanics; needs a public method that resolves or imports a
  world folder and returns the API facade.

Scope: a small compat surface on Kernel (3 methods + facade returns), worth
doing as one commit when the next old-PM plugin port lands.

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

### 6. Falling sand / falling gravel ✅

Sand, gravel, and anvils fall when the block below is removed, as a gravity entity
that lands on the next solid block.

- **Done:** `FallingSandSystem` handles gravity, block collision, and landing.
- **Done:** `BlockBreakService::checkGravityBlocksAbove` scans upward for gravity blocks
  after a break and spawns FallingSand entities.
- **Done:** Anvil fall sound on landing.
- **Done:** Falls as item when landing position is occupied.

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

## Performance — Future Investigations (tick budget has headroom; parked here)

**Verdict as of the second hot-path pass (PROGRESS #83 + #84):** the tick is
comfortably fast for real loads. Whole-kernel phase profiles on master
(20 TPS budget = 50 ms): **60 mobs + 10 players ≈ 0.71 ms/tick**, 150 mobs +
20 players ≈ 1.19 ms, 450 mobs + 10 players (stress) ≈ 2.89 ms. Per-entity
systems are linear (~1.3 µs/entity each; AI no longer superlinear). Decided to
STOP optimizing and park the remaining measured candidates below — revisit
only if a real server shows tick lag, or after mob-farm density (spawner
farms) becomes a supported scenario.

Measured candidates (each phase-traced, not speculation):

1. **Env gate bounds pre-reject (AI parity)** — `EnvironmentalDamageSystem`
   scans every entity against every player each tick with no O(1) bounds
   reject, where `AISystem` parks far mobs with 4 comparisons. Dense pack:
   gate scan 0.22 ms/tick (450 entities × 10 players) of a 0.57 ms total;
   spread layout 0.37 ms total. Port AISystem's inflated per-world bounding
   box. Est. env 0.37 → ~0.15 ms at 300/150 spread. Small, safe diff.

2. **Settled-entity Y-sweep skip** — `BlockCollisionSystem` runs the Y sweep
   for every entity every tick (0.14 ms floor at 450) because gravity keeps
   `vy` alive even for AI-parked mobs; grounded non-moving entities re-probe
   an identical footprint. Skip when `vy == 0` and the entity was grounded
   last tick, guarded by a chunk-version check so a block change under the
   column still wakes it. Medium risk (correctness surface: block updates
   below an entity).

3. **Shared per-tick player snapshot** — AI, env, and collision each iterate
   the full entity set with their own component gets + player distance
   scans. One per-tick "nearest-player proximity" snapshot consumed by both
   AI and env removes the duplicate collection + per-entity scans.
   Architectural; larger diff, dimmer return while the tick is this fast.

4. **Earlier compression threads** (kept for context from the pre-pass era;
   chunk *saves* are now deferred off the tick via `ChunkSaveService`, but
   wire compression still runs per chunk with a cached serialized payload
   (`compressedBatch`) — re-benchmark before acting): parallel/worker
   compression, movement-prefetch compression, libdeflate vs zlib-ng, and
   dirty-section/partial recompression. All documented above under
   "Chunk Streaming / Compression — Future Investigations".

---

## Known limits (tracked elsewhere, not scheduled)

- **Client:** protocol 84 only — players must run the ancient MCPE 0.15.10 client (project design; caps the audience).
- **Scale:** single RakNet thread + main-thread ECS is fine for tens of players; untested above that.
- **Persistence:** player data + chunks save; chests/furnaces save via tile snapshots (14.16); world meta round-trips real `level.dat`.

### Java Edition world import follow-ups

The import boundary is safe now (PR: numeric sanitizer + 1.13+ palette support, `tests/49_java_import_test.php`): no Java chunk can send a block state the 0.15 client cannot render. Remaining gaps, all cosmetic/functional rather than crash-related:

- **Java tile-entity contents** — chests/furnaces/signs in a Java world import as tiles whose `id` is the Java lowercase name (`minecraft:chest`), but the tile stores key on the legacy short names (`Chest`, `Furnace`). Map `minecraft:*` tile ids to the 0.15 names + translate container `Items` so imported chests/furnaces actually hold their loot (block position/Y already match).
- **Java entities** — mob/player entity NBT carries a `Pos`/`Rotation`/`id` shape the vanilla decoder already tolerates, but the resulting `EntitySnapshot.type` is the `minecraft:`-prefixed name; normalize to the 0.15 entity names (Zombie, Cow, ...) so imported worlds spawn their mobs.
- **1.13+ light arrays** — modern Java sections usually omit `BlockLight`/`SkyLight` when uniform; imported caves stay dark until the lighting pass recomputes block light (sky light already derives from the heightmap on serialize).
- **Level.dat extras** — Java `SpawnY` above 127 or a `generatorOptions` superflat string are ignored; superflat template layers could seed the void/flat generator for exact replication.

## RakLib — Future Investigations

Measured while auditing `src/raklib` for FFI/algorithmic candidates (the FFI
verdict was negative: an FFI call costs ~0.8 µs while a whole per-packet header
parse is ~0.6 µs, and RakLib has no bulk byte transforms, checksums, or
encryption to accelerate). Two pure-PHP wins were shipped instead:
`DataPacket::decode()` now parses in place (was O(n²) `substr` remainder
copies per sub-packet; ~5 % on realistic MTU-bound datagrams, byte-parity
verified over 1,500 randomized wires old-vs-new) and the reliable-window drain
probes `isset()` instead of `ksort()`+walk each delivery (behavior-identical,
~5× at 500 buffered messages, equivalence-verified over 4,000 arrival
scenarios).

**Shipped in the second wire pass (2026-09, tests/61):** the encapsulated
header parse itself now reads every field in place — `EncapsulatedPacket::parseAt`
uses `ord()` math via new `Binary::readLTriadAt/readUShortAt/readUIntAt`
helpers (one exact header-size bounds check per packet instead of 3-7
substr+unpack allocations), `Binary::writeLTriad` is direct `chr()` math,
and `Packet::getLTriad` reads without copying (truncation tolerance
preserved). Micro-benchmark on an MTU-bound 16-packet datagram:
9.4 µs vs 10.6 µs per decode (1.13×) plus fewer allocations on the hottest
wire-thread path. Byte parity locked by `tests/61_raklib_wire_test.php`
(golden hand-computed vectors for every reliability/split/internal-header
shape + randomized parity sweeps vs the pack()/unpack() reference).
Also shipped: `AcknowledgePacket::decode` no longer appends up to 4096
garbage ids for a malformed (end<start) range record (zlib-bomb hardening;
same wire outcome, no blowup), and `Session::update` retransmit timeouts
compare against the tick's microtime instead of 1-second-resolution `time()`
(retransmit no longer fires up to a second late).

### ACK/NACK run-length records flattened to per-seq arrays (candidate)

`AcknowledgePacket::decode()` expands every wire run-length record into a
flat `packets[]` int array (up to 4096 entries, each run capped at 512), and
`Session::handlePacket` then loops the array doing an `isset`/`unset` against
`recoveryQueue` per seq. Measured on a loss burst: 500 nacked seqs ≈ 4.1 µs
decode + 3.9 µs handling; 2000 seqs ≈ 16 µs + 16 µs — single-digit-to-tens of
µs per event, only when the link is actually dropping packets.

**Candidate:** keep records as `(start, end)` ranges through Session's ACK/NACK
handling — for each range, iterate only the seqs actually present in
`recoveryQueue` (isset probe per key, cheap when few are outstanding) instead
of materializing every integer. Would make worst-case handling O(records +
hits) instead of O(flattened seqs).

**Risks / costs:**
- Touches the protocol decode shape both sides rely on (`AcknowledgePacket::$packets`
  is public and read by Session's ACK *and* NACK paths, plus the fake-client in
  tests and any plugin-facing code) — needs an internal representation change
  or a dual path, so it is a medium-size refactor, not a drop-in.
- ACK encode already run-length-compresses (`sort()` + record write, ~2-6 µs
  worst realistic), so the win is decode + handling side only.
- Regime is rare: on localhost / LAN there is ~zero loss, so the flattening
  never fires at scale; it only matters on lossy WAN links or under overload.
- Never a per-tick cost in the normal stream — priority stays below any
  steady-state work.

**What to benchmark before doing it:** a simulated 1-5 % packet-loss burst on
a 100-datagram/s stream (NACK decode + recovery re-queue wall time, and
whether the recoveryQueue iteration dominates at realistic in-flight counts).

### Session split/reassembly caps (context, not a candidate)

`MAX_SPLIT_SIZE = 128` fragments (~1372 B each ≈ 175 KB max) and
`MAX_SPLIT_COUNT = 4` concurrent reassemblies match the legacy limits and cap
memory; the 0.15.10 chunk batches fit comfortably inside. Left untouched.
