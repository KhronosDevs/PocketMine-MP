<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\ChunkStore;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\WorldGenPort;

final class PlayerJoinService {
    public function __construct(
        private readonly World $world,
        private readonly NetworkPort $networkPort,
        private readonly StoragePort $storagePort,
        private readonly WorldGenPort $worldGenPort,
        private readonly ChunkLoadService $chunkLoadService,
    ) {}

    public function handleJoin(PlayerRef $playerRef, string $username): EntityRef {
        // Create or load player entity
        $entityRef = $this->createOrLoadPlayer($playerRef, $username);
        
        // Send join packets (spawn position, inventory, etc.)
        $this->sendJoinPackets($entityRef);
        
        // Load player data from storage
        $this->loadPlayerData($entityRef);
        
        // Notify other players
        $this->broadcastPlayerJoin($entityRef);
        
        return $entityRef;
    }

    private function createOrLoadPlayer(PlayerRef $playerRef, string $username): EntityRef {
        // Try to load existing player data
        $savedData = $this->storagePort->loadEntity($playerRef->uniqueId);
        
        if ($savedData !== null && $savedData->type === 'Player') {
            // Recreate from saved data
            $entityRef = $this->world->spawn(
                (new EntityBuilder())
                    ->with(new PositionComponent($savedData->x, $savedData->y, $savedData->z))
                    ->with(new RotationComponent($savedData->yaw, $savedData->pitch))
                    ->with(new VelocityComponent())
                    ->with(new HealthComponent())
                    ->with(new InventoryComponent(36))
                    ->with(new MetadataComponent())
                    ->withTag('player')
                    
            );
            
            // Restore components from saved data
            $this->restoreComponents($entityRef, $savedData);
            
            return $entityRef;
        }
        
        // New player - spawn at world spawn
        $spawn = $this->getWorldSpawn();
        
        $entityRef = $this->world->spawn(
            (new EntityBuilder())
                ->with(new PositionComponent($spawn->x, $spawn->y, $spawn->z))
                ->with(new RotationComponent(0, 0))
                ->with(new VelocityComponent())
                ->with(new HealthComponent(20, 20))
                ->with(new InventoryComponent(36))
                ->with(new MetadataComponent())
                ->withTag('player')
                
        );
        
        // Store mapping
        $entity = $entityRef->getEntity();
        if ($entity) {
            $entity->get(MetadataComponent::class)?->set('username', $username);
            $entity->get(MetadataComponent::class)?->set('uniqueId', $playerRef->uniqueId);
        }
        
        return $entityRef;
    }

    private function getWorldSpawn(): PositionComponent {
        $kernel = \pocketmine\Kernel::getInstance();
        $config = $kernel?->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
        $spawnX = $config instanceof \pocketmine\core\resource\ServerConfig ? $config->spawnX : 0;
        $spawnZ = $config instanceof \pocketmine\core\resource\ServerConfig ? $config->spawnZ : 0;

        // Safe spawn (legacy Level::getSafeSpawn): stand ON TOP of the highest
        // block at a dry column near the configured spawn. The old fixed
        // (0, 64, 0) default lands players INSIDE the terrain (hills are
        // 64-96 blocks tall) and the client suffocates them in a grass block.
        [$spawnX, $spawnY, $spawnZ] = $this->findSafeSpawn($spawnX, $spawnZ);

        // Persist the resolved spawn so respawn (and anything else reading
        // the config) lands on the same spot, not the un-resolved default.
        if ($config instanceof \pocketmine\core\resource\ServerConfig) {
            $config->spawnX = $spawnX;
            $config->spawnY = $spawnY;
            $config->spawnZ = $spawnZ;
        }

        return new PositionComponent($spawnX, $spawnY, $spawnZ);
    }

