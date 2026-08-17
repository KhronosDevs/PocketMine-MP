<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter;
use pocketmine\core\constants\BlockIds;
use pocketmine\core\enum\GeneratorType;

/**
 * Phase 14.32: the nether dimension.
 *
 * The nether generator produces a deterministic 128-high hell (bedrock floor
 * + ceiling, netherrack mass, lava below y=32, quartz/glowstone/fire via the
 * population pass). Portals light from an obsidian frame with flint & steel
 * and cross dimensions through ChangeDimensionPacket (the wire side lives in
 * tests/17). MCPE 0.15.10 has no End dimension - the nether is the only
 * alternate dimension.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();

test('nether chunk generation is deterministic, 128-high, with bedrock bounds', function (): void {
    $a = ParallelGeneratorAdapter::generateChunkPure(3, -2, 'nether', 12345);
    $b = ParallelGeneratorAdapter::generateChunkPure(3, -2, 'nether', 12345);
    $c = ParallelGeneratorAdapter::generateChunkPure(3, -2, 'nether', 999);
    same(serialize($a), serialize($b), 'same chunk + seed is byte-identical');
    ok(serialize($a) !== serialize($c), 'a different seed changes the terrain');

    same(8, count($a->sections), '8 sections = protocol-84 height 0..127');
    $sec = [];
    foreach ($a->sections as $s) {
        $sec[$s['y']] = $s['blocks'];
    }
    $get = function (int $x, int $y, int $z) use ($sec): int {
        $sy = intdiv($y, 16);
        $b = $sec[$sy] ?? '';
        return $b === '' ? 0 : ord($b[($y & 15) * 256 + $z * 16 + $x]);
    };
    same(7, $get(5, 0, 5), 'bedrock floor at y=0');
    same(7, $get(5, 127, 5), 'bedrock ceiling at y=127');
    same(7, $get(12, 0, 3), 'bedrock floor everywhere');
    same(7, $get(12, 127, 3), 'bedrock ceiling everywhere');

    // The mass exists (netherrack), lava fills below y=32, and the top is open.
    $netherrack = 0;
    $lava = 0;
    for ($x = 0; $x < 16; $x++) {
        for ($z = 0; $z < 16; $z++) {
            for ($y = 1; $y < 32; $y++) {
                $id = $get($x, $y, $z);
                if ($id === 87) {
                    $netherrack++;
                } elseif ($id === 11) {
                    $lava++;
                }
            }
        }
    }
    ok($netherrack > 1000, 'netherrack mass fills the floor');
    ok($lava > 1000, 'lava sea below y=32');
});

test('nether population places quartz, glowstone, soul sand, gravel and fire', function (): void {
    // Aggregate populated ids across 9 chunks so sparse features are seen.
    $agg = [];
    for ($cx = -1; $cx <= 1; $cx++) {
        for ($cz = -1; $cz <= 1; $cz++) {
            $g = ParallelGeneratorAdapter::generateChunkPure($cx, $cz, 'nether', 4242);
            $p = ParallelGeneratorAdapter::populateNetherChunkPure($cx, $cz, $g, 4242);
            $sec = [];
            foreach ($p->sections as $s) {
                $sec[$s['y']] = $s['blocks'];
            }
            for ($x = 0; $x < 16; $x++) {
                for ($z = 0; $z < 16; $z++) {
                    for ($y = 0; $y <= 127; $y++) { // include the bedrock bounds
                        $sy = intdiv($y, 16);
                        $b = $sec[$sy] ?? '';
                        if ($b === '') {
                            continue;
                        }
                        $id = ord($b[($y & 15) * 256 + $z * 16 + $x]);
                        $agg[$id] = ($agg[$id] ?? 0) + 1;
                    }
                }
            }
        }
    }
    ok(($agg[153] ?? 0) > 100, 'nether quartz ore veins present');
    ok(($agg[89] ?? 0) > 50, 'glowstone clusters present');
    ok(($agg[88] ?? 0) > 50, 'soul sand patches present');
    ok(($agg[13] ?? 0) > 50, 'gravel patches present');
    ok(($agg[51] ?? 0) >= 5, 'ground fire present');
    // Nothing replaced the bedrock bounds.
    same(9 * 512, $agg[7] ?? 0, 'bedrock floor + ceiling untouched by population');
});

test('GeneratorType exposes nether (with the legacy hell alias)', function (): void {
    same(GeneratorType::Nether->value, 'nether', 'canonical string persisted in level.dat');
    same(GeneratorType::Nether, GeneratorType::coerce('nether'), 'nether coerces');
    same(GeneratorType::Nether, GeneratorType::coerce('hell'), 'legacy hell alias coerces to nether');
    same(GeneratorType::Void, GeneratorType::coerce('bogus'), 'unknown types still default to void');
});

test('flint & steel lights only a complete obsidian portal frame', function () use ($kernel): void {
    $network = $kernel->getNetworkSessionService();
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    ok($store !== null, 'default world store present');
    if ($store === null) {
        return;
    }
    $o = BlockIds::OBSIDIAN;
    // The frame lives in chunk (0,0) - load it so block reads see the world.
    $kernel->getChunkLoadService()->loadChunk(0, 0, 0);
    // A 4x5 frame at (0, 40, 0), MISSING the top-left corner at first.
    for ($i = 0; $i < 4; $i++) {
        $store->setBlock(0 + $i, 40, 0, $o, 0);
        $store->setBlock(0 + $i, 44, 0, $o, 0);
    }
    // Side columns: right (x=3) complete, left (x=0) missing the (0,41) block.
    $store->setBlock(0, 42, 0, $o, 0);
    $store->setBlock(0, 43, 0, $o, 0);
    $store->setBlock(3, 41, 0, $o, 0);
    $store->setBlock(3, 42, 0, $o, 0);
    $store->setBlock(3, 43, 0, $o, 0);
    // The missing corner (0, 41) leaves the left side short: no ignite.
    ok(!$network->tryIgnitePortal(0, 1, 40, 0), 'incomplete frame does not ignite');
    ok($store->getBlock(1, 41, 0) !== BlockIds::PORTAL, 'no portal blocks before ignition');
    // Complete the corner (0, 41): now the frame ignites.
    $store->setBlock(0, 41, 0, $o, 0);
    ok($network->tryIgnitePortal(0, 1, 40, 0), 'complete frame ignites');
    same(BlockIds::PORTAL, $store->getBlock(1, 41, 0), 'portal interior lit');
    same(BlockIds::PORTAL, $store->getBlock(2, 43, 0), 'portal interior filled');
    same($o, $store->getBlock(1, 44, 0), 'frame top intact');
    // Clean up.
    for ($i = 0; $i < 4; $i++) {
        for ($y = 40; $y <= 44; $y++) {
            $store->setBlock($i, $y, 0, 0, 0);
        }
    }
});

test('ensureNetherWorld registers the nether with the nether generator and no sky', function () use ($kernel): void {
    $registry = $kernel->getWorldRegistry();
    $netherId = $registry->getWorldIdByName('nether');
    if ($netherId !== null) {
        // A previous test may have created it - drop it for a clean check.
        $registry->removeWorld($netherId);
    }
    $network = $kernel->getNetworkSessionService();
    $id = $network->ensureNetherWorld();
    ok($id !== null && $id > 0, 'nether world registered');
    $info = $registry->getWorld($id);
    ok($info !== null, 'nether world bundle present');
    same(GeneratorType::Nether, $info['config']->generator, 'nether generator set');
    ok(!$info['store']->hasSky(), 'nether store has no sky (dark)');
    // Calling again returns the same world (no duplicate registration).
    same($id, $network->ensureNetherWorld(), 'ensureNetherWorld is idempotent');
    $registry->removeWorld($id);
});

$kernel->shutdown();

exit(runTests());
