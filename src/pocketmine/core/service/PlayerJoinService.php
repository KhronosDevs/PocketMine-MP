<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\CollisionComponent;
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
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\WorldGenPort;
use pocketmine\port\driving\EventPort;

final class PlayerJoinService {
    public function __construct(
        private readonly World $world,
        private readonly NetworkPort $networkPort,
        private readonly StoragePort $storagePort,
        private readonly WorldGenPort $worldGenPort,
        private readonly ChunkLoadService $chunkLoadService,
        private readonly EventPort $eventPort,
    ) {}

    public function handleJoin(PlayerRef $playerRef, string $username): ?EntityRef {
        // Create or load player entity (returning players are restored from
        // their persisted snapshot inside createOrLoadPlayer).
        $entityRef = $this->createOrLoadPlayer($playerRef, $username);

        // Blocker 4 audit: cancellable PlayerLoginEvent fires right after the
        // entity exists, BEFORE PlayerJoinEvent (legacy ordering). A plugin
        // can veto the join; the caller (NetworkSessionService::handleLogin)
        // gets null back and disconnects with the kick message.
        $loginEvent = new \pocketmine\api\event\PlayerLoginEvent($this->wrapApiPlayer($entityRef));
        $this->eventPort->emit($loginEvent);
        if ($loginEvent->isCancelled()) {
            $this->world->despawn($entityRef->getEntity());
            return null;
        }

        // Mark the player as online so Player::isOnline() returns true.
        // Must happen before any events fire so plugins observing join see
        // the player as online.
        $meta = $entityRef->getEntity()?->get(\pocketmine\core\component\MetadataComponent::class);
        if ($meta !== null) {
            $meta->set(\pocketmine\core\constants\MetadataKeys::ONLINE, true);
        }

        // Blocker 1: the server-default gamemode (server.properties gamemode=)
        // applies to new players, and to returning players when force-gamemode
        // is on. Returning players otherwise keep their saved gamemode.
        $force = \pocketmine\api\server\Server::getInstance()->isForceGamemode();
        if ($meta !== null && ($meta->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE) === null || $force)) {
            $worldConfig = $this->world->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
            if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
                $meta->set(\pocketmine\core\constants\MetadataKeys::GAMEMODE, $worldConfig->gameMode->value);
            }
        }

        // Send join packets (spawn position, inventory, etc.)
        $this->sendJoinPackets($entityRef);
        
        // Notify other players
        $this->broadcastPlayerJoin($entityRef);

        // Blocker 4: PlayerJoinEvent lets plugins react to (and announce)
        // joins. Fired after the ECS entity and session state exist so the
        // event player is fully valid.
        $this->eventPort->emit(new \pocketmine\api\event\PlayerJoinEvent(
            $this->wrapApiPlayer($entityRef),
        ));
        
        return $entityRef;
    }

    private function wrapApiPlayer(EntityRef $ref): \pocketmine\api\entity\Player {
        $entity = \pocketmine\api\entity\Entity::wrap($ref, $this->world);
        return $entity instanceof \pocketmine\api\entity\Player
            ? $entity
            : new \pocketmine\api\entity\Player($ref, $this->world);
    }

    private function createOrLoadPlayer(PlayerRef $playerRef, string $username): EntityRef {
        // Try to load existing player data
        $savedData = $this->storagePort->loadEntity($playerRef->uniqueId);
        
        if ($savedData->type === 'Player') {
            // Returning player: rebuild from the persisted snapshot - the
            // position/rotation come from the snapshot top-level fields, and
            // health/inventory/metadata are restored below (the load branch
            // never hands out the fresh-spawn starter kit).
            $entityRef = $this->world->spawn(
                (new EntityBuilder())
                    ->with(new PositionComponent($savedData->x, $savedData->y, $savedData->z))
                    ->with(new RotationComponent($savedData->yaw, $savedData->pitch))
                    ->with(new VelocityComponent())
                    ->with(new CollisionComponent(width: 0.6, height: 1.8))
                    ->with(new HealthComponent())
                    ->with(new InventoryComponent())
                    ->with(new MetadataComponent())
                    ->with(new \pocketmine\core\component\HungerComponent())
                    // 14.20: players join the default world (id 0).
                    ->with(new \pocketmine\core\component\WorldComponent(0))
                    ->withTag(\pocketmine\core\constants\EntityTags::PLAYER)
            );
            
            $entity = $entityRef->getEntity();
            if ($entity) {
                // Identity first so restoreComponents can merge on top (and a
                // save without those keys still yields a named, owned player).
                $meta = $entity->get(MetadataComponent::class);
                if ($meta) {
                    $meta->set(\pocketmine\core\constants\MetadataKeys::USERNAME, $username);
                    $meta->set(\pocketmine\core\constants\MetadataKeys::UNIQUE_ID, $playerRef->uniqueId);
                }
                
                // Restore components from saved data
                $this->restoreComponents($entityRef, $savedData);

                // Suffocation guard: a returning player restores their saved
                // position, but that spot may now be inside solid terrain
                // (the seed used to reset every boot, so older saves are
                // buried under newly generated hills). Fall back to the
                // terrain-aware safe spawn when the saved spot is unsafe.
                $position = $entity->get(PositionComponent::class);
                if ($position !== null
                    && !$this->isSafePosition($position->x, $position->y, $position->z)) {
                    $safe = $this->getWorldSpawn();
                    $position->x = $safe->x;
                    $position->y = $safe->y;
                    $position->z = $safe->z;
                }
            }
            
            return $entityRef;
        }
        
        // New player - spawn at world spawn
        $spawn = $this->getWorldSpawn();
        
        $entityRef = $this->world->spawn(
            (new EntityBuilder())
                ->with(new PositionComponent($spawn->x, $spawn->y, $spawn->z))
                ->with(new RotationComponent(0, 0))
                ->with(new VelocityComponent())
                ->with(new CollisionComponent(width: 0.6, height: 1.8))
                ->with(new HealthComponent(20, 20))
                ->with(new InventoryComponent(36))
                ->with(new MetadataComponent())
                ->with(new \pocketmine\core\component\HungerComponent())
                // 14.20: players join the default world (id 0).
                ->with(new \pocketmine\core\component\WorldComponent(0))
                ->withTag(\pocketmine\core\constants\EntityTags::PLAYER)
                
        );
        
        // Store mapping
        $entity = $entityRef->getEntity();
        if ($entity) {
            $entity->get(MetadataComponent::class)?->set(\pocketmine\core\constants\MetadataKeys::USERNAME, $username);
            $entity->get(MetadataComponent::class)?->set(\pocketmine\core\constants\MetadataKeys::UNIQUE_ID, $playerRef->uniqueId);
        }


        
        return $entityRef;
    }

    /**
     * Is a spot safe to stand on? Both the feet block and the block above
     * the head must be non-solid in the loaded terrain. Falls back to
     * "unsafe" when the store/registry are unavailable, so the caller lands
     * on the (always safe) world spawn.
     */
    private function isSafePosition(float $x, float $y, float $z): bool {
        $registry = $this->world->getResourceRegistry();
        $store = $registry->get(ChunkStore::class);
        $blocks = $registry->get(BlockRegistry::class);
        if (!$store instanceof ChunkStore || !$blocks instanceof BlockRegistry) {
            return false;
        }
        $this->chunkLoadService->loadChunk((int)floor($x / 16), (int)floor($z / 16));
        $bx = (int)floor($x);
        $by = (int)floor($y);
        $bz = (int)floor($z);
        // Feet and head must be non-solid AND the block below must be solid
        // (player stands on ground, not floating in a cave).
        return !$blocks->isSolid($store->getBlock($bx, $by, $bz))
            && !$blocks->isSolid($store->getBlock($bx, $by + 1, $bz))
            && $blocks->isSolid($store->getBlock($bx, $by - 1, $bz));
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
            $y = $store->getHighestBlockAt($spawnX, $spawnZ) + 1;
            // Verify the column is actually safe (highest block + head
            // room both non-solid). Trees or overhangs can make the
            // heightmap position unsafe.
            if ($this->isSafePosition((float)$spawnX, (float)$y, (float)$spawnZ)) {
                return [$spawnX, $y, $spawnZ];
            }
            // Unsafe — fall through to the neighbour scan.
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
                        $top = $store->getHighestBlockAt($x, $z);
                        // Scan upward from the surface for 2 air blocks
                        // (feet + head) with solid ground below.
                        $safeY = null;
                        for ($sy = $top + 1; $sy < min($top + 10, 255); $sy++) {
                            if (!$blocks->isSolid($store->getBlock($x, $sy, $z))
                                && !$blocks->isSolid($store->getBlock($x, $sy + 1, $z))
                                && $blocks->isSolid($store->getBlock($x, $sy - 1, $z))) {
                                $safeY = $sy;
                                break;
                            }
                        }
                        if ($safeY === null) {
                            continue; // no safe spot — keep scanning
                        }
                        $dist = abs($x - $spawnX) + abs($z - $spawnZ);
                        if ($dist < $bestDist) {
                            $bestDist = $dist;
                            $best = [$x, $safeY, $z];
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

    /**
     * Restore the persisted player components (the explicit shape written by
     * PlayerLeaveService::savePlayer): health, inventory slots + held slot,
     * and the metadata data bag. Position/rotation were already applied when
     * the entity spawned from the snapshot's top-level fields.
     */
    private function restoreComponents(EntityRef $entityRef, \pocketmine\port\driven\EntitySnapshot $savedData): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        $components = $savedData->components;
        
        // Restore health. A player that disconnected mid-death (corpse) must
        // rejoin alive: the death screen is a session state, not a login one.
        $health = $entity->get(\pocketmine\core\component\HealthComponent::class);
        if ($health && isset($components['health'])) {
            $health->current = (float)($components['health']['current'] ?? 20);
            $health->max = (float)($components['health']['max'] ?? 20);
            if ($health->current <= 0) {
                $health->current = $health->max;
            }
        }
        
        // Restore inventory: slot => item array (id/meta/count/nbt) plus the
        // held slot so the hotbar selection survives a restart.
        $inventory = $entity->get(\pocketmine\core\component\InventoryComponent::class);
        if ($inventory && isset($components['inventory'])) {
            $slots = $components['inventory']['slots'] ?? [];
            if (is_array($slots)) {
                foreach ($slots as $slot => $itemData) {
                    if (is_array($itemData)) {
                        $inventory->set((int)$slot, \pocketmine\core\component\ItemStack::fromArray($itemData));
                    }
                }
            }
            if (isset($components['inventory']['heldSlot'])) {
                $inventory->setHeldSlot((int)$components['inventory']['heldSlot']);
            }
        }
        
        // Restore metadata (username, uniqueId, keepInventory, effects...).
        $metadata = $entity->get(\pocketmine\core\component\MetadataComponent::class);
        if ($metadata && isset($components['metadata']['data']) && is_array($components['metadata']['data'])) {
            foreach ($components['metadata']['data'] as $key => $value) {
                $metadata->set((string)$key, $value);
            }
        }
    }

    private function broadcastPlayerJoin(EntityRef $entityRef): void {
        // NetworkSyncSystem will handle sending AddPlayerPacket to nearby players
        // This service just ensures the entity is properly set up
    }
}