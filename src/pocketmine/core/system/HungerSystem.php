<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\HungerComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\Hunger;

/**
 * Player hunger (14.11).
 *
 * Sequential (main-thread) system running after combat/movement: applies a
 * small passive exhaustion each tick, converts exhaustion to saturation loss
 * (then hunger loss) at the legacy 4.0 threshold, deals starvation damage to
 * players at zero hunger, and syncs the HUD food bar (player.hunger /
 * player.saturation attributes) whenever the values move.
 *
 * Action exhaustion (attack/mining/damage) is fed by services through
 * Hunger::exhaust(); the drain loop here is the only place bars decrease.
 */
final class HungerSystem implements System {
    /** Passive exhaustion per tick (~0.2/s). */
    private const PASSIVE_EXHAUSTION = 0.01;
    /** 1 HP of starvation damage per 4 seconds at zero hunger. */
    public const STARVATION_INTERVAL_TICKS = 80;

    private int $tick = 0;

    /** @var array<int, int> entityId => tick of last starvation damage */
    private array $starvationTick = [];

    public function run(World $world, float $deltaTime): void {
        $this->tick++;

        $alive = [];
        foreach ($world->getEntities() as $entity) {
            if (!$entity->has(PlayerTag::class)) {
                continue;
            }
            $hunger = $entity->get(HungerComponent::class);
            $health = $entity->get(HealthComponent::class);
            if ($hunger === null) {
                continue;
            }
            $alive[$entity->id] = true;

            // Passive drain plus whatever the action hooks added.
            $hunger->exhaustion = min(Hunger::DRAIN_THRESHOLD, $hunger->exhaustion + self::PASSIVE_EXHAUSTION);
            while ($hunger->exhaustion >= Hunger::DRAIN_THRESHOLD) {
                $hunger->exhaustion -= Hunger::DRAIN_THRESHOLD;
                if ($hunger->saturation > 0.0) {
                    $hunger->saturation = max(0.0, $hunger->saturation - 1.0);
                } elseif ($hunger->hunger > 0.0) {
                    $hunger->hunger = max(0.0, $hunger->hunger - 1.0);
                }
            }

            // Starvation: at zero hunger the player slowly takes damage. The
            // 80-tick window starts when hunger first reaches zero, so a
            // player who just ran out of food gets a grace period before the
            // first damage tick.
            if ($hunger->hunger <= 0.0 && $health !== null && $health->current > 0.0) {
                if (!isset($this->starvationTick[$entity->id])) {
                    $this->starvationTick[$entity->id] = $this->tick;
                } elseif ($this->tick - $this->starvationTick[$entity->id] >= self::STARVATION_INTERVAL_TICKS) {
                    $health->current = max(0.0, $health->current - 1.0);
                    $this->starvationTick[$entity->id] = $this->tick;
                }
            } else {
                unset($this->starvationTick[$entity->id]);
            }

            // HUD sync only when the bar actually moved (eating/drain/starvation).
            if ($hunger->hunger !== $hunger->lastSyncedHunger || $hunger->saturation !== $hunger->lastSyncedSaturation) {
                $hunger->lastSyncedHunger = $hunger->hunger;
                $hunger->lastSyncedSaturation = $hunger->saturation;
                \pocketmine\Kernel::getInstance()?->getNetworkSessionService()->syncFoodFor($entity->id);
            }
        }

        foreach (array_keys($this->starvationTick) as $id) {
            if (!isset($alive[$id])) {
                unset($this->starvationTick[$id]);
            }
        }
    }
}
