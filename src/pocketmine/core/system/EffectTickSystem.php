<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\EffectComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;

/**
 * Bug 30: applies periodic timed-effect gameplay (old-src EntityEffects).
 * Regeneration heals; Poison damages (floors at 1 HP). All other timed
 * effects are passive stat modifiers checked by the systems that own those
 * stats (Speed→validateMove caps, Strength→CombatService damage, etc.) —
 * they don't need per-tick logic here.
 */
final class EffectTickSystem implements System {
    public const REGEN_INTERVAL = 50;   // legacy: 50 / (amplifier+1) ticks per HP
    public const POISON_INTERVAL = 25;  // legacy: 25 / (amplifier+1) ticks per damage

    private int $tickCounter = 0;

    /** @var array<int, int> entityId => last effect-tick */
    private array $lastRegen = [];
    private array $lastPoison = [];

    public function run(World $world, float $deltaTime): void {
        $this->tickCounter++;
        $combat = \pocketmine\Kernel::getInstance()?->getCombatService();
        foreach ($world->query()
            ->with(\pocketmine\core\component\EffectComponent::class, HealthComponent::class)
            ->build() as $entity) {
            $effects = $entity->get(EffectComponent::class);
            $health = $entity->get(HealthComponent::class);
            if ($effects === null || $health === null || $health->current <= 0) {
                continue;
            }
            $ref = EntityRef::create($entity->id, $world);

            // Regeneration: +1 HP every REGEN_INTERVAL / (amplifier+1) ticks.
            $regen = $effects->get(10); // EFFECT_REGENERATION
            if ($regen !== null && $health->current < $health->max) {
                $interval = max(1, intdiv(self::REGEN_INTERVAL, $regen->amplifier + 1));
                if ($this->tickCounter % $interval === 0) {
                    $health->current = min($health->max, $health->current + 1.0);
                }
            }

            // Poison: 1 damage every POISON_INTERVAL / (amplifier+1) ticks,
            // floors at 1 HP (vanilla: poison cannot kill).
            $poison = $effects->get(19); // EFFECT_POISON
            if ($poison !== null && $health->current > 1.0) {
                $interval = max(1, intdiv(self::POISON_INTERVAL, $poison->amplifier + 1));
                if ($this->tickCounter % $interval === 0 && $health->current > 1.0) {
                    $combat?->applyDamage($ref, 1.0, null,
                        \pocketmine\api\event\EntityDamageEvent::CAUSE_MAGIC);
                }
            }
        }

        // Clean up tracking state for entities whose effects expired.
        foreach ($this->lastRegen as $id => $_) {
            $e = $world->getEntity($id);
            if ($e === null || !$e->has(\pocketmine\core\component\EffectComponent::class)) {
                unset($this->lastRegen[$id]);
            }
        }
    }
}
