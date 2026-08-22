<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\Hunger;
use pocketmine\core\resource\ItemDurability;
use pocketmine\port\driving\EventPort;

final class EntityInteractionService {
    public function __construct(
        private readonly World $world,
        private readonly CombatService $combatService,
        private readonly EventPort $eventPort,
    ) {}

    public function interact(EntityRef $playerRef, EntityRef $targetRef): bool {
        $player = $playerRef->getEntity();
        $target = $targetRef->getEntity();
        
        if (!$player || !$target) return false;

        // Blocker 4: cancellable PlayerInteractEvent (right-click on an
        // entity) fires before any interaction handling.
        $event = new \pocketmine\api\event\PlayerInteractEvent(
            $this->wrapApiPlayer($playerRef),
            \pocketmine\api\event\PlayerInteractEvent::RIGHT_CLICK_BLOCK,
            \pocketmine\api\entity\Entity::wrap($targetRef, $this->world),
        );
        $this->eventPort->emit($event);
        if ($event->isCancelled()) {
            return false;
        }
        
        // Check interaction distance
        if (!$this->canInteract($playerRef, $targetRef)) {
            return false;
        }
        
        // Get target entity type
        $targetMeta = $target->get(\pocketmine\core\component\MetadataComponent::class);
        $playerMeta = $player->get(\pocketmine\core\component\MetadataComponent::class);
        
        if (!$targetMeta || !$playerMeta) return false;
        
        // Dropped item entities carry the 'item' stack in metadata but no
        // entityType - route them to pickup before the type dispatch (they
        // used to fall through to the no-op default interaction).
        if ($targetMeta->get(\pocketmine\core\constants\MetadataKeys::ITEM) instanceof ItemStack) {
            return $this->pickup($playerRef, $targetRef);
        }

        $targetType = $targetMeta->get(\pocketmine\core\constants\MetadataKeys::ENTITY_TYPE) ?? 'unknown';
        
        // Handle interaction based on target type
        return match ($targetType) {
            'Villager' => $this->interactWithVillager($playerRef, $targetRef),
            'Animal' => $this->interactWithAnimal($playerRef, $targetRef),
            // Bug 8: milking a cow with an empty bucket gives a milk bucket.
            'Cow' => $this->milkCow($playerRef, $targetRef),
            // Bug 15: shears on a sheep drop its wool (right-click with
            // shears); the fleece regrows after a while.
            'Sheep' => $this->shearSheep($playerRef, $targetRef),
            default => $this->defaultInteraction($playerRef, $targetRef),
        };
    }

    /**
     * Bug 15: shearing. Right-clicking a sheep while holding shears drops
     * 1-3 wool at the sheep's position and marks it shorn; the fleece
     * regrows after SHEEP_WOOL_REGROW_TICKS. Wears the shears (legacy tool
     * durability). Returns false when the sheep is already shorn or the
     * held item is not shears.
     */
    private function shearSheep(EntityRef $playerRef, EntityRef $targetRef): bool {
        $player = $playerRef->getEntity();
        $sheep = $targetRef->getEntity();
        if (!$player || !$sheep) return false;

        // Only shears in hand shear; anything else falls through as a no-op.
        $inventory = $player->get(InventoryComponent::class);
        if (!$inventory) return false;
        $held = $inventory->get($inventory->heldSlot);
        if ($held === null || $held->itemId !== \pocketmine\core\constants\ItemIds::SHEARS) {
            return false;
        }

        $sheepMeta = $sheep->get(MetadataComponent::class);
        if ($sheepMeta !== null && $sheepMeta->get('shorn')) {
            return false; // fleece not regrown yet
        }

        // Drop 1-3 wool at the sheep.
        $spawner = \pocketmine\Kernel::getInstance()?->getEntitySpawnService();
        $pos = $sheep->get(PositionComponent::class);
        if ($spawner !== null && $pos !== null) {
            $count = mt_rand(1, 3);
            for ($i = 0; $i < $count; $i++) {
                $spawner->spawnItem($pos->x + (mt_rand(-5, 5) / 10), $pos->y + 0.5, $pos->z + (mt_rand(-5, 5) / 10),
                    new ItemStack(35, 0, 1)); // white wool
            }
        }
        if ($sheepMeta !== null) {
            $tick = \pocketmine\Kernel::getInstance()?->getResourceRegistry()?->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0;
            $sheepMeta->set('shorn', true);
            $sheepMeta->set('shornAt', $tick);
        }
        ItemDurability::consume($playerRef);
        return true;
    }

