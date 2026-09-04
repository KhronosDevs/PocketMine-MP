<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\storage;

use function chr;
use function count;
use function error_log;
use function explode;
use function getenv;
use function in_array;
use function intdiv;
use function ord;
use function preg_match;
use function str_contains;
use function str_repeat;
use function strpos;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * Java Edition -> MCPE 0.15 (protocol 84) block state translation.
 *
 * Java worlds share the .mca container and (mostly) the same legacy numeric
 * ids as 0.15-era worlds, but a raw import has three hazards:
 *
 *  1. Java block ids with NO Pocket Edition counterpart (end portal frames
 *     119, ender chests 130, command blocks 137, beacons 138, stained glass
 *     panes 160, barriers 166, prismarine 168/169, banners 176/177...). The
 *     0.15 client indexes its renderer with these ids and segfaults on any
 *     it does not know - the exact SIGSEGV seen loading a Java arena world
 *     (BlockGraphics::getBlockShape() during chunk tessellation).
 *  2. Numeric ids whose meaning differs between the editions (Java 95 is
 *     stained glass, PE 95 is invisible bedrock) and metadata bits PE never
 *     models (Java leaf decay flags, the "bark" log axis 12).
 *  3. Modern (1.13+) chunks, which store sections as a named-block palette
 *     ("minecraft:oak_log" + properties) packed into long arrays instead of
 *     raw Blocks/Data nibbles.
 *
 * Strategy:
 *  - Numeric sections are sanitized per block: ids PE 0.15 has no claim to
 *    are replaced with the closest renderable block, exotic meta bits are
 *    clamped, and anything else falls back to stone. PE worlds are never
 *    affected: their ids and metas are a strict subset of the valid space.
 *  - Palette sections decode back into (id, meta) through the 1.13+ name
 *    table below, so modern Java worlds render as well.
 *
 * Translation is a best-effort visual mapping: blocks that never existed in
 * 0.15 (copper, deepslate, shulker boxes...) get a safe lookalike. The one
 * hard guarantee: every emitted state exists in the 0.15 client.
 */
final class JavaBlockTranslator {
    /**
     * Numeric ids the PE 0.15 client renders, from the legacy BlockIds
     * (old-src/block/BlockIds.php) that drove the same-era protocol.
     */
    private const PE_VALID_RANGES = [
        '0-35', '37-83', '85-118', '120-121', '123-129', '131-136',
        '139-159', '161-165', '167', '170-175', '178-187', '193-199',
        '243-251', '255',
    ];

    /**
     * Java-only numeric ids -> safe PE replacement [id, meta]. PE worlds can
     * never contain these (they are not PE blocks), so the remap is safe for
     * every world regardless of origin.
     */
    private const JAVA_ONLY_REMAP = [
        119 => [49, 0],  // end_portal_frame   -> obsidian
        122 => [49, 0],  // dragon_egg         -> obsidian
        130 => [49, 0],  // ender_chest        -> obsidian
        137 => [1, 0],   // command_block      -> stone
        138 => [89, 0],  // beacon             -> glowstone
        160 => [102, 0], // stained_glass_pane -> glass pane
        166 => [0, 0],   // barrier            -> air (invisible anyway)
        168 => [98, 0],  // prismarine         -> stone bricks
        169 => [89, 0],  // sea_lantern        -> glowstone
        176 => [0, 0],   // standing banner    -> air
        177 => [0, 0],   // wall banner        -> air
    ];

    /**
     * Meta fixes for numeric sections, [id => [kind, value]]: kind 1 masks
     * the meta, kind 2 normalizes the log axis (Java "bark" 12 is not a
     * 0.15 log axis). Only families whose bits the client does not model.
     */
    private const NUMERIC_META_FIX = [
        18 => [1, 0x03],  // leaves: strip Java decay/persistent flags
        161 => [1, 0x01], // leaves2: only acacia(0)/dark-oak(1) are real types
        17 => [2, 0],     // log: bark axis (12) -> vertical (0)
        162 => [2, 0],    // log2
    ];

    /** @var array<int, array{0:int,1:int,2:int}|null>|null */
    private static ?array $numericRuleCache = null;

    /** @var array<string, array{0: string, 1: int, 2: int, 3: int}>|null */
    private static ?array $nameTableCache = null;

    // --- Numeric (pre-1.13) sanitizer --------------------------------------

