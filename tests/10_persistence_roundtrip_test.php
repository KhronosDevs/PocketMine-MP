<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\storage\AnvilStorageAdapter;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\EntitySnapshot;
use pocketmine\port\driven\TileEntitySnapshot;

/**
 * Phase 10.4 - world persistence round-trip.
 *
 * Proves the AnvilStorageAdapter is lossless across save -> load:
 *   (1) A rich ChunkData DTO (sections, meta, light, biomes, heightmap,
 *       entities with string ids + components, tile entities) survives a
 *       round-trip through a FRESH adapter instance pointed at the same
 *       folder - the "restart" scenario.
 *   (2) Negative chunk coordinates exercise the region-file naming + local
 *       index math.
 *   (3) Re-saving the same chunk is idempotent.
 *   (4) Service-level: load -> mutate -> unload(save) -> reload returns
 *       identical DTO + block/biome state.
 *   (5) Cross-kernel: a mutation saved by kernel A is read back by a fresh
 *       kernel B boot (true persistence, not regeneration).
 */

function rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir() && !$f->isLink()) {
            rmdir($f->getPathname());
        } else {
            unlink($f->getPathname());
        }
    }
    rmdir($dir);
}

/** A fresh temp folder (deleted + recreated) for one test batch. */
function fresh_persist_dir(): string {
    $dir = sys_get_temp_dir() . '/khronos_rt_' . getmypid() . '_' . mt_rand(1000, 9999);
    rmdir_recursive($dir);
    mkdir($dir, 0755, true);
    return $dir;
}

/** Build a ChunkData with two sections, meta, light, biomes, heightmap,
 *  one entity (string id + components) and one tile entity. */
function sample_chunk(int $chunkX, int $chunkZ): ChunkData {
    $blocks0 = str_repeat(chr(1), 4096); // all stone
    $blocks0[3 * 256 + 5 * 16 + 7] = chr(2); // grass at local (7, 0, 5)
    $meta0 = str_repeat("\x00", 4096);
    $meta0[3 * 256 + 5 * 16 + 7] = chr(3);

    $blocks1 = str_repeat("\x00", 4096); // air with one block
    $blocks1[1 * 256 + 2 * 16 + 9] = chr(42); // iron at local (9, 16, 2)

    $biomes = array_fill(0, 256, 1);
    $biomes[5 * 16 + 7] = 4;

    $heightmap = array_fill(0, 256, 0);
    $heightmap[5 * 16 + 7] = 1;
    $heightmap[2 * 16 + 9] = 17;

    $entity = new EntitySnapshot(
        'uuid-9f8e7d6c-5b4a-3c2d-1e0f-a1b2c3d4e5f6', // string id must survive, not become 0
        'Zombie',
        1.5, 64.0, -3.25,
        90.0, 45.0,
        ['health' => '20', 'customName' => 'Bob']
    );
    $tile = new TileEntitySnapshot(
        'tile-42',
        'Chest',
        7, 1, 5,
        ['nbt' => base64_encode("\x0a\x00\x00chest")]
    );

    return new ChunkData(
        $chunkX, $chunkZ,
        [
            ['y' => 0, 'blocks' => $blocks0, 'data' => $meta0,
             'skyLight' => str_repeat("\xff", 2048), 'blockLight' => str_repeat("\x00", 2048)],
            ['y' => 1, 'blocks' => $blocks1, 'data' => str_repeat("\x00", 4096),
             'skyLight' => str_repeat("\xff", 2048), 'blockLight' => str_repeat("\x00", 2048)],
        ],
        $biomes,
        $heightmap,
        [$entity],
        [$tile],
    );
}

