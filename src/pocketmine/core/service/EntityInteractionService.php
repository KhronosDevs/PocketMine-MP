<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;

final class EntityInteractionService {
    public function __construct(
        private readonly World $world,
        private readonly CombatService $combatService,
    ) {}

    public function interact(EntityRef $playerRef, EntityRef $targetRef): bool {
        $player = $playerRef->getEntity();
        $target = $targetRef->getEntity();
        
        if (!$player || !$target) return false;
        
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
        if ($targetMeta->get('item') instanceof ItemStack) {
            return $this->pickup($playerRef, $targetRef);
        }

        $targetType = $targetMeta->get('entityType') ?? 'unknown';
        
        // Handle interaction based on target type
        return match ($targetType) {
            'Villager' => $this->interactWithVillager($playerRef, $targetRef),
            'Animal' => $this->interactWithAnimal($playerRef, $targetRef),
            default => $this->defaultInteraction($playerRef, $targetRef),
        };
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
        
        $itemStack = $itemMeta->get('item');
        if (!$itemStack instanceof ItemStack) return false;
        
        // Freshly dropped items are uncollectable for a short time (legacy
        // pickupDelay) so a drop cannot instantly re-enter the inventory.
        if ((int)$itemMeta->get('pickupDelay', 0) > 0) {
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
        
        // Check attack distance
        if (!$this->canAttack($attackerRef, $targetRef)) {
            return false;
        }
        
        // Calculate damage
        $damage = $this->calculateDamage($attackerRef, $targetRef);
        
        // Route through the unified combat pipeline: damage event, armor
        // reduction, knockback, death handling + loot drops.
        return $this->combatService->applyDamage(
            $targetRef,
            $damage,
            $attackerRef,
            \pocketmine\api\event\EntityDamageEvent::CAUSE_ENTITY_ATTACK
        );
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
            }
        }
        
        // Check attributes
        $attributes = $attacker->get(\pocketmine\core\component\AttributeComponent::class);
        if ($attributes) {
            $damage += $attributes->get('attack_damage');
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
}