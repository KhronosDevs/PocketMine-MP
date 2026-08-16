<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\ecs\ResourceRegistry;
use pocketmine\core\resource\BrewingStore;
use pocketmine\core\resource\ChestStore;
use pocketmine\core\resource\ContainerStore;
use pocketmine\core\resource\FurnaceStore;
use pocketmine\core\resource\TileEntityStore;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\port\driven\ChunkData;

/**
 * Shared chunk-save helper (14.16): every path that persists a ChunkData -
 * the kernel's autosave, FIFO eviction, and explicit unload - must attach the
 * block-store tile snapshots (chest contents, furnace slots + burn/cook
 * progress) so in-memory block state is never lost when a chunk leaves the
 * resident set. Before this existed, eviction saved only the raw terrain and
 * a crash after eviction (before the next autosave) permanently lost chest
 * and furnace contents.
 */
final class ChunkPersistence {
    private function __construct() {}

    public static function attachTileSnapshots(ResourceRegistry $resources, ChunkData $chunkData, int $chunkX, int $chunkZ, int $worldId = 0): ChunkData {
        // The in-memory block stores are authoritative while the chunk is
        // resident, so their snapshots REPLACE any store-owned snapshots that
        // rode in from disk (which are stale the moment a chest or furnace
        // changed in memory). Filter them out first, then attach fresh ones -
        // this also keeps a re-save from duplicating entries.
        //
        // Tile stores are per-world: a chest at the same coordinates in two
        // worlds has two different inventories. Non-default worlds resolve
        // strictly through the registry; the default world (id 0) falls back
        // to the global resource instances so single-world behavior is
        // unchanged.
        $fresh = [];
        $chestStore = self::chestStore($resources, $worldId);
        if ($chestStore !== null) {
            $fresh = array_merge($fresh, $chestStore->snapshotsForChunk($chunkX, $chunkZ));
        }
        $furnaceStore = self::furnaceStore($resources, $worldId);
        if ($furnaceStore !== null) {
            $fresh = array_merge($fresh, $furnaceStore->snapshotsForChunk($chunkX, $chunkZ));
        }
        $containerStore = self::containerStore($resources, $worldId);
        if ($containerStore !== null) {
            $fresh = array_merge($fresh, $containerStore->snapshotsForChunk($chunkX, $chunkZ));
        }
        $brewingStore = self::brewingStore($resources, $worldId);
        if ($brewingStore !== null) {
            $fresh = array_merge($fresh, $brewingStore->snapshotsForChunk($chunkX, $chunkZ));
        }
        $tileEntityStore = self::tileEntityStore($resources, $worldId);
        if ($tileEntityStore !== null) {
            $fresh = array_merge($fresh, $tileEntityStore->snapshotsForChunk($chunkX, $chunkZ));
        }
        if ($fresh === []) {
            return $chunkData; // no block stores: chunk rides through untouched
        }
        $owned = [ChestStore::TILE_TYPE => true, FurnaceStore::TILE_TYPE => true, ContainerStore::TILE_DISPENSER => true, ContainerStore::TILE_HOPPER => true, BrewingStore::TILE_TYPE => true, TileEntityStore::TILE_SIGN => true, TileEntityStore::TILE_ITEM_FRAME => true];
        $tileEntities = array_values(array_filter(
            $chunkData->tileEntities,
            static fn($snapshot): bool => !isset($owned[$snapshot->type]),
        ));
        return new ChunkData(
            $chunkData->chunkX,
            $chunkData->chunkZ,
            $chunkData->sections,
            $chunkData->biomes,
            $chunkData->heightmap,
            $chunkData->entities,
            array_merge($tileEntities, $fresh),
        );
    }

    /** The ChestStore for a world bundle (default world falls back to the global resource). */
    private static function chestStore(ResourceRegistry $resources, int $worldId): ?ChestStore {
        if ($worldId !== 0) {
            $registry = $resources->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getChestStore($worldId) : null;
        }
        $store = $resources->get(ChestStore::class);
        return $store instanceof ChestStore ? $store : null;
    }

    /** The FurnaceStore for a world bundle (default world falls back to the global resource). */
    private static function furnaceStore(ResourceRegistry $resources, int $worldId): ?FurnaceStore {
        if ($worldId !== 0) {
            $registry = $resources->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getFurnaceStore($worldId) : null;
        }
        $store = $resources->get(FurnaceStore::class);
        return $store instanceof FurnaceStore ? $store : null;
    }

    /** The ContainerStore for a world bundle (default world falls back to the global resource). */
    private static function containerStore(ResourceRegistry $resources, int $worldId): ?ContainerStore {
        if ($worldId !== 0) {
            $registry = $resources->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getContainerStore($worldId) : null;
        }
        $store = $resources->get(ContainerStore::class);
        return $store instanceof ContainerStore ? $store : null;
    }

    /** The BrewingStore for a world bundle (default world falls back to the global resource). */
    private static function brewingStore(ResourceRegistry $resources, int $worldId): ?BrewingStore {
        if ($worldId !== 0) {
            $registry = $resources->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getBrewingStore($worldId) : null;
        }
        $store = $resources->get(BrewingStore::class);
        return $store instanceof BrewingStore ? $store : null;
    }

    /** The TileEntityStore for a world bundle (default world falls back to the global resource). */
    private static function tileEntityStore(ResourceRegistry $resources, int $worldId): ?TileEntityStore {
        if ($worldId !== 0) {
            $registry = $resources->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getTileEntityStore($worldId) : null;
        }
        $store = $resources->get(TileEntityStore::class);
        return $store instanceof TileEntityStore ? $store : null;
    }
}
