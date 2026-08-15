<?php

declare(strict_types=1);

namespace pocketmine\core\constants;

/**
 * Entity tag names used with EntityBuilder::withTag() / Entity::has().
 * Tags map to dedicated Tag components when one exists (see
 * EntityBuilder::TAG_MAP); the rest are boolean markers.
 */
final class EntityTags {
    public const PLAYER = 'player';
    public const MONSTER = 'monster';
    public const DEAD = 'dead';
    public const INVISIBLE = 'invisible';
    public const ON_GROUND = 'on_ground';
    public const SPECTATOR = 'spectator';

    public const ANIMAL = 'animal';
    public const ITEM = 'item';
    public const XP_ORB = 'xp_orb';
}
