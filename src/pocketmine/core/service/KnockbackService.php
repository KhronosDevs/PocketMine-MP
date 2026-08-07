<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use function sqrt;

final class KnockbackService {
    public function __construct(
        private readonly World $world,
    ) {}

    public function applyKnockback(EntityRef $sourceRef, EntityRef $targetRef, float $force, float $vertical = 0.4): void {
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
            $horizontalForce = $force * 0.5;
            $targetVel->x += ($dx / $dist) * $horizontalForce;
            $targetVel->z += ($dz / $dist) * $horizontalForce;
            $targetVel->y = $vertical;
        }
    }

    public function applyExplosionKnockback(EntityRef $targetRef, float $centerX, float $centerY, float $centerZ, float $force): void {
        $target = $targetRef->getEntity();
        if (!$target) return;
        
        $targetPos = $target->get(PositionComponent::class);
        $targetVel = $target->get(\pocketmine\core\component\VelocityComponent::class);
        
        if (!$targetPos || !$targetVel) return;
        
        $dx = $targetPos->x - $centerX;
        $dy = $targetPos->y - $centerY;
        $dz = $targetPos->z - $centerZ;
        $dist = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        
        if ($dist > 0) {
            $targetVel->x += ($dx / $dist) * $force;
            $targetVel->y += ($dy / $dist) * $force * 0.5;
            $targetVel->z += ($dz / $dist) * $force;
        }
    }

    public function applyDirectionalKnockback(EntityRef $targetRef, float $dirX, float $dirY, float $dirZ, float $force): void {
        $target = $targetRef->getEntity();
        if (!$target) return;
        
        $targetVel = $target->get(\pocketmine\core\component\VelocityComponent::class);
        if (!$targetVel) return;
        
        $length = sqrt($dirX * $dirX + $dirY * $dirY + $dirZ * $dirZ);
        if ($length > 0) {
            $targetVel->x += ($dirX / $length) * $force;
            $targetVel->y += ($dirY / $length) * $force;
            $targetVel->z += ($dirZ / $length) * $force;
        }
    }
}