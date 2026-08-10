<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\component\HungerComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\ecs\EntityRef;

/**
 * Player food-state mutations shared by the services and the network layer
 * (14.11). The per-tick drain (exhaustion -> saturation -> hunger), starvation
 * damage and HUD sync live in HungerSystem; these helpers are the action
 * hooks that feed it.
 *
 * No-op for creative players and any entity without a HungerComponent (mobs).
 */
final class Hunger {

    public const MAX_FOOD = 20.0;
    public const MAX_SATURATION = 20.0;
    /** One exhaustion unit costs this much saturation (else hunger). */
    public const DRAIN_THRESHOLD = 4.0;

    /**
     * Add action exhaustion (legacy CAUSE_* values: attack 0.3, mining
     * 0.025, damage taken 0.3). Capped at the drain threshold; the system
     * converts it to saturation/hunger loss over time.
     */
    public static function exhaust(EntityRef $playerRef, float $amount): void {
        $player = $playerRef->getEntity();
        if ($player === null) {
            return;
        }
        $metadata = $player->get(MetadataComponent::class);
        if (($metadata?->get('gamemode') ?? 0) === 1) {
            return; // creative: no food drain
        }
        $hunger = $player->get(HungerComponent::class);
        if ($hunger === null) {
            return;
        }
        $hunger->exhaustion = min(self::DRAIN_THRESHOLD, $hunger->exhaustion + $amount);
    }

    /**
     * Apply a food item's restore values (legacy Food::onConsume): both bars
     * are capped at their max. Caller handles item consumption + HUD sync.
     */
    public static function applyFood(EntityRef $playerRef, int $food, float $saturation): void {
        $player = $playerRef->getEntity();
        $hunger = $player?->get(HungerComponent::class);
        if ($hunger === null) {
            return;
        }
        $hunger->hunger = min(self::MAX_FOOD, $hunger->hunger + $food);
        $hunger->saturation = min(self::MAX_SATURATION, $hunger->saturation + $saturation);
    }
}
