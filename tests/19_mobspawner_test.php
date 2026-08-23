<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\AIStateComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\system\MobSpawnerSystem;

/**
 * 14.3 - the builtin hostile mob spawner.
 *
 * MobSpawnerSystem (registered in every kernel) tops up hostile mobs around
 * alive players every SPAWN_INTERVAL ticks, spawning on the terrain surface
 * of LOADED chunks within [MIN, MAX] blocks of the player. This proves:
 *   (1) mobs actually appear near a living player (AI-enabled, hostile),
 *   (2) the global and per-player caps hold,
 *   (3) ServerConfig::spawnMobs = false stops spawning entirely.
 */

// Fresh world: a stale seed/terrain must not leak into the assertions.
$worldsDir = getcwd() . '/worlds';
if (is_dir($worldsDir)) {
    exec('rm -rf ' . escapeshellarg($worldsDir));
}

$kernel = \pocketmine\bootstrap();
// The despawn tests call loadChunk() mid-file, which drives Kernel::run()
// internally - keep the worker threads alive across runs.
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$worldConfig = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
    $worldConfig->spawnMobs = true;
}
// 14.6: hostile mobs only spawn after dusk. The spawn tests need night
// (midnight = 18000) - the day-gate test flips back to day to prove the
// spawner stays quiet.
$worldConfig = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
    $worldConfig->time = \pocketmine\core\system\TimeSystem::TIME_MIDNIGHT;
}

// Load a 3x3 ring of chunks around spawn so the spawner has plenty of
// real terrain to place mobs on (dry land - the spawn column itself may be
// underwater, and the spawner refuses to place mobs in liquid).
$coords = [];
for ($cx = -1; $cx <= 1; $cx++) {
    for ($cz = -1; $cz <= 1; $cz++) {
        $coords[] = [$cx, $cz];
    }
}
$kernel->getChunkLoadService()->loadChunks($coords);
$store = $kernel->getResourceRegistry()->get(ChunkStore::class);

// Find a dry surface column near the centre of the loaded area.
$top = 65;
$px = 0.5;
$pz = 0.5;
if ($store instanceof ChunkStore) {
    $foundLand = false;
    for ($dx = 0; $dx < 16 && !$foundLand; $dx++) {
        for ($dz = 0; $dz < 16 && !$foundLand; $dz++) {
            $t = $store->getHighestBlockAt($dx, $dz);
            $b = $t > 0 ? $store->getBlock($dx, $t, $dz) : 0;
            if ($t > 60 && !in_array($b, [8, 9, 10, 11], true)) {
                $top = $t;
                $px = $dx + 0.5;
                $pz = $dz + 0.5;
                $foundLand = true;
            }
        }
    }
}

// The world seed is random per boot, so the terrain around the found column
// can be ocean - and the spawner only places mobs on dry land. Guarantee the
// spawn annulus (8-24 blocks around the player) is dry by carving a flat
// stone platform at the surface height around the player position.
if ($store instanceof ChunkStore) {
    $cx = (int)floor($px);
    $cz = (int)floor($pz);
    $radius = MobSpawnerSystem::SPAWN_RADIUS + 2;
    for ($dx = -$radius; $dx <= $radius; $dx++) {
        for ($dz = -$radius; $dz <= $radius; $dz++) {
            if ($dx * $dx + $dz * $dz > $radius * $radius) {
                continue;
            }
            $x = $cx + $dx;
            $z = $cz + $dz;
            // Only fill columns inside already-loaded chunks.
            if ($store->isLoaded((int)floor($x / 16), (int)floor($z / 16))) {
                $store->setBlock($x, $top, $z, 1); // stone surface
            }
        }
    }
}

// A living player standing on the dry surface of the loaded terrain.
$player = $world->spawn(
    (new EntityBuilder())
        ->at($px, $top + 1, $pz)
        ->with(new VelocityComponent())
        ->with(new HealthComponent(20, 20))
        ->with(new MetadataComponent(['username' => 'MobTester']))
        ->withTag(PlayerTag::class)
);
$playerPos = $player->getPosition();
ok($playerPos !== null, 'player has a position');

test('mobs spawn near the player and respect the caps', function () use ($world, $playerPos): void {
    // 240 ticks = 6 spawn intervals; far more than enough for several spawns.
    for ($i = 0; $i < 240; $i++) {
        $world->tick(0.05);
    }

    $hostile = 0;
    $nearby = 0;
    $aiEnabled = 0;
    foreach ($world->getEntities() as $entity) {
        $meta = $entity->get(MetadataComponent::class);
        if ($meta === null || !$meta->get('hostile')) {
            continue;
        }
        $hostile++;
        if ($entity->get(AIStateComponent::class) !== null) {
            $aiEnabled++;
        }
        $pos = $entity->get(PositionComponent::class);
        if ($pos !== null) {
            $dx = $pos->x - $playerPos->x;
            $dz = $pos->z - $playerPos->z;
            if ($dx * $dx + $dz * $dz <= MobSpawnerSystem::SPAWN_RADIUS ** 2) {
                $nearby++;
            }
        }
    }
    ok($hostile > 0, 'hostile mobs spawned near the player');
    ok($hostile <= MobSpawnerSystem::MAX_TOTAL_MOBS, 'global mob cap respected');
    ok($nearby <= MobSpawnerSystem::MAX_MOBS_PER_PLAYER, 'per-player neighbourhood cap respected');
    ok($aiEnabled === $hostile, 'every spawned mob carries the AI component');
});

