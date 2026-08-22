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
            // Spectators neither drain food nor starve (vanilla: spectator is
            // fully detached from the survival loop).
            $meta = $entity->get(\pocketmine\core\component\MetadataComponent::class);
            if ($meta !== null && \pocketmine\core\enum\GameMode::coerce($meta->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE)) === \pocketmine\core\enum\GameMode::Spectator) {
                continue;
            }
            $hunger = $entity->get(HungerComponent::class);
            $health = $entity->get(HealthComponent::class);
            if ($hunger === null) {
                continue;
            }
            $alive[$entity->id] = true;

            // Passive drain plus whatever the action hooks added.
            // Bug 30: Hunger effect multiplies exhaustion accumulation.
            $hungerMult = 1.0;
            $fx = $entity->get(\pocketmine\core\component\EffectComponent::class);
            if ($fx !== null && $fx->get(17) !== null) { // EFFECT_HUNGER
                $hungerMult = 1.0 + 0.5 * ($fx->get(17)->amplifier + 1);
            }
            $hunger->exhaustion = min(Hunger::DRAIN_THRESHOLD, $hunger->exhaustion + self::PASSIVE_EXHAUSTION * $hungerMult);
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
            // Difficulty floor (old-src Human::entityBaseTick): easy stops
            // starving at 10 HP, normal at 1 HP, only hard can starve to
            // death - and peaceful never starves at all.
            if ($hunger->hunger <= 0.0 && $health !== null && $health->current > 0.0) {
                if (!isset($this->starvationTick[$entity->id])) {
                    $this->starvationTick[$entity->id] = $this->tick;
                } elseif ($this->tick - $this->starvationTick[$entity->id] >= self::STARVATION_INTERVAL_TICKS) {
                    $this->starvationTick[$entity->id] = $this->tick;
                    $difficulty = \pocketmine\Kernel::getInstance()?->getResourceRegistry()?->get(\pocketmine\core\resource\ServerConfig::class)?->difficulty
                        ?? \pocketmine\core\enum\Difficulty::Easy;
                    $floor = match ($difficulty) {
                        \pocketmine\core\enum\Difficulty::Peaceful => null,
                        \pocketmine\core\enum\Difficulty::Easy => 10.0,
                        \pocketmine\core\enum\Difficulty::Normal => 1.0,
                        \pocketmine\core\enum\Difficulty::Hard => 0.0,
                    };
                    if ($floor !== null && $health->current > $floor) {
                        // old-src parity: starvation goes through the damage
                        // pipeline (EntityDamageEvent CAUSE_STARVATION), so
                        // plugins observe/cancel it like any other damage.
                        \pocketmine\Kernel::getInstance()?->getCombatService()?->applyDamage(
                            \pocketmine\core\ecs\EntityRef::create($entity->id, $world),
                            1.0,
                            null,
                            \pocketmine\api\event\EntityDamageEvent::CAUSE_STARVATION,
                        );
                    }
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
