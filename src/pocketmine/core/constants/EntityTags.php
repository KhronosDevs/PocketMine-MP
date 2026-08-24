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
    public const PRIMED_TNT = 'primed_tnt';
    public const FALLING_SAND = 'falling_sand';
    public const VEHICLE = 'vehicle';

    /**
     * Marker for entities that survive chunk unload/reload and server
     * restart.  ChunkEntityPersistence captures and restores these
     * entities through their full component snapshot.
     *
     * Plugins mark an entity persistent with:
     *   $entity->set(EntityTags::PERSISTENT, true);
     *
     * On restore, ALL components are deserialized via
     * ComponentSerializer — plugin-added components are preserved
     * as long as their class exists in the autoloaded classmap.
     */
    public const PERSISTENT = 'persistent';
}
