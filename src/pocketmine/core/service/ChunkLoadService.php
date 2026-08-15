<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\ecs\World;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\WorldGenPort;

final class ChunkLoadService {
    /**
     * Default loaded-chunk budget, sized to the server's 512M memory floor
     * (see Kernel::bootstrap): each resident chunk holds ~196KB of binary
     * payload strings (blocks + meta + sky/block light + biomes), so ~2048
     * chunks cost ~400MB. The old 10000 default (~2GB worst case) could
     * outgrow the floor before eviction ever triggered with a handful of
     * far-apart players.
     */
    public const DEFAULT_MAX_LOADED_CHUNKS = 2048;

    private int $maxLoadedChunks;

    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
        private readonly WorldGenPort $worldGenPort,
        private readonly ChunkUnloadService $chunkUnloadService,
        int $maxLoadedChunks = self::DEFAULT_MAX_LOADED_CHUNKS,
    ) {
        $this->maxLoadedChunks = max(1, $maxLoadedChunks);
    }

    public function getMaxLoadedChunks(): int {
        return $this->maxLoadedChunks;
    }

    public function setMaxLoadedChunks(int $maxLoadedChunks): void {
        $this->maxLoadedChunks = max(1, $maxLoadedChunks);
    }

    public function loadChunk(int $chunkX, int $chunkZ, int $worldId = 0): ChunkData {
        return $this->loadChunks([[$chunkX, $chunkZ]], $worldId)[0];
    }

    /**
     * Bulk-load a set of chunks, generating any missing ones in a single
     * parallel WorldGenPort::generateChunks() call (the pool runs them across
     * worker threads) instead of one serialized generateChunk() per chunk.
     *
     * The returned ChunkData DTOs are independent snapshots; when the request
     * exceeds the loaded-chunk budget, the newest chunks stay resident and the
     * oldest are evicted to disk immediately after this call. The DTOs are
     * still valid (they were built before eviction), but a later read through
     * the ChunkStore may need to re-load an evicted chunk from disk.
     *
     * @param array<int, array{0: int, 1: int}> $chunkCoords chunk coordinate pairs
     * @param int $worldId which world bundle to load into (default world = 0)
     * @return list<ChunkData> one per requested coord, in input order
     */
    public function loadChunks(array $chunkCoords, int $worldId = 0): array {
        if (empty($chunkCoords)) {
            return [];
        }
        $config = new \pocketmine\port\driven\GeneratorConfig(
            $this->getWorldGenerator($worldId),
            $this->getWorldSeed($worldId),
            []
        );

        // Split into already-loaded (in-memory is authoritative), stored
        // (already on disk) vs missing (need generation).
        /** @var list<array{0: int, 1: int}> $toGenerate */
        $toGenerate = [];
        /** @var array<int, ?ChunkData> $byIndex */
        $byIndex = [];
        /** @var array<int, bool> $alreadyLoaded */
        $alreadyLoaded = [];
        /** @var array<int, bool> $generatedIndices */
        $generatedIndices = [];
        $store = $this->getChunkStore($worldId);
        foreach ($chunkCoords as $index => [$chunkX, $chunkZ]) {
            // Loaded chunks are authoritative in memory: a re-request (e.g. a
            // late login burst or a chunk-radius refresh after a move) must
            // never clobber the resident chunk with stale disk/generator
            // data - that would wipe in-memory breaks, places, and chest
            // contents that haven't been saved to disk yet.
            if ($store !== null && $store->isLoaded($chunkX, $chunkZ)) {
                $dto = $store->toChunkData($chunkX, $chunkZ);
                if ($dto !== null) {
                    $alreadyLoaded[$index] = true;
                    $byIndex[$index] = $dto;
                    continue;
                }
            }
            $chunkData = $this->getStorage($worldId)->loadChunk($chunkX, $chunkZ);
            if ($this->isEmptyChunk($chunkData)) {
                $toGenerate[] = [$chunkX, $chunkZ];
                $generatedIndices[$index] = true;
                $byIndex[$index] = null;
            } else {
                $byIndex[$index] = $chunkData;
            }
        }

        if (!empty($toGenerate)) {
            $generated = $this->worldGenPort->generateChunks($toGenerate, $config);
            $gi = 0;
            foreach ($byIndex as $index => $data) {
                if ($data === null) {
                    $byIndex[$index] = $generated[$gi];
                    $gi++;
                }
            }
        }

        // Populate, light, and materialize each chunk into the in-memory store
        // (already-loaded chunks are returned as-is - they are already live).
        $result = [];
        ksort($byIndex);
        foreach ($byIndex as $index => $chunkData) {
            if (!$chunkData instanceof ChunkData) {
                continue; // defensive: every index was resolved above
            }
            if (isset($alreadyLoaded[$index])) {
                $result[$index] = $chunkData;
                continue;
            }
            // Only freshly GENERATED chunks are populated: disk-loaded chunks
            // were populated at generation time (the population pass is a pure
            // function of (chunk, seed), so re-running it on an already-
            // populated chunk would place a second, different set of features
            // and corrupt persistence round-trips).
            $result[$index] = $this->materializeChunk($chunkData, $config->seed, isset($generatedIndices[$index]), $worldId);
        }

        // Loaded-chunk budget (11.3): the store grew, so bring it back under
        // the cap by evicting the oldest residents (persisted first, so
        // nothing is lost). This is the single choke point for the world
        // growing its resident set - every load path funnels through here.
        $this->chunkUnloadService->unloadUnusedChunks($this->maxLoadedChunks, $worldId);

        return array_values($result);
    }

    private function materializeChunk(ChunkData $chunkData, int $seed, bool $populate, int $worldId = 0): ChunkData {
        $chunkX = $chunkData->chunkX;
        $chunkZ = $chunkData->chunkZ;

        // Populate freshly generated chunks (trees, vegetation, ...). The
        // generator is a pure function of (chunkX, chunkZ, seed), so the world
        // seed makes the population deterministic across restarts. Chunks that
        // came from disk are skipped - they were already populated when saved,
        // and re-running the pass would place a second, different feature set.
        // Void worlds skip population entirely: a lobby platform must stay
        // exactly as generated (no trees/grass sprouting on it).
        if ($populate && $this->getWorldGenerator($worldId) !== 'void') {
            $chunkData = $this->worldGenPort->populateChunk($chunkX, $chunkZ, $chunkData, $seed);
        }
        
        // Calculate light if needed
        if (!$this->hasLightData($chunkData)) {
            $lightData = $this->worldGenPort->calculateLight($chunkX, $chunkZ, $chunkData);
            // Apply light data to chunk
        }
        
        // Materialize the chunk into the in-memory store so block reads/writes
        // and the API World facade operate on real data.
        $store = $this->getChunkStore($worldId);
        if ($store !== null) {
            $store->load($chunkData);
            // 14.15: rehydrate chest inventories from the chunk's tile
            // snapshots (saved by Kernel::saveWorld) so chest contents
            // survive restarts with the terrain. Stores are per-world: the
            // chunk belongs to world $worldId, so its tile entities restore
            // into that world's store only.
            $chestStore = $this->getChestStore($worldId);
            if ($chestStore !== null) {
                $chestStore->restoreFromSnapshots($chunkData->tileEntities);
            }
            // 14.16: rehydrate furnace state (slots + burn/cook) the same way.
            $furnaceStore = $this->getFurnaceStore($worldId);
            if ($furnaceStore !== null) {
                $furnaceStore->restoreFromSnapshots($chunkData->tileEntities);
            }
            if (!$this->isEmptyChunk($chunkData)) {
                $store->markGenerated($chunkX, $chunkZ);
                // Generated chunks are populated once the population pass ran
                // (for generators whose population is a no-op, this is still true
                // because the pass executed).
                $store->markPopulated($chunkX, $chunkZ);
            }
        }
        
        return $chunkData;
    }

    private function getWorldGenerator(int $worldId = 0): string {
        // Read the world's own generator type (WorldConfig->generator,
        // e.g. 'normal'/'flat'/'void'); non-default worlds resolve strictly
        // through the registry, the default world (id 0) falls back to the
        // resource WorldConfig. Unknown/absent config defaults to 'normal'.
        $registry = $this->world->getResourceRegistry()->get(WorldRegistry::class);
        if ($registry instanceof WorldRegistry && $registry->getWorld($worldId) !== null) {
            $config = $registry->getConfig($worldId);
            if ($config instanceof \pocketmine\core\resource\WorldConfig && $config->generator !== '') {
                return $config->generator;
            }
        }
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel !== null) {
            $resource = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
            if ($resource instanceof \pocketmine\core\resource\WorldConfig && $resource->generator !== '') {
                return $resource->generator;
            }
        }
        return 'normal';
    }

    private function getWorldSeed(int $worldId = 0): int {
        // A registered 0 seed means "not resolved yet": fall back to the
        // ServerConfig seed, which callers may pin AFTER bootstrap (the
        // network test pins seed=1 post-boot) and getSeed() resolves once.
        $registry = $this->world->getResourceRegistry()->get(WorldRegistry::class);
        if ($registry instanceof WorldRegistry && $registry->getWorld($worldId) !== null) {
            $seed = $registry->getSeed($worldId);
            if ($seed !== 0) {
                return $seed;
            }
        }
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel !== null) {
            $resource = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
            if ($resource !== null) {
                return $resource->getSeed();
            }
        }
        return random_int(1, PHP_INT_MAX);
    }

    private function isEmptyChunk(ChunkData $data): bool {
        return empty($data->sections) && empty($data->entities) && empty($data->tileEntities);
    }

    private function hasLightData(ChunkData $data): bool {
        foreach ($data->sections as $section) {
            if (!empty($section['skyLight']) || !empty($section['blockLight'])) {
                return true;
            }
        }
        return false;
    }

    public function unloadChunk(int $chunkX, int $chunkZ, int $worldId = 0): void {
        // Save the in-memory chunk to storage, then drop it from the store.
        $store = $this->getChunkStore($worldId);
        if ($store !== null && $store->isLoaded($chunkX, $chunkZ)) {
            $chunkData = $store->toChunkData($chunkX, $chunkZ);
            if ($chunkData !== null) {
                $chunkData = ChunkPersistence::attachTileSnapshots(
                    $this->world->getResourceRegistry(),
                    $chunkData,
                    $chunkX,
                    $chunkZ,
                    $worldId,
                );
                $this->getStorage($worldId)->saveChunk($chunkX, $chunkZ, $chunkData);
            }
            $store->unload($chunkX, $chunkZ);
        }
    }

    public function saveChunk(ChunkData $data, int $worldId = 0): void {
        $store = $this->getChunkStore($worldId);
        // Never overwrite newer in-memory state with an older DTO: only import
        // the DTO when the chunk is not currently loaded.
        if ($store !== null && !$store->isLoaded($data->chunkX, $data->chunkZ)) {
            $store->load($data);
        }
        // Attach block-store tile snapshots so a chunk saved while resident
        // never drops its chest/furnace contents on disk (per-world stores).
        $data = ChunkPersistence::attachTileSnapshots(
            $this->world->getResourceRegistry(),
            $data,
            $data->chunkX,
            $data->chunkZ,
            $worldId,
        );
        $this->getStorage($worldId)->saveChunk($data->chunkX, $data->chunkZ, $data);
    }

    private function getStorage(int $worldId = 0): StoragePort {
        // Only the default world (id 0) may fall back to the kernel's own
        // storage; an unknown non-zero world id must NOT read the default
        // world's data (a player left in an unloaded world would silently
        // mix worlds otherwise).
        if ($worldId !== 0) {
            $registry = $this->world->getResourceRegistry()->get(WorldRegistry::class);
            $storage = $registry instanceof WorldRegistry ? $registry->getStorage($worldId) : null;
            return $storage ?? $this->storagePort;
        }
        return $this->storagePort;
    }

    private function getChunkStore(int $worldId = 0): ?ChunkStore {
        // Non-default worlds resolve strictly through the registry; only the
        // default world (id 0) falls back to the classic resource-registry
        // store so single-world behavior is unchanged.
        if ($worldId !== 0) {
            $registry = $this->world->getResourceRegistry()->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getStore($worldId) : null;
        }
        $store = $this->world->getResourceRegistry()->get(ChunkStore::class);
        return $store instanceof ChunkStore ? $store : null;
    }

    private function getChestStore(int $worldId = 0): ?\pocketmine\core\resource\ChestStore {
        if ($worldId !== 0) {
            $registry = $this->world->getResourceRegistry()->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getChestStore($worldId) : null;
        }
        $store = $this->world->getResourceRegistry()->get(\pocketmine\core\resource\ChestStore::class);
        return $store instanceof \pocketmine\core\resource\ChestStore ? $store : null;
    }

    private function getFurnaceStore(int $worldId = 0): ?\pocketmine\core\resource\FurnaceStore {
        if ($worldId !== 0) {
            $registry = $this->world->getResourceRegistry()->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getFurnaceStore($worldId) : null;
        }
        $store = $this->world->getResourceRegistry()->get(\pocketmine\core\resource\FurnaceStore::class);
        return $store instanceof \pocketmine\core\resource\FurnaceStore ? $store : null;
    }
}