    /**
     * Sanitize one 16x16x16 section of raw byte-per-block (id, meta) data so
     * every state is renderable by the 0.15 client.
     *
     * @return array{0: string, 1: string, 2: bool} [blocks, data, changed]
     */
    public static function sanitizeSection(string $blocks, string $data): array {
        $rules = self::numericRules();
        $outBlocks = $blocks;
        $outData = $data;
        $changed = false;
        for ($i = 0; $i < 4096; $i++) {
            $id = ord($blocks[$i]);
            $rule = $rules[$id] ?? null;
            if ($rule === null) {
                continue;
            }
            $meta = ord($data[$i]);
            switch ($rule[0]) {
                case 0: // fixed [id, meta] replacement
                    if ($id !== $rule[1] || $meta !== $rule[2]) {
                        $outBlocks[$i] = chr($rule[1]);
                        $outData[$i] = chr($rule[2]);
                        $changed = true;
                    }
                    break;
                case 1: // mask meta
                    $masked = $meta & $rule[1];
                    if ($masked !== $meta) {
                        $outData[$i] = chr($masked);
                        $changed = true;
                    }
                    break;
                case 2: // log axis normalization
                    if (($meta & 0x0C) === 0x0C) {
                        $outData[$i] = chr($meta & 0x03);
                        $changed = true;
                    }
                    break;
            }
        }
        return [$outBlocks, $outData, $changed];
    }

    /** @return array<int, array{0:int,1:int,2:int}|null> */
    private static function numericRules(): array {
        if (self::$numericRuleCache !== null) {
            return self::$numericRuleCache;
        }
        $valid = [];
        foreach (self::PE_VALID_RANGES as $range) {
            if (str_contains($range, '-')) {
                [$lo, $hi] = explode('-', $range, 2);
                for ($id = (int)$lo; $id <= (int)$hi; $id++) {
                    $valid[$id] = true;
                }
            } else {
                $valid[(int)$range] = true;
            }
        }
        $rules = [];
        for ($id = 0; $id < 256; $id++) {
            if (isset(self::JAVA_ONLY_REMAP[$id])) {
                $to = self::JAVA_ONLY_REMAP[$id];
                $rules[$id] = [0, $to[0], $to[1]];
            } elseif (isset(self::NUMERIC_META_FIX[$id])) {
                $fix = self::NUMERIC_META_FIX[$id];
                $rules[$id] = [$fix[0], $fix[1], 0];
            } elseif (!isset($valid[$id])) {
                $rules[$id] = [0, 1, 0]; // stone floor for unknown ids
            }
        }
        return self::$numericRuleCache = $rules;
    }

    // --- 1.13+ palette decoding --------------------------------------------

    /**
     * Decode a packed palette section into raw blocks/data bytes.
     *
     * $states: one entry per palette slot as [block name, properties].
     * $longs: the raw signed 64-bit words of BlockStates/data (a word's bit
     * 0 is the least significant stored index bit).
     * $continuous: pre-1.16 packing lets indices straddle word boundaries;
     * 1.16+ never straddles (values restart at the next word).
     *
     * @param array<int, array{0: string, 1: array<string, string>}> $states
     * @param int[] $longs
     * @return array{0: string, 1: string, 2: bool} [blocks, data, hadUnknown]
     */
    public static function decodePaletteSection(array $states, array $longs, bool $continuous): array {
        $count = count($states);
        if ($count === 0) {
            $air = str_repeat("\x00", 4096);
            return [$air, $air, false];
        }

        $bits = 4;
        if ($count > 1) {
            $bits = 1;
            while ((1 << $bits) < $count) {
                $bits++;
            }
            if ($bits < 4) {
                $bits = 4;
            }
        }

        $resolved = [];
        $hadUnknown = false;
        foreach ($states as $entry) {
            [$id, $meta] = self::nameToState($entry[0], $entry[1]);
            $resolved[] = [$id, $meta];
            if ($id === 1 && !in_array($entry[0], ['stone', 'minecraft:stone'], true)) {
                $hadUnknown = true;
            }
        }

        $blocks = str_repeat("\x00", 4096);
        $data = str_repeat("\x00", 4096);

        // A single-state palette omits the data buffer entirely.
        if ($count === 1) {
            [$id, $meta] = $resolved[0];
            $idByte = chr($id);
            $metaByte = chr($meta);
            for ($i = 0; $i < 4096; $i++) {
                $blocks[$i] = $idByte;
                $data[$i] = $metaByte;
            }
            return [$blocks, $data, $hadUnknown];
        }

        if ($continuous) {
            // Pre-1.16: one uninterrupted little-endian bit stream across
            // the whole word array (values may straddle word boundaries).
            $totalBits = count($longs) * 64;
            for ($k = 0; $k < 4096 && $k * $bits + $bits <= $totalBits; $k++) {
                $idx = self::peekBits($longs, $k * $bits, $bits);
                $state = $resolved[$idx] ?? $resolved[0];
                $blocks[$k] = chr($state[0]);
                $data[$k] = chr($state[1]);
            }
            return [$blocks, $data, $hadUnknown];
        }

        // 1.16+: values never straddle a word. Each 64-bit word holds
        // floor(64 / bits) values packed from bit 0; the trailing
        // 64 % bits bits of each word are unused.
        $perWord = intdiv(64, $bits);
        $totalWords = count($longs);
        for ($k = 0; $k < 4096; $k++) {
            $word = intdiv($k, $perWord);
            if ($word >= $totalWords) {
                break; // truncated buffer: leave the rest as air
            }
            $off = ($k % $perWord) * $bits;
            $idx = self::peekBits($longs, $word * 64 + $off, $bits);
            $state = $resolved[$idx] ?? $resolved[0];
            $blocks[$k] = chr($state[0]);
            $data[$k] = chr($state[1]);
        }
        return [$blocks, $data, $hadUnknown];
    }