    /**
     * Right-clicking a cow with an empty bucket fills it with milk
     * (legacy Cow::onInteract / Bucket::onActivate). Any other held item
     * falls through to the default no-op interaction.
     */
    private function milkCow(EntityRef $playerRef, EntityRef $targetRef): bool {
        $player = $playerRef->getEntity();
        if (!$player) return false;
        $inventory = $player->get(\pocketmine\core\component\InventoryComponent::class);
        if (!$inventory) return false;

        $slot = $inventory->heldSlot;
        $held = $inventory->get($slot);
        if ($held === null || $held->itemId !== \pocketmine\core\constants\ItemIds::BUCKET || $held->meta !== 0) {
            return false; // needs an EMPTY bucket in hand
        }

        // Milk bucket: item 325 with meta 1 (legacy damage value).
        $milk = new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::BUCKET, 1, 1);
        if (!$inventory->canAddItem($milk)) {
            return false;
        }
        $inventory->set($slot, null); // the empty bucket is consumed
        $inventory->add($milk);

        $kernel = \pocketmine\Kernel::getInstance()?->getNetworkSessionService();
        $kernel?->syncInventorySlot($playerRef->getId(), $slot);
        return true;
    }

    private function canInteract(EntityRef $playerRef, EntityRef $targetRef): bool {
        $player = $playerRef->getEntity();
        $target = $targetRef->getEntity();
        
        if (!$player || !$target) return false;
        
        $playerPos = $player->get(\pocketmine\core\component\PositionComponent::class);
        $targetPos = $target->get(\pocketmine\core\component\PositionComponent::class);
        
        if (!$playerPos || !$targetPos) return false;
        
        $dx = $targetPos->x - $playerPos->x;
        $dy = $targetPos->y - $playerPos->y;
        $dz = $targetPos->z - $playerPos->z;
        $distanceSq = $dx * $dx + $dy * $dy + $dz * $dz;
        
        return $distanceSq <= 9; // 3 blocks interaction range
    }

    private function interactWithVillager(EntityRef $playerRef, EntityRef $villagerRef): bool {
        // Open trading GUI
        // NetworkSyncSystem would send ContainerOpenPacket
        return true;
    }

    private function interactWithAnimal(EntityRef $playerRef, EntityRef $animalRef): bool {
        $player = $playerRef->getEntity();
        if (!$player) return false;
        
        // Check if player is holding food item
        $inventory = $player->get(\pocketmine\core\component\InventoryComponent::class);
        if (!$inventory) return false;
        
        $heldItem = $inventory->get($this->getHeldSlot($player));
        if (!$heldItem) return false;
        
        // Check if item is food for this animal
        // If so, breed or tame
        return true;
    }

    private function getHeldSlot(\pocketmine\core\ecs\Entity $player): int {
        $inventory = $player->get(\pocketmine\core\component\InventoryComponent::class);
        return $inventory?->heldSlot ?? 0;
    }

    /**
     * Collect a dropped item entity into the player's inventory. Shared by
     * the right-click route (interact()) and the per-tick ItemPickupSystem
     * (walk-over): distance check, non-mutating space check, then move the
     * stack and despawn the entity. Returns false when the stack does not
     * fit, the entity is out of reach, or the drop is still in its pickup
     * delay.
     */
    public function pickup(EntityRef $playerRef, EntityRef $itemRef): bool {
        $player = $playerRef->getEntity();
        $itemEntity = $itemRef->getEntity();
        
        if (!$player || !$itemEntity) return false;
        
        $itemMeta = $itemEntity->get(MetadataComponent::class);
        if (!$itemMeta) return false;
        
        $itemStack = $itemMeta->get(\pocketmine\core\constants\MetadataKeys::ITEM);
        if (!$itemStack instanceof ItemStack) return false;
        
        // Freshly dropped items are uncollectable for a short time (legacy
        // pickupDelay) so a drop cannot instantly re-enter the inventory.
        if ((int)$itemMeta->get(\pocketmine\core\constants\MetadataKeys::PICKUP_DELAY, 0) > 0) {
            return false;
        }
        
        if (!$this->canInteract($playerRef, $itemRef)) {
            return false;
        }
        
        $playerInventory = $player->get(\pocketmine\core\component\InventoryComponent::class);
        if (!$playerInventory) return false;
        
        // Work on a clone: InventoryComponent::add() mutates the stack it is
        // given (count -= added), and this instance is still referenced by the
        // item entity's metadata until its despawn flush - a late broadcast
        // must not read a zeroed count, and a caller reusing the stack they
        // passed to spawnItem must not see it mutated.
        $itemStack = clone $itemStack;
        
        // canAddItem FIRST: InventoryComponent::add() partially stacks onto
        // existing slots before it can fail, so a blind add() would lose the
        // portion it already stacked when the remainder does not fit.
        if (!$playerInventory->canAddItem($itemStack)) {
            return false;
        }
        if (!$playerInventory->add($itemStack)) {
            return false;
        }
        
        // Remove item entity
        $this->world->despawn($itemEntity);
        return true;
    }

    private function defaultInteraction(EntityRef $playerRef, EntityRef $targetRef): bool {
        // Default interaction (right-click)
        return false;
    }

    public function attack(EntityRef $attackerRef, EntityRef $targetRef): bool {
        $attacker = $attackerRef->getEntity();
        $target = $targetRef->getEntity();
        
        if (!$attacker || !$target) return false;

        // Blocker 4: cancellable PlayerInteractEvent (left-click attack on an
        // entity) fires before damage so plugins can veto PvP/mob hits.
        $event = new \pocketmine\api\event\PlayerInteractEvent(
            $this->wrapApiPlayer($attackerRef),
            \pocketmine\api\event\PlayerInteractEvent::LEFT_CLICK_BLOCK,
            \pocketmine\api\entity\Entity::wrap($targetRef, $this->world),
        );
        $this->eventPort->emit($event);
        if ($event->isCancelled()) {
            return false;
        }
        
        // Check attack distance
        if (!$this->canAttack($attackerRef, $targetRef)) {
            return false;
        }
        
        // Calculate damage
        $damage = $this->calculateDamage($attackerRef, $targetRef);
        
        // Route through the unified combat pipeline: damage event, armor
        // reduction, knockback, death handling + loot drops.
        $landed = $this->combatService->applyDamage(
            $targetRef,
            $damage,
            $attackerRef,
            \pocketmine\api\event\EntityDamageEvent::CAUSE_ENTITY_ATTACK
        );
        // 14.10: a landed hit wears the held weapon (survival only; the
        // helper is a no-op in creative and for bare hands).
        if ($landed) {
            ItemDurability::consume($attackerRef);
            // 14.11: attacking is hungry work (legacy CAUSE_ATTACK 0.3).
            Hunger::exhaust($attackerRef, 0.3);
        }
        return $landed;
    }

    private function canAttack(EntityRef $attackerRef, EntityRef $targetRef): bool {
        $attacker = $attackerRef->getEntity();
        $target = $targetRef->getEntity();
        
        if (!$attacker || !$target) return false;
        
        $attackerPos = $attacker->get(\pocketmine\core\component\PositionComponent::class);
        $targetPos = $target->get(\pocketmine\core\component\PositionComponent::class);
        
        if (!$attackerPos || !$targetPos) return false;
        
        $dx = $targetPos->x - $attackerPos->x;
        $dy = $targetPos->y - $attackerPos->y;
        $dz = $targetPos->z - $attackerPos->z;
        $distanceSq = $dx * $dx + $dy * $dy + $dz * $dz;
        
        return $distanceSq <= 9; // 3 blocks attack range
    }

    private function calculateDamage(EntityRef $attackerRef, EntityRef $targetRef): float {
        $attacker = $attackerRef->getEntity();
        if (!$attacker) return 1.0;
        
        // Base damage
        $damage = 1.0;
        
        // Check for weapon
        $inventory = $attacker->get(\pocketmine\core\component\InventoryComponent::class);
        if ($inventory) {
            $heldItem = $inventory->get($this->getHeldSlot($attacker));
            if ($heldItem) {
                // Add weapon damage
                $damage += $this->getWeaponDamage($heldItem->itemId);
                // 14.30: Sharpness adds 0.5 * level bonus damage (legacy
                // Enchantment::getDamageBonus).
                $sharpness = $heldItem->getEnchantmentLevel(9);
                if ($sharpness > 0) {
                    $damage += 0.5 * $sharpness;
                }
            }
        }
        
        // Check attributes
        $attributes = $attacker->get(\pocketmine\core\component\AttributeComponent::class);
        if ($attributes) {
            $damage += $attributes->get(\pocketmine\core\constants\AttributeKeys::ATTACK_DAMAGE);
        }
        
        return $damage;
    }

    private function getWeaponDamage(int $itemId): float {
        return match ($itemId) {
            267 => 4, // Iron sword
            272 => 5, // Diamond sword
            268 => 3, // Iron axe
            279 => 3, // Diamond axe
            default => 1,
        };
    }

    private function wrapApiPlayer(EntityRef $ref): \pocketmine\api\entity\Player {
        $entity = \pocketmine\api\entity\Entity::wrap($ref, $this->world);
        return $entity instanceof \pocketmine\api\entity\Player
            ? $entity
            : new \pocketmine\api\entity\Player($ref, $this->world);
    }
}