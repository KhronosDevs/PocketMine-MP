<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\storage\AnvilStorageAdapter;
use pocketmine\adapter\driven\storage\LevelProviderManager;
use pocketmine\adapter\driven\storage\McRegionStorageAdapter;
use pocketmine\nbt\NBT;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\EntitySnapshot;
use pocketmine\port\driven\TileEntitySnapshot;
use pocketmine\utils\BinaryStream;

/**
 * Multi-format world storage (14.21).
 *
 * The world is infinite (nothing bounds generation or storage); the real bug
 * was the chunk stream not following the player - covered in tests/17. This
 * file proves the STORAGE side:
 *   (1) AnvilStorageAdapter now writes REAL vanilla .mca NBT chunks (the
 *       region record is zlib + big-endian NBT with a "Level"/"Sections"
 *       tree - not the old custom binary), lossless across a fresh adapter.
 *   (2) It still READS the legacy pre-NBT custom payload, so worlds saved by
 *       earlier Khronos builds keep loading (converted on next save).
 *   (3) McRegionStorageAdapter round-trips the classic 128-height flat
 *       column format (.mcr).
 *   (4) level.dat is real gzip NBT ("Data" compound) and round-trips.
 *   (5) LevelProviderManager auto-detects anvil/mcregion/leveldb and throws
 *       a clear error for LevelDB worlds.
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

function fresh_storage_dir(): string {
    // Trailing slash: adapters concatenate basePath . levelName . '/', so the
    // data path must end in a separator (tests/10 passes '$dir . '/' too).
    $dir = sys_get_temp_dir() . '/khronos_fmt_' . getmypid() . '_' . mt_rand(1000, 9999) . '/';
    rmdir_recursive($dir);
    mkdir($dir, 0755, true);
    return $dir;
}

/** Two non-empty sections (y 0 and 1) with meta, light, biomes, heightmap,
 *  one entity and one tile entity - the tests/10 sample shape. */