function assert_chunk_equals(ChunkData $a, ChunkData $b, string $msg): void {
    same($a->chunkX, $b->chunkX, "$msg: chunk x");
    same($a->chunkZ, $b->chunkZ, "$msg: chunk z");
    same(count($a->sections), count($b->sections), "$msg: section count");
    foreach ($a->sections as $i => $s) {
        same($s['y'], $b->sections[$i]['y'], "$msg: section $i y");
        same($s['blocks'], $b->sections[$i]['blocks'], "$msg: section $i blocks");
        same($s['data'], $b->sections[$i]['data'], "$msg: section $i meta");
        same($s['skyLight'], $b->sections[$i]['skyLight'], "$msg: section $i skyLight");
        same($s['blockLight'], $b->sections[$i]['blockLight'], "$msg: section $i blockLight");
    }
    same($a->biomes, $b->biomes, "$msg: biomes");
    same($a->heightmap, $b->heightmap, "$msg: heightmap");
    same(count($a->entities), count($b->entities), "$msg: entity count");
    foreach ($a->entities as $i => $e) {
        same($e->id, $b->entities[$i]->id, "$msg: entity $i id");
        same($e->type, $b->entities[$i]->type, "$msg: entity $i type");
        near($e->x, $b->entities[$i]->x, 1e-6, "$msg: entity $i x");
        near($e->y, $b->entities[$i]->y, 1e-6, "$msg: entity $i y");
        near($e->z, $b->entities[$i]->z, 1e-6, "$msg: entity $i z");
        near($e->yaw, $b->entities[$i]->yaw, 1e-6, "$msg: entity $i yaw");
        near($e->pitch, $b->entities[$i]->pitch, 1e-6, "$msg: entity $i pitch");
        same($e->components, $b->entities[$i]->components, "$msg: entity $i components");
    }
    same(count($a->tileEntities), count($b->tileEntities), "$msg: tile count");
    foreach ($a->tileEntities as $i => $t) {
        same($t->id, $b->tileEntities[$i]->id, "$msg: tile $i id");
        same($t->type, $b->tileEntities[$i]->type, "$msg: tile $i type");
        same($t->x, $b->tileEntities[$i]->x, "$msg: tile $i x");
        same($t->y, $b->tileEntities[$i]->y, "$msg: tile $i y");
        same($t->z, $b->tileEntities[$i]->z, "$msg: tile $i z");
        same($t->data, $b->tileEntities[$i]->data, "$msg: tile $i data");
    }
}

// --- 1. Adapter-level DTO round-trip through a fresh instance -------------

test('adapter round-trip: full DTO equality via a fresh adapter (restart)', function () {
    $dir = fresh_persist_dir();
    try {
        $writer = new AnvilStorageAdapter($dir . '/', 'testworld');
        $writer->saveChunk(0, 0, sample_chunk(0, 0));

        // Fresh instance = a server restart. Same folder, no shared state.
        $reader = new AnvilStorageAdapter($dir . '/', 'testworld');
        $loaded = $reader->loadChunk(0, 0);

        assert_chunk_equals(sample_chunk(0, 0), $loaded, 'restart round-trip');
        ok(file_exists($dir . '/testworld/region/r.0.0.mca'), 'region file written on disk');
    } finally {
        rmdir_recursive($dir);
    }
});

test('adapter round-trip: negative chunk coordinates', function () {
    $dir = fresh_persist_dir();
    try {
        $writer = new AnvilStorageAdapter($dir . '/', 'testworld');
        // chunk (-3, 5): region (-1, 0), local (29, 5). chunk (2, -7): region (0, -1).
        $writer->saveChunk(-3, 5, sample_chunk(-3, 5));
        $writer->saveChunk(2, -7, sample_chunk(2, -7));

        $reader = new AnvilStorageAdapter($dir . '/', 'testworld');
        assert_chunk_equals(sample_chunk(-3, 5), $reader->loadChunk(-3, 5), 'negative-x round-trip');
        assert_chunk_equals(sample_chunk(2, -7), $reader->loadChunk(2, -7), 'negative-z round-trip');

        $regions = glob($dir . '/testworld/region/*.mca');
        $names = array_map('basename', $regions ?? []);
        sort($names);
        same(['r.-1.0.mca', 'r.0.-1.mca'], $names, 'region files named from arithmetic-shifted coords');
    } finally {
        rmdir_recursive($dir);
    }
});

