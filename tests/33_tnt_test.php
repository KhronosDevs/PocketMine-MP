<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\constants\EntityTags;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\service\EntitySpawnService;
use pocketmine\core\system\TNTExplosionSystem;
use pocketmine\Kernel;

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$store = $world->getResourceRegistry()->get(ChunkStore::class);
$spawn = $kernel->getEntitySpawnService();

// Materialize chunks at every test site so setBlock has a store to write to.
$apiWorld = new \pocketmine\api\world\World($world, 'world', 'world');
foreach ([[6, 6], [12, 12], [18, 18]] as [$cx, $cz]) {
    $apiWorld->loadChunk($cx, $cz);
}

// Ensure a chunk exists at the TNT position so blocks can be set/read.
$tick = static function (int $n) use ($world): void {
    for ($i = 0; $i < $n; $i++) {
        $world->tick(0.05);
    }
};

test('primed TNT explodes after the fuse and destroys blocks', function () use ($kernel, $world, $store, $spawn, $tick): void {
    $blocks = $world->getResourceRegistry()->get(BlockRegistry::class);
    $tnt = $spawn->spawnPrimedTNT(100.0, 66.0, 100.0);
    $meta = $tnt->getEntity()?->get(\pocketmine\core\component\MetadataComponent::class);
    ok($meta !== null && $meta->get(MetadataKeys::FUSE_TICKS) === 80, 'TNT starts with an 80-tick fuse');

    // Build a small stone box around the TNT so there is something to destroy.
    // The generated terrain may already fill the interior (and the blocks
    // above), so carve the whole 5x5x6 volume to air first - a solid interior
    // would push the TNT up through the ceiling via block collision.
    for ($dx = -2; $dx <= 2; $dx++) {
        for ($dz = -2; $dz <= 2; $dz++) {
            for ($y = 63; $y <= 70; $y++) {
                $store->setBlock(100 + $dx, $y, 100 + $dz, 0);
            }
            $store->setBlock(100 + $dx, 65, 100 + $dz, 1); // stone floor
            $store->setBlock(100 + $dx, 68, 100 + $dz, 1); // stone ceiling
        }
    }
    $before = 0;
    for ($dx = -2; $dx <= 2; $dx++) {
        for ($dz = -2; $dz <= 2; $dz++) {
            if ($store->getBlock(100 + $dx, 65, 100 + $dz) !== 0) {
                $before++;
            }
        }
    }
    ok($before > 0, "stone box present ($before blocks)");

    // Fuse must count down: after 79 ticks it is still ticking.
    $tick(79);
    $stillThere = false;
    foreach ($world->getEntities() as $e) {
        if ($e->has(EntityTags::PRIMED_TNT)) {
            $stillThere = true;
        }
    }
    ok($stillThere, 'TNT is still primed before the fuse ends');

    // One more tick: it explodes.
    $tick(2);
    $after = 0;
    for ($dx = -2; $dx <= 2; $dx++) {
        for ($dz = -2; $dz <= 2; $dz++) {
            if ($store->getBlock(100 + $dx, 65, 100 + $dz) !== 0) {
                $after++;
            }
        }
    }
    ok($after < $before, "explosion destroyed blocks ($before -> $after)");
    $tntGone = true;
    foreach ($world->getEntities() as $e) {
        if ($e->has(EntityTags::PRIMED_TNT)) {
            $tntGone = false;
        }
    }
    ok($tntGone, 'TNT entity despawned after exploding');
});

test('TNT chain reaction primes nearby TNT blocks', function () use ($kernel, $world, $store, $spawn, $tick): void {
    // Carve the volume first (generated terrain may fill the interior),
    // then lay a solid stone floor so the lit TNT rests at the TNT block
    // level instead of falling into the void (its blast must reach the
    // neighbour block).
    for ($dx = -1; $dx <= 3; $dx++) {
        for ($dz = -1; $dz <= 1; $dz++) {
            for ($y = 63; $y <= 70; $y++) {
                $store->setBlock(200 + $dx, $y, 200 + $dz, 0);
            }
            $store->setBlock(200 + $dx, 65, 200 + $dz, 1);
        }
    }
    // Two TNT blocks side by side, one primed: the blast re-primes the other.
    $store->setBlock(200, 66, 200, ItemIds::TNT);
    $store->setBlock(201, 66, 200, ItemIds::TNT);
    $spawn->spawnPrimedTNT(200.0, 66.0, 200.0);
    $tick(85); // fuse + explosion + chain reaction spawn

    $primed = 0;
    foreach ($world->getEntities() as $e) {
        if ($e->has(EntityTags::PRIMED_TNT)) {
            $primed++;
        }
    }
    // At least the chain-reaction TNT is now lit (the original despawned).
    ok($primed >= 1, "chain reaction primed nearby TNT ($primed lit)");
});

test('creeper death triggers a blast that destroys blocks', function () use ($kernel, $world, $store, $spawn, $tick): void {
    $combat = $kernel->getCombatService();
    $creeper = $spawn->spawnMob(\pocketmine\core\enum\EntityType::Creeper, 300.0, 66.0, 300.0);
    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dz = -1; $dz <= 1; $dz++) {
            $store->setBlock(300 + $dx, 65, 300 + $dz, 1);
        }
    }
    $before = 0;
    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dz = -1; $dz <= 1; $dz++) {
            if ($store->getBlock(300 + $dx, 65, 300 + $dz) !== 0) {
                $before++;
            }
        }
    }
    $combat->kill($creeper, null);
    $tick(5);
    $after = 0;
    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dz = -1; $dz <= 1; $dz++) {
            if ($store->getBlock(300 + $dx, 65, 300 + $dz) !== 0) {
                $after++;
            }
        }
    }
    ok($after < $before, "creeper blast destroyed blocks ($before -> $after)");
});

// NOTE: no shutdown - the world saves its chunks and the next test cleans up.
exit(runTests());