function fmt_sample_chunk(int $chunkX, int $chunkZ): ChunkData {
    $blocks0 = str_repeat(chr(1), 4096); // all stone
    $blocks0[3 * 256 + 5 * 16 + 7] = chr(2); // grass at local (7, 0, 5)
    $meta0 = str_repeat("\x00", 4096);
    $meta0[3 * 256 + 5 * 16 + 7] = chr(3);

    $blocks1 = str_repeat("\x00", 4096);
    $blocks1[1 * 256 + 2 * 16 + 9] = chr(42); // iron at local (9, 16, 2)

    $biomes = array_fill(0, 256, 1);
    $biomes[5 * 16 + 7] = 4;

    $heightmap = array_fill(0, 256, 0);
    $heightmap[5 * 16 + 7] = 1;
    $heightmap[2 * 16 + 9] = 17;

    $entity = new EntitySnapshot(
        'uuid-fmt-entity',
        \pocketmine\core\enum\EntityType::Zombie->value,
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

function fmt_assert_chunk_equals(ChunkData $a, ChunkData $b, string $msg): void {
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

// --- 1. Real Anvil NBT -----------------------------------------------------

test('anvil: chunk payload on disk is real NBT (Level/Sections), not the legacy binary', function (): void {
    $dir = fresh_storage_dir();
    try {
        $adapter = new AnvilStorageAdapter($dir, 'world');
        $adapter->saveChunk(0, 0, fmt_sample_chunk(0, 0));

        // Read the raw region record the way the container does.
        $region = $dir . '/world/region/r.0.0.mca';
        ok(file_exists($region), 'region file created');
        $fh = fopen($region, 'rb');
        $header = unpack('N', (string)fread($fh, 4))[1];
        $sectorOffset = ($header >> 8) & 0xFFFFFF;
        fseek($fh, $sectorOffset * 4096);
        $length = unpack('N', (string)fread($fh, 4))[1];
        $compression = ord((string)fread($fh, 1));
        $record = gzuncompress((string)fread($fh, $length - 1));
        fclose($fh);

        same(2, $compression, 'record compressed with zlib (type 2)');
        ok($record !== false && ord($record[0]) === NBT::TAG_Compound, 'payload starts with the NBT compound tag');

        $nbt = new NBT(NBT::BIG_ENDIAN);
        $nbt->read($record);
        $root = $nbt->getData();
        ok($root instanceof \pocketmine\nbt\tag\CompoundTag, 'root is a compound tag');
        $level = $root->getCompoundTag('Level');
        ok($level !== null, 'Level compound present');
        same(0, $level->getInt('xPos'), 'xPos matches');
        same(0, $level->getInt('zPos'), 'zPos matches');
        $sections = $level->getListTag('Sections');
        ok($sections !== null, 'Sections list present');
        same(2, count($sections), 'two sections on disk');
    } finally {
        rmdir_recursive($dir);
    }
});

test('anvil: full DTO round-trips through a fresh adapter (real NBT path)', function (): void {
    $dir = fresh_storage_dir();
    try {
        $writer = new AnvilStorageAdapter($dir, 'world');
        $writer->saveChunk(0, 0, fmt_sample_chunk(0, 0));

        $reader = new AnvilStorageAdapter($dir, 'world');
        $round = $reader->loadChunk(0, 0);
        fmt_assert_chunk_equals(fmt_sample_chunk(0, 0), $round, 'anvil round-trip');
    } finally {
        rmdir_recursive($dir);
    }
});

// --- 2. Legacy pre-NBT payload fallback ------------------------------------

/** Build the exact legacy (pre-NBT) chunk payload this adapter used to write. */
function legacy_chunk_payload(int $chunkX, int $chunkZ): string {
    $s = new BinaryStream();
    $s->putByte(1); // version
    $s->putByte(2); // section count
    $s->putByte(0);
    $s->put(str_repeat(chr(1), 4096)); // stone
    $s->put(str_repeat("\x00", 4096));
    $s->put(str_repeat("\xff", 2048));
    $s->put(str_repeat("\x00", 2048));
    $s->putByte(1);
    $s->put(str_repeat("\x00", 4096));
    $s->put(str_repeat("\x00", 4096));
    $s->put(str_repeat("\xff", 2048));
    $s->put(str_repeat("\x00", 2048));
    for ($i = 0; $i < 256; $i++) {
        $s->putByte(1); // biomes
    }
    for ($i = 0; $i < 256; $i++) {
        $s->putInt(1); // heightmap
    }
    $s->putInt(1); // one entity
    $s->putString('uuid-legacy');
    $s->putString('Zombie');
    $s->putDouble(1.5);
    $s->putDouble(64.0);
    $s->putDouble(-3.25);
    $s->putFloat(90.0);
    $s->putFloat(45.0);
    $s->putInt(1);
    $s->putString('health');
    $s->putString('20');
    $s->putInt(0); // no tile entities
    return $s->getBuffer();
}

/** Write a raw chunk record into a region file (mirrors the container write). */
function write_region_record(string $dir, string $levelName, int $chunkX, int $chunkZ, string $payload): void {
    $regionDir = $dir . '/' . $levelName . '/region/';
    mkdir($regionDir, 0755, true);
    $regionX = $chunkX >> 5;
    $regionZ = $chunkZ >> 5;
    $path = $regionDir . "r.$regionX.$regionZ.mca";
    file_put_contents($path, str_repeat("\x00", 8192));

    $compressed = gzcompress($payload);
    ok($compressed !== false, 'legacy payload compresses');
    $length = strlen($compressed) + 1;
    $record = pack('N', $length) . chr(2) . $compressed;
    $record = str_pad($record, 4096, "\x00");
    file_put_contents($path, $record, FILE_APPEND);

    $localX = $chunkX & 31;
    $localZ = $chunkZ & 31;
    $index = ($localZ * 32 + $localX) * 4;
    $fh = fopen($path, 'r+b');
    fseek($fh, $index);
    // Sector 2: sectors 0-1 hold the 8192-byte header, so the first data
    // sector is 2 (byte 8192) - exactly where the record was appended.
    fwrite($fh, pack('N', (2 << 8) | 1));
    fseek($fh, 4096 + $index);
    fwrite($fh, pack('N', time()));
    fclose($fh);
}

test('anvil: legacy pre-NBT chunks still load (old worlds keep working)', function (): void {
    $dir = fresh_storage_dir();
    try {
        write_region_record($dir, 'world', 0, 0, legacy_chunk_payload(0, 0));

        $adapter = new AnvilStorageAdapter($dir, 'world');
        $chunk = $adapter->loadChunk(0, 0);
        same(2, count($chunk->sections), 'legacy sections parsed');
        $idx = 3 * 256 + 5 * 16 + 7;
        same(1, ord($chunk->sections[0]['blocks'][$idx]), 'legacy stone at y=3');
        same(1, count($chunk->entities), 'legacy entity parsed');
        same('uuid-legacy', $chunk->entities[0]->id, 'legacy entity id');
        same(\pocketmine\core\enum\EntityType::Zombie->value, $chunk->entities[0]->type, 'legacy entity type');
        same(['health' => '20'], $chunk->entities[0]->components, 'legacy entity components');

        // Re-saving converts the chunk to real Anvil NBT.
        $adapter->saveChunk(0, 0, $chunk);
        $again = $adapter->loadChunk(0, 0);
        same(2, count($again->sections), 'converted chunk reloads');
    } finally {
        rmdir_recursive($dir);
    }
});

// --- 3. McRegion (128-height flat) -----------------------------------------

test('mcregion: full DTO round-trips through a fresh adapter (.mcr)', function (): void {
    $dir = fresh_storage_dir();
    try {
        $writer = new McRegionStorageAdapter($dir, 'world');
        $writer->saveChunk(0, 0, fmt_sample_chunk(0, 0));
        ok(file_exists($dir . '/world/region/r.0.0.mcr'), 'writes .mcr region files');

        $reader = new McRegionStorageAdapter($dir, 'world');
        $round = $reader->loadChunk(0, 0);
        fmt_assert_chunk_equals(fmt_sample_chunk(0, 0), $round, 'mcregion round-trip');
    } finally {
        rmdir_recursive($dir);
    }
});

test('mcregion: blocks above Y=127 are dropped (format limit)', function (): void {
    $dir = fresh_storage_dir();
    try {
        $high = fmt_sample_chunk(0, 0);
        $extra = str_repeat(chr(42), 4096); // non-empty top section (y=15)
        $sections = $high->sections;
        $sections[] = ['y' => 15, 'blocks' => $extra, 'data' => str_repeat("\x00", 4096),
                       'skyLight' => str_repeat("\xff", 2048), 'blockLight' => str_repeat("\x00", 2048)];
        $high = new ChunkData(0, 0, $sections, $high->biomes, $high->heightmap, $high->entities, $high->tileEntities);

        $writer = new McRegionStorageAdapter($dir, 'world');
        $writer->saveChunk(0, 0, $high);
        $round = $writer->loadChunk(0, 0);
        foreach ($round->sections as $section) {
            ok($section['y'] < 8, 'no section above the 128-height limit survives');
        }
    } finally {
        rmdir_recursive($dir);
    }
});

// --- 4. level.dat is real NBT ----------------------------------------------

test('level.dat round-trips as gzip NBT with a vanilla Data compound', function (): void {
    $dir = fresh_storage_dir();
    try {
        $adapterA = new AnvilStorageAdapter($dir, 'world');
        $adapterA->saveWorldMeta([
            'seed' => '123456789',
            'spawnX' => '12',
            'spawnY' => '71',
            'spawnZ' => '-4',
            'difficulty' => '2',
            'time' => '12345',
        ]);

        $raw = (string)file_get_contents($dir . '/world/level.dat');
        same(0x1F, ord($raw[0]), 'level.dat is gzip');
        $nbt = new NBT(NBT::BIG_ENDIAN);
        $nbt->readCompressed($raw);
        $root = $nbt->getData();
        ok($root instanceof \pocketmine\nbt\tag\CompoundTag, 'level.dat parses as NBT');
        $data = $root->getCompoundTag('Data');
        ok($data !== null, 'Data compound present');
        same(123456789, $data->getLong('RandomSeed'), 'RandomSeed written');
        same(12, $data->getInt('SpawnX'), 'SpawnX written');

        $adapterB = new AnvilStorageAdapter($dir, 'world');
        $meta = $adapterB->loadWorldMeta();
        ok(is_array($meta), 'meta loaded');
        same('123456789', $meta['seed'] ?? null, 'seed round-trips');
        same('12', $meta['spawnX'] ?? null, 'spawnX round-trips');
        same('71', $meta['spawnY'] ?? null, 'spawnY round-trips');
        same('-4', $meta['spawnZ'] ?? null, 'spawnZ round-trips');
        same('2', $meta['difficulty'] ?? null, 'difficulty round-trips');
        same('12345', $meta['time'] ?? null, 'time round-trips');
    } finally {
        rmdir_recursive($dir);
    }
});

// --- 5. LevelProviderManager auto-detect -----------------------------------

test('LevelProviderManager detects anvil / mcregion / leveldb / fresh folders', function (): void {
    $dir = fresh_storage_dir();
    try {
        mkdir($dir . '/anv/region', 0755, true);
        file_put_contents($dir . '/anv/region/r.0.0.mca', 'x');
        same(LevelProviderManager::FORMAT_ANVIL, LevelProviderManager::detect($dir, 'anv'), 'detects .mca as anvil');

        mkdir($dir . '/mcr/region', 0755, true);
        file_put_contents($dir . '/mcr/region/r.0.0.mcr', 'x');
        same(LevelProviderManager::FORMAT_MCREGION, LevelProviderManager::detect($dir, 'mcr'), 'detects .mcr as mcregion');

        mkdir($dir . '/ldb/db', 0755, true);
        same(LevelProviderManager::FORMAT_LEVELDB, LevelProviderManager::detect($dir, 'ldb'), 'detects db/ as leveldb');

        mkdir($dir . '/fresh', 0755, true);
        same(LevelProviderManager::FORMAT_NONE, LevelProviderManager::detect($dir, 'fresh'), 'empty folder is fresh');
    } finally {
        rmdir_recursive($dir);
    }
});

test('LevelProviderManager.create returns the matching adapter and a clear LevelDB error', function (): void {
    $dir = fresh_storage_dir();
    try {
        mkdir($dir . '/mcr/region', 0755, true);
        file_put_contents($dir . '/mcr/region/r.0.0.mcr', 'x');
        ok(LevelProviderManager::create($dir, 'mcr') instanceof McRegionStorageAdapter, 'mcregion folder -> McRegion adapter');

        mkdir($dir . '/fresh', 0755, true);
        ok(LevelProviderManager::create($dir, 'fresh') instanceof AnvilStorageAdapter, 'fresh folder -> Anvil adapter');

        mkdir($dir . '/ldb/db', 0755, true);
        $threw = false;
        try {
            LevelProviderManager::create($dir, 'ldb');
        } catch (RuntimeException $e) {
            $threw = true;
            ok(str_contains($e->getMessage(), 'LevelDB'), 'error names the format');
            ok(str_contains($e->getMessage(), 'php-leveldb'), 'error names the missing extension');
        }
        ok($threw, 'LevelDB folder throws a clear error');
    } finally {
        rmdir_recursive($dir);
    }
});

test('mcregion folder is auto-selected as the default world storage', function (): void {
    $dir = fresh_storage_dir();
    try {
        mkdir($dir . '/world/region', 0755, true);
        file_put_contents($dir . '/world/region/r.0.0.mcr', 'x');
        $storage = LevelProviderManager::create($dir, 'world');
        ok($storage instanceof McRegionStorageAdapter, 'default world picks McRegion when on disk');
    } finally {
        rmdir_recursive($dir);
    }
});

exit(runTests());
