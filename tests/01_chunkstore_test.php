<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\resource\ChunkStore;
use pocketmine\port\driven\ChunkData;

/**
 * ChunkStore unit tests: binary-string index math, block/meta/biome
 * round-trips, heightmap derivation, and ChunkData rebuild.
 */

function section(int $y, string $blocks, string $meta = null): array {
    return [
        'y' => $y,
        'blocks' => $blocks,
        'data' => $meta ?? str_repeat("\x00", ChunkStore::SECTION_BYTES),
        'skyLight' => str_repeat("\xff", ChunkStore::LIGHT_BYTES),
        'blockLight' => str_repeat("\x00", ChunkStore::LIGHT_BYTES),
    ];
}

test('load + block reads across sections', function () {
    $store = new ChunkStore();

    // Section 0: all stone. Section 1: air with one torch at local (3, 4, 5).
    $stone = str_repeat(chr(1), ChunkStore::SECTION_BYTES);
    $air = str_repeat("\x00", ChunkStore::SECTION_BYTES);
    // index = (y << 8) | (z << 4) | x  -> local y=4, z=5, x=3
    $air[4 * 256 + 5 * 16 + 3] = chr(50);

    $data = new ChunkData(
        0, 0,
        [section(0, $stone), section(1, $air)],
        array_fill(0, 256, 1),
        array_fill(0, 256, 0),
        [],
        [],
    );
    $store->load($data);

    ok($store->isLoaded(0, 0), 'chunk 0,0 loaded');
    same(1, $store->getBlock(3, 0, 5), 'stone in section 0');
    same(50, $store->getBlock(3, 20, 5), 'torch in section 1 at world y=20');
    same(0, $store->getBlock(0, 20, 0), 'air elsewhere in section 1');
    same(0, $store->getBlock(1000, 100, 1000), 'unloaded chunk reads air');
    same(1, $store->getBiome(3, 5), 'biome roundtrip');
});

test('setBlock/getBlock/getBlockMeta roundtrip + bounds', function () {
    $store = new ChunkStore();
    $store->load(new ChunkData(
        0, 0,
        [section(0, str_repeat("\x00", ChunkStore::SECTION_BYTES))],
        array_fill(0, 256, 0),
        array_fill(0, 256, 0),
        [],
        [],
    ));

    ok($store->setBlock(7, 64, 9, 42, 3), 'setBlock accepted');
    same(42, $store->getBlock(7, 64, 9), 'block id roundtrip');
    same(3, $store->getBlockMeta(7, 64, 9), 'block meta roundtrip');
    same(0, $store->getBlockMeta(8, 64, 9), 'neighbor unaffected');

    ok(!$store->setBlock(0, 256, 0, 1), 'y=256 rejected');
    ok(!$store->setBlock(0, -1, 0, 1), 'negative y rejected');
    ok(!$store->setBlock(0, 64, 0, 256), 'block id 256 rejected');
    ok(!$store->setBlock(500, 64, 500, 1), 'unloaded chunk rejected');
});

test('heightmap + highest block after edits', function () {
    $store = new ChunkStore();
    // Air section with a single stone block at local (3, 0, 5).
    $air = str_repeat("\x00", ChunkStore::SECTION_BYTES);
    $air[5 * 16 + 3] = chr(1);
    $store->load(new ChunkData(
        0, 0,
        [section(0, $air)],
        array_fill(0, 256, 0),
        array_fill(0, 256, 0),
        [],
        [],
    ));

    same(0, $store->getHighestBlockAt(3, 5), 'single stone column highest is y=0');
    same(0, $store->getHighestBlockAt(9, 9), 'empty column highest is y=0');
    $store->setBlock(7, 64, 9, 42);
    same(64, $store->getHighestBlockAt(7, 9), 'edited column highest is y=64');
    $store->setBiome(4, 6, 2);
    same(2, $store->getBiome(4, 6), 'biome set');
});

test('toChunkData rebuild: sections, heightmap, biomes', function () {
    $store = new ChunkStore();
    $air = str_repeat("\x00", ChunkStore::SECTION_BYTES);
    $air[5 * 16 + 3] = chr(1); // one stone at local (3, 0, 5)
    $store->load(new ChunkData(
        0, 0,
        [section(0, $air)],
        array_fill(0, 256, 2),
        array_fill(0, 256, 0),
        [],
        [],
    ));
    $store->setBlock(7, 64, 9, 42);

    $out = $store->toChunkData(0, 0);
    ok($out !== null, 'toChunkData returns DTO');
    same(0, $out->chunkX, 'chunk x preserved');
    same(0, $out->chunkZ, 'chunk z preserved');
    same(2, $out->biomes[0], 'biomes preserved');
    same(65, $out->heightmap[9 * 16 + 7], 'heightmap recomputed to y+1 = 65');
    same(1, $out->heightmap[5 * 16 + 3], 'stone column heightmap = 1');
    same(0, $out->heightmap[0], 'empty column heightmap = 0');
    same(2, count($out->sections), 'two non-empty sections kept (stone at y=0, iron at y=64)');

    // Section 0 blocks survive the roundtrip (sections are indexed densely).
    same(1, ord($out->sections[0]['blocks'][5 * 16 + 3]), 'stone block byte in rebuilt section');
    $ironSection = array_values(array_filter($out->sections, fn(array $s): bool => $s['y'] === 4));
    same(1, count($ironSection), 'iron section present');
    same(42, ord($ironSection[0]['blocks'][9 * 16 + 7]), 'iron block byte in rebuilt section y=4');
    same(null, $store->toChunkData(5, 5), 'toChunkData on unloaded chunk returns null');
});

test('unload clears store', function () {
    $store = new ChunkStore();
    $store->load(new ChunkData(0, 0, [], array_fill(0, 256, 0), array_fill(0, 256, 0), [], []));
    ok($store->isLoaded(0, 0), 'loaded before unload');
    $store->unload(0, 0);
    ok(!$store->isLoaded(0, 0), 'not loaded after unload');
    same(0, $store->getCount(), 'count is zero');
    same(0, $store->getBlock(3, 0, 5), 'reads return air after unload');
});

test('markGenerated/markPopulated flags', function () {
    $store = new ChunkStore();
    $store->load(new ChunkData(0, 0, [], array_fill(0, 256, 0), array_fill(0, 256, 0), [], []));
    ok($store->isGenerated(0, 0), 'loaded chunk is generated by design');
    ok(!$store->isPopulated(0, 0), 'not populated by default');
    $store->markPopulated(0, 0);
    ok($store->isPopulated(0, 0), 'populated flag set');
});

exit(runTests());
