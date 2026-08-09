<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\resource\WorldConfig;
use pocketmine\core\system\TimeSystem;

/**
 * 14.6 - day/night cycle (TimeSystem).
 *
 * TimeSystem (registered in every kernel) advances WorldConfig::$time by one
 * tick per world tick, wrapping at a full 24000-tick day. This proves:
 *   (1) the clock advances as the world ticks,
 *   (2) it wraps at 24000 (a full day),
 *   (3) the night gate semantics (hostiles spawn after dusk),
 *   (4) the API World facade reads/writes the same clock,
 *   (5) the clock survives a save/load round-trip (restart resumes the hour).
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$config = $kernel->getResourceRegistry()->get(WorldConfig::class);
ok($config instanceof WorldConfig, 'WorldConfig resource present');
if (!$config instanceof WorldConfig) {
    exit(runTests());
}

test('the world clock advances one tick per world tick', function () use ($kernel, $world, $config): void {
    $config->time = 0;
    for ($i = 0; $i < 100; $i++) {
        $world->tick(0.05);
    }
    same(100, $config->time, 'time advanced 100 ticks after 100 world ticks');
    // The API facade reads the same clock.
    same(100, $world->getResourceRegistry()->get(WorldConfig::class)?->time, 'world resource clock matches');
});

test('the clock wraps at a full 24000-tick day', function () use ($world, $config): void {
    $config->time = TimeSystem::DAY_LENGTH - 5;
    for ($i = 0; $i < 10; $i++) {
        $world->tick(0.05);
    }
    same(5, $config->time, 'time wrapped to 5 after crossing 24000');
});

test('night gate semantics (hostiles spawn only in the vanilla night window)', function (): void {
    ok(!TimeSystem::isNight(0), 'dawn is not night');
    ok(!TimeSystem::isNight(12999), 'dusk hour (18:00-19:00) is still quiet');
    ok(TimeSystem::isNight(13000), 'deep night starts at 19:00');
    ok(TimeSystem::isNight(18000), 'midnight is night');
    ok(TimeSystem::isNight(22999), 'late night (04:59) is night');
    ok(!TimeSystem::isNight(23000), 'dawn hour (05:00) is quiet again');
});

test('the api World facade can set the clock', function () use ($world, $kernel): void {
    $facade = new \pocketmine\api\world\World($world, 'world', 'world');
    $facade->setTime(15000);
    same(15000, $facade->getTime(), 'setTime/getTime round-trip on the facade');
    $config = $kernel->getResourceRegistry()->get(WorldConfig::class);
    same(15000, $config?->time, 'facade setTime writes the WorldConfig resource');
});

test('the persisted time of day survives a save/load round-trip', function () use ($kernel, $config): void {
    // Save world meta with a known time, then reload it through the same
    // applyPersistedWorldMeta path the kernel boot uses.
    $config->time = 21000; // 03:00
    $storage = $kernel->getStoragePort();
    $storage->saveWorldMeta([
        'time' => (string)$config->time,
    ]);
    $meta = $storage->loadWorldMeta();
    if ($meta === null || !isset($meta['time'])) {
        ok(false, 'time persisted in world meta');
        return;
    }
    $config->time = 0; // forget the value before reloading
    \pocketmine\applyPersistedWorldMeta($storage, $kernel->getResourceRegistry());
    same(21000, $config->time, 'time restored from persisted meta');
});

exit(runTests());
