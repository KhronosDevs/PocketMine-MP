<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\constants\BlockIds;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\system\FluidSystem;

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$store = $world->getResourceRegistry()->get(ChunkStore::class);
$apiWorld = new \pocketmine\api\world\World($world, 'world', 'world');
// Sites are on a diagonal: (322,322), (340,340), (360,360), (380,380) live
// in chunks (20,20), (21,21), (22,22), (23,23).
foreach ([20, 21, 22, 23] as $c) {
    $apiWorld->loadChunk($c, $c);
}

// Carve a clean air box around a site so generated terrain never interferes.
$clearSite = static function (int $cx, int $cz, int $yMin, int $yMax) use ($store): void {
    for ($dx = -4; $dx <= 4; $dx++) {
        for ($dz = -4; $dz <= 4; $dz++) {
            for ($y = $yMin; $y <= $yMax; $y++) {
                $store->setBlock($cx + $dx, $y, $cz + $dz, 0, 0);
            }
        }
    }
};

$tick = static function (int $n) use ($world): void {
    for ($i = 0; $i < $n; $i++) {
        $world->tick(0.05);
    }
};

test('water flows straight down from a source block', function () use ($store, $clearSite, $tick): void {
    $clearSite(322, 322, 58, 68);
    for ($lx = -2; $lx <= 2; $lx++) {
        for ($lz = -2; $lz <= 2; $lz++) {
            $store->setBlock(322 + $lx, 60, 322 + $lz, 1); // stone floor
        }
    }
    $store->setBlock(322, 66, 322, BlockIds::WATER, 0); // source
    $tick(10); // 5 passes at 2-tick interval

    ok($store->getBlock(322, 60, 322) === 1, 'floor intact');
    $columnFilled = true;
    for ($y = 65; $y >= 61; $y--) {
        if ($store->getBlock(322, $y, 322) !== 8 && $store->getBlock(322, $y, 322) !== 9) {
            $columnFilled = false;
        }
    }
    ok($columnFilled, 'water column descends from source to floor');
});

test('water spreads sideways when it hits solid ground', function () use ($store, $clearSite, $tick): void {
    $clearSite(340, 340, 58, 68);
    for ($lx = -3; $lx <= 3; $lx++) {
        for ($lz = -3; $lz <= 3; $lz++) {
            $store->setBlock(340 + $lx, 60, 340 + $lz, 1); // stone pad
        }
    }
    $store->setBlock(340, 66, 340, BlockIds::WATER, 0); // source above pad
    $tick(14); // ~7 passes -> reaches spread limit

    $wet = 0;
    for ($lx = -2; $lx <= 2; $lx++) {
        for ($lz = -2; $lz <= 2; $lz++) {
            $id = $store->getBlock(340 + $lx, 61, 340 + $lz);
            if ($id === 8 || $id === 9) {
                $wet++;
            }
        }
    }
    ok($wet >= 5, "water spread onto the pad ($wet wet cells)");
});

test('water flows down then spreads out at the bottom', function () use ($store, $clearSite, $tick): void {
    $clearSite(360, 360, 58, 68);
    for ($lx = -3; $lx <= 3; $lx++) {
        for ($lz = -3; $lz <= 3; $lz++) {
            $store->setBlock(360 + $lx, 60, 360 + $lz, 1); // floor at y=60
        }
    }
    // Cliff: solid block at (360, 61..63, 360) so water at y=64 falls off.
    $store->setBlock(360, 61, 360, 1);
    $store->setBlock(360, 62, 360, 1);
    $store->setBlock(360, 63, 360, 1);
    $store->setBlock(360, 64, 360, BlockIds::WATER, 0); // source on the cliff top
    $tick(20);

    $floorWet = 0;
    for ($lx = -2; $lx <= 2; $lx++) {
        for ($lz = -2; $lz <= 2; $lz++) {
            $id = $store->getBlock(360 + $lx, 61, 360 + $lz);
            if ($id === 8 || $id === 9) {
                $floorWet++;
            }
        }
    }
    ok($floorWet >= 3, "water fell off the cliff and spread on the floor ($floorWet wet)");
});

test('water touching lava hardens it to stone', function () use ($store, $clearSite, $tick): void {
    $clearSite(380, 380, 58, 68);
    for ($lx = -3; $lx <= 3; $lx++) {
        for ($lz = -3; $lz <= 3; $lz++) {
            $store->setBlock(380 + $lx, 60, 380 + $lz, 1); // floor
        }
    }
    $store->setBlock(380, 61, 380, BlockIds::LAVA, 0); // lava source
    $store->setBlock(381, 61, 380, BlockIds::WATER, 0); // water source adjacent
    $tick(6);
    $lavaCell = $store->getBlock(380, 61, 380);
    ok($lavaCell === 49, "source lava hardened to obsidian (got $lavaCell)");
});

exit(runTests());
