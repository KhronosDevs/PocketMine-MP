<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\WorldConfig;

/**
 * Day/night cycle (14.6).
 *
 * Sequential system: advances WorldConfig::$time by one tick every world tick.
 * Minecraft time is 24000 ticks per full day at 20 TPS (a 20-minute cycle),
 * so time = 0 is dawn (06:00), 12000 is dusk (18:00), and 18000 is midnight.
 *
 * The kernel's NetworkSessionService broadcasts the current value to players
 * (SetTimePacket) every tick, and MobSpawnerSystem reads it to gate hostile
 * spawning to night - so the world actually turns dark and mobs only appear
 * after sunset.
 */
final class TimeSystem implements System {
    /** Full day in ticks (24000 = 24h at 20 TPS). */
    public const DAY_LENGTH = 24000;
    /** Dawn (06:00). */
    public const TIME_DAWN = 0;
    /** Dusk (18:00) - sunset starts. */
    public const TIME_DUSK = 12000;
    /** Midnight. */
    public const TIME_MIDNIGHT = 18000;
    /** Hostile spawn window start (19:00, vanilla). */
    public const NIGHT_START = 13000;
    /** Hostile spawn window end (05:00, vanilla). */
    public const NIGHT_END = 23000;

    public function run(World $world, float $deltaTime): void {
        $config = $world->getResourceRegistry()->get(WorldConfig::class);
        if (!$config instanceof WorldConfig) {
            return;
        }
        $config->time = ($config->time + 1) % self::DAY_LENGTH;
    }

    /**
     * Whether the given world time is deep night (vanilla hostile window
     * 13000-23000, i.e. 19:00 to 05:00). The first/last hour of dusk and
     * dawn stay quiet so the world has a visible safe window.
     */
    public static function isNight(int $time): bool {
        return $time >= self::NIGHT_START && $time < self::NIGHT_END;
    }
}
