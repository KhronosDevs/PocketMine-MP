<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\component\MetadataComponent;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;

final class EntityInteractionService {
    public function __construct(
        private readonly World $world,
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
        $targetMeta.ToolResult>
<parameter=error>
Could not find oldString in content.those
        $targetMeta = $target->get(MetadataComponent::class);
        $playerMeta = $player->get(MetadataComponent::class);
        
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
        
        $playerPos = $player->get(PositionComponent::class);
        $targetPos = $target->get(PositionComponent::class);
        
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
        $inventory = $player->get(\pocketmine\domain\component\InventoryComponent::class);
        if (!$inventory) return false;
        
        $heldItem = $inventory->get(0); // Simplified - check held slot
        if (!$heldItem) return false;
        
        // Check if item is food for this animal
        // If so, breed or tame
        return true;
    }

    private function pickupItem(EntityRef $playerRef, EntityRef $itemRef): bool {
        $player = $playerRef->getEntity();
        $itemEntity = $itemRef->getEntity();
        
        if (!$player || !$itemEntity) return false;
        
        $itemMeta = $itemEntity->get(\pocketmine\domain\component\MetadataComponent::class);
        if (!$itemMeta) return false;
        
        $itemStack = $itemMeta->get('item');
        if (!$itemStack) return false;
        
        $playerInventory = $player->get(\pocketmine\domain\component\InventoryComponent::class);
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
        
        // Apply damage
        $targetHealth = $target->get(\pocketmine\domain\component\HealthComponent::class);
        if ($targetHealth) {
            $targetHealth->current = max(0, $targetHealth->current - $damage);
            
            // Apply knockback
            $this->applyKnockback($attackerRef, $targetRef, $damage);
            
            // Check death
            if ($targetHealth->current <= 0) {
                $this->handleDeath($targetRef, $attackerRef);
            }
        }
        
        return true;
    }

    private function canAttack(EntityRef $attackerRef, EntityRef $targetRef): bool {
        $attacker = $attackerRef->getEntity();
        $target = $targetRef->getEntity();
        
        if (!$attacker || !$target) return false;
        
        $attackerPos = $attacker->get(PositionComponent::class);
        $targetPos = $target->get(PositionComponent::class);
        
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
        $inventory = $attacker->get(\pocketmine\domain\component\InventoryComponent::class);
        if ($inventory) {
            $heldItem = $inventory->get(0);
            if ($heldItem) {
                // Add weapon damage
                $damage += $this->getWeaponDamage($heldItem->itemId);
            }
        }
        
        // Check attributes
        $attributes = $attacker->get(\pocketmine\domain\component\AttributeComponent::class);
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

    private function applyKnockback(EntityRef $attackerRef, EntityRef $targetRef, float $damage): void {
        $attacker = $attackerRef->getEntity();
        $target = $targetRef->getEntity();
        
        if (!$attacker || !$target) return;
        
        $attackerPos = $attacker->get(PositionComponent::class);
        $targetPos = $target->get(PositionComponent::class);
        $targetVel = $target->get(\pocketmine\domain\component\VelocityComponent::class);
        
        if (!$attackerPos || !$targetPos || !$targetVel) return;
        
        $dx = $targetPos->x - $attackerPos->x;
        $dz = $targetPos->z - $attackerPos->z;
        $dist = sqrt($dx * $dx + $dz * $dz);
        
        if ($dist > 0) {
            $knockback = 0.4;
            $targetVel->x += ($dx / $dist) * $knockback;
            $targetVel->z += ($dz / $dist) * $knockback;
            $targetVel->y = 0.4;
        }
    }

    private function handleDeath(EntityRef $targetRef, EntityRef $killerRef): void {
        // Drop loot, experience, etc.
        // Despawn entity
        $this->world->despawn($targetRef->getEntity()!);
    }
}