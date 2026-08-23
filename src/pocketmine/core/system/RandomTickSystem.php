<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\constants\BlockIds;

// Block ids: 59 wheat crop, 141 carrots crop, 142 potatoes crop, 60 farmland, 17 log, 18 leaves
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldComponent;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\component\PositionComponent;

/**
 * Random block ticks (bug 16): crop growth, grass spread and leaf decay.
 *
 * Vanilla samples a few random blocks per chunk section per tick; without
 * per-section storage this implementation samples random surface columns of
 * loaded default-world chunks every RANDOM_TICK_INTERVAL ticks instead.
 * Crops sit in the sampled window (surface ±3), so planted wheat/carrots/
 * potatoes progress through their meta stages; leaves whose tree was
 * removed decay; grass spreads onto nearby exposed dirt.
 */
final class RandomTickSystem implements System {
    public const TICK_INTERVAL = 20;
    /** Loaded chunks processed per pass (nearest-player chunks first). */
    public const MAX_CHUNKS_PER_PASS = 24;
    /** Random columns sampled per chunk. */
    public const SAMPLES_PER_CHUNK = 12;
    /** Crop growth stages live in block meta 0..7. */
    private const CROP_MAX_STAGE = 7;
    /** Ticks a leaf persists with no log nearby before decaying. */
    private const LEAF_DECAY_RADIUS = 2;

    private int $tickCounter = 0;

    public function run(World $world, float $deltaTime): void {
        $this->tickCounter++;
        if ($this->tickCounter % self::TICK_INTERVAL !== 0) {
            return;
        }
        $store = $world->getResourceRegistry()->get(ChunkStore::class);
        if (!$store instanceof ChunkStore) {
            return;
        }

        // Alive default-world player chunk positions anchor the pass: only
        // loaded chunks within view range are ticked (bounds the cost to
        // where players can actually see).
        $anchors = [];
        foreach ($world->query()->with(PlayerTag::class)->build() as $entity) {
            $pos = $entity->get(PositionComponent::class);
            $worldComponent = $entity->get(WorldComponent::class);
            if ($pos === null || ($worldComponent !== null && $worldComponent->id !== 0)) {
                continue;
            }
            $health = $entity->get(HealthComponent::class);
            if ($health !== null && $health->current <= 0) {
                continue;
            }
            $anchors[] = [(int)floor($pos->x / 16), (int)floor($pos->z / 16)];
        }
        if ($anchors === []) {
            return;
        }

        $processed = 0;
        foreach ($store->getLoadedChunkCoordinates() as [$cx, $cz]) {
            if ($processed >= self::MAX_CHUNKS_PER_PASS) {
                break;
            }
            $near = false;
            foreach ($anchors as [$ax, $az]) {
                if (max(abs($cx - $ax), abs($cz - $az)) <= 2) { // view radius + margin
                    $near = true;
                    break;
                }
            }
            if (!$near) {
                continue;
            }
            $processed++;
            $this->randomTickChunk($store, $cx, $cz);
        }
    }

    private function randomTickChunk(ChunkStore $store, int $chunkX, int $chunkZ): void {
        $baseX = $chunkX * 16;
        $baseZ = $chunkZ * 16;
        for ($s = 0; $s < self::SAMPLES_PER_CHUNK; $s++) {
            $x = $baseX + random_int(0, 15);
            $z = $baseZ + random_int(0, 15);
            $top = $store->getHighestBlockAt($x, $z);
            if ($top <= 0) {
                continue;
            }
            // Scan the small window around the surface where crops, grass
            // and low leaves live.
            for ($y = $top + 1; $y >= $top - 2 && $y > 0; $y--) {
                $block = $store->getBlock($x, $y, $z);
                switch ($block) {
                    case 59: // wheat crop
                    case 141: // carrots
                    case 142: // potatoes
                        $this->growCrop($store, $x, $y, $z, $block);
                        break;
                    case BlockIds::GRASS:
                        $this->trySpreadGrass($store, $x, $y, $z);
                        break;
                    case 18: // leaves
                        $this->tryLeafDecay($store, $x, $y, $z);
                        break;
                }
            }
        }
    }

    private function growCrop(ChunkStore $store, int $x, int $y, int $z, int $block): void {
        $meta = $store->getBlockMeta($x, $y, $z);
        if ($meta >= self::CROP_MAX_STAGE) {
            return; // fully grown
        }
        // Needs farmland below.
        if ($store->getBlock($x, $y - 1, $z) !== 60) { // FARMLAND
            return;
        }
        // ~35% chance per pass keeps full growth around a few minutes.
        if (mt_rand(1, 100) > 35) {
            return;
        }
        $store->setBlock($x, $y, $z, $block, min(self::CROP_MAX_STAGE, $meta + 1));
    }

    private function trySpreadGrass(ChunkStore $store, int $x, int $y, int $z): void {
        if (mt_rand(1, 100) > 10) {
            return;
        }
        // Spread to one adjacent exposed dirt block.
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
            $nx = $x + $dx;
            $nz = $z + $dz;
            if ($store->getBlock($nx, $y, $nz) === BlockIds::DIRT
                && $store->getBlock($nx, $y + 1, $nz) === 0) {
                $store->setBlock($nx, $y, $nz, BlockIds::GRASS);
                return;
            }
        }
    }

    private function tryLeafDecay(ChunkStore $store, int $x, int $y, int $z): void {
        // A leaf with no log within LEAF_DECAY_RADIUS blocks decays.
        for ($dx = -self::LEAF_DECAY_RADIUS; $dx <= self::LEAF_DECAY_RADIUS; $dx++) {
            for ($dy = -self::LEAF_DECAY_RADIUS; $dy <= self::LEAF_DECAY_RADIUS; $dy++) {
                for ($dz = -self::LEAF_DECAY_RADIUS; $dz <= self::LEAF_DECAY_RADIUS; $dz++) {
                    if ($store->getBlock($x + $dx, $y + $dy, $z + $dz) === 17) { // log
                        return;
                    }
                }
            }
        }
        $store->setBlock($x, $y, $z, 0);
    }
}
