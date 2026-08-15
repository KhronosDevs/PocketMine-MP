<?php

declare(strict_types=1);

namespace pocketmine\core\constants;

/**
 * Well-known AttributeComponent keys. AttributeComponent is a float-keyed
 * store (like the API Minecraft attribute system it mirrors), so the keys the
 * server itself reads/writes are named here instead of bare strings.
 */
final class AttributeKeys {
    public const HUNGER = 'hunger';
    public const SATURATION = 'saturation';
    public const EXHAUSTION = 'exhaustion';
    public const EXPERIENCE = 'experience';
    public const EXPERIENCE_LEVEL = 'experience_level';
    public const MAX_HEALTH = 'max_health';
    public const MOVEMENT_SPEED = 'movement_speed';
    public const ATTACK_DAMAGE = 'attack_damage';
}
