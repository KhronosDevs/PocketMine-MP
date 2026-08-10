<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;
use pocketmine\port\driven\StoragePort;

/**
 * Multi-world registry (14.20): the single source of truth for which worlds
 * exist and where their data lives.
 *
 * Each world is a bundle of:
 *   - a display name and on-disk folder name,
 *   - its own seed (so terrain generation is per-world),
 *   - its own ChunkStore (block/entity data never mixes between worlds),
 *   - its own WorldConfig (time, spawn, difficulty, rules),
 *   - its own StoragePort adapter (region files under worlds/<folder>/).
 *
 * The ECS world itself is shared: entities carry a WorldComponent with the
 * bundle id they belong to, and services route chunk/block/broadcast lookups
 * through this registry. The default world is always id 0 and is registered
 * by the Kernel at boot.
 *
 * Known limitation: block-state stores (ChestStore/FurnaceStore) are shared
 * resources keyed by block coordinates only, so two worlds with chests at the
 * exact same coordinates share contents. World-level block data (ChunkStore)
 * is fully isolated; keying the tile stores by world id is a future pass.
 */
#[Resource]
final class WorldRegistry {
    /** @var array<int, array{id: int, name: string, folderName: string, seed: int, store: ChunkStore, config: WorldConfig, storage: StoragePort}> */
    private array $worlds = [];
    /** @var array<string, int> folderName => id (cheap lookup for load/generate dedup) */
    private array $byFolder = [];
    private int $nextId = 1;

    public function registerWorld(
        string $name,
        string $folderName,
        int $seed,
        ChunkStore $store,
        WorldConfig $config,
        StoragePort $storage,
    ): int {
        // The default world is pre-registered with id 0 by the Kernel; any
        // later registration gets the next id. A folder may only back one
        // world at a time (two bundles would fight over the same region
        // files).
        if (isset($this->byFolder[$folderName])) {
            return $this->byFolder[$folderName];
        }
        $id = $this->nextId++;
        $this->worlds[$id] = [
            'id' => $id,
            'name' => $name,
            'folderName' => $folderName,
            'seed' => $seed,
            'store' => $store,
            'config' => $config,
            'storage' => $storage,
        ];
        $this->byFolder[$folderName] = $id;
        return $id;
    }

    /**
     * Register the default world under a fixed id 0 (only valid before any
     * other world exists).
     */
    public function registerDefaultWorld(
        string $name,
        string $folderName,
        int $seed,
        ChunkStore $store,
        WorldConfig $config,
        StoragePort $storage,
    ): void {
        $this->worlds[0] = [
            'id' => 0,
            'name' => $name,
            'folderName' => $folderName,
            'seed' => $seed,
            'store' => $store,
            'config' => $config,
            'storage' => $storage,
        ];
        $this->byFolder[$folderName] = 0;
    }

    public function getWorld(int $id): ?array {
        return $this->worlds[$id] ?? null;
    }

    public function getWorldByName(string $name): ?array {
        foreach ($this->worlds as $world) {
            if ($world['name'] === $name) {
                return $world;
            }
        }
        return null;
    }

    public function getWorldIdByName(string $name): ?int {
        foreach ($this->worlds as $id => $world) {
            if ($world['name'] === $name) {
                return $id;
            }
        }
        return null;
    }

    public function getStore(int $id): ?ChunkStore {
        $world = $this->worlds[$id] ?? null;
        return $world !== null ? $world['store'] : null;
    }

    public function getConfig(int $id): ?WorldConfig {
        $world = $this->worlds[$id] ?? null;
        return $world !== null ? $world['config'] : null;
    }

    public function getStorage(int $id): ?StoragePort {
        $world = $this->worlds[$id] ?? null;
        return $world !== null ? $world['storage'] : null;
    }

    public function getSeed(int $id): int {
        $world = $this->worlds[$id] ?? null;
        return $world !== null ? $world['seed'] : 0;
    }

    /** @return array<int, array{id: int, name: string, folderName: string, seed: int}> */
    public function getWorlds(): array {
        $out = [];
        foreach ($this->worlds as $id => $world) {
            $out[$id] = [
                'id' => $id,
                'name' => $world['name'],
                'folderName' => $world['folderName'],
                'seed' => $world['seed'],
            ];
        }
        return $out;
    }

    public function count(): int {
        return count($this->worlds);
    }

    /** Unregister a non-default world (saving is the caller's job). */
    public function removeWorld(int $id): bool {
        if ($id === 0 || !isset($this->worlds[$id])) {
            return false;
        }
        unset($this->byFolder[$this->worlds[$id]['folderName']]);
        unset($this->worlds[$id]);
        return true;
    }
}