    /**
     * Find a safe place to stand: the nearest column with a dry surface
     * (top block at or above sea level and not water) to the configured
     * spawn, scanning the 3x3 chunk area around it. Falls back to standing
     * on the water surface if the whole area is ocean - the player swims
     * instead of spawning inside a block.
     *
     * @return array{0: int, 1: int, 2: int} [x, y, z]
     */
    private function findSafeSpawn(int $spawnX, int $spawnZ): array {
        $store = $this->world->getResourceRegistry()->get(ChunkStore::class);
        $store = $store instanceof ChunkStore ? $store : null;
        $seaLevel = \pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter::SEA_LEVEL;
        $water = \pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter::WATER_BLOCK;

        $isDry = static function (int $x, int $z) use ($store, $seaLevel, $water): bool {
            if ($store === null) {
                return false;
            }
            $top = $store->getHighestBlockAt($x, $z);
            return $top >= $seaLevel && $store->getBlock($x, $top, $z) !== $water;
        };

        $chunkX = (int)floor($spawnX / 16);
        $chunkZ = (int)floor($spawnZ / 16);

        // Fast path (the common case): the configured spawn column is dry
        // land, so stand exactly on it and the world spawn never moves. Only
        // load one chunk here; the 8-neighbour scan below is the rare path.
        $this->chunkLoadService->loadChunk($chunkX, $chunkZ);
        if ($isDry($spawnX, $spawnZ)) {
            return [$spawnX, $store->getHighestBlockAt($spawnX, $spawnZ) + 1, $spawnZ];
        }

        // Slow path: the configured spawn is underwater - scan the 8
        // surrounding chunks for the nearest dry column (Manhattan distance)
        // so the player lands on the closest beach instead of swimming.
        $best = null;
        $bestDist = PHP_INT_MAX;
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dz = -1; $dz <= 1; $dz++) {
                if ($dx === 0 && $dz === 0) {
                    continue; // spawn chunk already checked
                }
                $cx = $chunkX + $dx;
                $cz = $chunkZ + $dz;
                $this->chunkLoadService->loadChunk($cx, $cz);
                $wx0 = $cx * 16;
                $wz0 = $cz * 16;
                for ($bz = 0; $bz < 16; $bz++) {
                    for ($bx = 0; $bx < 16; $bx++) {
                        $x = $wx0 + $bx;
                        $z = $wz0 + $bz;
                        if (!$isDry($x, $z)) {
                            continue; // underwater column or open ocean
                        }
                        $dist = abs($x - $spawnX) + abs($z - $spawnZ);
                        if ($dist < $bestDist) {
                            $bestDist = $dist;
                            $best = [$x, $store->getHighestBlockAt($x, $z) + 1, $z];
                        }
                    }
                }
            }
        }
        if ($best !== null) {
            return $best;
        }

        // No dry land in the area: stand on the water surface at the
        // configured spawn so the player at least doesn't spawn inside a
        // block (they will swim).
        $top = $store !== null ? $store->getHighestBlockAt($spawnX, $spawnZ) : $seaLevel;
        return [$spawnX, max($top + 1, $seaLevel + 1), $spawnZ];
    }

    private function sendJoinPackets(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $position = $entity->get(PositionComponent::class);
        $rotation = $entity->get(RotationComponent::class);
        $health = $entity->get(HealthComponent::class);
        $inventory = $entity->get(InventoryComponent::class);
        
        if ($position && $rotation) {
            // Send StartGamePacket (protocol 84) - would be created in NetworkSyncSystem
            // For now, we just ensure the entity is ready for network sync
        }
    }

    private function loadPlayerData(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $metadata = $entity->get(MetadataComponent::class);
        $uniqueId = $metadata?->get('uniqueId');
        
        if ($uniqueId) {
            $savedData = $this->storagePort->loadEntity($uniqueId);
            if ($savedData) {
                $this->restoreComponents($entityRef, $savedData);
            }
        }
    }

    private function restoreComponents(EntityRef $entityRef, \pocketmine\port\driven\EntitySnapshot $savedData): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        // Restore health
        $health = $entity->get(\pocketmine\core\component\HealthComponent::class);
        if ($health && isset($savedData->components['pocketmine\core\component\HealthComponent'])) {
            $healthData = $savedData->components['pocketmine\core\component\HealthComponent'];
            $health->current = $healthData['current'] ?? 20;
            $health->max = $healthData['max'] ?? 20;
        }
        
        // Restore inventory
        $inventory = $entity->get(\pocketmine\core\component\InventoryComponent::class);
        if ($inventory && isset($savedData->components['pocketmine\core\component\InventoryComponent'])) {
            $invData = $savedData->components['pocketmine\core\component\InventoryComponent'];
            if (isset($invData['slots'])) {
                foreach ($invData['slots'] as $slot => $itemData) {
                    $inventory->set($slot, \pocketmine\core\component\ItemStack::fromArray($itemData));
                }
            }
        }
        
        // Restore metadata
        $metadata = $entity->get(\pocketmine\core\component\MetadataComponent::class);
        if ($metadata && isset($savedData->components['pocketmine\core\component\MetadataComponent'])) {
            $metaData = $savedData->components['pocketmine\core\component\MetadataComponent'];
            if (isset($metaData['data'])) {
                foreach ($metaData['data'] as $key => $value) {
                    $metadata->set($key, $value);
                }
            }
        }
    }

    private function broadcastPlayerJoin(EntityRef $entityRef): void {
        // NetworkSyncSystem will handle sending AddPlayerPacket to nearby players
        // This service just ensures the entity is properly set up
    }
}