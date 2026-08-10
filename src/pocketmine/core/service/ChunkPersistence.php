<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\ecs\ResourceRegistry;
use pocketmine\core\resource\ChestStore;
use pocketmine\core\resource\FurnaceStore;
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

    public static function attachTileSnapshots(ResourceRegistry $resources, ChunkData $chunkData, int $chunkX, int $chunkZ): ChunkData {
        // The in-memory block stores are authoritative while the chunk is
        // resident, so their snapshots REPLACE any store-owned snapshots that
        // rode in from disk (which are stale the moment a chest or furnace
        // changed in memory). Filter them out first, then attach fresh ones -
        // this also keeps a re-save from duplicating entries.
        $fresh = [];
        $chestStore = $resources->get(ChestStore::class);
        if ($chestStore instanceof ChestStore) {
            $fresh = array_merge($fresh, $chestStore->snapshotsForChunk($chunkX, $chunkZ));
        }
        $furnaceStore = $resources->get(FurnaceStore::class);
        if ($furnaceStore instanceof FurnaceStore) {
            $fresh = array_merge($fresh, $furnaceStore->snapshotsForChunk($chunkX, $chunkZ));
        }
        if ($fresh === []) {
            return $chunkData; // no block stores: chunk rides through untouched
        }
        $owned = [ChestStore::TILE_TYPE => true, FurnaceStore::TILE_TYPE => true];
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
}