test('adapter round-trip: re-saving the same chunk is idempotent', function () {
    $dir = fresh_persist_dir();
    try {
        $adapter = new AnvilStorageAdapter($dir . '/', 'testworld');
        $adapter->saveChunk(4, 4, sample_chunk(4, 4));
        $first = $adapter->loadChunk(4, 4);
        $adapter->saveChunk(4, 4, sample_chunk(4, 4)); // overwrite
        $second = $adapter->loadChunk(4, 4);
        assert_chunk_equals($first, $second, 're-save round-trip');
    } finally {
        rmdir_recursive($dir);
    }
});

// --- 2. Service-level: load -> mutate -> save -> reload -------------------

test('service round-trip: mutate persists through unload and reload', function () {
    $worldDir = getcwd() . '/worlds/world';
    rmdir_recursive($worldDir);

    $kernel = null;
    try {
        $kernel = \pocketmine\bootstrap();
        $apiWorld = new \pocketmine\api\world\World($kernel->getWorld(), 'testworld', 'testworld');
        $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);

        // Load chunk (0,0): generates terrain and materializes into the store.
        $apiWorld->loadChunk(0, 0);
        ok($store->isLoaded(0, 0), 'chunk loaded (generated)');
        ok($apiWorld->getBlock(0, 1, 0) !== 0, 'terrain present');

        // Mutate: a marker block well above terrain + a biome change.
        ok($apiWorld->setBlock(5, 200, 5, 42, 3), 'marker block written to store');
        same(42, $apiWorld->getBlock(5, 200, 5), 'marker block read back');
        $apiWorld->setBiome(3, 4, 7);
        same(7, $apiWorld->getBiome(3, 4), 'biome written');
        $saved = $store->toChunkData(0, 0);
        ok($saved !== null, 'toChunkData produced a DTO for saving');

        // Save to disk + drop from memory.
        $apiWorld->unloadChunk(0, 0);
        ok(!$store->isLoaded(0, 0), 'chunk unloaded from memory');
        ok(file_exists($worldDir . '/region/r.0.0.mca'), 'chunk persisted to region file');

        // Reload: must come from disk (not regenerated) and match what we saved.
        $apiWorld->loadChunk(0, 0);
        ok($store->isLoaded(0, 0), 'chunk reloaded');
        same(42, $apiWorld->getBlock(5, 200, 5), 'marker block survived reload');
        same(3, $apiWorld->getBlockMeta(5, 200, 5), 'marker meta survived reload');
        same(7, $apiWorld->getBiome(3, 4), 'biome survived reload');
        $reloaded = $store->toChunkData(0, 0);
        assert_chunk_equals($saved, $reloaded, 'reload DTO equals saved DTO');
    } finally {
        if ($kernel !== null) {
            $kernel->shutdown();
        }
        rmdir_recursive($worldDir);
    }
});

// --- 3. Cross-kernel persistence ------------------------------------------

test('cross-kernel: mutation saved by kernel A is read by a fresh kernel B', function () {
    $worldDir = getcwd() . '/worlds/world';
    rmdir_recursive($worldDir);

    // Kernel A: load, mutate, save, shut down (simulated stop).
    $kernelA = null;
    $kernelB = null;
    try {
        $kernelA = \pocketmine\bootstrap();
        $worldA = new \pocketmine\api\world\World($kernelA->getWorld(), 'testworld', 'testworld');
        $worldA->loadChunk(0, 0);
        ok($worldA->setBlock(6, 190, 6, 17, 0), 'kernel A mutation accepted');
        $worldA->setBiome(2, 2, 5);
        $worldA->unloadChunk(0, 0);
        $kernelA->shutdown();
        $kernelA = null;

        // Kernel B: a brand-new boot reads the same folder.
        $kernelB = \pocketmine\bootstrap();
        $worldB = new \pocketmine\api\world\World($kernelB->getWorld(), 'testworld', 'testworld');
        $worldB->loadChunk(0, 0);
        same(17, $worldB->getBlock(6, 190, 6), 'kernel B sees the persisted mutation');
        same(5, $worldB->getBiome(2, 2), 'kernel B sees the persisted biome');
        // Discriminator: y=190 is far above any generated surface, so a 17
        // here proves the data came from disk rather than a fresh regeneration.
        ok($worldB->getBlock(6, 190, 6) !== 0, 'marker survived at altitude (not regenerated air)');
    } finally {
        if ($kernelA !== null) {
            $kernelA->shutdown();
        }
        if ($kernelB !== null) {
            $kernelB->shutdown();
        }
        rmdir_recursive($worldDir);
    }
});

