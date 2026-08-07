<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\component\AttributeComponent;
use pocketmine\domain\component\HealthComponent;
use pocketmine\domain\component\MetadataComponent;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;

final class CombatService {
    public function __construct(
        private readonly World $world,
    ) {}

    public function applyDamage(EntityRef $targetRef, float $damage, ?EntityRef $source = null, int $cause = 0): bool {
        $target = $targetRef->getEntity();
        if (!$target) return false;
        
        $health = $target->get(HealthComponent::class);
        if (!$health) return false;
        
        // Apply damage reduction from armor
        $damage = $this->applyArmorReduction($targetRef, $damage, $cause);
        
        // Apply damage
        $health->current = max(0, $health->current - $damage);
        
        // Apply knockback if source exists
        if ($source) {
            $this->applyKnockback($source, $targetRef, $damage);
        }
        
        // Check death
        if ($health->current <= 0) {
            $this->handleDeath($targetRef, $source);
        }
        
        return true;
    }

    public function heal(EntityRef $targetRef, float $amount): void {
        $target = $targetRef->getEntity();
        if (!$target) return;
        
        $health = $target->get(HealthComponent::class);
        if (!$health) return;
        
        $health->current = min($health->max, $health->current + $amount);
    }

    public function applyKnockback(EntityRef $sourceRef, EntityRef $targetRef, float $force): void {
        $source = $sourceRef->getEntity();
        $target = $targetRef->getEntity();
        
        if (!$source || !$target) return;
        
        $sourcePos = $source->get(PositionComponent::class);
        $targetPos = $target->get(PositionComponent::class);
        $targetVel = $target->get(VelocityComponent::class);
        
        if (!$sourcePos || !$targetPos || !$targetVel) return;
        
        $dx = $targetPos->x - $sourcePos->x;
        $dz = $targetPos->z - $sourcePos->z;
        $dist = sqrt($dx * $dx + $dz * $dz);
        
        if ($dist > 0) {
            $knockback = $force * 0.4;
            $targetVel->x += ($dx / $dist) * $knockback;
            $targetVel->z += ($dz / $dist) * $knockback;
            $targetVel->y = $force * 0.2;
        }
    }

    private function applyArmorReduction(EntityRef $targetRef, float $damage, int $cause): float {
        $target = $targetRef->getEntity();
        if (!$target) return $damage;
        
        $inventory = $target->get(\pocketmine\domain\component\InventoryComponent::class);
        if (!$inventory) return $damage;
        
        $armorSlots = [5, 6, 7, 8]; // Helmet, Chestplate, Leggings, Boots
        $totalReduction = 0;
        
        foreach ($armorSlots as $slot) {
            $item = $inventory->get($slot);
            if ($item && $item->count > 0) {
                $reduction = $this->getArmorReduction($item->itemId);
                $totalReduction += $reduction;
            }
        }
        
        // Cap at 80% reduction
        $totalReduction = min(0.8, $totalReduction);
        
        return $damage * (1 - $totalReduction);
    }

    private function getArmorReduction(int $itemId): float {
        return match ($itemId) {
            302 => 0.04, // Leather helmet
            303 => 0.06, // Leather chestplate
            304 => 0.05, // Leather leggings
            305 => 0.02, // Leather boots
            306 => 0.06, // Chain helmet
            307 => 0.10, // Chain chestplate
            308 => 0.08, // Chain leggings
            309 => 0.04, // Chain boots
            310 => 0.08, // Iron helmet
            311 => 0.15, // Iron chestplate
            312 => 0.12, // Iron leggings
            313 => 0.06, // Iron boots
            314 => 0.08, // Gold helmet
            315 => 0.10, // Gold chestplate
            316 => 0.08, // Gold leggings
            317 => 0.04, // Gold boots
            318 => 0.12, // Diamond helmet
            319 => 0.20, // Diamond chestplate
            320 => 0.16, // Diamond leggings
            321 => 0.08, // Diamond boots
            default => 0,
        };
    }

    private function handleDeath(EntityRef $targetRef, ?EntityRef $killerRef): void {
        $target = $targetRef->getEntity();
        if (!$target) return;
        
        // Drop experience
        $this->dropExperience($targetRef);
        
        // Drop loot
        $this->dropLoot($targetRef);
        
        // Despawn entity
        $this->world->despawn($target);
    }

    private function dropExperience(EntityRef $entityRef): void {
        // Spawn XP orbs
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $position = $entity->get(\pocketmine\domain\component\PositionComponent::class);
        if (!$position) return;
        
        // Calculate XP drop based on entity type
        $xpAmount = 5; // Default
        
        $this->world->spawn(
            (new \pocketmine\domain\ecs\EntityBuilder())
                ->with(new \pocketmine\domain\component\PositionComponent($entity->x, $entity->y + 0.5, $entity->z))
                ->with(new \pocketmine\domain\component\VelocityComponent())
                ->with(new \pocketmine\domain\component\HealthComponent(1, 1))
                ->with(new \pocketmine\domain\component\MetadataComponent())
                ->withTag('xp_orb')
                ->build($this->world)
        );
    }

    private function dropLoot(EntityRef $entityRef): void {
        // Drop items based on entity loot table
    }

    public function canEntityAttack(EntityRef $attackerRef, EntityRef $targetRef): bool {
        $attacker = $attackerRef->getEntity();
        $target = $targetRef->getEntity();
        
        if (!$attacker || !$target) return false;
        
        // Check if both are in PvP mode
        $attackerMeta = $attacker->get(MetadataComponent::class);
        $targetMeta = $target->get(MetadataComponent::class);
        
        if ($attackerMeta && $targetMeta) {
            $attackerPvP = $attackerMeta->get('pvpEnabled') ?? true;
            $targetPvP = $targetMeta->get('pvpEnabled') ?? true;
            
            if (!$attackerPvP || !$targetPvP) {
                return false;
            }
        }
        
        return true;
    }
}