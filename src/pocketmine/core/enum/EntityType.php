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
    // Hostile
    case Zombie = 'Zombie';
    case Skeleton = 'Skeleton';
    case Creeper = 'Creeper';
    case Spider = 'Spider';
    case Slime = 'Slime';
    case Enderman = 'Enderman';
    case Silverfish = 'Silverfish';
    case CaveSpider = 'CaveSpider';
    case PigZombie = 'PigZombie';
    case Blaze = 'Blaze';
    case LavaSlime = 'LavaSlime';
    case Ghast = 'Ghast';
    case Witch = 'Witch';
    case Stray = 'Stray';
    case Husk = 'Husk';
    case ZombieVillager = 'ZombieVillager';
    // Passive / neutral
    case Cow = 'Cow';
    case Pig = 'Pig';
    case Sheep = 'Sheep';
    case Chicken = 'Chicken';
    case Villager = 'Villager';
    case Mooshroom = 'Mooshroom';
    case Squid = 'Squid';
    case Rabbit = 'Rabbit';
    case Bat = 'Bat';
    case Ocelot = 'Ocelot';
    case Wolf = 'Wolf';
    case IronGolem = 'IronGolem';
    case SnowGolem = 'SnowGolem';
    // Projectiles / misc
    case Arrow = 'Arrow';
    case Snowball = 'Snowball';
    case Egg = 'Egg';
    case ThrownPotion = 'ThrownPotion';
    case XPOrb = 'XPOrb';
    case PrimedTNT = 'PrimedTNT';
    case FishingHook = 'FishingHook';
    // Vehicles (14.25)
    case Boat = 'Boat';
    case Minecart = 'Minecart';

    /** Protocol-84 entity type id for AddEntityPacket. */
    public function networkId(): int {
        return match ($this) {
            self::Chicken => 10,
            self::Cow => 11,
            self::Pig => 12,
            self::Sheep => 13,
            self::Wolf => 14,
            self::Villager => 15,
            self::Mooshroom => 16,
            self::Squid => 17,
            self::Rabbit => 18,
            self::Bat => 19,
            self::IronGolem => 20,
            self::SnowGolem => 21,
            self::Ocelot => 22,
            self::Zombie => 32,
            self::Creeper => 33,
            self::Skeleton => 34,
            self::Spider => 35,
            self::PigZombie => 36,
            self::Slime => 37,
            self::Enderman => 38,
            self::Silverfish => 39,
            self::CaveSpider => 40,
            self::Ghast => 41,
            self::LavaSlime => 42,
            self::Blaze => 43,
            self::ZombieVillager => 44,
            self::Witch => 45,
            self::Stray => 46,
            self::Husk => 47,
            self::Arrow => 80,
            self::Snowball => 81,
            self::Egg => 82,
            self::ThrownPotion => 86,
            self::XPOrb => 69,
            self::PrimedTNT => 65,
            self::Minecart => 84,
            self::Boat => 90,
        };
    }

    /** Throwable projectiles (snowball/egg/potion) - non-sticky by nature. */
    public function isThrowable(): bool {
        return match ($this) {
            self::Snowball, self::Egg, self::ThrownPotion => true,
            default => false,
        };
    }

    /** Explosive entities (PrimedTNT). */
    public function isExplosive(): bool {
        return $this === self::PrimedTNT;
    }

    /** Rideable vehicles (boats / minecarts). */
    public function isVehicle(): bool {
        return match ($this) {
            self::Boat, self::Minecart => true,
            default => false,
        };
    }

    /** Hostile monsters (attack players; spawned by the night spawner). */
    public function isHostile(): bool {
        return match ($this) {
            self::Zombie, self::Skeleton, self::Creeper, self::Spider,
            self::Slime, self::Enderman, self::Silverfish, self::CaveSpider,
            self::PigZombie, self::Blaze, self::LavaSlime, self::Ghast,
            self::Witch, self::Stray, self::Husk, self::ZombieVillager => true,
            default => false,
        };
    }

    /** Passive farm animals. */
    public function isPassive(): bool {
        return match ($this) {
            self::Cow, self::Pig, self::Sheep, self::Chicken,
            self::Villager, self::Mooshroom, self::Squid, self::Rabbit,
            self::Bat, self::Ocelot, self::SnowGolem => true,
            default => false,
        };
    }
}
