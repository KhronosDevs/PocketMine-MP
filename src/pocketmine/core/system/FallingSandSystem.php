<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\CollisionComponent;
use pocketmine\core\component\WorldComponent;
use pocketmine\core\constants\EntityTags;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\Entity;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\api\server\Server;
use function abs;
use function floor;

/**
 * Handles gravity blocks (sand, gravel, anvils) that fall when unsupported.
 *
 * Flow:
 * 1. BlockBreakService detects a gravity block above air/liquid → spawns FallingSand entity
 * 2. PhysicsSystem applies gravity + movement each tick
 * 3. This system checks for block collisions (landing) and handles the result:
 *    - Solid block below → place the block, play anvil sound if anvil
 *    - Air/liquid below → keep falling
 *    - Void (y <= 0) → drop as item
 */
final class FallingSandSystem implements System {

    /** Gravity block IDs: sand=12, gravel=13, anvils=145-152 */
    private const GRAVITY_BLOCKS = [
        12,  // sand
        13,  // gravel
        145, // anvil (all orientations)
        146,
        147,
        148,
    ];

    public function run(World $world, float $deltaTime): void {
        $store = $world->getResourceRegistry()->get(ChunkStore::class);
        if ($store === null) {
            return;
        }

        $registry = $world->getResourceRegistry()->get(BlockRegistry::class);
        $kernel = \pocketmine\Kernel::getInstance();
        $wes = $kernel?->getWorldEventService();

        $query = $world->query()
            ->with(PositionComponent::class, VelocityComponent::class)
            ->withTag(EntityTags::FALLING_SAND)
            ->build();

        foreach ($query as $entity) {
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            $meta = $entity->get(\pocketmine\core\component\MetadataComponent::class);
            if ($pos === null || $vel === null || $meta === null) {
                continue;
            }

            $blockId = (int)($meta->get(MetadataKeys::BLOCK_DATA, 0));
            if ($blockId === 0) {
                // Invalid falling sand — despawn
                $world->despawn($entity->id);
                continue;
            }

            // Check if the entity has landed (velocity near zero = PhysicsSystem
            // snapped it to ground, or BlockCollisionSystem resolved it).
            $isOnGround = abs($vel->y) < 0.01 && abs($vel->x) < 0.01 && abs($vel->z) < 0.01;

            if (!$isOnGround) {
                continue;
            }

            // Landed! Determine what to do.
            $bx = (int)floor($pos->x);
            $by = (int)floor($pos->y);
            $bz = (int)floor($pos->z);

            // Check the block at the landing position
            $landBlock = $store->getBlock($bx, $by, $bz);

            if ($landBlock !== 0) {
                // Solid or non-air block at landing spot — drop as item instead
                // (the block occupies the space, can't place)
                $this->dropAsItem($world, $pos->x, $pos->y, $pos->z, $blockId);
                $world->despawn($entity->id);
                continue;
            }

            // Check the block below for solid ground
            $belowBlock = $store->getBlock($bx, $by - 1, $bz);
            if ($belowBlock === 0) {
                // Still air below — keep falling (velocity snap was premature)
                continue;
            }

            // Place the block
            $store->setBlock($bx, $by, $bz, $blockId, 0);
            $store->recalculateLight((int)floor($bx / 16), (int)floor($bz / 16), $registry);

            // Anvil fall sound
            if ($blockId >= 145 && $blockId <= 148 && $wes !== null) {
                $chunkX = (int)floor($bx / 16);
                $chunkZ = (int)floor($bz / 16);
                $wes->playSound(
                    $entity->get(WorldComponent::class)?->id ?? 0,
                    $chunkX, $chunkZ,
                    $bx + 0.5, $by + 0.5, $bz + 0.5,
                    \pocketmine\core\service\WorldEventService::SOUND_ANVIL_FALL
                );
            }

            // Broadcast the block change to nearby players
            $nss = $kernel?->getNetworkSessionService();
            if ($nss !== null) {
                $worldId = $entity->get(WorldComponent::class)?->id ?? 0;
                $nss->broadcastBlockState($bx, $by, $bz, $worldId);
            }

            $world->despawn($entity->id);
        }
    }

    /**
     * Check if a block ID is a gravity block (sand, gravel, anvil).
     */
    public static function isGravityBlock(int $blockId): bool {
        return in_array($blockId, self::GRAVITY_BLOCKS, true);
    }

    /**
     * Spawn a FallingSand entity at the given position.
     */
    public static function spawn(World $world, float $x, float $y, float $z, int $blockId, int $worldId): void {
        $entityRef = $world->spawn(
            (new \pocketmine\core\ecs\EntityBuilder())
                ->with(new PositionComponent($x, $y, $z))
                ->with(new VelocityComponent(0.0, 0.0, 0.0))
                ->with(new \pocketmine\core\component\HealthComponent(1, 1))
                ->with(new \pocketmine\core\component\MetadataComponent([
                    MetadataKeys::ENTITY_TYPE => 'FallingSand',
                    MetadataKeys::BLOCK_DATA => $blockId,
                ]))
                ->with(new CollisionComponent(width: 0.98, height: 0.98))
                ->with(new WorldComponent($worldId))
                ->withTag(EntityTags::FALLING_SAND)
        );
    }

    /**
     * Drop a falling block as an item entity when it can't place (e.g. occupied).
     */
    private function dropAsItem(World $world, float $x, float $y, float $z, int $blockId): void {
        // Map falling block to its item form
        $itemId = $blockId; // sand=12, gravel=13 map directly to item ids
        $entityRef = $world->spawn(
            (new \pocketmine\core\ecs\EntityBuilder())
                ->with(new PositionComponent($x, $y + 0.1, $z))
                ->with(new VelocityComponent(
                    (\pocketmine\mt_rand(-10, 10) / 5),
                    0.0,
                    (\pocketmine\mt_rand(-10, 10) / 5)
                ))
                ->with(new \pocketmine\core\component\HealthComponent(5, 5))
                ->with(new \pocketmine\core\component\InventoryComponent(1))
                ->with(new \pocketmine\core\component\MetadataComponent())
                ->with(new CollisionComponent(width: 0.25, height: 0.25))
                ->with(new WorldComponent(0))
                ->withTag(EntityTags::ITEM)
                ->with(new \pocketmine\core\component\DragComponent())
        );

        $entity = $entityRef->getEntity();
        if ($entity) {
            $meta = $entity->get(\pocketmine\core\component\MetadataComponent::class);
            if ($meta) {
                $meta->set(MetadataKeys::ENTITY_TYPE, 'item');
                $meta->set(MetadataKeys::ITEM, new \pocketmine\core\component\ItemStack($itemId, 0, 1));
                $meta->set(MetadataKeys::PICKUP_DELAY, 40);
            }
        }
    }
}
