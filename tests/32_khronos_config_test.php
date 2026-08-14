<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\ecs\ResourceRegistry;
use pocketmine\core\resource\KhronosConfig;
use pocketmine\core\resource\ServerConfig;
use pocketmine\core\resource\WorldConfig;

/**
 * khronos.json - the JSON config surface (default world, default spawn,
 * anti-cheat thresholds/punishments). The file is generated with defaults on
 * first boot and merged (never clobbered) on load.
 */

test('writeDefaults generates a loadable file with the baseline values', function (): void {
    $path = sys_get_temp_dir() . '/khr_cfg_' . getmypid() . '.json';
    @unlink($path);
    KhronosConfig::writeDefaults($path);
    ok(is_file($path), 'defaults file generated');
    $cfg = KhronosConfig::load($path);
    same('world', $cfg->defaultWorld, 'default world');
    same(null, $cfg->spawnX, 'spawn x unset by default (keeps world spawn)');
    ok($cfg->antiCheatEnabled, 'anti-cheat enabled by default');
    near(1.2, $cfg->maxMoveHorizontalPerTick, 1e-9, 'default horizontal cap');
    near(2.0, $cfg->maxMoveTotalPerTick, 1e-9, 'default total cap');
    same(5, $cfg->maxMoveViolations, 'default max violations');
    same(100, $cfg->moveViolationWindowTicks, 'default violation window');
    ok($cfg->rubberBandOnViolation, 'rubber-band on by default');
    ok($cfg->kickOnViolations, 'kick on violations by default');
    near(0.4, $cfg->chatMinIntervalSeconds, 1e-9, 'default chat interval');
    same(256, $cfg->maxChatLength, 'default chat length cap');
    same(30, $cfg->loginAttemptsPerMinute, 'default login attempts');
    same(25, $cfg->maxSessionsPerIp, 'default sessions per ip');
    @unlink($path);
});

test('a custom khronos.json overrides world, spawn and anti-cheat values', function (): void {
    $path = sys_get_temp_dir() . '/khr_cfg2_' . getmypid() . '.json';
    @unlink($path);
    file_put_contents($path, json_encode([
        'default-world' => 'survival',
        'default-spawn' => ['x' => 10, 'y' => 70, 'z' => -5],
        'anti-cheat' => [
            'enabled' => false,
            'movement' => [
                'max-horizontal-per-tick' => 5.0,
                'max-total-per-tick' => 8.0,
                'max-violations' => 2,
                'rubber-band' => false,
                'kick-on-violations' => false,
            ],
            'chat' => ['min-interval-seconds' => 1.5, 'max-length' => 64],
            'command' => ['min-interval-seconds' => 2.0],
            'login' => ['attempts-per-minute' => 5, 'max-sessions-per-ip' => 3],
        ],
    ]));
    $cfg = KhronosConfig::load($path);
    same('survival', $cfg->defaultWorld, 'custom default world');
    same(10, $cfg->spawnX, 'custom spawn x');
    same(70, $cfg->spawnY, 'custom spawn y');
    same(-5, $cfg->spawnZ, 'custom spawn z');
    ok(!$cfg->antiCheatEnabled, 'anti-cheat disabled');
    near(5.0, $cfg->maxMoveHorizontalPerTick, 1e-9, 'custom horizontal cap');
    near(8.0, $cfg->maxMoveTotalPerTick, 1e-9, 'custom total cap');
    same(2, $cfg->maxMoveViolations, 'custom max violations');
    ok(!$cfg->rubberBandOnViolation, 'rubber-band off');
    ok(!$cfg->kickOnViolations, 'kick on violations off');
    near(1.5, $cfg->chatMinIntervalSeconds, 1e-9, 'custom chat interval');
    same(64, $cfg->maxChatLength, 'custom chat length');
    near(2.0, $cfg->commandMinIntervalSeconds, 1e-9, 'custom command interval');
    same(5, $cfg->loginAttemptsPerMinute, 'custom login attempts');
    same(3, $cfg->maxSessionsPerIp, 'custom sessions per ip');
    // Unspecified keys keep the defaults.
    same(0.8, (int)($cfg->maxMoveAscentPerTick * 10) / 10, 'untouched ascent cap keeps default');
    same(100, $cfg->moveViolationWindowTicks, 'untouched window keeps default');
    @unlink($path);
});

test('partial or malformed values fall back to the defaults', function (): void {
    $path = sys_get_temp_dir() . '/khr_cfg3_' . getmypid() . '.json';
    @unlink($path);
    file_put_contents($path, json_encode([
        'default-world' => 42,          // wrong type -> ignored
        'default-spawn' => ['x' => 1],  // incomplete -> ignored
        'anti-cheat' => [
            'enabled' => 'nope',        // not a bool -> ignored
            'movement' => [
                'max-total-per-tick' => 'fast', // not numeric -> ignored
                'max-violations' => 0,          // clamped to 1
            ],
        ],
    ]));
    $cfg = KhronosConfig::load($path);
    same('world', $cfg->defaultWorld, 'bad default-world ignored');
    same(null, $cfg->spawnX, 'incomplete spawn ignored');
    ok($cfg->antiCheatEnabled, 'non-bool enabled ignored');
    near(2.0, $cfg->maxMoveTotalPerTick, 1e-9, 'non-numeric cap ignored');
    same(1, $cfg->maxMoveViolations, 'zero violations clamped to 1');
    @unlink($path);
});

test('a missing file loads the built-in defaults', function (): void {
    $path = sys_get_temp_dir() . '/khr_cfg_missing_' . getmypid() . '.json';
    @unlink($path);
    $cfg = KhronosConfig::load($path);
    same('world', $cfg->defaultWorld, 'defaults without a file');
    same(25, $cfg->maxSessionsPerIp, 'defaults without a file (anti-cheat)');
});

test('applyKhronosConfig overrides the spawn only when all three coordinates are set', function (): void {
    $registry = new ResourceRegistry();
    $server = new ServerConfig();
    $world = new WorldConfig();
    // Pin a persisted-style spawn so the test can tell what won.
    $server->spawnX = 5;
    $server->spawnY = 66;
    $server->spawnZ = 9;
    $world->spawnX = 5;
    $world->spawnY = 66;
    $world->spawnZ = 9;
    $registry->set($server);
    $registry->set($world);

    // Spawn unset (defaults file) -> the existing spawn is preserved.
    $cfg = new KhronosConfig();
    \pocketmine\applyKhronosConfig($registry, $cfg);
    same(5, $server->spawnX, 'unset spawn keeps the persisted x');
    same(9, $world->spawnZ, 'unset spawn keeps the persisted z');

    // Explicit spawn -> overrides both configs and mirrors default-world.
    $cfg->spawnX = 100;
    $cfg->spawnY = 120;
    $cfg->spawnZ = -50;
    $cfg->defaultWorld = 'nether';
    \pocketmine\applyKhronosConfig($registry, $cfg);
    same(100, $server->spawnX, 'explicit spawn overrides ServerConfig x');
    same(120, $server->spawnY, 'explicit spawn overrides ServerConfig y');
    same(-50, $server->spawnZ, 'explicit spawn overrides ServerConfig z');
    same(100, $world->spawnX, 'explicit spawn overrides WorldConfig x');
    same(-50, $world->spawnZ, 'explicit spawn overrides WorldConfig z');
    same('nether', $world->name, 'default-world mirrored to WorldConfig name');
    same('nether', $world->folderName, 'default-world mirrored to WorldConfig folder');
});

exit(runTests());
