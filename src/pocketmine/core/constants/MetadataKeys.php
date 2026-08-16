<?php

declare(strict_types=1);

namespace pocketmine\core\constants;

/**
 * Well-known MetadataComponent keys. Entities store arbitrary string-keyed
 * metadata (plugins may add their own), but the keys the server itself reads
 * and writes are named here so logic never references a bare string.
 */
final class MetadataKeys {
    // Identity
    public const USERNAME = 'username';
    public const DISPLAY_NAME = 'displayName';
    public const UNIQUE_ID = 'uniqueId';
    public const CLIENT_ID = 'clientId';
    public const ADDRESS = 'address';
    public const ONLINE = 'online';
    public const PING = 'ping';

    // Entity classification
    public const ENTITY_TYPE = 'entityType';
    public const MOB_TYPE = 'mobType';
    public const PROJECTILE_TYPE = 'projectileType';
    public const HOSTILE = 'hostile';
    public const PASSIVE = 'passive';

    // Gameplay state
    public const GAMEMODE = 'gamemode';
    public const HEALTH = 'health';
    public const ITEM = 'item';
    public const SHOOTER_ID = 'shooterId';
    /** 14.30: the firing bow's Power enchantment level (projectile meta). */
    public const POWER_ENCHANT = 'powerEnchant';
    public const PICKUP_DELAY = 'pickupDelay';
    public const CRITICAL = 'critical';
    public const DETECTION_RANGE = 'detectionRange';
    public const OWNER = 'owner';
    public const TAMED = 'tamed';
    public const LOVE_TIMER = 'loveTimer';
    public const STUCK = 'stuck';
    public const STUCK_TARGET_ID = 'stuckTargetId';
    public const OPEN_CONTAINER = 'openContainer';
    public const CONTAINER_TYPE = 'containerType';
    public const CRAFTING_TABLE = 'craftingTable';
    public const ENDER_CHEST_INVENTORY = 'enderChestInventory';
    public const KEEP_INVENTORY = 'keepInventory';

    // Hunger / experience (players)
    public const HUNGER = 'hunger';
    public const SATURATION = 'saturation';
    public const EXHAUSTION = 'exhaustion';
    public const XP = 'xp';
    public const XP_LEVEL = 'xpLevel';
    public const EXPERIENCE = 'experience';

    // Skin
    public const SKIN_ID = 'skinId';
    public const SKIN_DATA = 'skinData';

    // Server/plugin state
    public const PERMISSIONS = 'permissions';
    public const PVP_ENABLED = 'pvpEnabled';
    public const ABSORPTION = 'absorption';
    public const AGE = 'age';
    public const ARROW_AGE = 'arrowAge';

    // Thrown potion
    public const POTION_ID = 'potionId';

    // TNT fuse
    public const FUSE_TICKS = 'fuseTicks';
    public const FUSE_LENGTH = 'fuseLength';
    public const EXPLOSION_RADIUS = 'explosionRadius';
    public const EXPLOSION_SOURCE_ID = 'explosionSourceId';

    // Vehicles / riding (14.25)
    public const VEHICLE_TYPE = 'vehicleType';
    public const VEHICLE_RIDER_ID = 'vehicleRiderId';
    public const RIDING_VEHICLE_ID = 'ridingVehicleId';
    public const VEHICLE_INPUT_X = 'vehicleInputX';
    public const VEHICLE_INPUT_Z = 'vehicleInputZ';
    public const VEHICLE_JUMPING = 'vehicleJumping';
}