    /**
     * Read $width bits (<= 32) starting at global bit position $bitIndex of
     * the little-endian 64-bit word stream, crossing word boundaries as
     * needed. Words are signed PHP ints (bit 63 shows negative), so each is
     * split into two unsigned 32-bit halves before shifting.
     *
     * @param int[] $longs
     */
    public static function peekBits(array $longs, int $bitIndex, int $width): int {
        $result = 0;
        $got = 0;
        while ($width > 0) {
            $word = intdiv($bitIndex, 64);
            if (!isset($longs[$word])) {
                return $result;
            }
            $off = $bitIndex & 63;
            $take = min($width, 64 - $off);
            $v = $longs[$word];
            $hi = ($v >> 32) & 0xFFFFFFFF;
            $lo = $v & 0xFFFFFFFF;
            if ($off + $take <= 32) {
                $chunk = ($lo >> $off) & ((1 << $take) - 1);
            } elseif ($off >= 32) {
                $chunk = ($hi >> ($off - 32)) & ((1 << $take) - 1);
            } else {
                $fromHi = $off + $take - 32; // straddles the two halves
                $chunk = (($lo >> $off)
                    | ((($hi & ((1 << $fromHi) - 1)) << (32 - $off)) & 0xFFFFFFFF))
                    & ((1 << $take) - 1);
            }
            $result |= $chunk << $got;
            $got += $take;
            $bitIndex += $take;
            $width -= $take;
        }
        return $result;
    }

    // --- 1.13+ name table ---------------------------------------------------

    /** @var array<string, int> dye color name => 0.15 color meta (wool order) */
    private const COLORS = [
        'white' => 0, 'orange' => 1, 'magenta' => 2, 'light_blue' => 3,
        'yellow' => 4, 'lime' => 5, 'pink' => 6, 'gray' => 7,
        'light_gray' => 8, 'cyan' => 9, 'purple' => 10, 'blue' => 11,
        'brown' => 12, 'green' => 13, 'red' => 14, 'black' => 15,
    ];

    /** @var array<string, int> wood name => type meta (planks/slabs/logs) */
    private const WOODS = [
        'oak' => 0, 'spruce' => 1, 'birch' => 2, 'jungle' => 3,
        'acacia' => 4, 'dark_oak' => 5,
    ];

    private const COLORED_RE = '/^(white|orange|magenta|light_blue|yellow|lime|pink|gray|light_gray|cyan|purple|blue|brown|red|black)_(\w+)$/';
    private const WOOD_RE = '/^(oak|spruce|birch|jungle|acacia|dark_oak|crimson|warped|mangrove|cherry|bamboo)_(\w+)$/';
    private const STRIPPED_WOOD_RE = '/^stripped_(oak|spruce|birch|jungle|acacia|dark_oak|crimson|warped|mangrove|cherry)_(log|wood|stem|hyphae)$/';

