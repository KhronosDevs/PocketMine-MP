<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\WorldConfig;

/**
 * Weather cycle (14.22).
 *
 * Sequential system mirroring the legacy 0.15 `Weather::calcWeather`: the
 * world alternates between clear spells and storms (rain, rain+thunder,
 * thunder) with random durations, and every tick spent in a storm advances a
 * lightning timer the network layer reads to strike periodically.
 *
 * State lives on WorldConfig (weather, weatherDuration, lightningTick) so it
 * persists with the world meta and the per-tick NetworkSessionService
 * broadcast can diff it against what each client was last told.
 *
 * State machine (legacy randomWeatherData table, weighted toward clear and
 * plain rain):
 *   clear       -> pick a random storm type (or stay clear) for 6000-12000 ticks
 *   any storm   -> always clear for 6000-12000 ticks
 */
final class WeatherSystem implements System {
    /** Clear skies. */
    public const CLEAR = 0;
    /** Rain (no thunder). */
    public const RAIN = 1;
    /** Rain with thunder (storm). */
    public const RAINY_THUNDER = 2;
    /** Thunder without rain. */
    public const THUNDER = 3;

    /** Legacy Weather::$randomWeatherData: 7/10 stay clear, 2/10 rain, 1/10 storm. */
    private const RANDOM_WEATHER = [self::CLEAR, self::RAIN, self::CLEAR, self::RAIN, self::CLEAR, self::RAIN, self::CLEAR, self::RAINY_THUNDER, self::CLEAR, self::THUNDER];

    /** Weather duration bounds in ticks (legacy 6000-12000 = 5-10 minutes). */
    public const DURATION_MIN = 6000;
    public const DURATION_MAX = 12000;

    /** Storm-to-storm lightning interval in ticks (legacy lightningTime = 200 = 10s). */
    public const LIGHTNING_INTERVAL = 200;

    public function run(World $world, float $deltaTime): void {
        $config = $world->getResourceRegistry()->get(WorldConfig::class);
        if (!$config instanceof WorldConfig) {
            return;
        }

        // When weather is disabled in khronos.json, force clear and skip.
        $kernel = \pocketmine\Kernel::getInstance();
        $khConfig = $kernel?->getResourceRegistry()->get(\pocketmine\core\resource\KhronosConfig::class);
        if ($khConfig instanceof \pocketmine\core\resource\KhronosConfig && !$khConfig->worldWeatherEnabled) {
            if ($config->weather !== self::CLEAR) {
                $config->weather = self::CLEAR;
                $config->weatherDuration = 0;
                $config->lightningTick = 0;
            }
            return;
        }

        // Legacy calcWeather decrements first, then rolls a new spell once the
        // current one is spent - so the very first tick of a fresh world (0
        // remaining) rolls immediately, and a restored spell resumes mid-way.
        $config->weatherDuration--;
        if ($config->weatherDuration <= 0) {
            $newWeather = $config->weather === self::CLEAR
                ? self::RANDOM_WEATHER[array_rand(self::RANDOM_WEATHER)]
                : self::CLEAR;
            // Blocker 4 audit: cancellable WeatherChangeEvent - a plugin can
            // keep the current weather.
            $kernel = \pocketmine\Kernel::getInstance();
            if ($kernel !== null) {
                $event = new \pocketmine\api\event\WeatherChangeEvent(
                    new \pocketmine\api\world\World($world, 'world', 'world', 0),
                    $newWeather,
                );
                $kernel->getEventPort()->emit($event);
                if (!$event->isCancelled()) {
                    $config->weather = $event->getWeather();
                }
            } else {
                $config->weather = $newWeather;
            }
            $config->weatherDuration = mt_rand(self::DURATION_MIN, self::DURATION_MAX);
        }

        if (self::isThundering($config->weather)) {
            $config->lightningTick++;
        } else {
            $config->lightningTick = 0;
        }
    }

    /** Rain is falling (legacy state table: rain or rain+thunder only - the
     *  standalone THUNDER state sends STOP_RAIN + START_THUNDER). */
    public static function isRaining(int $weather): bool {
        return $weather === self::RAIN || $weather === self::RAINY_THUNDER;
    }

    /** Lightning can strike (rain+thunder or thunder). */
    public static function isThundering(int $weather): bool {
        return $weather === self::RAINY_THUNDER || $weather === self::THUNDER;
    }
}
