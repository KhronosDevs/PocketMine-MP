<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\Kernel;
use pocketmine\port\driven\NetworkPort;
use pocketmine\protocol\UpdateBlockPacket;
use function array_key_exists;
use function array_keys;
use function array_map;
use function chr;
use function count;
use function explode;
use function intval;
use function strpos;
use function strlen;

/**
 * 14.24: fluid flow (water + lava).
 *
 * Instead of re-scanning every block of every loaded chunk each pass, the
 * system keeps a registry of liquid cells ("x:y:z" keys). Every FLOW_INTERVAL
 * ticks only those cells are processed: a liquid flows DOWN into any flowable
 * block below (carrying its "falling" flag, meta bit 3), then spreads sideways
 * with a flow decay (water 7, lava 3), and lava hardens to stone/obsidian on
 * contact with water (legacy checkForHarden). When a liquid moves into a new
 * cell the registry grows; when a cell becomes non-liquid it is dropped, so
 * steady-state worlds with no active liquid cost ~nothing.
 *
 * Each world has its own ChunkStore (and therefore its own FluidSystem run
 * scope), so the cell keys need no world qualifier.
 *
 * Liquid meta conventions (legacy 0.15):
 *   - meta 0 = source (never dries, flows forever)
 *   - meta 1-7 = flow decay (higher = thinner/further from source)
 *   - meta 8-15 = falling (bit 3 set): only flows down, spreads when it lands
 *
 * Updates are broadcast to viewers as UpdateBlockPacket so clients see the
 * world move without a chunk resend.
 */
final class FluidSystem implements System {

    /** Run the pass every N ticks (2 ticks = 10/s; smooth but cheap). */
    public const FLOW_INTERVAL = 2;
    /** Water spreads at most this many blocks from its source. */
    public const WATER_MAX_DECAY = 7;
    /** Lava is far more viscous (legacy multiplier 2). */
    public const LAVA_MAX_DECAY = 3;

    /** Water block ids (source 8, flowing 9). */
    public const WATER_IDS = [8, 9];
    /** Lava block ids (source 10, flowing 11). */
    public const LAVA_IDS = [10, 11];
    /** All liquid block ids (water + lava), for seed scanning. */
    public const LIQUID_IDS = [8, 9, 10, 11];

    private int $tickCounter = 0;
    private ?NetworkPort $network = null;
    /** @var array<string, true> seeded chunk keys (world-scoped by the store) */
    private array $seededChunks = [];
    /** @var array<string, true> active liquid cells "x:y:z" => true */
    private array $liquidCells = [];
    /** @var array<int, true> stores (by spl_object_id) that got a block listener */
    private array $listenedStores = [];

    /** True when the block id is water or lava. */
    public static function isLiquid(int $id): bool {
        return $id === 8 || $id === 9 || $id === 10 || $id === 11;
    }

    /**
     * Register a liquid cell from outside the system (block place, bucket,
     * explosion) so the next pass flows it. No-op for non-liquid ids.
     */
    public function registerLiquidCell(int $x, int $y, int $z, int $id): void {
        if (self::isLiquid($id)) {
            $this->liquidCells[$x . ':' . $y . ':' . $z] = true;
        }
    }

    public function run(World $world, float $deltaTime): void {
        $this->tickCounter++;
        if ($this->tickCounter % self::FLOW_INTERVAL !== 0) {
            return;
        }
        $store = $world->getResourceRegistry()->get(ChunkStore::class);
        $blocks = $world->getResourceRegistry()->get(BlockRegistry::class);
        if (!$store instanceof ChunkStore || !$blocks instanceof BlockRegistry) {
            return;
        }

        // Listen for block writes so liquids placed after a chunk was seeded
        // (worldgen, buckets, test setups) still get registered on the next
        // pass - no need to rescan the whole chunk.
        $sid = spl_object_id($store);
        if (!isset($this->listenedStores[$sid])) {
            $this->listenedStores[$sid] = true;
            $store->setBlockListener(fn(int $x, int $y, int $z, int $id) => $this->registerLiquidCell($x, $y, $z, $id));
        }

        $this->seedNewChunks($store);

        $updates = [];
        // Iterate a snapshot so newly spread cells can be processed next pass
        // (avoid mutating the array while walking it).
        foreach (array_keys($this->liquidCells) as $key) {
            $this->flowCell($store, $blocks, $key, $updates);
        }
        if (empty($updates)) {
            return;
        }

        // Light changes as liquids displace blocks; recompute per affected
        // chunk so the client's darkness/sky matches.
        $chunksTouched = [];
        foreach (array_keys($updates) as $key) {
            [$ux, $uz] = [0, 0];
            $parts = explode(':', $key);
            if (count($parts) === 3) {
                [$ux, $uz] = [intdiv((int)$parts[0], 16), intdiv((int)$parts[2], 16)];
            }
            $chunksTouched[$ux . ':' . $uz] = true;
        }
        foreach (array_keys($chunksTouched) as $chunkKey) {
            [$chunkX, $chunkZ] = array_map('intval', explode(':', $chunkKey));
            $store->recalculateLight($chunkX, $chunkZ, $blocks);
        }
        $this->broadcastUpdates($world, $updates);
    }

