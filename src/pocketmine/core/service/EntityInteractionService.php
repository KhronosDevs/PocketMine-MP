<?php

declare(strict_types=1);

namespace pocketmine\core\service;

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
        
        $targetType = $targetMeta->get('entityType') ?? 'unknown';
        
        // Handle interaction based on target type
        return match ($targetType) {
            'Villager' => $this->interactWithVillager($playerRef, $targetRef),
            'Animal' => $this->interactWithAnimal($playerRef, $targetRef),
            'Item' => $this->pickupItem($playerRef, $targetRef),
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

    private function pickupItem(EntityRef $playerRef, EntityRef $itemRef): bool {
        $player = $playerRef->getEntity();
        $itemEntity = $itemRef->getEntity();
        
        if (!$player || !$itemEntity) return false;
        
        $itemMeta = $itemEntity->get(\pocketmine\core\component\MetadataComponent::class);
        if (!$itemMeta) return false;
        
        $itemStack = $itemMeta->get('item');
        if (!$itemStack) return false;
        
        $playerInventory = $player->get(\pocketmine\core\component\InventoryComponent::class);
        if (!$playerInventory) return false;
        
        // Try to add to inventory
        if ($playerInventory->add($itemStack)) {
            // Remove item entity
            $this->world->despawn($itemEntity);
            return true;
        }
        
        return false;
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