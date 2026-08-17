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
        private readonly ?\pocketmine\port\driving\EventPort $eventPort = null,
    ) {}

    public function applyKnockback(EntityRef $sourceRef, EntityRef $targetRef, float $force, float $vertical = 0.4): void {
        $source = $sourceRef->getEntity();
        $target = $targetRef->getEntity();
        
        if (!$source || !$target) return;

        // Events breadth audit: cancellable EntityMotionEvent - a plugin can
        // suppress knockback entirely.
        if (!$this->emitMotion($targetRef, 0.0, $vertical, 0.0)) {
            return;
        }
        
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

        // Events breadth audit: cancellable EntityMotionEvent.
        $targetPos = $target->get(PositionComponent::class);
        $dx0 = $targetPos !== null ? $targetPos->x - $centerX : 0.0;
        $dy0 = $targetPos !== null ? $targetPos->y - $centerY : 0.0;
        $dz0 = $targetPos !== null ? $targetPos->z - $centerZ : 0.0;
        $dist0 = sqrt($dx0 * $dx0 + $dy0 * $dy0 + $dz0 * $dz0);
        if ($dist0 > 0 && !$this->emitMotion($targetRef, ($dx0 / $dist0) * $force, ($dy0 / $dist0) * $force * 0.5, ($dz0 / $dist0) * $force)) {
            return;
        }
        
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

        // Events breadth audit: cancellable EntityMotionEvent.
        $length0 = sqrt($dirX * $dirX + $dirY * $dirY + $dirZ * $dirZ);
        if ($length0 > 0 && !$this->emitMotion($targetRef, ($dirX / $length0) * $force, ($dirY / $length0) * $force, ($dirZ / $length0) * $force)) {
            return;
        }
        
        $targetVel = $target->get(\pocketmine\core\component\VelocityComponent::class);
        if (!$targetVel) return;
        
        $length = sqrt($dirX * $dirX + $dirY * $dirY + $dirZ * $dirZ);
        if ($length > 0) {
            $targetVel->x += ($dirX / $length) * $force;
            $targetVel->y += ($dirY / $length) * $force;
            $targetVel->z += ($dirZ / $length) * $force;
        }
    }

    /**
     * Emit a cancellable EntityMotionEvent for a motion application. Returns
     * false when the event was cancelled (caller must skip applying).
     */
    private function emitMotion(EntityRef $targetRef, float $mx, float $my, float $mz): bool {
        if ($this->eventPort === null) {
            return true;
        }
        $event = new \pocketmine\api\event\EntityMotionEvent(
            \pocketmine\api\entity\Entity::wrap($targetRef, $this->world),
            $mx,
            $my,
            $mz,
        );
        $this->eventPort->emit($event);
        return !$event->isCancelled();
    }
}