    /**
     * Lazily scan chunks that were loaded since the last pass (worldgen and
     * chunk streaming can place liquids between passes). Each chunk is only
     * scanned once per FluidSystem lifetime, so the cost stays bounded.
     *
     * The scan itself is a C-speed strpos() walk over the chunk's raw 65536-
     * byte block string (index layout (y << 8) | (localZ << 4) | localX),
     * which is ~100x cheaper than 65K getBlock() calls - important because
     * chunk streaming loads chunks one at a time, so this runs per new chunk
     * during the join burst.
     */
    private function seedNewChunks(ChunkStore $store): void {
        // Fast path: every chunk we know about has already been scanned, so
        // there is nothing new to seed (the block listener registers liquids
        // placed inside already-seeded chunks). This keeps the per-pass cost
        // O(1) even with hundreds of chunks loaded.
        if (count($this->seededChunks) >= $store->getCount()) {
            return;
        }
        foreach ($store->getLoadedChunkCoordinates() as [$chunkX, $chunkZ]) {
            $seedKey = $chunkX . ':' . $chunkZ;
            if (isset($this->seededChunks[$seedKey])) {
                continue;
            }
            $this->seededChunks[$seedKey] = true;
            $raw = $store->getRawBlocks($chunkX, $chunkZ);
            if ($raw === null || strlen($raw) < ChunkStore::CHUNK_BLOCK_COUNT) {
                continue;
            }
            $baseX = $chunkX * 16;
            $baseZ = $chunkZ * 16;
            foreach (self::LIQUID_IDS as $lid) {
                $needle = chr($lid);
                $offset = 0;
                while (($pos = strpos($raw, $needle, $offset)) !== false) {
                    $y = $pos >> 8;
                    $rem = $pos & 0xFF;
                    $localZ = $rem >> 4;
                    $localX = $rem & 0x0F;
                    $this->liquidCells[($baseX + $localX) . ':' . $y . ':' . ($baseZ + $localZ)] = true;
                    $offset = $pos + 1;
                }
            }
        }
    }

    /** Process one liquid cell. */
    private function flowCell(ChunkStore $store, BlockRegistry $blocks, string $key, array &$updates): void {
        $parts = explode(':', $key);
        if (count($parts) !== 3) {
            unset($this->liquidCells[$key]);
            return;
        }
        $x = (int)$parts[0];
        $y = (int)$parts[1];
        $z = (int)$parts[2];
        $id = $store->getBlock($x, $y, $z);
        if (!self::isLiquid($id)) {
            unset($this->liquidCells[$key]);
            return;
        }
        $meta = $store->getBlockMeta($x, $y, $z);
        $isLava = $id === 10 || $id === 11;
        $this->flowAt($store, $blocks, $x, $y, $z, $id, $meta, $isLava, $updates);
    }

    /**
     * Flow one liquid cell, writing changed blocks into $updates (keyed
     * "x:y:z" => [id, meta]) so broadcasts happen once per pass. The liquid
     * registry is kept in sync as cells change.
     */
    private function flowAt(
        ChunkStore $store,
        BlockRegistry $blocks,
        int $x,
        int $y,
        int $z,
        int $id,
        int $meta,
        bool $isLava,
        array &$updates
    ): void {
        $falling = ($meta & 0x08) !== 0;
        $decay = $meta & 0x07;

        // Lava touching water hardens INTO stone at the lava cell (legacy
        // checkForHarden): source lava -> obsidian (49), flowing -> cobble (4).
        if ($isLava && $this->touchesWater($store, $blocks, $x, $y, $z)) {
            $this->setBlock($store, $blocks, $x, $y, $z, $decay === 0 ? 49 : 4, 0, $updates);
            return;
        }

        $below = $store->getBlock($x, $y - 1, $z);
        $belowSolid = $blocks->isSolid($below);

        // 1) Flow down when the cell below is flowable (air or replaceable).
        //    A non-falling source above a hole keeps falling (bit 3 set); a
        //    flowing stream keeps its decay while descending.
        if (!$belowSolid && $below !== $id) {
            $newMeta = 0x08;
            if ($decay > 0) {
                $newMeta = 0x08 | $decay;
            }
            $this->setLiquid($store, $blocks, $x, $y - 1, $z, $id, $newMeta, $updates);
            return;
        }
        // The cell below is the same liquid: keep descending so the column
        // reaches the floor (a source keeps falling; a falling stream keeps
        // its falling flag). Only the bottom cell, above solid ground, then
        // spreads sideways.
        if (!$belowSolid && $below === $id) {
            $this->setLiquid($store, $blocks, $x, $y - 1, $z, $id, 0x08, $updates);
            return;
        }

        // 2) Falling liquid that landed: spread sideways at decay 0, then stop.
        if ($falling) {
            $this->spreadSideways($store, $blocks, $x, $y, $z, $id, $isLava, 0, $updates);
            return;
        }

        // 3) Solid below: source spreads sideways; flowing blocks may also
        //    advance. A water source stays a source forever.
        $this->spreadSideways($store, $blocks, $x, $y, $z, $id, $isLava, $decay, $updates);
    }

