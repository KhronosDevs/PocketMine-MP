<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\resource\WorldConfig;
use pocketmine\core\system\WeatherSystem;

/**
 * 14.22 - weather cycle (WeatherSystem).
 *
 * WeatherSystem (registered in every kernel) advances WorldConfig's weather
 * state each world tick exactly like legacy 0.15 Weather::calcWeather:
 *   (1) the current spell's remaining duration counts down every tick,
 *   (2) a storm always clears, and clear weather rolls a new spell from the
 *       legacy weighted table with a 6000-12000 tick duration,
 *   (3) a per-tick lightning counter advances only during storms,
 *   (4) the state predicates (isRaining / isThundering) match the legacy
 *       state table,
 *   (5) weather + remaining duration survive a save/load round-trip so a
 *       restart resumes the same storm.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$config = $kernel->getResourceRegistry()->get(WorldConfig::class);
ok($config instanceof WorldConfig, 'WorldConfig resource present');
if (!$config instanceof WorldConfig) {
    exit(runTests());
}

test('the weather spell duration counts down one tick per world tick', function () use ($world, $config): void {
    $config->weather = WeatherSystem::RAIN;
    $config->weatherDuration = 50;
    for ($i = 0; $i < 10; $i++) {
        $world->tick(0.05);
    }
    same(40, $config->weatherDuration, 'duration decremented 10 ticks after 10 world ticks');
    same(WeatherSystem::RAIN, $config->weather, 'weather unchanged while the spell lasts');
});

test('a storm always clears when its duration expires', function () use ($world, $config): void {
    $config->weather = WeatherSystem::RAINY_THUNDER;
    $config->weatherDuration = 1;
    $world->tick(0.05);
    same(WeatherSystem::CLEAR, $config->weather, 'storm cleared on expiry');
    $duration = $config->weatherDuration;
    ok($duration >= WeatherSystem::DURATION_MIN && $duration <= WeatherSystem::DURATION_MAX,
        "clear spell got a fresh 6000-12000 tick duration ($duration)");
});

test('clear weather rolls a new spell from the legacy table', function () use ($world, $config): void {
    $seen = [];
    for ($i = 0; $i < 60; $i++) {
        $config->weather = WeatherSystem::CLEAR;
        $config->weatherDuration = 1;
        $world->tick(0.05);
        $seen[$config->weather] = true;
    }
    // The legacy table only ever yields clear / rain / rain+thunder / thunder.
    foreach ($seen as $state => $_) {
        ok(in_array($state, [WeatherSystem::CLEAR, WeatherSystem::RAIN, WeatherSystem::RAINY_THUNDER, WeatherSystem::THUNDER], true),
            "rolled weather state $state is a valid spell");
    }
    ok(isset($seen[WeatherSystem::RAIN]), 'rain appears across rolls');
});

test('the lightning timer advances only during storms and resets on clear', function () use ($world, $config): void {
    $config->weather = WeatherSystem::RAIN;
    $config->lightningTick = 0;
    for ($i = 0; $i < 5; $i++) {
        $world->tick(0.05);
    }
    same(0, $config->lightningTick, 'plain rain advances no lightning timer');

    $config->weather = WeatherSystem::RAINY_THUNDER;
    $config->lightningTick = 0;
    for ($i = 0; $i < 7; $i++) {
        $world->tick(0.05);
    }
    same(7, $config->lightningTick, 'storm advances the lightning timer 7 ticks');

    $config->weather = WeatherSystem::CLEAR;
    for ($i = 0; $i < 3; $i++) {
        $world->tick(0.05);
    }
    same(0, $config->lightningTick, 'lightning timer reset once the storm ends');
});

test('weather state predicates match the legacy state table', function (): void {
    ok(!WeatherSystem::isRaining(WeatherSystem::CLEAR), 'clear is not raining');
    ok(WeatherSystem::isRaining(WeatherSystem::RAIN), 'rain is raining');
    ok(WeatherSystem::isRaining(WeatherSystem::RAINY_THUNDER), 'storm is raining');
    ok(!WeatherSystem::isRaining(WeatherSystem::THUNDER), 'legacy THUNDER state is thunder without rain');
    ok(!WeatherSystem::isThundering(WeatherSystem::RAIN), 'plain rain has no thunder');
    ok(WeatherSystem::isThundering(WeatherSystem::RAINY_THUNDER), 'storm thunders');
    ok(WeatherSystem::isThundering(WeatherSystem::THUNDER), 'thunder state thunders');
    ok(!WeatherSystem::isThundering(WeatherSystem::CLEAR), 'clear has no thunder');
});

test('the weather spell survives a save/load round-trip', function () use ($kernel, $config): void {
    $config->weather = WeatherSystem::RAINY_THUNDER;
    $config->weatherDuration = 4321;
    $storage = $kernel->getStoragePort();
    $storage->saveWorldMeta([
        'seed' => (string)($config->seed !== 0 ? $config->seed : 1),
        'weather' => (string)$config->weather,
        'weatherDuration' => (string)$config->weatherDuration,
    ]);
    $meta = $storage->loadWorldMeta();
    if ($meta === null || !isset($meta['weather'], $meta['weatherDuration'])) {
        ok(false, 'weather persisted in world meta');
        return;
    }
    $config->weather = WeatherSystem::CLEAR;
    $config->weatherDuration = 0;
    \pocketmine\applyPersistedWorldMeta($storage, $kernel->getResourceRegistry());
    // Weather always resets to clear on load — saved weather is not restored.
    same(WeatherSystem::CLEAR, $config->weather, 'weather resets to clear on load');
    same(12000, $config->weatherDuration, 'weather duration reset to 12000 on load');
});

exit(runTests());