    /**
     * Resolve a 1.13+ block resource name (plus properties) to a PE 0.15
     * [id, meta]. Never fails: unmapped names become stone so the chunk can
     * always be rendered.
     *
     * @param array<string, string> $props
     * @return array{0: int, 1: int}
     */
    public static function nameToState(string $name, array $props = []): array {
        $name = strtolower($name);
        if (str_contains($name, ':')) {
            $name = substr($name, strpos($name, ':') + 1);
        }

        // Colored families: <color>_<family>.
        if (preg_match(self::COLORED_RE, $name, $m) === 1) {
            $color = self::COLORS[$m[1]];
            switch ($m[2]) {
                case 'wool':
                    return [35, $color];
                case 'carpet':
                    return [171, $color];
                case 'terracotta':
                case 'stained_hardened_clay':
                case 'glazed_terracotta':
                case 'concrete':
                case 'concrete_powder':
                    return [159, $color]; // stained clay stands in
                case 'stained_glass':
                    return [20, 0]; // PE 0.15 has plain glass only
                case 'stained_glass_pane':
                    return [102, 0];
                case 'shulker_box':
                    return [45, 0];
                case 'bed':
                    return [26, $color];
                case 'banner':
                    return [0, 0];
            }
        }
        if ($name === 'terracotta') {
            return [172, 0]; // plain terracotta == Java 172 hardened clay
        }
        // Un-flattened single names with a color property (defensive).
        if ($name === 'wool') {
            return [35, self::COLORS[$props['color'] ?? 'white'] ?? 0];
        }
        if ($name === 'carpet') {
            return [171, self::COLORS[$props['color'] ?? 'white'] ?? 0];
        }

        // Wood families: <wood>_<part>. Names that start with a color (e.g.
        // red_mushroom, brown_mushroom, blackstone) fall through here only
        // when they are not colored blocks - safe, their families differ.
        if (preg_match(self::WOOD_RE, $name, $m) === 1) {
            $wood = $m[1];
            $type = self::WOODS[$wood] ?? 0;
            $second = in_array($wood, ['acacia', 'dark_oak'], true);
            switch ($m[2]) {
                case 'planks':
                    return [5, $type];
                case 'log':
                case 'wood':
                case 'stem':
                case 'hyphae':
                    return [$second ? 162 : 17, $type | self::logAxisMeta($props)];
                case 'leaves':
                    return [$second ? 161 : 18, $type];
                case 'slab':
                    $meta = $type;
                    if (($props['type'] ?? 'bottom') === 'top') {
                        $meta |= 8;
                    }
                    return [158, $meta];
                case 'double_slab':
                    return [157, $type];
                case 'stairs':
                    $id = ['oak' => 53, 'spruce' => 134, 'birch' => 135, 'jungle' => 136, 'acacia' => 163, 'dark_oak' => 164][$wood] ?? 53;
                    return [$id, self::stairMeta($props)];
                case 'fence':
                    return [85, 0];
                case 'fence_gate':
                    $id = ['oak' => 107, 'spruce' => 183, 'birch' => 184, 'jungle' => 185, 'dark_oak' => 186, 'acacia' => 187][$wood] ?? 107;
                    return [$id, 0];
                case 'door':
                    $id = ['oak' => 64, 'spruce' => 193, 'birch' => 194, 'jungle' => 195, 'acacia' => 196, 'dark_oak' => 197][$wood] ?? 64;
                    return [$id, 0];
                case 'trapdoor':
                    return [96, 0];
                case 'button':
                    return [143, 0];
                case 'pressure_plate':
                    return [72, 0];
                case 'sign':
                    return [63, 0];
                case 'wall_sign':
                    return [68, 0];
                case 'sapling':
                    return [6, $type];
            }
        }
        if (preg_match(self::STRIPPED_WOOD_RE, $name, $m) === 1) {
            $type = self::WOODS[$m[1]] ?? 0;
            $second = in_array($m[1], ['acacia', 'dark_oak'], true);
            return [$second ? 162 : 17, $type | self::logAxisMeta($props)];
        }

        return self::fromTable($name, $props);
    }

    /**
     * @param array<string, string> $props
     * @return array{0: int, 1: int}
     */
    private static function fromTable(string $name, array $props): array {
        $table = self::nameTable();
        if (isset($table[$name])) {
            $entry = $table[$name];
            if ($entry[0] === 'stairs') {
                return [$entry[1], self::stairMeta($props)];
            }
            if ($entry[0] === 'slab') {
                return self::slabState($entry[1], $entry[2], $entry[3], $props);
            }
            return [$entry[1], $entry[2]];
        }

        // Flower pots: any potted plant renders as the pot itself.
        if (str_starts_with($name, 'potted_')) {
            return [140, 0];
        }

        // Double plants: the upper half is a separate block state.
        $double = ['sunflower' => 0, 'lilac' => 1, 'tall_grass' => 2, 'large_fern' => 3, 'rose_bush' => 4, 'peony' => 5];
        if (isset($double[$name])) {
            return [175, $double[$name] | (($props['half'] ?? 'lower') === 'upper' ? 8 : 0)];
        }

        // "minecraft:air"-only leftovers and interior worldgen states.
        if ($name === 'air' || $name === 'cave_air' || $name === 'void_air') {
            return [0, 0];
        }

        self::logUnknown($name);
        return [1, 0]; // stone
    }

    /**
     * Slab states carry their material meta so top/bottom/double resolve
     * to real PE blocks: single id + meta (+8 for top), or the double id.
     *
     * @param array<string, string> $props
     * @return array{0: int, 1: int}
     */
    private static function slabState(int $singleId, int $material, int $doubleId, array $props): array {
        $type = $props['type'] ?? 'bottom';
        if ($type === 'double') {
            return [$doubleId, $material];
        }
        return [$singleId, $type === 'top' ? $material | 8 : $material];
    }

    /** Java 1.13 stair facing/half -> legacy numeric meta (PE shares it). */
    private static function stairMeta(array $props): int {
        $facing = strtolower((string)($props['facing'] ?? 'north'));
        $meta = ['east' => 0, 'west' => 1, 'south' => 2, 'north' => 3][$facing] ?? 0;
        if (($props['half'] ?? 'bottom') === 'top') {
            $meta |= 0x04;
        }
        return $meta;
    }