// --- 14.4 world meta persistence -------------------------------------------
test('world meta round-trips through the adapter (level.dat)', function (): void {
    $dir = fresh_persist_dir();
    try {
        $adapterA = new AnvilStorageAdapter($dir, 'world');
        $adapterA->saveWorldMeta([
            'seed' => '123456789',
            'spawnX' => '12',
            'spawnY' => '71',
            'spawnZ' => '-4',
            'difficulty' => '2',
        ]);

        // A FRESH instance pointed at the same folder reads it back: the
        // "restart" scenario.
        $adapterB = new AnvilStorageAdapter($dir, 'world');
        $meta = $adapterB->loadWorldMeta();
        ok(is_array($meta), 'world meta loaded');
        same('123456789', $meta['seed'] ?? null, 'seed persisted');
        same('12', $meta['spawnX'] ?? null, 'spawnX persisted');
        same('71', $meta['spawnY'] ?? null, 'spawnY persisted');
        same('-4', $meta['spawnZ'] ?? null, 'spawnZ persisted');
        same('2', $meta['difficulty'] ?? null, 'difficulty persisted');
    } finally {
        rmdir_recursive($dir);
    }
});

test('applyPersistedWorldMeta restores the saved world config at boot', function (): void {
    $dir = fresh_persist_dir();
    try {
        $adapter = new AnvilStorageAdapter($dir, 'world');
        $adapter->saveWorldMeta([
            'seed' => '987654321',
            'spawnX' => '7',
            'spawnY' => '68',
            'spawnZ' => '-2',
            'difficulty' => '3',
        ]);

        $registry = new \pocketmine\core\ecs\ResourceRegistry();
        $registry->set(new \pocketmine\core\resource\ServerConfig());
        $registry->set(new \pocketmine\core\resource\WorldConfig());
        \pocketmine\applyPersistedWorldMeta($adapter, $registry);

        $config = $registry->get(\pocketmine\core\resource\ServerConfig::class);
        same(987654321, $config->seed, 'seed restored');
        // The WorldConfig must be kept in sync: the save path prefers it over
        // the ServerConfig seed, and a stale 0 there wipes the seed on disk
        // (which made the world regenerate with a new random seed every boot).
        $worldConfig = $registry->get(\pocketmine\core\resource\WorldConfig::class);
        same(987654321, $worldConfig->seed, 'WorldConfig seed synced on restore');
        same(7, $config->spawnX, 'spawnX restored');
        same(68, $config->spawnY, 'spawnY restored');
        same(-2, $config->spawnZ, 'spawnZ restored');
        same(3, $config->difficulty, 'difficulty restored');
    } finally {
        rmdir_recursive($dir);
    }
});

test('loaded chunks flush to disk via getLoadedChunkCoordinates and survive a fresh adapter', function (): void {
    $dir = fresh_persist_dir();
    try {
        $adapter = new AnvilStorageAdapter($dir, 'world');
        $store = new \pocketmine\core\resource\ChunkStore();
        $store->load(sample_chunk(0, 0));
        // Mutate a block so disk state provably differs from the input DTO.
        $store->setBlock(7, 3, 5, 42); // iron at the sample's grass column

        // The exact iteration Kernel::saveWorld() uses: every loaded chunk.
        $coords = $store->getLoadedChunkCoordinates();
        same(1, count($coords), 'one loaded chunk enumerated');
        foreach ($coords as [$cx, $cz]) {
            $data = $store->toChunkData($cx, $cz);
            if ($data !== null) {
                $adapter->saveChunk($cx, $cz, $data);
            }
        }

        $adapterB = new AnvilStorageAdapter($dir, 'world');
        $round = $adapterB->loadChunk(0, 0);
        same(2, count($round->sections), 'both sections survived');
        $idx = 3 * 256 + 5 * 16 + 7;
        same(42, ord($round->sections[0]['blocks'][$idx]), 'mutated block persisted to disk');
    } finally {
        rmdir_recursive($dir);
    }
});

exit(runTests());
