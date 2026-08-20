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
    public const PICKUP_RADIUS = 2.0;

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
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            $meta = $entity->get(MetadataComponent::class);
            if ($meta === null) {
                continue;
            }

            // 14.9: XP orbs (tagged 'xp_orb') credit the player's XP bar
            // instead of the inventory. No pickup delay (legacy orbs were
            // collectible immediately; the XP is server-side anyway).
            if ($entity->has(\pocketmine\core\constants\EntityTags::XP_ORB)) {
                $amount = (int)$meta->get('xp', 0);
                if ($amount <= 0) {
                    $world->despawn($entity); // nothing to credit: don't linger
                    continue;
                }
                foreach ($players as $playerId => $playerPos) {
                    $dx = $pos->x - $playerPos->x;
                    $dy = $pos->y - $playerPos->y;
                    $dz = $pos->z - $playerPos->z;
                    if ($dx * $dx + $dy * $dy + $dz * $dz <= $radiusSq) {
                        $this->grantXp($world, $playerId, $amount);
                        $world->despawn($entity);
                        $this->syncXpFor($playerId);
                        break;
                    }
                }
                continue;
            }

            // Item entities are tagged 'item' at spawn (withTag('item')).
            if (!$entity->has(\pocketmine\core\constants\EntityTags::ITEM)) {
                continue;
            }
            // Count down the drop's pickup delay before it can be collected.
            $delay = (int)$meta->get('pickupDelay', 0);
            if ($delay > 0) {
                $meta->set('pickupDelay', $delay - 1);
                continue;
            }
            foreach ($players as $playerId => $playerPos) {
                $dx = $pos->x - $playerPos->x;
                $dy = $pos->y - $playerPos->y;
                $dz = $pos->z - $playerPos->z;
                if ($dx * $dx + $dy * $dy + $dz * $dz <= $radiusSq) {
                    // Blocker 4 audit: cancellable InventoryPickupItemEvent - a
                    // plugin can leave the item on the ground.
                    $kernel = \pocketmine\Kernel::getInstance();
                    if ($kernel !== null) {
                        $stack = $meta->get(\pocketmine\core\constants\MetadataKeys::ITEM);
                        $event = new \pocketmine\api\event\InventoryPickupItemEvent(
                            $this->wrapApiPlayer($playerId, $world),
                            \pocketmine\api\entity\Entity::wrap(EntityRef::create($entity->id, $world), $world),
                            \pocketmine\api\inventory\ItemStack::fromCore(
                                $stack instanceof \pocketmine\core\component\ItemStack ? $stack : new \pocketmine\core\component\ItemStack(0, 0, 1)
                            ),
                        );
                        $kernel->getEventPort()->emit($event);
                        if ($event->isCancelled()) {
                            continue; // try the next player
                        }
                    }
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
     * Credit XP to a player's persistent metadata (xp progress + level),
     * leveling up through the standard curve as needed.
     */
    private function grantXp(World $world, int $playerId, int $amount): void {
        $player = $world->getEntity($playerId);
        if ($player === null) {
            return;
        }
        $meta = $player->get(MetadataComponent::class);
        if ($meta === null) {
            return;
        }
        $xp = (int)$meta->get('xp', 0) + $amount;
        $level = (int)$meta->get('xpLevel', 0);
        $need = \pocketmine\core\service\NetworkSessionService::xpNeedForLevel($level);
        while ($need > 0 && $xp >= $need) {
            $xp -= $need;
            $level++;
            $need = \pocketmine\core\service\NetworkSessionService::xpNeedForLevel($level);
        }
        $meta->set('xp', $xp);
        $meta->set('xpLevel', $level);
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

    private function wrapApiPlayer(int $playerId, World $world): \pocketmine\api\entity\Player {
        $ref = EntityRef::create($playerId, $world);
        $entity = \pocketmine\api\entity\Entity::wrap($ref, $world);
        return $entity instanceof \pocketmine\api\entity\Player
            ? $entity
            : new \pocketmine\api\entity\Player($ref, $world);
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

    /** Push the updated XP bar to the collector's client. */
    private function syncXpFor(int $playerId): void {
        $sessionService = \pocketmine\Kernel::getInstance()?->getNetworkSessionService();
        if ($sessionService !== null) {
            $sessionService->syncXpFor($playerId);
        }
    }
}
