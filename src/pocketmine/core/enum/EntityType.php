<?php

declare(strict_types=1);

namespace pocketmine\core\enum;

/**
 * Built-in entity types. The enum value is the canonical type string stored in
 * MetadataComponent ('entityType'/'mobType'/'projectileType') and persisted in
 * chunk storage, so it must never change once a world exists.
 *
 * networkId() maps the type to the protocol-84 entity type id used in
 * AddEntityPacket - the single source of truth that used to live as a private
 * map in NetworkSessionService.
 */
enum EntityType: string {
    case Zombie = 'Zombie';
    case Skeleton = 'Skeleton';
    case Creeper = 'Creeper';
    case Spider = 'Spider';
    case Cow = 'Cow';
    case Pig = 'Pig';
    case Sheep = 'Sheep';
    case Chicken = 'Chicken';
    case Arrow = 'Arrow';

    /** Protocol-84 entity type id for AddEntityPacket. */
    public function networkId(): int {
        return match ($this) {
            self::Chicken => 10,
            self::Cow => 11,
            self::Pig => 12,
            self::Sheep => 13,
            self::Zombie => 32,
            self::Creeper => 33,
            self::Skeleton => 34,
            self::Spider => 35,
            self::Arrow => 80,
        };
    }

    /** Hostile monsters (attack players; spawned by the night spawner). */
    public function isHostile(): bool {
        return match ($this) {
            self::Zombie, self::Skeleton, self::Creeper, self::Spider => true,
            default => false,
        };
    }

    /** Passive farm animals. */
    public function isPassive(): bool {
        return match ($this) {
            self::Cow, self::Pig, self::Sheep, self::Chicken => true,
            default => false,
        };
    }
}
