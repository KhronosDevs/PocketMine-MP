<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\service\EntityInteractionService;

/**
 * Item pickup (14.5).
 *
 * Sequential (main-thread) system: every tick, dropped item entities within
 * PICKUP_RADIUS of an alive player are collected into that player's
 * inventory through EntityInteractionService::pickup (non-mutating space
 * check first, then move + despawn). Freshly dropped items honor the
 * metadata pickupDelay (set by EntitySpawnService::spawnItem) so a drop
 * cannot instantly re-enter the player's own inventory. Runs after
 * movement/AI so entity positions are current.
 */
final class ItemPickupSystem implements System {
    public const PICKUP_RADIUS = 1.5;

    private ?EntityInteractionService $interactionService = null;

    public function run(World $world, float $deltaTime): void {
        $interaction = $this->getInteractionService();
        if ($interaction === null) {
            return;
        }

        // Alive players only (dead players do not collect items).
        $players = [];
        foreach ($world->getEntities() as $entity) {
            if (!$entity->has(PlayerTag::class)) {
                continue;
            }
            $health = $entity->get(HealthComponent::class);
            $pos = $entity->get(PositionComponent::class);
            if ($health === null || $health->current <= 0 || $pos === null) {
                continue;
            }
            $players[$entity->id] = $pos;
        }
        if (empty($players)) {
            return;
        }

        $radiusSq = self::PICKUP_RADIUS * self::PICKUP_RADIUS;
        foreach ($world->getEntities() as $entity) {
            // Item entities are tagged 'item' at spawn (withTag('item')).
            if (!$entity->has('item')) {
                continue;
            }
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            // Count down the drop's pickup delay before it can be collected.
            $meta = $entity->get(MetadataComponent::class);
            if ($meta !== null) {
                $delay = (int)$meta->get('pickupDelay', 0);
                if ($delay > 0) {
                    $meta->set('pickupDelay', $delay - 1);
                    continue;
                }
            }
            foreach ($players as $playerId => $playerPos) {
                $dx = $pos->x - $playerPos->x;
                $dy = $pos->y - $playerPos->y;
                $dz = $pos->z - $playerPos->z;
                if ($dx * $dx + $dy * $dy + $dz * $dz <= $radiusSq) {
                    if ($interaction->pickup(
                        EntityRef::create($playerId, $world),
                        EntityRef::create($entity->id, $world),
                    )) {
                        $this->syncInventoryFor($playerId);
                        break; // collected: the despawn is queued for next flush
                    }
                }
            }
        }
    }

    /**
     * EntityInteractionService is created by the Kernel constructor, after
     * systems are registered - resolve lazily on first run (the same pattern
     * as AISystem).
     */
    private function getInteractionService(): ?EntityInteractionService {
        if ($this->interactionService === null) {
            $kernel = \pocketmine\Kernel::getInstance();
            $this->interactionService = $kernel?->getEntityInteractionService();
        }
        return $this->interactionService;
    }

    /**
     * The picked-up stack must show up in the owner's inventory window.
     * Pickups happen outside the network layer (this system runs inside the
     * world tick), so reach the session service through the Kernel the same
     * way the interaction service is resolved. No-op for non-network tests.
     */
    private function syncInventoryFor(int $playerId): void {
        $sessionService = \pocketmine\Kernel::getInstance()?->getNetworkSessionService();
        if ($sessionService !== null) {
            $sessionService->syncInventoryContents($playerId);
        }
    }
}
