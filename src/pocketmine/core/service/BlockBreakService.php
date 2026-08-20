<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\WorldComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\core\resource\Hunger;
use pocketmine\core\resource\ItemDurability;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driving\EventPort;

final class BlockBreakService {
    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
        private readonly EventPort $eventPort,
    ) {}

    public function breakBlock(EntityRef $playerRef, int $x, int $y, int $z, int $face): bool {
        $player = $playerRef->getEntity();
        if (!$player) return false;
        $worldId = $this->worldIdOf($playerRef);
        
        // Check if player can reach the block
        // Legacy canInteract: creative=13, survival=6, measured from eye height.
        $metadata = $player->get(MetadataComponent::class);
        $gm = \pocketmine\core\enum\GameMode::coerce($metadata?->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE));
        $maxReach = ($gm === \pocketmine\core\enum\GameMode::Creative) ? 13.0 : 6.0;
        if (!$this->canReach($playerRef, $x, $y, $z, $maxReach)) {
            return false;
        }
        
        // Check if block is breakable
        if (!$this->isBreakable($x, $y, $z, $worldId)) {
            return false;
        }
        
        // Blocker 4: cancellable BlockBreakEvent - a plugin can veto the
        // break before anything mutates (block stays, no drops, no wear).
        $event = new \pocketmine\api\event\BlockBreakEvent(
            $this->wrapApiPlayer($playerRef),
            $this->apiBlock($x, $y, $z, $worldId),
        );
        $this->eventPort->emit($event);
        if ($event->isCancelled()) {
            return false;
        }

        // Get tool from player's hand
        $tool = $this->getHeldItem($playerRef);
        
        // For instant break (creative mode or zero-hardness blocks)
        if ($gm === \pocketmine\core\enum\GameMode::Creative) {
            return $this->doBreakBlock($playerRef, $x, $y, $z, $tool, $worldId);
        }
        
        // Survival mode - instant break for now (progressive breaking
        // is handled by the timing gate in NetworkSessionService)
        return $this->doBreakBlock($playerRef, $x, $y, $z, $tool, $worldId);
    }

    /**
     * Reach check matching legacy canInteract: measured from the player's
     * eye height, with a configurable max distance (13 creative / 6 survival).
     */
    private function canReach(EntityRef $playerRef, int $x, int $y, int $z, float $maxReach = 6.0): bool {
        $player = $playerRef->getEntity();
        if (!$player) return false;
        
        $position = $player->get(PositionComponent::class);
        if (!$position) return false;
        
        // Legacy: eye height = 1.62 (position.y + eye height)
        $eyeY = $position->y + 1.62;
        
        $dx = $x + 0.5 - $position->x;
        $dy = $y + 0.5 - $eyeY;
        $dz = $z + 0.5 - $position->z;
        $distanceSq = $dx * $dx + $dy * $dy + $dz * $dz;
        
        return $distanceSq <= ($maxReach * $maxReach);
    }

    private function isBreakable(int $x, int $y, int $z, int $worldId = 0): bool {
        $store = $this->getChunkStore($worldId);
        if ($store === null) {
            return false;
        }
        $blockId = $store->getBlock($x, $y, $z);
        return $this->getBlockRegistry()->isBreakable($blockId);
    }

    private function getHeldItem(EntityRef $playerRef): ?\pocketmine\core\component\ItemStack {
        $player = $playerRef->getEntity();
        if (!$player) return null;
        
        $inventory = $player->get(InventoryComponent::class);
        if (!$inventory) return null;
        
        return $inventory->get($inventory->heldSlot);
    }

    private function calculateBreakSpeed(?ItemStack $tool, int $x, int $y, int $z, int $worldId = 0): float {
        $store = $this->getChunkStore($worldId);
        if ($store === null) {
            return 0.0;
        }
        $blockId = $store->getBlock($x, $y, $z);
        $registry = $this->getBlockRegistry();

        $hardness = $registry->getHardness($blockId);
        if ($hardness < 0.0) {
            return 0.0; // unbreakable
        }
        if ($hardness === 0.0) {
            return 10.0; // instant (grass, torches, etc.)
        }

        // Correct tool tier mines faster; wrong tool mines very slowly.
        $requiredTool = $registry->getToolType($blockId);
        $toolType = $tool !== null ? $this->toolTypeForItem($tool->itemId) : 'hand';
        $speed = 1.0 / $hardness;

        if ($toolType === $requiredTool) {
            $toolLevel = $registry->getToolLevel($blockId);
            $speed *= $toolLevel <= 0 ? 2.0 : 3.0 + $toolLevel; // correct tool
        } elseif ($registry->requiresTool($blockId)) {
            $speed *= 0.2; // wrong tool: five times slower
        }

        // 14.30: Efficiency multiplies mining speed by (1 + 0.3 * level),
        // applied after the tool multiplier (legacy Tool::getSpeed bonus).
        if ($tool !== null) {
            $efficiency = $tool->getEnchantmentLevel(15);
            if ($efficiency > 0) {
                $speed *= 1.0 + 0.3 * $efficiency;
            }
        }

        return max(0.05, $speed);
    }

    /**
     * How many server ticks a survival player must hold the break button
     * before the block can be broken (0 = instant: creative mode or
     * zero-hardness blocks, -1 = cannot be broken at all).
     *
     * The 0.15 client animates the crack over its own local timer and then
     * confirms with REMOVE_BLOCK_PACKET / STOP_BREAK; the server honours that
     * confirmation only once this much time has passed, so a hacked client
     * cannot insta-mine everything.
     */
    public function requiredBreakTicks(EntityRef $playerRef, int $x, int $y, int $z): int {
        $player = $playerRef->getEntity();
        if (!$player) {
            return -1;
        }
        $metadata = $player->get(MetadataComponent::class);
        if (\pocketmine\core\enum\GameMode::coerce($metadata?->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE)) === \pocketmine\core\enum\GameMode::Creative) {
            return 0; // creative: instant
        }
        $worldId = $this->worldIdOf($playerRef);
        if (!$this->canReach($playerRef, $x, $y, $z) || !$this->isBreakable($x, $y, $z, $worldId)) {
            return -1;
        }
        $speed = $this->calculateBreakSpeed($this->getHeldItem($playerRef), $x, $y, $z, $worldId);
        if ($speed <= 0.0) {
            return -1; // unbreakable
        }
        $seconds = 1.0 / $speed;
        if ($seconds <= 0.05) {
            return 0; // effectively instant (torches, saplings, ...)
        }
        return max(1, (int)ceil($seconds * 20.0));
    }

    private function doBreakBlock(EntityRef $playerRef, int $x, int $y, int $z, ?ItemStack $tool, int $worldId = 0): bool {
        // Creative mode drops nothing (legacy BlockBreakEvent defaults
        // drops to [] unless the player is in survival).
        $player = $playerRef->getEntity();
        $metadata = $player?->get(MetadataComponent::class);
        $creative = \pocketmine\core\enum\GameMode::coerce($metadata?->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE)) === \pocketmine\core\enum\GameMode::Creative;

        // Drops must be resolved BEFORE the block is cleared - getBlockDrops
        // reads the block id out of the store.
        $drops = $creative ? [] : $this->getBlockDrops($x, $y, $z, $tool, $worldId);
        $blockId = $this->getChunkStore($worldId)?->getBlock($x, $y, $z) ?? 0;

        // Set block to air
        $this->setBlock($x, $y, $z, 0, $worldId); // Air

        // 14.24: breaking a sign or item frame drops its tile entity (the
        // frame's contained item is dropped with it; the block drops are
        // handled above via the registry).
        if ($blockId === 63 || $blockId === 68 || $blockId === 199) {
            $tiles = $this->getTileEntityStore($worldId);
            if ($tiles !== null) {
                if ($blockId === 199) {
                    $frame = $tiles->getFrame($x, $y, $z);
                    $item = $frame['item'] ?? null;
                    if ($item !== null && $item['id'] > 0) {
                        $this->spawnDropEntity($x + 0.5, $y + 0.5, $z + 0.5, new \pocketmine\core\component\ItemStack($item['id'], $item['meta'], $item['count']), $worldId);
                    }
                }
                $tiles->remove($x, $y, $z);
            }
        }

        // Spawn drop entities
        foreach ($drops as $drop) {
            $this->spawnDropEntity($x + 0.5, $y + 0.5, $z + 0.5, $drop, $worldId);
        }
        
        // Play break sound/particles
        $this->playBreakEffects($x, $y, $z, $blockId, $worldId);

        // 14.10: tools wear out with use (survival only; the helper is a
        // no-op in creative and for bare hands / non-durable items).
        ItemDurability::consume($playerRef);
        // 14.11: mining is slightly hungry work (legacy CAUSE_MINING 0.025).
        Hunger::exhaust($playerRef, 0.025);
        
        return true;
    }

    /**
     * @return ItemStack[]
     */
    private function getBlockDrops(int $x, int $y, int $z, ?ItemStack $tool, int $worldId = 0): array {
        $store = $this->getChunkStore($worldId);
        if ($store === null) {
            return [];
        }
        $blockId = $store->getBlock($x, $y, $z);

        $silkTouch = false;
        $toolType = $tool !== null ? $this->toolTypeForItem($tool->itemId) : null;
        if ($toolType === 'shears') {
            $silkTouch = true;
        }

        $drops = $this->getBlockRegistry()->getDrops($blockId, $silkTouch, $toolType);
        $items = [];
        foreach ($drops as $drop) {
            $items[] = new ItemStack($drop['id'], $drop['meta'], $drop['count']);
        }
        return $items;
    }

    private function toolTypeForItem(int $itemId): string {
        return match (true) {
            $itemId >= 256 && $itemId <= 259 => 'sword',
            $itemId >= 269 && $itemId <= 271 => 'shovel',
            $itemId >= 273 && $itemId <= 275 => 'pickaxe',
            $itemId >= 277 && $itemId <= 279 => 'axe',
            $itemId >= 284 && $itemId <= 286 => 'shears',
            default => 'hand',
        };
    }

    private function spawnDropEntity(float $x, float $y, float $z, \pocketmine\core\component\ItemStack $item, int $worldId = 0): void {
        $entityRef = $this->world->spawn(
            (new \pocketmine\core\ecs\EntityBuilder())
                ->with(new \pocketmine\core\component\PositionComponent($x, $y, $z))
                ->with(new \pocketmine\core\component\VelocityComponent(
                    (mt_rand(-10, 10) / 5),
                    0.0,
                    (mt_rand(-10, 10) / 5)
                ))
                ->with(new \pocketmine\core\component\HealthComponent(5, 5))
                ->with(new \pocketmine\core\component\InventoryComponent(1))
                ->with(new \pocketmine\core\component\MetadataComponent())
                ->with(new \pocketmine\core\component\CollisionComponent(width: 0.25, height: 0.25))
                ->with(new \pocketmine\core\component\WorldComponent($worldId))
                ->withTag(\pocketmine\core\constants\EntityTags::ITEM)
        );

        // A drop is only visible/pickup-able once its item is in the
        // metadata (the network renderer keys AddItemEntityPacket off
        // MetadataKeys::ITEM). Mirror EntitySpawnService::spawnItem.
        $entity = $entityRef->getEntity();
        if ($entity) {
            $meta = $entity->get(MetadataComponent::class);
            if ($meta) {
                $meta->set(\pocketmine\core\constants\MetadataKeys::ENTITY_TYPE, 'item');
                $meta->set(\pocketmine\core\constants\MetadataKeys::ITEM, $item);
                $meta->set(\pocketmine\core\constants\MetadataKeys::PICKUP_DELAY, 40);
            }
        }
    }

    private function playBreakEffects(int $x, int $y, int $z, int $blockId, int $worldId): void {
        $kernel = \pocketmine\Kernel::getInstance();
        $wes = $kernel?->getWorldEventService();
        if ($wes === null) {
            return;
        }
        $chunkX = (int)floor($x / 16);
        $chunkZ = (int)floor($z / 16);
        // Destroy-block particle + sound (matches old-src BreakParticle)
        $wes->spawnBlockBreakParticle($worldId, $chunkX, $chunkZ, $x + 0.5, $y + 0.5, $z + 0.5, $blockId);
    }

    private function setBlock(int $x, int $y, int $z, int $blockId, int $worldId = 0): void {
        $store = $this->getChunkStore($worldId);
        if ($store !== null) {
            $store->setBlock($x, $y, $z, $blockId, 0);
            // 14.21: removing a light source (torch/glowstone) or unblocking
            // a column must update the chunk's light arrays.
            $store->recalculateLight((int)floor($x / 16), (int)floor($z / 16), $this->getBlockRegistry());
        }
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

    private function getTileEntityStore(int $worldId = 0): ?\pocketmine\core\resource\TileEntityStore {
        if ($worldId !== 0) {
            $registry = $this->world->getResourceRegistry()->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getTileEntityStore($worldId) : null;
        }
        $store = $this->world->getResourceRegistry()->get(\pocketmine\core\resource\TileEntityStore::class);
        return $store instanceof \pocketmine\core\resource\TileEntityStore ? $store : null;
    }

    private function worldIdOf(EntityRef $playerRef): int {
        $entity = $playerRef->getEntity();
        $worldComponent = $entity?->get(WorldComponent::class);
        return $worldComponent instanceof WorldComponent ? $worldComponent->id : 0;
    }

    private function getBlockRegistry(): BlockRegistry {
        $registry = $this->world->getResourceRegistry()->get(BlockRegistry::class);
        return $registry instanceof BlockRegistry ? $registry : new BlockRegistry();
    }

    private function wrapApiPlayer(EntityRef $ref): \pocketmine\api\entity\Player {
        $entity = \pocketmine\api\entity\Entity::wrap($ref, $this->world);
        return $entity instanceof \pocketmine\api\entity\Player
            ? $entity
            : new \pocketmine\api\entity\Player($ref, $this->world);
    }

    /**
     * The API Block facade for a position in the player's world. Falls back
     * to the default world when the world id has no registered bundle.
     */
    private function apiBlock(int $x, int $y, int $z, int $worldId = 0): \pocketmine\api\block\Block {
        $server = \pocketmine\api\server\Server::getInstance();
        $apiWorld = $server->getWorldById($worldId) ?? $server->getDefaultWorld();
        return new \pocketmine\api\block\Block($apiWorld, $x, $y, $z);
    }
}