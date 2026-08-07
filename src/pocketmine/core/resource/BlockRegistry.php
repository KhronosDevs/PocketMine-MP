<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;

/**
 * Static block property registry (protocol 84 era block IDs).
 *
 * Holds the data-oriented block property tables — hardness, blast
 * resistance, light, opacity, flammability, required tool — as plain
 * data. Systems and API facades read from this registry; they never
 * hard-code block behavior.
 */
#[Resource]
final class BlockRegistry {
    /** Defaults used for any block not present in the table. */
    private const DEFAULTS = [
        'name' => 'Unknown',
        'hardness' => 1.0,
        'resistance' => 1.0,
        'light' => 0,
        'opacity' => 15,
        'flammable' => false,
        'flamability' => 0,
        'burnTime' => 0,
        'tool' => 'hand',
        'toolLevel' => 0,
        'solid' => true,
        'transparent' => false,
        'replaceable' => false,
        'silkTouch' => true,
        'xp' => 0,
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private const BLOCKS = [
        0 => ['name' => 'Air', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => true],
        1 => ['name' => 'Stone', 'hardness' => 1.5, 'resistance' => 30.0, 'tool' => 'pickaxe', 'toolLevel' => 1],
        2 => ['name' => 'Grass', 'hardness' => 0.6, 'resistance' => 3.0, 'tool' => 'shovel'],
        3 => ['name' => 'Dirt', 'hardness' => 0.5, 'resistance' => 3.0, 'tool' => 'shovel'],
        4 => ['name' => 'Cobblestone', 'hardness' => 2.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        5 => ['name' => 'Planks', 'hardness' => 2.0, 'resistance' => 15.0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        6 => ['name' => 'Sapling', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 60, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        7 => ['name' => 'Bedrock', 'hardness' => -1.0, 'resistance' => 3600000.0, 'silkTouch' => false],
        8 => ['name' => 'Water', 'hardness' => 100.0, 'resistance' => 500.0, 'opacity' => 1, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        9 => ['name' => 'Water', 'hardness' => 100.0, 'resistance' => 500.0, 'opacity' => 1, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        10 => ['name' => 'Lava', 'hardness' => 100.0, 'resistance' => 500.0, 'opacity' => 0, 'light' => 15, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        11 => ['name' => 'Lava', 'hardness' => 100.0, 'resistance' => 500.0, 'opacity' => 0, 'light' => 15, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        12 => ['name' => 'Sand', 'hardness' => 0.5, 'resistance' => 3.0, 'tool' => 'shovel'],
        13 => ['name' => 'Gravel', 'hardness' => 0.6, 'resistance' => 3.0, 'tool' => 'shovel'],
        14 => ['name' => 'Gold Ore', 'hardness' => 3.0, 'resistance' => 15.0, 'tool' => 'pickaxe', 'toolLevel' => 2, 'xp' => 3],
        15 => ['name' => 'Iron Ore', 'hardness' => 3.0, 'resistance' => 15.0, 'tool' => 'pickaxe', 'toolLevel' => 1, 'xp' => 1],
        16 => ['name' => 'Coal Ore', 'hardness' => 3.0, 'resistance' => 15.0, 'tool' => 'pickaxe', 'xp' => 1],
        17 => ['name' => 'Log', 'hardness' => 2.0, 'resistance' => 15.0, 'flammable' => true, 'flamability' => 5, 'burnTime' => 5, 'tool' => 'axe'],
        18 => ['name' => 'Leaves', 'hardness' => 0.2, 'resistance' => 1.0, 'opacity' => 1, 'flammable' => true, 'flamability' => 30, 'burnTime' => 5, 'tool' => 'shears', 'silkTouch' => true],
        19 => ['name' => 'Sponge', 'hardness' => 0.6, 'resistance' => 3.0],
        20 => ['name' => 'Glass', 'hardness' => 0.3, 'resistance' => 1.5, 'opacity' => 0, 'transparent' => true, 'silkTouch' => true],
        21 => ['name' => 'Lapis Lazuli Ore', 'hardness' => 3.0, 'resistance' => 15.0, 'tool' => 'pickaxe', 'toolLevel' => 1, 'xp' => 3],
        22 => ['name' => 'Lapis Lazuli Block', 'hardness' => 3.0, 'resistance' => 15.0, 'tool' => 'pickaxe'],
        23 => ['name' => 'Dispenser', 'hardness' => 3.5, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        24 => ['name' => 'Sandstone', 'hardness' => 0.8, 'resistance' => 4.0, 'tool' => 'pickaxe'],
        25 => ['name' => 'Note Block', 'hardness' => 0.8, 'resistance' => 4.0, 'tool' => 'axe'],
        26 => ['name' => 'Bed', 'hardness' => 0.2, 'resistance' => 1.0, 'solid' => false, 'transparent' => true],
        27 => ['name' => 'Powered Rail', 'hardness' => 0.7, 'resistance' => 3.0, 'solid' => false, 'transparent' => true, 'replaceable' => true],
        28 => ['name' => 'Detector Rail', 'hardness' => 0.7, 'resistance' => 3.0, 'solid' => false, 'transparent' => true, 'replaceable' => true],
        29 => ['name' => 'Sticky Piston', 'hardness' => 0.5, 'resistance' => 3.0, 'solid' => false, 'transparent' => true],
        30 => ['name' => 'Cobweb', 'hardness' => 4.0, 'resistance' => 20.0, 'tool' => 'sword', 'silkTouch' => false],
        31 => ['name' => 'Tall Grass', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 60, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        32 => ['name' => 'Dead Bush', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 60, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        33 => ['name' => 'Piston', 'hardness' => 0.5, 'resistance' => 3.0, 'solid' => false, 'transparent' => true],
        34 => ['name' => 'Piston Head', 'hardness' => 0.5, 'resistance' => 3.0, 'solid' => false, 'transparent' => true],
        35 => ['name' => 'Wool', 'hardness' => 0.8, 'resistance' => 4.0, 'flammable' => true, 'flamability' => 30, 'burnTime' => 5, 'tool' => 'shears'],
        37 => ['name' => 'Dandelion', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        38 => ['name' => 'Poppy', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        39 => ['name' => 'Brown Mushroom', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'light' => 1, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        40 => ['name' => 'Red Mushroom', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        41 => ['name' => 'Gold Block', 'hardness' => 3.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        42 => ['name' => 'Iron Block', 'hardness' => 5.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        43 => ['name' => 'Double Stone Slab', 'hardness' => 2.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        44 => ['name' => 'Stone Slab', 'hardness' => 2.0, 'resistance' => 30.0, 'opacity' => 0, 'transparent' => true, 'tool' => 'pickaxe'],
        45 => ['name' => 'Bricks', 'hardness' => 2.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        46 => ['name' => 'TNT', 'hardness' => 0.0, 'resistance' => 0.0, 'flammable' => true, 'flamability' => 15, 'burnTime' => 5],
        47 => ['name' => 'Bookshelf', 'hardness' => 1.5, 'resistance' => 7.5, 'flammable' => true, 'flamability' => 30, 'burnTime' => 5, 'tool' => 'axe'],
        48 => ['name' => 'Mossy Cobblestone', 'hardness' => 2.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        49 => ['name' => 'Obsidian', 'hardness' => 50.0, 'resistance' => 6000.0, 'tool' => 'pickaxe', 'toolLevel' => 3],
        50 => ['name' => 'Torch', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'light' => 14, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        51 => ['name' => 'Fire', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'light' => 15, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        52 => ['name' => 'Mob Spawner', 'hardness' => 5.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        53 => ['name' => 'Oak Wood Stairs', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        54 => ['name' => 'Chest', 'hardness' => 2.5, 'resistance' => 12.5, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        55 => ['name' => 'Redstone Wire', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        56 => ['name' => 'Diamond Ore', 'hardness' => 3.0, 'resistance' => 15.0, 'tool' => 'pickaxe', 'toolLevel' => 2, 'xp' => 4],
        57 => ['name' => 'Diamond Block', 'hardness' => 5.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        58 => ['name' => 'Crafting Table', 'hardness' => 2.5, 'resistance' => 12.5, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        59 => ['name' => 'Wheat', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        60 => ['name' => 'Farmland', 'hardness' => 0.6, 'resistance' => 3.0, 'tool' => 'shovel'],
        61 => ['name' => 'Furnace', 'hardness' => 3.5, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        62 => ['name' => 'Furnace', 'hardness' => 3.5, 'resistance' => 30.0, 'light' => 13, 'tool' => 'pickaxe'],
        63 => ['name' => 'Sign', 'hardness' => 1.0, 'resistance' => 5.0, 'solid' => false, 'transparent' => true, 'silkTouch' => false],
        64 => ['name' => 'Wooden Door', 'hardness' => 3.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        65 => ['name' => 'Ladder', 'hardness' => 0.4, 'resistance' => 2.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'tool' => 'axe'],
        66 => ['name' => 'Rail', 'hardness' => 0.7, 'resistance' => 3.0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        67 => ['name' => 'Cobblestone Stairs', 'hardness' => 2.0, 'resistance' => 30.0, 'opacity' => 0, 'tool' => 'pickaxe'],
        68 => ['name' => 'Wall Sign', 'hardness' => 1.0, 'resistance' => 5.0, 'solid' => false, 'transparent' => true, 'silkTouch' => false],
        69 => ['name' => 'Lever', 'hardness' => 0.5, 'resistance' => 2.5, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        70 => ['name' => 'Stone Pressure Plate', 'hardness' => 0.5, 'resistance' => 2.5, 'solid' => false, 'transparent' => true, 'tool' => 'pickaxe'],
        71 => ['name' => 'Iron Door', 'hardness' => 5.0, 'resistance' => 30.0, 'opacity' => 0, 'tool' => 'pickaxe'],
        72 => ['name' => 'Wooden Pressure Plate', 'hardness' => 0.5, 'resistance' => 2.5, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'solid' => false, 'transparent' => true],
        73 => ['name' => 'Redstone Ore', 'hardness' => 3.0, 'resistance' => 15.0, 'tool' => 'pickaxe', 'toolLevel' => 2, 'xp' => 2],
        74 => ['name' => 'Redstone Ore', 'hardness' => 3.0, 'resistance' => 15.0, 'light' => 9, 'tool' => 'pickaxe', 'toolLevel' => 2, 'xp' => 2],
        75 => ['name' => 'Redstone Torch', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'light' => 7, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        76 => ['name' => 'Redstone Torch', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'light' => 7, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        77 => ['name' => 'Stone Button', 'hardness' => 0.5, 'resistance' => 2.5, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'tool' => 'pickaxe'],
        78 => ['name' => 'Snow Layer', 'hardness' => 0.1, 'resistance' => 0.5, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'tool' => 'shovel'],
        79 => ['name' => 'Ice', 'hardness' => 0.5, 'resistance' => 2.5, 'opacity' => 3, 'transparent' => true, 'silkTouch' => true],
        80 => ['name' => 'Snow Block', 'hardness' => 0.2, 'resistance' => 1.0, 'tool' => 'shovel'],
        81 => ['name' => 'Cactus', 'hardness' => 0.4, 'resistance' => 2.0, 'opacity' => 0, 'solid' => false, 'transparent' => true],
        82 => ['name' => 'Clay Block', 'hardness' => 0.6, 'resistance' => 3.0],
        83 => ['name' => 'Sugar Cane', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        84 => ['name' => 'Jukebox', 'hardness' => 2.0, 'resistance' => 30.0, 'tool' => 'axe'],
        85 => ['name' => 'Fence', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        86 => ['name' => 'Pumpkin', 'hardness' => 1.0, 'resistance' => 5.0, 'solid' => false, 'transparent' => true, 'tool' => 'axe'],
        87 => ['name' => 'Netherrack', 'hardness' => 0.4, 'resistance' => 2.0, 'tool' => 'pickaxe'],
        88 => ['name' => 'Soul Sand', 'hardness' => 0.5, 'resistance' => 2.5, 'tool' => 'shovel'],
        89 => ['name' => 'Glowstone', 'hardness' => 0.3, 'resistance' => 1.5, 'opacity' => 0, 'light' => 15, 'transparent' => true],
        90 => ['name' => 'Nether Portal', 'hardness' => -1.0, 'resistance' => 0.0, 'opacity' => 0, 'light' => 11, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        91 => ['name' => 'Jack o Lantern', 'hardness' => 1.0, 'resistance' => 5.0, 'opacity' => 0, 'light' => 15, 'solid' => false, 'transparent' => true, 'tool' => 'axe'],
        92 => ['name' => 'Cake', 'hardness' => 0.5, 'resistance' => 2.5, 'solid' => false, 'transparent' => true, 'silkTouch' => false],
        93 => ['name' => 'Redstone Repeater', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        94 => ['name' => 'Redstone Repeater', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'light' => 9, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        95 => ['name' => 'Stained Glass', 'hardness' => 0.3, 'resistance' => 1.5, 'opacity' => 0, 'transparent' => true, 'silkTouch' => true],
        97 => ['name' => 'Stone', 'hardness' => 1.5, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        98 => ['name' => 'Stone Bricks', 'hardness' => 1.5, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        103 => ['name' => 'Melon', 'hardness' => 1.0, 'resistance' => 5.0, 'solid' => false, 'transparent' => true, 'tool' => 'axe'],
        106 => ['name' => 'Vines', 'hardness' => 0.2, 'resistance' => 1.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 15, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'silkTouch' => true],
        107 => ['name' => 'Fence Gate', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'tool' => 'axe'],
        108 => ['name' => 'Brick Stairs', 'hardness' => 2.0, 'resistance' => 30.0, 'opacity' => 0, 'tool' => 'pickaxe'],
        110 => ['name' => 'Mycelium', 'hardness' => 0.6, 'resistance' => 3.0, 'tool' => 'shovel'],
        111 => ['name' => 'Lily Pad', 'hardness' => 0.0, 'resistance' => 0.0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        112 => ['name' => 'Nether Brick', 'hardness' => 2.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        113 => ['name' => 'Nether Brick Fence', 'hardness' => 2.0, 'resistance' => 30.0, 'opacity' => 0, 'tool' => 'pickaxe'],
        114 => ['name' => 'Nether Brick Stairs', 'hardness' => 2.0, 'resistance' => 30.0, 'opacity' => 0, 'tool' => 'pickaxe'],
        116 => ['name' => 'Enchanting Table', 'hardness' => 5.0, 'resistance' => 6000.0, 'opacity' => 0, 'transparent' => true, 'tool' => 'pickaxe'],
        120 => ['name' => 'End Portal Frame', 'hardness' => -1.0, 'resistance' => 3600000.0],
        121 => ['name' => 'End Stone', 'hardness' => 3.0, 'resistance' => 45.0, 'tool' => 'pickaxe'],
        129 => ['name' => 'Emerald Ore', 'hardness' => 3.0, 'resistance' => 15.0, 'tool' => 'pickaxe', 'toolLevel' => 2, 'xp' => 4],
        133 => ['name' => 'Emerald Block', 'hardness' => 5.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        137 => ['name' => 'Command Block', 'hardness' => -1.0, 'resistance' => 3600000.0],
    ];

    public function get(int $id): array {
        return array_merge(self::DEFAULTS, self::BLOCKS[$id] ?? []);
    }

    public function getName(int $id): string {
        return $this->get($id)['name'];
    }

    public function getHardness(int $id): float {
        return (float)$this->get($id)['hardness'];
    }

    public function getResistance(int $id): float {
        return (float)$this->get($id)['resistance'];
    }

    public function getLightLevel(int $id): int {
        return (int)$this->get($id)['light'];
    }

    public function getLightOpacity(int $id): int {
        return (int)$this->get($id)['opacity'];
    }

    public function isFlammable(int $id): bool {
        return (bool)$this->get($id)['flammable'];
    }

    public function getFlammability(int $id): int {
        return (int)$this->get($id)['flamability'];
    }

    public function getBurnTime(int $id): int {
        return (int)$this->get($id)['burnTime'];
    }

    public function getToolType(int $id): string {
        return (string)$this->get($id)['tool'];
    }

    public function getToolLevel(int $id): int {
        return (int)$this->get($id)['toolLevel'];
    }

    public function isSolid(int $id): bool {
        return (bool)$this->get($id)['solid'];
    }

    public function isTransparent(int $id): bool {
        return (bool)$this->get($id)['transparent'];
    }

    public function isReplaceable(int $id): bool {
        return (bool)$this->get($id)['replaceable'];
    }

    public function canBeSilkTouched(int $id): bool {
        return (bool)$this->get($id)['silkTouch'];
    }

    public function getExperienceDrop(int $id): int {
        return (int)$this->get($id)['xp'];
    }

    /**
     * Whether the block is breakable at all (bedrock, liquids, fire, air are not).
     */
    public function isBreakable(int $id): bool {
        if ($id <= 0) {
            return false;
        }
        return $this->getHardness($id) >= 0.0;
    }

    /**
     * Whether a block requires the given tool type to be mined efficiently
     * (a wrong tool simply takes longer; the block still breaks).
     */
    public function requiresTool(int $id): bool {
        return $this->getToolType($id) !== 'hand';
    }

    /**
     * @return array<int, array{id: int, meta: int, count: int}> item drops for the block.
     */
    public function getDrops(int $id, bool $silkTouch = false, ?string $tool = null): array {
        if (!$this->isBreakable($id)) {
            return [];
        }

        // Silk touch keeps the block itself.
        if ($silkTouch && $this->canBeSilkTouched($id)) {
            return [['id' => $id, 'meta' => 0, 'count' => 1]];
        }

        // Blocks that always drop an item instead of themselves.
        $specialDrops = match (true) {
            $id === 16 => [['id' => 263, 'meta' => 0, 'count' => 1]],          // coal ore -> coal
            $id === 15 => [['id' => 265, 'meta' => 0, 'count' => 1]],          // iron ore -> iron ingot
            $id === 14 => [['id' => 266, 'meta' => 0, 'count' => 1]],          // gold ore -> gold ingot
            $id === 56 => [['id' => 264, 'meta' => 0, 'count' => 1]],          // diamond ore -> diamond
            $id === 129 => [['id' => 388, 'meta' => 0, 'count' => 1]],         // emerald ore -> emerald
            $id === 73 || $id === 74 => [['id' => 331, 'meta' => 0, 'count' => mt_rand(4, 5)]], // redstone ore -> redstone dust
            $id === 21 => [['id' => 351, 'meta' => 4, 'count' => mt_rand(4, 8)]], // lapis ore -> lapis lazuli
            $id === 89 => [['id' => 348, 'meta' => 0, 'count' => mt_rand(2, 4)]], // glowstone -> glowstone dust
            $id === 20 || $id === 79 || $id === 95 => [],                     // glass/ice/stained glass: nothing without silk touch
            $id === 30 => [['id' => 287, 'meta' => 0, 'count' => 1]],          // cobweb -> string
            $id === 103 => [['id' => 360, 'meta' => 0, 'count' => mt_rand(3, 7)]], // melon -> melon slices
            $id === 18 => $this->leafDrops(),                                  // leaves -> sapling/apple
            $id === 31 => (mt_rand(1, 3) === 1) ? [['id' => 295, 'meta' => 0, 'count' => 1]] : [], // tall grass -> seeds
            $id === 32 => (mt_rand(1, 3) === 1) ? [['id' => 280, 'meta' => 0, 'count' => 1]] : [], // dead bush -> stick
            $id === 13 => (mt_rand(1, 10) === 1) ? [['id' => 318, 'meta' => 0, 'count' => 1]] : [['id' => 13, 'meta' => 0, 'count' => 1]], // gravel -> flint
            $id === 1 => [['id' => 4, 'meta' => 0, 'count' => 1]],             // stone -> cobblestone (unless silk touch)
            $id === 60 => [['id' => 3, 'meta' => 0, 'count' => 1]],            // farmland -> dirt
            default => [['id' => $id, 'meta' => 0, 'count' => 1]],
        };

        return $specialDrops;
    }

    /**
     * @return array<int, array{id: int, meta: int, count: int}>
     */
    private function leafDrops(): array {
        if (mt_rand(1, 20) === 1) {
            return [['id' => 6, 'meta' => 0, 'count' => 1]]; // sapling
        }
        if (mt_rand(1, 200) === 1) {
            return [['id' => 260, 'meta' => 0, 'count' => 1]]; // apple
        }
        return [];
    }
}