test('mobs do not spawn during the day (night gate)', function () use ($kernel, $world, $playerPos): void {
    $worldConfig = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
    if (!$worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
        ok(true, 'no world config to test');
        return;
    }
    $before = 0;
    foreach ($world->getEntities() as $entity) {
        $meta = $entity->get(MetadataComponent::class);
        if ($meta !== null && $meta->get('hostile')) {
            $before++;
        }
    }
    $worldConfig->time = \pocketmine\core\system\TimeSystem::TIME_DAWN; // 06:00
    for ($i = 0; $i < 120; $i++) { // 3 spawn intervals, all in daylight
        $world->tick(0.05);
    }
    $after = 0;
    foreach ($world->getEntities() as $entity) {
        $meta = $entity->get(MetadataComponent::class);
        if ($meta !== null && $meta->get('hostile')) {
            $after++;
        }
    }
    same($before, $after, 'no hostile mobs spawn while the sun is up');
});

test('mob spawning stops when WorldConfig::spawnMobs is false', function () use ($kernel, $world): void {
    $worldConfig = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
    if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
        $worldConfig->spawnMobs = false;
    }
    // Back to night so the gate is not the reason no mobs spawn.
    $worldConfig = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
    if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
        $worldConfig->time = \pocketmine\core\system\TimeSystem::TIME_MIDNIGHT;
    }
    $before = count($world->getEntities());
    for ($i = 0; $i < 120; $i++) { // 3 spawn intervals
        $world->tick(0.05);
    }
    $after = count($world->getEntities());
    same($before, $after, 'no new entities while mob spawning is disabled');
});

// NOTE: no $kernel->shutdown() here

test('hostile mobs far from ALL players are despawned before the caps run', function () use ($world, $kernel, $config, $worldConfig, $player, $playerPos, $top, $px, $pz, $store): void {
    // The despawn sweep lives behind the night + spawnMobs gates in the
    // spawner; earlier tests may have flipped either.
    if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
        $worldConfig->spawnMobs = true;
        $worldConfig->time = \pocketmine\core\system\TimeSystem::TIME_MIDNIGHT;
    }

    // Deterministic terrain: everyone must stand in open air, or the
    // suffocation system kills them mid-test and invalidates the guards.
    $farX = $px + 200.0;
    $load = $kernel->getChunkLoadService();
    $load->loadChunk((int)floor($farX / 16), (int)floor($pz / 16));
    $kernel->run(1);
    foreach ([[$px, $pz], [$px + 12.0, $pz], [$farX, $pz]] as [$ax, $az]) {
        for ($y = (int)floor($playerPos->y); $y <= (int)floor($playerPos->y) + 4; $y++) {
            if ($store instanceof ChunkStore && $store->isLoaded((int)floor($ax / 16), (int)floor($az / 16))) {
                $store->setBlock((int)floor($ax), $y, (int)floor($az), 0);
            }
        }
    }
    $health = $player->getHealth();
    if ($health !== null) {
        $health->current = $health->max;
    }

    $spawner = $kernel->getEntitySpawnService();
    $near = $spawner->spawnMob(\pocketmine\core\enum\EntityType::Zombie, $px + 12.0, $playerPos->y, $pz);
    $far = $spawner->spawnMob(\pocketmine\core\enum\EntityType::Zombie, $farX, $playerPos->y, $pz);
    ok($near !== null && $far !== null, 'both zombies spawned');

    // One spawn interval (40 ticks) plus the flush tick that applies the
    // queued despawn. Heal the player every few ticks so environmental
    // damage can never remove the guard before the sweep runs.
    for ($i = 0; $i < 42; $i++) {
        $world->tick(0.05);
        if ($i % 5 === 0 && $health !== null) {
            $health->current = $health->max;
        }
    }

    ok($world->getEntity($far->getId()) === null, 'abandoned far mob was despawned');
    ok($world->getEntity($near->getId()) !== null, 'mob near the player stays');
});

test('a mob near ANY player survives even when far from another', function () use ($world, $kernel, $playerPos, $top, $px, $pz, $store): void {
    // Second player 300 blocks away, in carved open air; a guard mob right
    // next to them must survive even though it is far from player 1.
    $otherX = $px + 300.0;
    $kernel->getChunkLoadService()->loadChunk((int)floor($otherX / 16), (int)floor($pz / 16));
    $kernel->run(1);
    for ($y = (int)floor($playerPos->y); $y <= (int)floor($playerPos->y) + 4; $y++) {
        if ($store instanceof ChunkStore && $store->isLoaded((int)floor($otherX / 16), (int)floor($pz / 16))) {
            $store->setBlock((int)floor($otherX), $y, (int)floor($pz), 0);
        }
    }
    $other = $world->spawn(
        (new EntityBuilder())
            ->at($otherX, $playerPos->y, $pz)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['username' => 'FarTester']))
            ->withTag(PlayerTag::class)
    );
    $guard = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, $otherX + 5.0, $playerPos->y, $pz);

    for ($i = 0; $i < 42; $i++) {
        $world->tick(0.05);
    }
    ok($world->getEntity($guard->getId()) !== null, 'mob near the second player is not despawned by the first player distance');
});

exit(runTests());