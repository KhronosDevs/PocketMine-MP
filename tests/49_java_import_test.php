<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\storage\AnvilStorageAdapter;
use pocketmine\adapter\driven\storage\JavaBlockTranslator;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\port\driven\ChunkData;

/**
 * Java Edition world import (14.29).
 *
 * Java worlds share the .mca container but differ inside the chunk NBT:
 *  - pre-1.13 numeric sections can contain block ids PE 0.15 has no
 *    renderer for (end portal frames, ender chests, ...) - the 0.15 client
 *    segfaults tessellating them - plus meta bits PE never models;
 *  - 1.9-1.12 stores sections as an integer palette + packed long buffer;
 *  - 1.13+ stores a named-block palette ("minecraft:oak_log" + properties);
 *  - 1.18 moved the tags out of "Level" and into the chunk root.
 *
 * This file proves the import boundary (AnvilStorageAdapter +
 * JavaBlockTranslator) turns every one of those shapes into PE-0.15-safe
 * (id, meta) states, so a Java world can never crash the client again.
 */

function jt_rmdir_recursive(string $dir): void {
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

/** Crafted payloads decode through AnvilStorageAdapter::decodePayload. */
function jt_adapter(string $dataPath = ''): AnvilStorageAdapter {
    return new AnvilStorageAdapter($dataPath, 'world');
}

/** Serialize a root compound to big-endian NBT bytes. */
function jt_nbt_bytes(CompoundTag $root): string {
    $nbt = new NBT(NBT::BIG_ENDIAN);
    $nbt->setData($root);
    return $nbt->write();
}

function jt_string_tag(CompoundTag $parent, string $name, string $value): void {
    $parent->setTag($name, new StringTag($name, $value));
}

/** One named palette entry (1.13+ style, uppercase or lowercase keys). */
function jt_named_state(string $name, array $props = [], bool $lowercase = false): CompoundTag {
    $tag = new CompoundTag('', []);
    $nameKey = $lowercase ? 'name' : 'Name';
    $propsKey = $lowercase ? 'properties' : 'Properties';
    jt_string_tag($tag, $nameKey, $name);
    if ($props !== []) {
        $propTag = new CompoundTag('', []);
        foreach ($props as $k => $v) {
            $key = strtolower($k); // property keys are lowercase in both eras
            $propTag->setTag($key, new StringTag($key, $v));
        }
        $tag->setTag($propsKey, $propTag);
    }
    return $tag;
}

/**
 * Pack per-index states into Java long words. $continuous = the pre-1.16
 * layout (one uninterrupted LSB-first bit stream across the 64-bit words);
 * false = the 1.16+ layout: each 64-bit word holds floor(64 / bits)
 * values packed from bit 0, the trailing bits of the word stay unused.
 *
 * @param int[] $indices palette index per block position (4096 entries)
 * @return int[] signed 64-bit words
 */
function jt_pack_long_array(array $indices, int $bits, bool $continuous): array {
    $longs = [];
    $perWord = intdiv(64, $bits);
    foreach ($indices as $k => $idx) {
        if ($continuous) {
            $word = intdiv($k * $bits, 64);
            $off = ($k * $bits) & 63;
        } else {
            $word = intdiv($k, $perWord);
            $off = ($k % $perWord) * $bits;
        }
        if (!isset($longs[$word])) {
            $longs[$word] = 0;
        }
        $longs[$word] |= $idx << $off; // may go negative past bit 63: intended
    }
    ksort($longs);
    return array_values($longs);
}

/** All-identical index array, then set specific slots. */
function jt_index_plane(int $fill, array $slots): array {
    $indices = array_fill(0, 4096, $fill);
    foreach ($slots as $pos => $idx) {
        $indices[$pos] = $idx;
    }
    return $indices;
}

/** Build a named palette entry list with one state per distinct name. */
function jt_palette_names(array $names): ListTag {
    $list = new ListTag('', []);
    $list->setTagType(NBT::TAG_Compound);
    foreach ($names as $name) {
        $list[] = jt_named_state($name);
    }
    return $list;
}

// --- 1. numeric (pre-1.13) sanitizer ---------------------------------------

test('numeric Java chunk: Java-only ids and exotic metas are sanitized to 0.15-safe states', function (): void {
    $dir = sys_get_temp_dir() . '/khr_java_num_' . getmypid() . '_' . mt_rand(1000, 9999);
    try {
        $adapter = new AnvilStorageAdapter($dir . '/', 'world');

        $blocks = str_repeat("\x00", 4096);
        $data = str_repeat("\x00", 4096);
        $put = function (int $pos, int $id, int $meta) use (&$blocks, &$data): void {
            $blocks[$pos] = chr($id);
            $data[$pos] = chr($meta);
        };
        $put(0, 119, 0);        // end portal frame -> obsidian
        $put(1, 130, 0);        // ender chest -> obsidian
        $put(2, 122, 0);        // dragon egg -> obsidian
        $put(3, 138, 0);        // beacon -> glowstone
        $put(4, 160, 5);        // stained glass pane -> glass pane
        $put(5, 18, 12);        // oak leaves w/ Java decay flags -> leaves 0
        $put(6, 161, 13);       // dark-oak leaves w/ flags -> leaves2 1
        $put(7, 17, 12);        // log "bark" axis -> vertical log
        $put(8, 200, 0);        // id no PE/Java knows -> stone
        $put(9, 2, 0);          // grass: PE-valid, must be untouched
        $put(10, 35, 5);        // lime wool untouched
        $put(11, 44, 11);       // upper cobblestone slab: valid PE, untouched

        $chunk = new ChunkData(
            0, 0,
            [['y' => 0, 'blocks' => $blocks, 'data' => $data,
              'skyLight' => str_repeat("\xff", 2048), 'blockLight' => str_repeat("\x00", 2048)]],
            array_fill(0, 256, 1),
            array_fill(0, 256, 1),
            [], []
        );
        // saveChunk writes the raw bytes verbatim; loadChunk sanitizes.
        $adapter->saveChunk(0, 0, $chunk);
        $back = $adapter->loadChunk(0, 0);

        $sec = $back->sections[0];
        $blockAt = fn(int $pos): int => ord($sec['blocks'][$pos]);
        $metaAt = fn(int $pos): int => ord($sec['data'][$pos]);
        same(49, $blockAt(0), '119 end portal frame -> obsidian');
        same(49, $blockAt(1), '130 ender chest -> obsidian');
        same(49, $blockAt(2), '122 dragon egg -> obsidian');
        same(89, $blockAt(3), '138 beacon -> glowstone');
        same(102, $blockAt(4), '160 stained glass pane -> glass pane');
        same(18, $blockAt(5), 'leaves id preserved');
        same(0, $metaAt(5), 'Java leaf decay bits masked off');
        same(161, $blockAt(6), 'leaves2 id preserved');
        same(1, $metaAt(6), 'leaves2 type bits kept');
        same(17, $blockAt(7), 'log id preserved');
        same(0, $metaAt(7), 'log bark axis (12) -> vertical');
        same(1, $blockAt(8), 'unknown id -> stone');
        same(2, $blockAt(9), 'grass untouched');
        same(35, $blockAt(10), 'wool untouched');
        same(5, $metaAt(10), 'wool meta untouched');
        same(44, $blockAt(11), 'slab untouched');
        same(11, $metaAt(11), 'upper-slab meta untouched (0x08 top is valid PE)');
    } finally {
        jt_rmdir_recursive($dir);
    }
});

// --- 2. 1.13-1.17 named palette ---------------------------------------------

test('1.13-1.17 chunk: named Palette + continuous BlockStates decode into safe states', function (): void {
    // 33 distinct states -> 6 bits/entry so indices straddle word borders.
    $names = [];
    for ($i = 0; $i < 33; $i++) {
        $names[] = $i % 2 === 0 ? 'minecraft:stone' : 'minecraft:glowstone';
    }
    $names[10] = 'minecraft:bedrock';  // unique markers for straddle checks
    $names[11] = 'minecraft:obsidian';

    $list = jt_palette_names($names);
    // Distinct markers: entry 0 stone, 1 glowstone, but index 10 -> grass…
    // With only 3 kinds the straddle positions are still verifiable.
    $slots = [];
    for ($k = 9; $k <= 12; $k++) {
        $slots[$k] = $k; // palette index = its own name slot
    }
    $indices = jt_index_plane(1, $slots);
    $longs = jt_pack_long_array($indices, 6, true); // continuous (pre-1.16)

    $level = new CompoundTag('Level', []);
    $level->setInt('xPos', 0);
    $level->setInt('zPos', 0);
    $sections = new ListTag('Sections', []);
    $sections->setTagType(NBT::TAG_Compound);
    $sec = new CompoundTag('', []);
    $sec->setByte('Y', 0);
    $sec->setTag('Palette', $list);
    $sec->setLongArray('BlockStates', $longs);
    $sec->setByteArray('SkyLight', str_repeat("\xff", 2048));
    $sec->setByteArray('BlockLight', str_repeat("\x00", 2048));
    $sections[] = $sec;
    $level->setTag('Sections', $sections);
    $level->setByteArray('Biomes', str_repeat("\x00", 256));
    $level->setIntArray('HeightMap', array_fill(0, 256, 1));
    $level->setTag('Entities', new ListTag('Entities', []));
    $level->setTag('TileEntities', new ListTag('TileEntities', []));

    $root = new CompoundTag('', []);
    $root->setInt('DataVersion', 1968); // 1.14: continuous packing
    $root->setTag('Level', $level);

    $adapter = jt_adapter();
    $chunk = $adapter->decodePayload(jt_nbt_bytes($root), 0, 0);
    ok($chunk !== null, '1.13-1.17 payload parses');
    if ($chunk === null) {
        return;
    }
    $sec = $chunk->sections[0] ?? null;
    ok($sec !== null, 'section present');
    if ($sec === null) {
        return;
    }
    $blockAt = fn(int $pos): int => ord($sec['blocks'][$pos]);
    $metaAt = fn(int $pos): int => ord($sec['data'][$pos]);

    // Fill slot is palette index 1 = names[1] = glowstone.
    same(89, $blockAt(0), 'glowstone fill');
    same(0, $metaAt(0), 'glowstone meta');
    // Markers: slots 9..12 hold palette indices 9..12, whose names are
    // glowstone / bedrock / obsidian / stone. k=10 starts at bit 60 and
    // straddles words 0/1 under continuous (pre-1.16) packing, so these
    // assertions prove cross-word reads work.
    same(89, $blockAt(9), 'k=9 (bits 54-59) -> glowstone');
    same(7, $blockAt(10), 'k=10 (straddles words) -> bedrock');
    same(49, $blockAt(11), 'k=11 (next word) -> obsidian');
    same(1, $blockAt(12), 'k=12 -> stone');
    same(89, $blockAt(100), 'after-straddle slot still glowstone');
    same(89, $blockAt(4095), 'last block decodes');
    ok(count($chunk->sections) === 1, 'exactly one section');
});

// --- 3. 1.16+ named palette (never straddle) --------------------------------

test('1.16+ chunk: named palette with non-straddling long packing decodes', function (): void {
    $names = [];
    for ($i = 0; $i < 33; $i++) {
        $names[] = $i % 2 === 0 ? 'minecraft:stone' : 'minecraft:glowstone';
    }
    $names[10] = 'minecraft:red_wool'; // marker at a would-straddle position

    $list = jt_palette_names($names);
    $slots = [10 => 10];
    $indices = jt_index_plane(1, $slots);
    $longs = jt_pack_long_array($indices, 6, false); // non-straddling (1.16+)

    $level = new CompoundTag('Level', []);
    $level->setInt('xPos', 0);
    $level->setInt('zPos', 0);
    $sections = new ListTag('Sections', []);
    $sections->setTagType(NBT::TAG_Compound);
    $sec = new CompoundTag('', []);
    $sec->setByte('Y', 0);
    $sec->setTag('Palette', $list);
    $sec->setLongArray('BlockStates', $longs);
    $sections[] = $sec;
    $level->setTag('Sections', $sections);
    $level->setByteArray('Biomes', str_repeat("\x00", 256));
    $level->setTag('Entities', new ListTag('Entities', []));
    $level->setTag('TileEntities', new ListTag('TileEntities', []));

    $root = new CompoundTag('', []);
    $root->setInt('DataVersion', 2586); // 1.16.1: never straddle
    $root->setTag('Level', $level);

    $adapter = jt_adapter();
    $chunk = $adapter->decodePayload(jt_nbt_bytes($root), 0, 0);
    ok($chunk !== null, '1.16 payload parses');
    if ($chunk === null) {
        return;
    }
    $sec = $chunk->sections[0] ?? null;
    ok($sec !== null, 'section present');
    if ($sec === null) {
        return;
    }
    $blockAt = fn(int $pos): int => ord($sec['blocks'][$pos]);
    $metaAt = fn(int $pos): int => ord($sec['data'][$pos]);
    same(35, $blockAt(10), 'marker slot k=10 (would straddle) -> red wool');
    same(14, $metaAt(10), 'red wool meta');
    same(89, $blockAt(11), 'slot right after the marker is glowstone again');
    same(89, $blockAt(4095), 'last block decodes');
});

// --- 4. property-aware translation ------------------------------------------

test('palette properties translate into the right 0.15 meta', function (): void {
    $adapter = jt_adapter();

    $cases = [
        [jt_named_state('minecraft:oak_log', ['axis' => 'x']), 17, 4],
        [jt_named_state('minecraft:spruce_log', ['axis' => 'z']), 17, 9],
        [jt_named_state('minecraft:stone_slab', ['type' => 'top']), 44, 8],
        [jt_named_state('minecraft:cobblestone_slab', ['type' => 'double']), 43, 3],
        [jt_named_state('minecraft:oak_slab', ['type' => 'top']), 158, 8],
        [jt_named_state('minecraft:stone_brick_stairs', ['facing' => 'west', 'half' => 'top']), 109, 5],
        [jt_named_state('minecraft:sunflower', ['half' => 'upper']), 175, 8],
        [jt_named_state('minecraft:white_wool'), 35, 0],
        [jt_named_state('minecraft:black_terracotta'), 159, 15],
        [jt_named_state('minecraft:acacia_planks'), 5, 4],
    ];
    foreach ($cases as [$entry, $expId, $expMeta]) {
        $palette = new ListTag('', []);
        $palette->setTagType(NBT::TAG_Compound);
        $palette[] = $entry;

        $level = new CompoundTag('Level', []);
        $sections = new ListTag('Sections', []);
        $sections->setTagType(NBT::TAG_Compound);
        $sec = new CompoundTag('', []);
        $sec->setByte('Y', 0);
        $sec->setTag('Palette', $palette);
        // single state: no data buffer needed
        $sections[] = $sec;
        $level->setTag('Sections', $sections);
        $level->setByteArray('Biomes', str_repeat("\x00", 256));
        $level->setIntArray('HeightMap', array_fill(0, 256, 1));

        $root = new CompoundTag('', []);
        $root->setInt('DataVersion', 1968);
        $root->setTag('Level', $level);

        $chunk = $adapter->decodePayload(jt_nbt_bytes($root), 0, 0);
        ok($chunk !== null && isset($chunk->sections[0]), 'chunk parses');
        if ($chunk === null || !isset($chunk->sections[0])) {
            continue;
        }
        $label = $entry->getTag('Name') !== null
            ? $entry->getString('Name')
            : $entry->getString('name');
        same($expId, ord($chunk->sections[0]['blocks'][5]), "$label id");
        same($expMeta, ord($chunk->sections[0]['data'][5]), "$label meta");
    }
});

// --- 5. 1.9-1.12 integer palette ---------------------------------------------

test('1.9-1.12 chunk: integer Palette decodes and sanitizes', function (): void {
    // 3 states, 4 bits each: oak planks 5:0, stone 1:0, ender chest 130:0.
    $palette = new ListTag('Palette', []);
    $palette->setTagType(NBT::TAG_Int);
    $palette[] = new IntTag('', 5 << 4 | 0);
    $palette[] = new IntTag('', 1 << 4 | 0);
    $palette[] = new IntTag('', 130 << 4 | 0);

    $indices = jt_index_plane(0, [100 => 1, 200 => 2]);
    $longs = jt_pack_long_array($indices, 4, true);

    $level = new CompoundTag('Level', []);
    $sections = new ListTag('Sections', []);
    $sections->setTagType(NBT::TAG_Compound);
    $sec = new CompoundTag('', []);
    $sec->setByte('Y', 0);
    $sec->setTag('Palette', $palette);
    $sec->setLongArray('BlockStates', $longs);
    $sections[] = $sec;
    $level->setTag('Sections', $sections);
    $level->setByteArray('Biomes', str_repeat("\x00", 256));
    $level->setIntArray('HeightMap', array_fill(0, 256, 1));

    $root = new CompoundTag('', []);
    $root->setInt('DataVersion', 1343); // 1.12.2
    $root->setTag('Level', $level);

    $adapter = jt_adapter();
    $chunk = $adapter->decodePayload(jt_nbt_bytes($root), 0, 0);
    ok($chunk !== null, '1.9-1.12 payload parses');
    if ($chunk === null) {
        return;
    }
    $sec = $chunk->sections[0];
    $blockAt = fn(int $pos): int => ord($sec['blocks'][$pos]);
    same(5, $blockAt(0), 'planks fill');
    same(1, $blockAt(100), 'stone slot');
    same(49, $blockAt(200), 'ender chest state sanitized to obsidian');
});

// --- 6. 1.18+ root layout -----------------------------------------------------

test('1.18+ chunk: root-level tags, lowercase block_states, negative Y dropped, heightmap derived', function (): void {
    $palette = new ListTag('palette', []);
    $palette->setTagType(NBT::TAG_Compound);
    $palette[] = jt_named_state('minecraft:air', [], true);
    $palette[] = jt_named_state('minecraft:grass_block', [], true);

    $indices = jt_index_plane(0, [0 => 1, 5 => 1]);
    $longs = jt_pack_long_array($indices, 4, false);

    $root = new CompoundTag('', []);
    $root->setInt('DataVersion', 2860); // 1.18
    $root->setInt('xPos', 3);
    $root->setInt('zPos', -2);

    $sections = new ListTag('sections', []);
    $sections->setTagType(NBT::TAG_Compound);

    // Negative-Y section (below y=0): must be dropped entirely.
    $neg = new CompoundTag('', []);
    $neg->setTag('Y', new ByteTag('Y', -4));
    $blockStatesNeg = new CompoundTag('block_states', []);
    $palNeg = new ListTag('palette', []);
    $palNeg->setTagType(NBT::TAG_Compound);
    $palNeg[] = jt_named_state('minecraft:stone', [], true);
    $blockStatesNeg->setTag('palette', $palNeg);
    $neg->setTag('block_states', $blockStatesNeg);
    $sections[] = $neg;

    // Surface section y=0 with grass at the first column.
    $sec = new CompoundTag('', []);
    $sec->setTag('Y', new ByteTag('Y', 0));
    $blockStates = new CompoundTag('block_states', []);
    $blockStates->setTag('palette', $palette);
    $blockStates->setLongArray('data', $longs);
    $sec->setTag('block_states', $blockStates);
    $sections[] = $sec;

    $root->setTag('sections', $sections);
    $root->setTag('block_entities', new ListTag('block_entities', []));
    $root->setTag('entities', new ListTag('entities', []));

    $adapter = jt_adapter();
    $chunk = $adapter->decodePayload(jt_nbt_bytes($root), 3, -2);
    ok($chunk !== null, '1.18 payload parses');
    if ($chunk === null) {
        return;
    }
    same(3, $chunk->chunkX, 'root xPos read');
    same(-2, $chunk->chunkZ, 'root zPos read');
    same(1, count($chunk->sections), 'negative-Y section dropped');
    same(2, ord($chunk->sections[0]['blocks'][0]), 'grass_block at origin column');
    same(2, ord($chunk->sections[0]['blocks'][5]), 'grass_block at the second slot');
    same(0, ord($chunk->sections[0]['blocks'][100]), 'air stays air');
    ok(count($chunk->heightmap) === 256, 'heightmap derived to 256 entries');
    same(1, $chunk->heightmap[0], 'heightmap counts the grass column');
    same(1, $chunk->heightmap[5], 'heightmap counts the second grass column');
});

// --- 7. unknown names never escape as raw states -----------------------------

test('unmapped 1.13+ names fall back to stone (client can always render)', function (): void {
    [$id, $meta] = JavaBlockTranslator::nameToState('minecraft:totem_of_undying_block_thing', []);
    same(1, $id, 'unknown name -> stone');
    same(0, $meta, 'unknown name -> meta 0');
    [$id, $meta] = JavaBlockTranslator::nameToState('minecraft:red_concrete', []);
    same(159, $id, 'concrete substitutes stained clay');
    same(14, $meta, 'red color kept');
    [$id, $meta] = JavaBlockTranslator::nameToState('minecraft:oak_log', ['axis' => 'y']);
    same(17, $id, 'vertical oak log id');
    same(0, $meta, 'vertical log meta');
});

exit(runTests());
