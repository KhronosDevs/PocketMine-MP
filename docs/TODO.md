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
(2026-09). The level-API half of the gap is now **done**: `Server` (the API
facade plugins actually resolve) gained the legacy `Level` aliases —
`getLevel(string)` / `getLevelByName()` (→ `getWorldByName`),
`getDefaultLevelName()`, `loadLevel(string)` (→ `loadWorld`),
`generateLevel()` (→ `generateWorld`), `unloadLevel()` (→ `unloadWorld`) and
`setDefaultLevel(string)` (writes `KhronosConfig::defaultWorld`). Old-PM
plugins calling the level API by name now load without edits.

Still open for deeper old-PM ports: matching `Kernel`-level shortcuts (plugins
that call `\pocketmine\Kernel::getInstance()->getLevel($name)` directly instead
of going through the Server facade) — add forwarding calls there if a real
plugin needs them.

## Gameplay roadmap (Blocker 3) — deferred

### 1. ⭐ Ores (highest gameplay impact — mining has no reward yet)

**✅ DONE (PROGRESS #70)** — `populateChunkPure` places deterministic legacy-0.15 veins (coal any depth, iron ≤64, gold/lapis ≤32, redstone/diamond ≤16, emerald in extreme hills), stone-only, chunk-local, RNG-safe vs the tree pass. `tests/39` covers it.

### 2. Caves

**✅ DONE (PROGRESS #71)** — worm-carver pass in `generateChunkPure` (world-grid-anchored so caves connect across chunks), lava below Y=10, water in ocean-column caves, surface/bedrock intact, stone-only, chunk-local. `tests/40` covers it. Bonus: fixed a latent `EntityRef`-vs-`PlayerRef` broadcast bug in `FluidSystem`/`TNTExplosionSystem` that cave water exposed.

### 3. Block light (+ proper sky light shading)

- **Done (PR #89):** the full light pipeline is in. `LightCalculator` computes per-chunk sky falloff (15 at surface → 0 at depth) and BFS block-light propagation from every emitter (torches 14, lava 15, glowstone 15, lit furnaces 13…). Place/break re-runs it; `ChunkStore::recalculateLight` now marks changed chunks light-dirty and the network layer re-sends them, so a placed torch **actually lights up on the client** (UpdateBlockPacket carries no light — a full chunk re-send is the only way protocol 84 delivers it). `ChunkStore` gained a light query API (`getSkyLightLevel`/`getBlockLightLevel`/`getLightLevel`) and `MobSpawnerSystem` now refuses to spawn hostile mobs at torch-lit positions — torches protect an area for real.
- **Remaining (minor):** light-dependent *block updates* beyond place/break (e.g. snow/ice melt, crop growth gating) — a `BlockUpdateSystem` nicety, not a wire/lighting gap.

### 4. Mob AI pathfinding ✅

Hostile mobs chase players in **straight lines** and get stuck on hills and walls (12.1's AI is target-acquire + chase).

- **Already in place:** `AISystem` (sequential), `AIStateComponent` with per-mob stats, `SpatialIndex`, combat pipeline integration.
- **Done:** obstacle navigation inside `AISystem` steering — a probe ahead of the mob along its heading detects a wall; the mob jumps 1-block steps (when ground ahead+up is clear and the mob is grounded) or strafes around the wall (side probe picks the freer side, remembered for a few ticks so it commits to one direction). Grounded detection reads the block under the feet (physics-owned `OnGroundTag` is cleared each tick by `applyPendingComponents`). Fields persist through the normal component serializer. `tests/71_ai_navigation_test.php` covers wall-jump and dead-end strafe.
- **Remaining (deferred):** `PathComponent`/waypoint loops — straight-line + step-up + strafe already reads as 0.15-era mob behavior.

### 5. Pressure plates (70/72) ✅

Entity-over-block activation is done: `PressurePlateSystem` (sequential, after
movement/physics) presses a plate (meta bit 0x08 + click sound) when a living
entity's feet occupy its block, and unpresses it after a grace period (stone
20 ticks, wood 10) once the entity steps off. Dead entities don't press.
Plate state is plain block meta — persists through the normal chunk save.
`tests/69_pressure_plate_test.php` covers press/unpress/dead-entity.

- **Missing (deferred):** weighted gold/iron plates (147/148, entity-count
  signal strength), redstone signal emission (tied to the redstone engine).

### 6. Falling sand / falling gravel ✅

Sand, gravel, and anvils fall when the block below is removed, as a gravity entity
that lands on the next solid block.

- **Done:** `FallingSandSystem` handles gravity, block collision, and landing.
- **Done:** `BlockBreakService::checkGravityBlocksAbove` scans upward for gravity blocks
  after a break and spawns FallingSand entities.
- **Done:** Anvil fall sound on landing.
- **Done:** Falls as item when landing position is occupied.

### 7. Cauldron (118) ✅

Cauldrons hold water and can be filled/emptied with buckets.

- **Done:** water level in block meta (0 empty … 6 full, legacy parity);
  `NetworkSessionService::interactCauldron()` — empty bucket fills from a
  full cauldron (legacy rule), water bucket fills the cauldron to full,
  glass bottle (≥2 levels) yields a water bottle (373) and drops the level
  by 2; splash sound on every interaction; creative does not consume items.
- **Test:** `tests/66_cauldron_test.php`.
- **Missing:** dye coloring / leather-armor dyeing and potion storage
  (legacy TileCauldron custom color + PotionId) — cosmetic, deferred.

### 8. Redstone

No circuit engine at all.

- **Already in place:** `BlockRegistry` covers redstone dust/wire, torches, repeaters, comparators, pistons, lamps, plates, buttons with full state metadata tables.
- **Missing:** the whole engine — a `RedstoneSystem` (sequential) with a wire/torch/component tick model: power propagation over dust, torch powering/unpowering, repeaters/comparators, pistons pushing blocks, lamps/doors responding, and `BlockUpdateService` wiring so placement/broken blocks trigger updates. Scope it as: dust + torches + repeaters + pistons first; comparators/lamps/plates second.

### 6. Enchanting / anvils (beacons removed — not in MCPE 0.15.10, added in 0.16)

- **Done (PR #90):** `EnchantmentRegistry` (full 0.15 catalogue: weights, max levels, slot masks, level ranges, conflicts, enchantability); `ItemStack` NBT enchant helpers (`ench` list, custom names, repair cost); `EnchantmentService` (bookshelf-boosted three-option rolls, lapis + level application, anvil combine/rename); enchanting-table + anvil windows on the wire (options ride `CraftingDataPacket` ENTRY_ENCHANT_LIST); effects wired in — Sharpness (melee), Power (arrows), Efficiency (mining), Unbreaking (durability), Protection (4% damage reduction per level per worn piece), Knockback (+2 blocks/tick horizontal impulse per weapon level), Fire Aspect (ignites the target 4s per level), Fortune (ore drop-count multiplier).
- **Done (anvil):** same-item repair merges durability (combined remaining uses + 12% bonus) and enchantments; repair-material repair (legacy `Item::$repairMaterial` table — ingots/diamond/planks/leather restore 25% durability per unit); `RepairCost` NBT accumulates per operation (cost = 1 + input repair costs) and feeds the rename cost.
- **Fixed:** `ItemStack::getEnchantments()` dropped id-0 entries — every Protection enchantment vanished on read (`$id > 0` guard; legacy ids run 0..24).
- **Remaining (minor):** enchanted book items from loot, Smite/Bane-of-Arthropods bonus damage vs specific mob classes.

### 7. Nether / End dimensions

- **Done (PR #91):** the nether is a real second dimension. `GeneratorType::Nether` + a deterministic 128-high `ParallelGeneratorAdapter::generateNetherChunk` (bedrock floor AND ceiling, netherrack mass with 3D cave noise, lava sea below y=32, `hasSky=false` so sky light stays 0 and the serializer sends dark nibbles — the nether is genuinely dark except lava/glowstone). Population pass: quartz veins, soul-sand/gravel patches, ceiling glowstone clusters, ground fire, occasional surface lava lakes. Portals: flint & steel lights a complete 4-23 x 5-23 obsidian frame (legacy detector, both orientations); standing inside a portal charges 80 ticks (survival) / instant (creative) and crosses via `ChangeDimensionPacket` (0x36) with the legacy wire format — the client shows the building-terrain screen and streams the nether's chunks. The nether world (`nether.enabled` / `nether.world` in khronos.json, default `nether`) auto-creates on first use; returning through a nether portal lands back at the saved overworld spot. Nether worlds get no mob spawns yet (spawner is overworld-only), and MCPE 0.15.10 has no End dimension.
- **Remaining (deferred):** nether fortresses, `nether.allow-nether=false` server property parity (khronos.json `nether.enabled=false` covers it), the End (does not exist in 0.15.10). Nether mobs are **done**: `MobSpawnerSystem` loops every registered world, and a `GeneratorType::Nether` world spawns from a nether-only pool (zombie pigmen, ghasts, blazes, magma cubes + vanilla parity skeleton/enderman variants) around the clock — the night gate is skipped because the dimension has no sky. Overworld worlds never draw from the nether pool.

### 8. LevelDB storage provider

`LevelProviderManager` **auto-detects** LevelDB worlds (`db/` subfolder) and throws a clear "php-leveldb extension not available" error (14.20/28). PocketMine-era worlds on disk today are often LevelDB.

- **Already in place:** provider auto-detect + error path; `old-src/level/format/leveldb/` is a full reference implementation of the key layout (`chunkIndex`, `ENTRY_ENTITIES`, `ENTRY_TILES`, `ENTRY_EXTRA_DATA`).
- **Missing:** the php-leveldb extension in the build (bundled `bin/php7` doesn't have it) + a `LevelDBStorageAdapter` following the same `StoragePort` contract as `AnvilStorageAdapter`, with chunk/tile/entity key layout parity.

### 9. Audit the remaining block-state/container stores for the same cross-world leak

Chests and furnaces were fixed (per-world stores in `WorldRegistry`, PR #80) — but they were the only **block** containers audited. Verify nothing else that stores state by `(x, y, z)` can leak across worlds.

- **Done:** dispenser/hopper/brewing-stand containers added in PR #88 are per-world from day one (`ContainerStore` + `BrewingStore` follow the `ChestStore` pattern); double chests pair into one 54-slot window with break-spill of both halves. Remaining audit surface: any future beacon store, and any wire/container state cached on sessions that should key by `WorldComponent`.
- **Missing:** (a) audit for other coordinate-keyed stores or ad-hoc state beyond the audited set (beacon pending), (c) any wire/container state cached on sessions or entities that should key by `WorldComponent`.

### 10. Crafting (inventory 2x2 grid + crafting table) ✅

The inventory/crafting screen now **opens crash-free** (client fix: the creative-items window must carry the exact legacy 0.15 list — a curated subset made the 0.15 client SIGSEGV in `CraftingContainerManagerModel::init()`).

- **Already in place:** `CraftingService::craft()` (grid → result), `RecipeRegistry` with shaped recipes, recipes sent at login via `CraftingDataPacket`.
- **Done:** the wire round-trip is complete — `handleCraftingEvent` validates the client's grid server-side, `CraftItemEvent` is cancellable, ingredients are consumed atomically and the result added. Shapeless recipes (`RecipeRegistry::registerShapeless` / `matchShapeless`, unordered ingredient multiset with wildcard meta support, concrete-meta consumption) plus the legacy-parity shapeless set (mushroom stew, book, flint & steel, mossy cobble/stone bricks, gray/light-gray dye). Furnace recipes ride the recipe list as `ENTRY_FURNACE`/`ENTRY_FURNACE_DATA` (id-only vs `(id<<16)|meta` payload, verified against the legacy encoder); `cleanRecipes` byte is now 1 (legacy `buildCraftingDataCache` parity). `tests/72_crafting_wire_test.php` covers shapeless matching/rejection, shaped regression, and the packet entry stream.
- **Remaining (deferred):** a full recipes + `CreativeItems` audit against the legacy 0.15 `recipes.json` (remaining dye combos, dyed-wool shapeless from flowers, golden-apple variants); wildcard-ingredient damage `7fff` vs `ffff` audit.

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

### Saddle (item 329) ✅

Right-clicking a pig with a saddle makes it rideable. Done:

- **Done:** saddle consumption on interact (`EntityInteractionService::saddlePig`
  — item 329 in hand, consumes one, tags the pig VEHICLE + PIG_SADDLED);
  mounting rides the standard vehicle link path (`InteractPacket` right-click →
  `mountVehicle`, `PlayerInputPacket` steering, sneak to dismount);
  `VehicleSystem::tickPig()` — boat-style yaw-relative steering at pig pace
  (0.25 vs boat 0.4), ground jump from VEHICLE_JUMPING (no mid-air re-jump);
  unsaddle on death (`CombatService::handleDeath`) drops the saddle and
  ejects the rider via `NetworkSessionService::dismountByRiderId()`.
- **Test:** `tests/65_pig_saddle_test.php` (saddle/tag, steering+jump, death eject).

### Map cartography ✅ DONE

- **Done:** `MapStore` resource (per-world, tile-snapshot persisted like the
  other stores) keyed by map id: 128×128 RGB color buffer, scale, center,
  tracking position, dirty flag. `ClientboundMapItemDataPacket` (0x3b, full
  0x04-texture flag) + `MapInfoRequestPacket` (0x33) on the wire, varint
  helpers added to `BinaryStream`. Flow: crafting a map (compass + paper,
  1×2 shaped) assigns a fresh id from the store; equipping a filled map
  pushes the texture; the client's `MapInfoRequest` re-sends it; exploring
  re-renders the footprint around the holder at 1 px/block into a 128² window
  centered on the map's center (only when the holder moves ≥1 block since the
  last render). `tests/73_map_test.php` covers store round-trip, wire encode,
  id assignment on craft, and request handling.
- **Missing (deferred):** decorations (markers/frames), copying maps in
  crafting, zoom-out via crafting, map-in-item-frame rendering.

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

- **Java tile-entity contents ✅ DONE** — `JavaBlockTranslator::javaTileType()` maps Java 1.13+ tile ids (chest/trapped_chest/furnace/dispenser/dropper/hopper/brewing_stand/sign/item_frame/painting) to the Khronos store type names; `AnvilStorageAdapter::decodeTileEntities()` now translates the vanilla `Items`/`BurnTime`/`Text1-4` compounds into the Khronos snapshot shape each store restores from, so imported chests/furnaces actually hold their loot (unknown items are skipped; unsupported tiles are dropped; Khronos-native tiles pass through untouched). `JavaBlockTranslator::javaItemToPe()` maps Java item resource names to PE 0.15 item ids. `tests/68_java_import_test.php` covers all of it.
- **Java entities ✅ DONE** — `JavaBlockTranslator::javaEntityType()` normalizes `minecraft:`-prefixed ids to the 0.15 entity names (Zombie, Cow, …; `zombie_pigman`/`zombified_piglin` → PigZombie, `magma_cube` → LavaSlime, `wither_skeleton` → Skeleton, `drowned` → Zombie, cat → Ocelot, boat/minecart variants), and `RegionStorageAdapter::decodeEntity()` applies it to every foreign snapshot — so imported worlds spawn their mobs. Unknown types become `unknown` and are skipped on restore.
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

### Security hardening pass (2026-09)

Network audit results, shipped in severity order:

- **Critical — NBT** (`tests/64`): container nesting capped at 32 levels
  (deep hostile compounds segfaulted PHP via C-stack overflow — process
  kill from a sign edit); `ListTag::read` stops on unsupported element
  types (previously spun ~2 billion no-op iterations — remote main-thread
  hang); `readCompressed` capped at 64 MB. `online-mode=true` is now
  enforced: logins whose Mojang signature chain did not verify are
  rejected (the flag existed but nothing consumed it; bans, whitelist and
  ops all keyed on attacker-controlled identity strings).
- **High — RakLib wire**: the 16-byte offline magic is verified on all
  unconnected traffic (1-byte spoofed datagrams previously created
  sessions and elicited responses — reflection/amplification vector);
  NACK re-acceptance capped at 64 datagrams per NACK (one hostile packet
  could hold ~2048 datagrams in permanent retransmit, ~2 MB/s outbound);
  both cross-thread queues capped at 1024 frames (previously unbounded).
- **Medium — game layer**: truncated DATA packets with a null seqNumber
  are dropped (they slipped past every window comparison and got ACKed);
  login skins capped at 64×64×4 + head layer (~20 KB, was ~2 MB rebroadcast
  per join); usernames clamped to 16 printable chars (chat-echo injection);
  non-finite/absurd move coordinates rejected (NaN passed every anti-cheat
  comparison); login-attempt windows pruned (per-host leak).
- **Low**: client-declared MTU floored at 400 (an mtuSize near 0 crashed
  the wire thread's send path on the next outbound).


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
