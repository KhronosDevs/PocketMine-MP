<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;

/**
 * Natural health regeneration (14.9).
 *
 * Sequential (main-thread) system: a living player below max health starts
 * regenerating 1 HP per REGEN_INTERVAL ticks once REGEN_DELAY ticks have
 * passed since their last damage (vanilla cadence: 5s of no damage, then
 * 1 HP every 4s). Damage is detected by observing the health component drop
 * between ticks, so the system needs no hooks into the combat pipeline.
 *
 * Runs after combat/movement so the health it reads is current for the tick.
 */
final class RegenSystem implements System {
    /** 5 seconds of no damage before regen may begin. */
    public const REGEN_DELAY_TICKS = 100;
    /** 1 HP every 4 seconds while regen is active. */
    public const REGEN_INTERVAL_TICKS = 80;

    private int $tick = 0;

    /** @var array<int, float> entityId => last observed health */
    private array $lastHealth = [];
    /** @var array<int, int> entityId => tick of last damage */
    private array $lastDamageTick = [];
    /** @var array<int, int> entityId => tick of last regen */
    private array $lastRegenTick = [];

    public function run(World $world, float $deltaTime): void {
        $this->tick++;

        $alive = [];
        foreach ($world->getEntities() as $entity) {
            if (!$entity->has(PlayerTag::class)) {
                continue;
            }
            $health = $entity->get(HealthComponent::class);
            $pos = $entity->get(PositionComponent::class);
            if ($health === null || $pos === null) {
                continue;
            }
            $alive[$entity->id] = true;

            $prev = $this->lastHealth[$entity->id] ?? $health->current;
            if ($health->current < $prev) {
                // Took damage this tick: reset the regen window.
                $this->lastDamageTick[$entity->id] = $this->tick;
                $this->lastRegenTick[$entity->id] = $this->tick;
            }
            $this->lastHealth[$entity->id] = $health->current;

            if ($health->current <= 0 || $health->current >= $health->max) {
                continue; // dead or full: nothing to regen
            }
            $sinceDamage = $this->tick - ($this->lastDamageTick[$entity->id] ?? 0);
            $sinceRegen = $this->tick - ($this->lastRegenTick[$entity->id] ?? 0);
            if ($sinceDamage < self::REGEN_DELAY_TICKS || $sinceRegen < self::REGEN_INTERVAL_TICKS) {
                continue;
            }
            $health->current = min($health->max, $health->current + 1.0);
            $this->lastRegenTick[$entity->id] = $this->tick;
        }

        // Drop tracking state for players that left the world.
        foreach (array_keys($this->lastHealth) as $id) {
            if (!isset($alive[$id])) {
                unset($this->lastHealth[$id], $this->lastDamageTick[$id], $this->lastRegenTick[$id]);
            }
        }
    }
}