    /** Java 1.13 log axis -> legacy numeric axis bits (x=4, z=8, y=0). */
    private static function logAxisMeta(array $props): int {
        $axis = strtolower((string)($props['axis'] ?? 'y'));
        return match ($axis) {
            'x' => 4,
            'z' => 8,
            default => 0,
        };
    }

    /**
     * Direct name table. Entries are:
     *   ['b', id, meta]               fixed state
     *   ['stairs', id]                meta from facing/half properties
     *   ['slab', singleId, material, doubleId]  meta from type property
     *
     * @return array<string, array{0: string, 1: int, 2: int, 3: int}>
     */
    private static function nameTable(): array {
        if (self::$nameTableCache !== null) {
            return self::$nameTableCache;
        }
        $t = [];
        $put = static function (string $name, int $id, int $meta = 0) use (&$t): void {
            $t[$name] = ['b', $id, $meta, 0];
        };

        // Natural terrain.
        $put('stone', 1); $put('granite', 1); $put('diorite', 1); $put('andesite', 1);
        $put('polished_granite', 1); $put('polished_diorite', 1); $put('polished_andesite', 1);
        $put('deepslate', 1); $put('cobbled_deepslate', 4); $put('polished_deepslate', 4);
        $put('grass_block', 2); $put('podzol', 243);
        $put('dirt', 3); $put('coarse_dirt', 3); $put('rooted_dirt', 3);
        $put('mud', 3); $put('muddy_mangrove_roots', 3);
        $put('cobblestone', 4); $put('mossy_cobblestone', 48);
        $put('bedrock', 7);
        $put('sand', 12); $put('red_sand', 12, 1); $put('gravel', 13);
        $put('sandstone', 24); $put('chiseled_sandstone', 24, 1); $put('cut_sandstone', 24, 2);
        $put('smooth_sandstone', 24, 3);
        $put('red_sandstone', 179); $put('chiseled_red_sandstone', 179, 1);
        $put('cut_red_sandstone', 179, 2); $put('smooth_red_sandstone', 179, 3);
        $put('clay', 82);
        $put('dripstone_block', 1); $put('pointed_dripstone', 1);
        $put('calcite', 1); $put('tuff', 4); $put('tuff_bricks', 4);
        $put('ice', 79); $put('packed_ice', 174); $put('blue_ice', 174); $put('frosted_ice', 79);
        $put('moss_block', 82); $put('moss_carpet', 171, 13);
        $put('snow', 78); $put('snow_block', 80); $put('powder_snow', 78);

        // Bricks / stone bricks / quartzes.
        $put('stone_bricks', 98); $put('mossy_stone_bricks', 98, 1);
        $put('cracked_stone_bricks', 98, 2); $put('chiseled_stone_bricks', 98, 3);
        $put('bricks', 45); $put('mud_bricks', 45);
        $put('nether_bricks', 112); $put('red_nether_bricks', 112);
        $put('cracked_nether_bricks', 112); $put('chiseled_nether_bricks', 112);
        $put('quartz_block', 155); $put('chiseled_quartz_block', 155, 1);
        $put('quartz_pillar', 155, 2); $put('quartz_bricks', 155); $put('smooth_quartz', 155);
        $put('purpur_block', 121); $put('purpur_pillar', 121);
        $put('end_stone', 121); $put('end_stone_bricks', 121);
        $put('prismarine', 98); $put('prismarine_bricks', 98); $put('dark_prismarine', 98, 1);
        $put('sea_lantern', 89); $put('magma_block', 87);

        // Ores & minerals.
        $put('coal_ore', 16); $put('deepslate_coal_ore', 16);
        $put('iron_ore', 15); $put('deepslate_iron_ore', 15);
        $put('gold_ore', 14); $put('deepslate_gold_ore', 14); $put('nether_gold_ore', 14);
        $put('diamond_ore', 56); $put('deepslate_diamond_ore', 56);
        $put('emerald_ore', 129); $put('deepslate_emerald_ore', 129);
        $put('redstone_ore', 73); $put('deepslate_redstone_ore', 73);
        $put('lapis_ore', 21); $put('deepslate_lapis_ore', 21);
        $put('copper_ore', 14); $put('deepslate_copper_ore', 14); $put('raw_copper_block', 42);
        $put('nether_quartz_ore', 153); $put('ancient_debris', 49);
        $put('coal_block', 173); $put('iron_block', 42); $put('gold_block', 41);
        $put('diamond_block', 57); $put('emerald_block', 133); $put('lapis_block', 22);
        $put('redstone_block', 152); $put('netherite_block', 49);
        $put('copper_block', 42); $put('exposed_copper', 42); $put('weathered_copper', 42);
        $put('oxidized_copper', 42); $put('cut_copper', 42);
        $put('amethyst_block', 1); $put('budding_amethyst', 1);
        $put('raw_iron_block', 15); $put('raw_gold_block', 14);

        // Wood-ish decoration & furniture.
        $put('bookshelf', 47); $put('chiseled_bookshelf', 47);
        $put('crafting_table', 58); $put('crafter', 58);
        $put('barrel', 54); $put('chest', 54); $put('trapped_chest', 146);
        $put('ender_chest', 49);
        $put('fletching_table', 58); $put('smithing_table', 58);
        $put('cartography_table', 58); $put('loom', 58); $put('composter', 58);
        $put('stonecutter', 245);
        $put('beehive', 47); $put('bee_nest', 47); $put('honeycomb_block', 5);
        $put('honey_block', 5); $put('slime_block', 165);
        $put('hay_block', 170); $put('target', 170);
        $put('jukebox', 25); $put('noteblock', 25); $put('note_block', 25);
        $put('sponge', 19); $put('wet_sponge', 19); $put('dried_kelp_block', 82);
        $put('glowstone', 89); $put('shroomlight', 89); $put('jack_o_lantern', 91);
        $put('lantern', 89); $put('soul_lantern', 89);
        $put('campfire', 51); $put('soul_campfire', 51);
        $put('candle', 50); $put('sea_pickle', 50);
        $put('ochre_froglight', 89); $put('verdant_froglight', 89); $put('pearlescent_froglight', 89);
        $put('tnt', 46);
        $put('obsidian', 49); $put('crying_obsidian', 49);
        $put('respawn_anchor', 49); $put('reinforced_deepslate', 49);
        $put('blackstone', 4); $put('gilded_blackstone', 4); $put('polished_blackstone', 4);
        $put('polished_blackstone_bricks', 4); $put('cracked_polished_blackstone_bricks', 4);
        $put('chiseled_polished_blackstone', 4);
        $put('basalt', 4); $put('polished_basalt', 4); $put('smooth_basalt', 4);
        $put('netherrack', 87); $put('soul_sand', 88); $put('soul_soil', 88);
        $put('nether_wart_block', 115); $put('warped_wart_block', 115);

        // Slabs (property-driven; material meta per family, double id for
        // the full-block variant).
        $slab = static function (string $name, int $single, int $material, int $double) use (&$t): void {
            $t[$name] = ['slab', $single, $material, $double];
        };
        $slab('stone_slab', 44, 0, 43);          $slab('smooth_stone_slab', 44, 0, 43);
        $slab('sandstone_slab', 44, 1, 43);      $slab('cut_sandstone_slab', 44, 1, 43);
        $slab('smooth_sandstone_slab', 44, 1, 43);
        $slab('petrified_oak_slab', 44, 2, 43);
        $slab('cobblestone_slab', 44, 3, 43);    $slab('cobbled_deepslate_slab', 44, 3, 43);
        $slab('mossy_cobblestone_slab', 44, 3, 43);
        $slab('brick_slab', 44, 4, 43);          $slab('mud_brick_slab', 44, 4, 43);
        $slab('stone_brick_slab', 44, 5, 43);    $slab('mossy_stone_brick_slab', 44, 5, 43);
        $slab('nether_brick_slab', 44, 7, 43);
        $slab('quartz_slab', 44, 6, 43);         $slab('smooth_quartz_slab', 44, 6, 43);
        $slab('purpur_slab', 44, 6, 43);
        $slab('red_sandstone_slab', 182, 0, 181); $slab('cut_red_sandstone_slab', 182, 0, 181);
        $slab('smooth_red_sandstone_slab', 182, 0, 181);
        // No 0.15 material for these: fold onto the closest slab texture.
        $slab('prismarine_slab', 44, 5, 43);     $slab('dark_prismarine_slab', 44, 5, 43);
        $slab('prismarine_brick_slab', 44, 5, 43);
        $slab('end_stone_brick_slab', 44, 5, 43);
        $slab('granite_slab', 44, 3, 43);        $slab('polished_granite_slab', 44, 3, 43);
        $slab('diorite_slab', 44, 3, 43);        $slab('polished_diorite_slab', 44, 3, 43);
        $slab('andesite_slab', 44, 3, 43);       $slab('polished_andesite_slab', 44, 3, 43);
        $slab('blackstone_slab', 44, 3, 43);     $slab('polished_blackstone_slab', 44, 3, 43);
        $slab('polished_blackstone_brick_slab', 44, 3, 43);

        // Stairs (meta from facing/half).
        $stairs = static function (string $name, int $id) use (&$t): void {
            $t[$name] = ['stairs', $id, 0, 0];
        };
        $stairs('stone_stairs', 67);             $stairs('cobblestone_stairs', 67);
        $stairs('cobbled_deepslate_stairs', 67); $stairs('mossy_cobblestone_stairs', 67);
        $stairs('granite_stairs', 67);           $stairs('polished_granite_stairs', 67);
        $stairs('diorite_stairs', 67);           $stairs('polished_diorite_stairs', 67);
        $stairs('andesite_stairs', 67);          $stairs('polished_andesite_stairs', 67);
        $stairs('blackstone_stairs', 67);        $stairs('polished_blackstone_stairs', 67);
        $stairs('polished_blackstone_brick_stairs', 67);
        $stairs('brick_stairs', 108);            $stairs('mud_brick_stairs', 108);
        $stairs('stone_brick_stairs', 109);      $stairs('mossy_stone_brick_stairs', 109);
        $stairs('prismarine_stairs', 109);       $stairs('prismarine_brick_stairs', 109);
        $stairs('dark_prismarine_stairs', 109);  $stairs('end_stone_brick_stairs', 109);
        $stairs('nether_brick_stairs', 114);     $stairs('red_nether_brick_stairs', 114);
        $stairs('sandstone_stairs', 128);        $stairs('smooth_sandstone_stairs', 128);
        $stairs('red_sandstone_stairs', 180);    $stairs('smooth_red_sandstone_stairs', 180);
        $stairs('quartz_stairs', 156);           $stairs('smooth_quartz_stairs', 156);
        $stairs('purpur_stairs', 156);

        // Walls / fences / panes / rails.
        $put('cobblestone_wall', 139); $put('mossy_cobblestone_wall', 139);
        $put('stone_brick_wall', 139); $put('mossy_stone_brick_wall', 139);
        $put('brick_wall', 139); $put('mud_brick_wall', 139);
        $put('nether_brick_wall', 113); $put('red_nether_brick_wall', 113);
        $put('sandstone_wall', 139); $put('red_sandstone_wall', 139);
        $put('andesite_wall', 139); $put('diorite_wall', 139); $put('granite_wall', 139);
        $put('end_stone_brick_wall', 139); $put('blackstone_wall', 139);
        $put('polished_blackstone_wall', 139); $put('polished_blackstone_brick_wall', 139);
        $put('glass', 20); $put('glass_pane', 102); $put('iron_bars', 101);
        $put('chain', 101); $put('nether_brick_fence', 113);

        // Functional / mechanical.
        $put('furnace', 61); $put('blast_furnace', 61); $put('smoker', 61);
        $put('dispenser', 23); $put('dropper', 125);
        $put('piston', 33); $put('sticky_piston', 29); $put('piston_head', 34);
        $put('lever', 69); $put('stone_button', 77); $put('polished_blackstone_button', 77);
        $put('stone_pressure_plate', 70); $put('polished_blackstone_pressure_plate', 70);
        $put('light_weighted_pressure_plate', 147); $put('heavy_weighted_pressure_plate', 148);
        $put('iron_door', 71); $put('iron_trapdoor', 167);
        $put('ladder', 65); $put('rail', 66); $put('powered_rail', 27);
        $put('detector_rail', 28); $put('activator_rail', 126);
        $put('redstone_wire', 55); $put('redstone_torch', 76); $put('redstone_wall_torch', 76);
        $put('repeater', 93); $put('comparator', 149);
        $put('daylight_detector', 151); $put('redstone_lamp', 123);
        $put('observer', 251); $put('tripwire_hook', 131); $put('tripwire', 132);
        $put('hopper', 154); $put('cauldron', 118); $put('water_cauldron', 118);
        $put('brewing_stand', 117); $put('enchanting_table', 116);
        $put('anvil', 145); $put('chipped_anvil', 145); $put('damaged_anvil', 145);
        $put('grindstone', 1);
        $put('torch', 50); $put('wall_torch', 50); $put('soul_torch', 50);
        $put('soul_wall_torch', 50);
        $put('fire', 51); $put('soul_fire', 51);
        $put('spawner', 52); $put('mob_spawner', 52); $put('trial_spawner', 52); $put('vault', 52);
        $put('end_portal_frame', 49); $put('end_portal', 90); $put('end_gateway', 49);
        $put('dragon_egg', 49); $put('conduit', 89); $put('beacon', 89);
        $put('lightning_rod', 42); $put('bell', 41);
        $put('sculk', 49); $put('sculk_catalyst', 49); $put('sculk_sensor', 49);
        $put('sculk_shrieker', 49); $put('sculk_vein', 49);
        $put('decorated_pot', 82); $put('sniffer_egg', 49);
        $put('infested_stone', 1); $put('infested_cobblestone', 4);
        $put('infested_stone_bricks', 98); $put('infested_mossy_stone_bricks', 98, 1);
        $put('infested_cracked_stone_bricks', 98, 2); $put('infested_chiseled_stone_bricks', 98, 3);
        $put('infested_deepslate', 1);
        $put('moving_piston', 34); $put('structure_block', 1);
        $put('structure_void', 0); $put('jigsaw', 1); $put('barrier', 0); $put('light', 0);

        // Plants.
        $put('grass', 31); $put('fern', 31, 2); $put('dead_bush', 32);
        $put('dandelion', 37); $put('poppy', 38); $put('blue_orchid', 38);
        $put('allium', 38); $put('azure_bluet', 38); $put('red_tulip', 38);
        $put('orange_tulip', 38); $put('white_tulip', 38); $put('pink_tulip', 38);
        $put('oxeye_daisy', 38); $put('cornflower', 38); $put('lily_of_the_valley', 38);
        $put('wither_rose', 38);
        $put('brown_mushroom', 39); $put('red_mushroom', 40);
        $put('warped_fungus', 39); $put('crimson_fungus', 40);
        $put('warped_roots', 31); $put('crimson_roots', 31); $put('nether_sprouts', 31);
        $put('weeping_vines', 106); $put('twisting_vines', 106);
        $put('vines', 106); $put('cave_vines', 106); $put('glow_lichen', 106);
        $put('lily_pad', 111);
        $put('cactus', 81); $put('sugar_cane', 83);
        $put('kelp', 0); $put('kelp_plant', 0); $put('seagrass', 0); $put('tall_seagrass', 0);
        $put('wheat', 59); $put('carrots', 141); $put('potatoes', 142);
        $put('beetroots', 244); $put('melon', 103); $put('pumpkin', 86);
        $put('carved_pumpkin', 86);
        $put('pumpkin_stem', 104); $put('melon_stem', 105);
        $put('attached_pumpkin_stem', 104); $put('attached_melon_stem', 105);
        $put('cocoa', 127); $put('sweet_berry_bush', 31); $put('nether_wart', 115);
        $put('chorus_plant', 0); $put('chorus_flower', 0);
        foreach ([
            'tube', 'brain', 'bubble', 'fire', 'horn',
        ] as $coral) {
            $put("{$coral}_coral", 0); $put("{$coral}_coral_fan", 0);
            $put("{$coral}_coral_wall_fan", 0);
            $put("dead_{$coral}_coral", 0); $put("dead_{$coral}_coral_fan", 0);
            $put("dead_{$coral}_coral_wall_fan", 0);
        }
        $put('coral_block', 1); $put('dead_coral_block', 1);
        $put('tube_coral_block', 1); $put('brain_coral_block', 1);
        $put('bubble_coral_block', 1); $put('fire_coral_block', 1);
        $put('horn_coral_block', 1);
        $put('dead_tube_coral_block', 1); $put('dead_brain_coral_block', 1);
        $put('dead_bubble_coral_block', 1); $put('dead_fire_coral_block', 1);
        $put('dead_horn_coral_block', 1);

        // Heads / skulls / pots.
        $put('skeleton_skull', 144); $put('wither_skeleton_skull', 144);
        $put('zombie_head', 144); $put('player_head', 144); $put('creeper_head', 144);
        $put('dragon_head', 144); $put('piglin_head', 144);
        $put('skeleton_wall_skull', 144); $put('wither_skeleton_wall_skull', 144);
        $put('zombie_wall_head', 144); $put('player_wall_head', 144);
        $put('creeper_wall_head', 144); $put('dragon_wall_head', 144);
        $put('piglin_wall_head', 144);
        $put('flower_pot', 140);

        // Fluid-ish states.
        $put('water', 8); $put('flowing_water', 8); $put('lava', 10); $put('flowing_lava', 10);
        $put('bubble_column', 8);
        $put('cake', 92);
        $put('mangrove_roots', 17, 0); $put('azalea', 31); $put('flowering_azalea', 31);
        $put('azalea_leaves', 18, 0); $put('flowering_azalea_leaves', 18, 0);
        $put('big_dripleaf', 31); $put('small_dripleaf', 31); $put('spore_blossom', 38);
        $put('hanging_roots', 106); $put('frogspawn', 0);
        $put('torchflower', 38); $put('torchflower_crop', 38); $put('pitcher_plant', 175);
        $put('pitcher_crop', 175);
        $put('suspicious_sand', 12); $put('suspicious_gravel', 13);

        // Bamboo-family names the wood regex already handles are fine; the
        // crop block needs an explicit slot.
        $put('bamboo', 85);

        return self::$nameTableCache = $t;
    }

    private static function logUnknown(string $name): void {
        static $logged = 0;
        if ($logged < 25 && getenv('KHRONOS_DEBUG_IMPORT') !== false && getenv('KHRONOS_DEBUG_IMPORT') !== '0') {
            error_log("[java-import] unmapped block '$name' -> stone");
            $logged++;
        }
    }
}
