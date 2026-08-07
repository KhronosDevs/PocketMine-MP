<?php

declare(strict_types=1);

namespace pocketmine\domain\thread;

use pocketmine\domain\region\RegionWorld;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\port\driven\ThreadingPort as ThreadingPortInterface;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\WorldGenPort;
use pocketmine\Thread;
use pocketmine\ThreadSafe;
use pocketmine\port\driven\PlayerRef;

final class RegionThread extends Thread {
    private RegionWorld $world;
    private ThreadingPortInterface $threadingPort;
    private ThreadSafe $commandQueue;
    private ThreadSafe $syncQueue;
    private ThreadSafe $migrationQueue;
    private bool $running = true;
    private float $targetDeltaTime = 0.05; // 20 TPS

    public function __construct(
        RegionWorld $world,
        ThreadingPortInterface $threadingPort,
        NetworkPort $networkPort,
        StoragePort $storagePort,
        WorldGenPort $worldGenPort,
    ) {
        $this->world = $world;
        $this->threadingPort = $threadingPort;
        $this->commandQueue = new ThreadSafe();
        $this->syncQueue = new ThreadSafe();
        $this->migrationQueue = new ThreadSafe();
    }

    public function getCommandQueue(): ThreadSafe {
        return $this->commandQueue;
    }

    public function getSyncQueue(): ThreadSafe {
        return $this->syncQueue;
    }

    public function getMigrationQueue(): ThreadSafe {
        return $this->migrationQueue;
    }

    public function getWorld(): RegionWorld {
        return $this->world;
    }

    public function run(): void {
        $this->registerClassLoader();
        
        while ($this->running) {
            $start = hrtime(true);
            
            // 1. Drain commands from coordination thread
            $this->processCommands();
            
            // 2. Process incoming migrations
            $this->processMigrations();
            
            // 3. Run ECS tick
            $this->world->tick($this->targetDeltaTime);
            
            // 4. Collect network sync data
            $this->collectNetworkSync();
            
            // 5. Sleep to maintain 20 TPS
            $end = hrtime(true);
            $elapsedMs = ($end - $start) / 1_000_000;
            
            if ($elapsedMs < $this->targetDeltaTime * 1000) {
                $sleepUs = (int)(($this->targetDeltaTime * 1000 - $elapsedMs) * 1000);
                if ($sleepUs > 0) {
                    usleep($sleepUs);
                }
            }
        }
    }

    public function shutdown(): void {
        $this->running = false;
    }

    private function processCommands(): void {
        while (true) {
            $cmd = $this->commandQueue->shift();
            if ($cmd === null) break;
            
            match ($cmd['type']) {
                'migrate_entity' => $this->handleMigrateEntity($cmd),
                'spawn_entity' => $this->handleSpawnEntity($cmd),
                'despawn_entity' => $this->handleDespawnEntity($cmd),
                'chunk_load' => $this->handleChunkLoad($cmd),
                'chunk_unload' => $this->handleChunkUnload($cmd),
                default => null,
            };
        }
    }

    private function processMigrations(): void {
        while (true) {
            $migration = $this->migrationQueue->shift();
            if ($migration === null) break;
            
            // Apply entity migration
            $entityRef = $migration['entityRef'];
            $snapshot = $migration['snapshot'];
            // Entity would be recreated in this region's world
        }
    }

    private function handleMigrateEntity(array $cmd): void {
        // Entity migration handled by coordination thread
    }

    private function handleSpawnEntity(array $cmd): void {
        // Entity spawn handled locally
    }

    private function handleDespawnEntity(array $cmd): void {
        // Entity despawn handled locally
    }

    private function handleChunkLoad(array $cmd): void {
        // Chunk load request
    }

    private function handleChunkUnload(array $cmd): void {
        // Chunk unload request
    }

    private function collectNetworkSync(): void {
        // Collect NetworkSyncComponent from all entities
        // This would iterate over entities and collect position/velocity/metadata changes
        // For now, placeholder
    }

    public function migrateEntityOut(\pocketmine\domain\ecs\EntityRef $entityRef, int $targetRegionId): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        // Capture component snapshot
        $components = [];
        foreach ($entity->getComponents() as $type => $component) {
            $components[$type] = \pocketmine\domain\ecs\ComponentSerializer::serialize($component);
        }
        
        $position = $entity->get(\pocketmine\domain\component\PositionComponent::class);
        $rotation = $entity->get(\pocketmine\domain\component\RotationComponent::class);
        
        $snapshot = new \pocketmine\port\driven\EntitySnapshot(
            (string)$entity->id,
            get_class($entity),
            $position?->x ?? 0,
            $position?->y ?? 0,
            $position?->z ?? 0,
            $rotation?->yaw ?? 0,
            $rotation?->pitch ?? 0,
            $components
        );
        
        // Send to target region via coordination thread
        // This would be queued in the coordination thread's migration queue
    }
}