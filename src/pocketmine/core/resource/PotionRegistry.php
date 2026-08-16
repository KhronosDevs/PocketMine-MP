<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;

/**
 * Potion registry (14.23): potion item meta => applied effect, mirroring the
 * legacy Potion::POTIONS table. Both drinkable (item 373) and splash (438)
 * potions share these ids - splash just applies them in a radius.
 *
 * Each entry is [effectId, durationTicks, amplifier]. Durations are the
 * legacy *non-extended* values; the "T" (extended) variants map to the same
 * effect with the doubled duration.
 */
#[Resource]
final class PotionRegistry {
    // Effect ids (legacy entity/Effect constants, protocol-84 compatible).
    public const EFFECT_SPEED = 1;
    public const EFFECT_SLOWNESS = 2;
    public const EFFECT_STRENGTH = 5;
    public const EFFECT_HEALING = 6;
    public const EFFECT_HARMING = 7;
    public const EFFECT_JUMP = 8;
    public const EFFECT_REGENERATION = 10;
    public const EFFECT_FIRE_RESISTANCE = 12;
    public const EFFECT_WATER_BREATHING = 13;
    public const EFFECT_INVISIBILITY = 14;
    public const EFFECT_NIGHT_VISION = 16;
    public const EFFECT_HUNGER = 17;
    public const EFFECT_WEAKNESS = 18;
    public const EFFECT_POISON = 19;

    // Potion ids (legacy Potion constants = item meta).
    public const POTION_NIGHT_VISION = 5;
    public const POTION_INVISIBILITY = 7;
    public const POTION_LEAPING = 9;
    public const POTION_FIRE_RESISTANCE = 12;
    public const POTION_SWIFTNESS = 14;
    public const POTION_SLOWNESS = 17;
    public const POTION_WATER_BREATHING = 19;
    public const POTION_HEALING = 21;
    public const POTION_HARMING = 23;
    public const POTION_POISON = 25;
    public const POTION_REGENERATION = 28;
    public const POTION_STRENGTH = 31;
    public const POTION_WEAKNESS = 34;

    /**
     * @var array<int, array{0: int, 1: int, 2: int}> meta => [effectId, duration, amplifier]
     */
    private array $potions = [];

    public function __construct() {
        $this->register(self::POTION_NIGHT_VISION, self::EFFECT_NIGHT_VISION, 180 * 20, 0);
        $this->register(self::POTION_INVISIBILITY, self::EFFECT_INVISIBILITY, 180 * 20, 0);
        $this->register(self::POTION_LEAPING, self::EFFECT_JUMP, 180 * 20, 0);
        $this->register(self::POTION_FIRE_RESISTANCE, self::EFFECT_FIRE_RESISTANCE, 180 * 20, 0);
        $this->register(self::POTION_SWIFTNESS, self::EFFECT_SPEED, 180 * 20, 0);
        $this->register(self::POTION_SLOWNESS, self::EFFECT_SLOWNESS, 90 * 20, 0);
        $this->register(self::POTION_WATER_BREATHING, self::EFFECT_WATER_BREATHING, 180 * 20, 0);
        $this->register(self::POTION_HEALING, self::EFFECT_HEALING, 1, 0);
        $this->register(self::POTION_HARMING, self::EFFECT_HARMING, 1, 0);
        $this->register(self::POTION_POISON, self::EFFECT_POISON, 45 * 20, 0);
        $this->register(self::POTION_REGENERATION, self::EFFECT_REGENERATION, 45 * 20, 0);
        $this->register(self::POTION_STRENGTH, self::EFFECT_STRENGTH, 180 * 20, 0);
        $this->register(self::POTION_WEAKNESS, self::EFFECT_WEAKNESS, 90 * 20, 0);
    }

    public function register(int $potionId, int $effectId, int $duration, int $amplifier): void {
        $this->potions[$potionId] = [$effectId, $duration, $amplifier];
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    public function get(int $potionId): ?array {
        return $this->potions[$potionId] ?? null;
    }

    public function isPotion(int $potionId): bool {
        return isset($this->potions[$potionId]);
    }
}