    private function spreadSideways(
        ChunkStore $store,
        BlockRegistry $blocks,
        int $x,
        int $y,
        int $z,
        int $id,
        bool $isLava,
        int $decay,
        array &$updates
    ): void {
        $maxDecay = $isLava ? self::LAVA_MAX_DECAY : self::WATER_MAX_DECAY;
        if ($decay >= $maxDecay) {
            return; // spread limit reached
        }
        $nextDecay = $decay + 1;
        $offsets = [[-1, 0], [1, 0], [0, -1], [0, 1]];
        foreach ($offsets as [$dx, $dz]) {
            $nx = $x + $dx;
            $nz = $z + $dz;
            $target = $store->getBlock($nx, $y, $nz);
            if ($target === $id) {
                continue; // already this liquid
            }
            if (!$blocks->isReplaceable($target) && $target !== 0) {
                continue; // not flowable
            }
            $this->setLiquid($store, $blocks, $nx, $y, $nz, $id, $nextDecay, $updates);
        }
    }

    /** True when any of the 6 neighbours of (x, y, z) is water. */
    private function touchesWater(ChunkStore $store, BlockRegistry $blocks, int $x, int $y, int $z): bool {
        $offsets = [[-1, 0, 0], [1, 0, 0], [0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1]];
        foreach ($offsets as [$dx, $dy, $dz]) {
            $id = $store->getBlock($x + $dx, $y + $dy, $z + $dz);
            if ($id === 8 || $id === 9) {
                return true;
            }
        }
        return false;
    }

    private function setLiquid(ChunkStore $store, BlockRegistry $blocks, int $x, int $y, int $z, int $id, int $meta, array &$updates): void {
        $this->setBlock($store, $blocks, $x, $y, $z, $id, $meta & 0x0F, $updates);
    }

    private function setBlock(ChunkStore $store, BlockRegistry $blocks, int $x, int $y, int $z, int $id, int $meta, array &$updates): void {
        if ($y < 0 || $y > 255) {
            return;
        }
        $key = "$x:$y:$z";
        if (array_key_exists($key, $updates)) {
            return; // already set this pass
        }
        $old = $store->getBlock($x, $y, $z);
        if ($old === $id && $store->getBlockMeta($x, $y, $z) === $meta) {
            return;
        }
        if (!$store->setBlock($x, $y, $z, $id, $meta)) {
            return;
        }
        $updates[$key] = [$id, $meta];
        // Keep the liquid registry in sync with the world.
        if (self::isLiquid($id)) {
            $this->liquidCells[$key] = true;
        } else {
            unset($this->liquidCells[$key]);
        }
    }

    private function broadcastUpdates(World $world, array $updates): void {
        $network = $this->getNetwork();
        if ($network === null) {
            return;
        }
        // Collect players in this world (all sessions are world-filtered by
        // the session service; we target every connected player).
        $players = [];
        foreach ($world->getEntities() as $entity) {
            if (!$entity->has(\pocketmine\core\component\tags\PlayerTag::class)) {
                continue;
            }
            $players[] = \pocketmine\core\ecs\EntityRef::create($entity->id, $world);
        }
        if (empty($players)) {
            return;
        }
        foreach ($updates as $key => [$id, $meta]) {
            [$ux, $uy, $uz] = array_map('intval', explode(':', $key));
            $pk = new UpdateBlockPacket();
            $pk->x = $ux;
            $pk->y = $uy;
            $pk->z = $uz;
            $pk->blockId = $id;
            $pk->blockData = $meta;
            $pk->flags = UpdateBlockPacket::FLAG_ALL_PRIORITY;
            $network->broadcastPacket($players, $pk);
        }
    }

    private function getNetwork(): ?NetworkPort {
        $this->network ??= Kernel::getInstance()?->getNetworkPort();
        return $this->network;
    }
}
