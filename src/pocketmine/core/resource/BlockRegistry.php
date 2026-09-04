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
        18 => ['name' => 'Leaves', 'hardness' => 0.2, 'resistance' => 1.0, 'opacity' => 1, 'solid' => false, 'transparent' => true, 'flammable' => true, 'flamability' => 30, 'burnTime' => 5, 'tool' => 'shears', 'silkTouch' => true],
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
        36 => ['name' => 'Piston Arm', 'hardness' => 0.5, 'resistance' => 3.0, 'solid' => false, 'transparent' => true, 'silkTouch' => false],
        96 => ['name' => 'Trapdoor', 'hardness' => 3.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe', 'solid' => false, 'transparent' => true],
        99 => ['name' => 'Huge Brown Mushroom', 'hardness' => 0.2, 'resistance' => 1.0],
        100 => ['name' => 'Huge Red Mushroom', 'hardness' => 0.2, 'resistance' => 1.0],
        101 => ['name' => 'Iron Bars', 'hardness' => 5.0, 'resistance' => 30.0, 'opacity' => 0, 'transparent' => true, 'tool' => 'pickaxe'],
        102 => ['name' => 'Glass Pane', 'hardness' => 0.3, 'resistance' => 1.5, 'opacity' => 0, 'transparent' => true, 'silkTouch' => true],
        104 => ['name' => 'Pumpkin Stem', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        105 => ['name' => 'Melon Stem', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        109 => ['name' => 'Stone Brick Stairs', 'hardness' => 1.5, 'resistance' => 30.0, 'opacity' => 0, 'tool' => 'pickaxe'],
        115 => ['name' => 'Nether Wart', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        117 => ['name' => 'Brewing Stand', 'hardness' => 0.5, 'resistance' => 2.5, 'opacity' => 0, 'solid' => false, 'transparent' => true],
        118 => ['name' => 'Cauldron', 'hardness' => 2.0, 'resistance' => 10.0, 'solid' => false, 'transparent' => true],
        119 => ['name' => 'End Portal', 'hardness' => -1.0, 'resistance' => 3600000.0, 'opacity' => 0, 'light' => 15, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        122 => ['name' => 'Dragon Egg', 'hardness' => -1.0, 'resistance' => 3600000.0, 'opacity' => 0, 'light' => 1, 'transparent' => true, 'silkTouch' => false],
        123 => ['name' => 'Redstone Lamp', 'hardness' => 0.3, 'resistance' => 1.5],
        124 => ['name' => 'Redstone Lamp', 'hardness' => 0.3, 'resistance' => 1.5, 'light' => 15],
        125 => ['name' => 'Double Wooden Slab', 'hardness' => 2.0, 'resistance' => 15.0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        126 => ['name' => 'Wooden Slab', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'transparent' => true, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        127 => ['name' => 'Cocoa', 'hardness' => 0.2, 'resistance' => 1.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'silkTouch' => false],
        128 => ['name' => 'Sandstone Stairs', 'hardness' => 0.8, 'resistance' => 4.0, 'opacity' => 0, 'tool' => 'pickaxe'],
        130 => ['name' => 'Ender Chest', 'hardness' => 22.5, 'resistance' => 6000.0, 'opacity' => 0, 'transparent' => true, 'tool' => 'pickaxe'],
        131 => ['name' => 'Tripwire Hook', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        132 => ['name' => 'Tripwire', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        134 => ['name' => 'Spruce Wood Stairs', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        135 => ['name' => 'Birch Wood Stairs', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        136 => ['name' => 'Jungle Wood Stairs', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        138 => ['name' => 'Beacon', 'hardness' => 3.0, 'resistance' => 15.0, 'opacity' => 0, 'light' => 15, 'transparent' => true],
        139 => ['name' => 'Cobblestone Wall', 'hardness' => 2.0, 'resistance' => 30.0, 'opacity' => 0, 'tool' => 'pickaxe'],
        140 => ['name' => 'Flower Pot', 'hardness' => 0.0, 'resistance' => 0.0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        141 => ['name' => 'Carrots', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        142 => ['name' => 'Potatoes', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        143 => ['name' => 'Wooden Button', 'hardness' => 0.5, 'resistance' => 2.5, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'replaceable' => true],
        144 => ['name' => 'Skull', 'hardness' => 1.0, 'resistance' => 5.0, 'solid' => false, 'transparent' => true],
        145 => ['name' => 'Anvil', 'hardness' => 5.0, 'resistance' => 6000.0, 'tool' => 'pickaxe'],
        146 => ['name' => 'Trapped Chest', 'hardness' => 2.5, 'resistance' => 12.5, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        147 => ['name' => 'Light Weighted Pressure Plate', 'hardness' => 0.5, 'resistance' => 2.5, 'solid' => false, 'transparent' => true, 'replaceable' => true],
        148 => ['name' => 'Heavy Weighted Pressure Plate', 'hardness' => 0.5, 'resistance' => 2.5, 'solid' => false, 'transparent' => true, 'replaceable' => true],
        149 => ['name' => 'Redstone Comparator', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        150 => ['name' => 'Redstone Comparator', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'light' => 9, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        151 => ['name' => 'Daylight Sensor', 'hardness' => 0.2, 'resistance' => 1.0, 'opacity' => 0, 'solid' => false, 'transparent' => true],
        152 => ['name' => 'Redstone Block', 'hardness' => 5.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        153 => ['name' => 'Quartz Ore', 'hardness' => 3.0, 'resistance' => 15.0, 'tool' => 'pickaxe', 'xp' => 2],
        154 => ['name' => 'Hopper', 'hardness' => 3.0, 'resistance' => 24.0, 'tool' => 'pickaxe'],
        155 => ['name' => 'Quartz Block', 'hardness' => 0.8, 'resistance' => 4.0, 'tool' => 'pickaxe'],
        156 => ['name' => 'Quartz Stairs', 'hardness' => 0.8, 'resistance' => 4.0, 'opacity' => 0, 'tool' => 'pickaxe'],
        157 => ['name' => 'Activator Rail', 'hardness' => 0.7, 'resistance' => 3.0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        158 => ['name' => 'Dropper', 'hardness' => 3.5, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        159 => ['name' => 'Stained Clay', 'hardness' => 1.25, 'resistance' => 21.0],
        160 => ['name' => 'Stained Glass Pane', 'hardness' => 0.3, 'resistance' => 1.5, 'opacity' => 0, 'transparent' => true, 'silkTouch' => true],
        161 => ['name' => 'Leaves', 'hardness' => 0.2, 'resistance' => 1.0, 'opacity' => 1, 'solid' => false, 'transparent' => true, 'flammable' => true, 'flamability' => 30, 'burnTime' => 5, 'tool' => 'shears', 'silkTouch' => true],
        162 => ['name' => 'Log', 'hardness' => 2.0, 'resistance' => 15.0, 'flammable' => true, 'flamability' => 5, 'burnTime' => 5, 'tool' => 'axe'],
        163 => ['name' => 'Acacia Wood Stairs', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        164 => ['name' => 'Dark Oak Wood Stairs', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        165 => ['name' => 'Slime Block', 'hardness' => 0.0, 'resistance' => 0.0],
        166 => ['name' => 'Barrier', 'hardness' => -1.0, 'resistance' => 3600000.0, 'opacity' => 0, 'transparent' => true, 'silkTouch' => false],
        167 => ['name' => 'Iron Trapdoor', 'hardness' => 5.0, 'resistance' => 30.0, 'opacity' => 0, 'tool' => 'pickaxe', 'solid' => false, 'transparent' => true],
        168 => ['name' => 'Prismarine', 'hardness' => 1.5, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        169 => ['name' => 'Sea Lantern', 'hardness' => 0.3, 'resistance' => 1.5, 'opacity' => 0, 'light' => 15, 'transparent' => true],
        170 => ['name' => 'Hay Bale', 'hardness' => 0.5, 'resistance' => 2.5, 'flammable' => true, 'flamability' => 60, 'burnTime' => 5],
        171 => ['name' => 'Carpet', 'hardness' => 0.1, 'resistance' => 0.5, 'opacity' => 0, 'flammable' => true, 'flamability' => 60, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'replaceable' => true],
        172 => ['name' => 'Hardened Clay', 'hardness' => 1.25, 'resistance' => 21.0],
        173 => ['name' => 'Coal Block', 'hardness' => 5.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        174 => ['name' => 'Packed Ice', 'hardness' => 0.5, 'resistance' => 2.5, 'opacity' => 3, 'transparent' => true, 'silkTouch' => true],
        175 => ['name' => 'Double Plant', 'hardness' => 0.0, 'resistance' => 0.0, 'opacity' => 0, 'solid' => false, 'transparent' => true, 'replaceable' => true, 'silkTouch' => false],
        178 => ['name' => 'Inverted Daylight Sensor', 'hardness' => 0.2, 'resistance' => 1.0, 'opacity' => 0, 'solid' => false, 'transparent' => true],
        179 => ['name' => 'Red Sandstone', 'hardness' => 0.8, 'resistance' => 4.0, 'tool' => 'pickaxe'],
        180 => ['name' => 'Red Sandstone Stairs', 'hardness' => 0.8, 'resistance' => 4.0, 'opacity' => 0, 'tool' => 'pickaxe'],
        181 => ['name' => 'Double Red Sandstone Slab', 'hardness' => 2.0, 'resistance' => 30.0, 'tool' => 'pickaxe'],
        182 => ['name' => 'Red Sandstone Slab', 'hardness' => 2.0, 'resistance' => 30.0, 'opacity' => 0, 'transparent' => true, 'tool' => 'pickaxe'],
        183 => ['name' => 'Spruce Fence Gate', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'tool' => 'axe'],
        184 => ['name' => 'Birch Fence Gate', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'tool' => 'axe'],
        185 => ['name' => 'Jungle Fence Gate', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'tool' => 'axe'],
        186 => ['name' => 'Dark Oak Fence Gate', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'tool' => 'axe'],
        187 => ['name' => 'Acacia Fence Gate', 'hardness' => 2.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'solid' => false, 'transparent' => true, 'tool' => 'axe'],
        193 => ['name' => 'Spruce Door', 'hardness' => 3.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        194 => ['name' => 'Birch Door', 'hardness' => 3.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        195 => ['name' => 'Jungle Door', 'hardness' => 3.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        196 => ['name' => 'Acacia Door', 'hardness' => 3.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        197 => ['name' => 'Dark Oak Door', 'hardness' => 3.0, 'resistance' => 15.0, 'opacity' => 0, 'flammable' => true, 'flamability' => 20, 'burnTime' => 5, 'tool' => 'axe'],
        199 => ['name' => 'Item Frame', 'hardness' => 0.0, 'resistance' => 0.0, 'solid' => false, 'transparent' => true, 'replaceable' => false, 'silkTouch' => false],
    ];

    /**
     * Block-state metadata (protocol 84): how a block's meta encodes its
     * visual/placement state.
     *
     * - slab/double_slab: meta & 0x7 = material variant, 0x8 = top half
     * - stairs: meta & 0x3 = facing (0 south, 1 west, 2 north, 3 east),
     *   0x4 = corner shape, 0x8 = upside-down
     * - door: meta & 0x3 = facing, 0x4 = open, 0x8 = top half
     */
    private const STATES = [
        43 => ['kind' => 'double_slab', 'variants' => [0 => 'Stone', 1 => 'Sandstone', 2 => 'Wooden', 3 => 'Cobblestone', 4 => 'Brick', 5 => 'Stone Brick', 6 => 'Quartz', 7 => 'Nether Brick']],
        44 => ['kind' => 'slab', 'variants' => [0 => 'Stone', 1 => 'Sandstone', 2 => 'Wooden', 3 => 'Cobblestone', 4 => 'Brick', 5 => 'Stone Brick', 6 => 'Quartz', 7 => 'Nether Brick']],
        125 => ['kind' => 'double_slab', 'variants' => [0 => 'Oak Wood', 1 => 'Spruce Wood', 2 => 'Birch Wood', 3 => 'Jungle Wood', 4 => 'Acacia Wood', 5 => 'Dark Oak Wood']],
        126 => ['kind' => 'slab', 'variants' => [0 => 'Oak Wood', 1 => 'Spruce Wood', 2 => 'Birch Wood', 3 => 'Jungle Wood', 4 => 'Acacia Wood', 5 => 'Dark Oak Wood']],
        181 => ['kind' => 'double_slab', 'variants' => [0 => 'Red Sandstone']],
        182 => ['kind' => 'slab', 'variants' => [0 => 'Red Sandstone']],
        53 => ['kind' => 'stairs'],
        67 => ['kind' => 'stairs'],
        108 => ['kind' => 'stairs'],
        109 => ['kind' => 'stairs'],
        114 => ['kind' => 'stairs'],
        128 => ['kind' => 'stairs'],
        134 => ['kind' => 'stairs'],
        135 => ['kind' => 'stairs'],
        136 => ['kind' => 'stairs'],
        156 => ['kind' => 'stairs'],
        163 => ['kind' => 'stairs'],
        164 => ['kind' => 'stairs'],
        180 => ['kind' => 'stairs'],
        64 => ['kind' => 'door'],
        71 => ['kind' => 'door'],
        193 => ['kind' => 'door'],
        194 => ['kind' => 'door'],
        195 => ['kind' => 'door'],
        196 => ['kind' => 'door'],
        197 => ['kind' => 'door'],
    ];

    public function has(int $id): bool {
        return isset(self::BLOCKS[$id]);
    }

    /**
     * @return array<int> all explicitly registered block ids.
     */
    public function getIds(): array {
        return array_keys(self::BLOCKS);
    }

    /** @var array<int, array<string, mixed>> */
    private static array $mergedCache = [];

    public function get(int $id): array {
        return self::$mergedCache[$id] ??= array_merge(self::DEFAULTS, self::BLOCKS[$id] ?? []);
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

    /** @var array<int, array<int, int>>|null id => 256-entry 1/0 solid flags, memoized per registry */
    private ?array $solidFlagsCache = null;

    /**
     * 256-entry lookup: block id => 1 if solid else 0 (ids outside the table
     * use DEFAULTS['solid'], matching isSolid()). Built once per registry and
     * reused by hot paths (BlockCollisionSystem) that probe raw chunk bytes.
     *
     * @return array<int, int>
     */
    public function getSolidFlags(): array {
        if ($this->solidFlagsCache === null) {
            $flags = [];
            for ($id = 0; $id <= 255; $id++) {
                $flags[$id] = (bool)$this->get($id)['solid'] ? 1 : 0;
            }
            $this->solidFlagsCache = $flags;
        }
        return $this->solidFlagsCache;
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
     * Block-state metadata accessors (slab/stairs/doors).
     */

    /**
     * The block's state kind: 'slab' | 'double_slab' | 'stairs' | 'door', or
     * null for plain blocks whose meta carries no state.
     */
    public function getStateKind(int $id): ?string {
        return self::STATES[$id]['kind'] ?? null;
    }

    /**
     * @return array<int, string> meta => material/variant name for the block's
     *                             variants (empty for plain stairs/doors).
     */
    public function getStateVariants(int $id): array {
        return self::STATES[$id]['variants'] ?? [];
    }

    /**
     * Display name for a block at a given meta, including its state variant
     * (e.g. slab meta 1 -> "Sandstone Slab"). Falls back to the plain name.
     */
    public function getStateName(int $id, int $meta): string {
        $state = self::STATES[$id] ?? null;
        if ($state === null) {
            return $this->getName($id);
        }
        $variant = $state['variants'][$meta & 0x7] ?? null;
        if ($variant === null) {
            return $this->getName($id);
        }
        return match ($state['kind']) {
            'slab' => $variant . ' Slab',
            'double_slab' => $variant . ' Double Slab',
            default => $this->getName($id),
        };
    }

    /**
     * The slab's material variant name (meta & 0x7), or null if the block is
     * not a slab.
     */
    public function getSlabMaterial(int $id, int $meta): ?string {
        $state = self::STATES[$id] ?? null;
        if ($state === null || ($state['kind'] !== 'slab' && $state['kind'] !== 'double_slab')) {
            return null;
        }
        return $state['variants'][$meta & 0x7] ?? null;
    }

    /**
     * Whether a slab is placed in the top half of its block space (meta bit 3).
     */
    public function isSlabTop(int $id, int $meta): ?bool {
        $kind = $this->getStateKind($id);
        if ($kind !== 'slab') {
            return null;
        }
        return ($meta & 0x8) !== 0;
    }

    /**
     * Stair facing from meta bits 0-1 (0 south, 1 west, 2 north, 3 east), or
     * null if the block is not stairs.
     */
    public function getStairFacing(int $id, int $meta): ?int {
        return $this->getStateKind($id) === 'stairs' ? $meta & 0x3 : null;
    }

    /**
     * Whether stairs are upside-down (meta bit 3), or null if not stairs.
     */
    public function isStairUpsideDown(int $id, int $meta): ?bool {
        if ($this->getStateKind($id) !== 'stairs') {
            return null;
        }
        return ($meta & 0x8) !== 0;
    }

    /**
     * Whether a door is open (meta bit 2), or null if not a door.
     */
    public function isDoorOpen(int $id, int $meta): ?bool {
        if ($this->getStateKind($id) !== 'door') {
            return null;
        }
        return ($meta & 0x4) !== 0;
    }

    /**
     * Whether a door block is the top half (meta bit 3), or null if not a door.
     */
    public function isDoorTopHalf(int $id, int $meta): ?bool {
        if ($this->getStateKind($id) !== 'door') {
            return null;
        }
        return ($meta & 0x8) !== 0;
    }

    /**
     * Resolve the meta a placed block should carry given the face it was
     * placed against. Slabs get their top/bottom bit from the face (clicking
     * the top face of a block places a bottom-half slab and vice versa); all
     * other blocks keep the requested meta unchanged.
     */
    public function applyPlacementMeta(int $id, int $face, int $meta): int {
        if ($this->getStateKind($id) !== 'slab') {
            return $meta;
        }
        return $face === 0 ? ($meta | 0x8) : ($meta & ~0x8);
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
        // Vanilla semantics: only blocks that drop NOTHING by hand truly
        // require a tool (stone, ores, ...). Those carry a toolLevel. Soft
        // blocks (grass, dirt, planks) break by hand at normal speed even
        // though a tool is faster - the 5x hand penalty would otherwise
        // make them take longer than the 0.15 client's own crack timer,
        // and legit breaks would be rejected server-side.
        return $this->getToolLevel($id) > 0;
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
            $id === 153 => [['id' => 406, 'meta' => 0, 'count' => 1]],         // quartz ore -> quartz
            $id === 89 => [['id' => 348, 'meta' => 0, 'count' => mt_rand(2, 4)]], // glowstone -> glowstone dust
            $id === 20 || $id === 79 || $id === 95 || $id === 102 || $id === 160 => [], // glass/ice/stained glass/panes: nothing without silk touch
            $id === 30 => [['id' => 287, 'meta' => 0, 'count' => 1]],          // cobweb -> string
            $id === 103 => [['id' => 360, 'meta' => 0, 'count' => mt_rand(3, 7)]], // melon -> melon slices
            $id === 18 => $this->leafDrops(),                                  // leaves -> sapling/apple
            $id === 31 => (mt_rand(1, 3) === 1) ? [['id' => 295, 'meta' => 0, 'count' => 1]] : [], // tall grass -> seeds
            $id === 32 => (mt_rand(1, 3) === 1) ? [['id' => 280, 'meta' => 0, 'count' => 1]] : [], // dead bush -> stick
            $id === 13 => (mt_rand(1, 10) === 1) ? [['id' => 318, 'meta' => 0, 'count' => 1]] : [['id' => 13, 'meta' => 0, 'count' => 1]], // gravel -> flint
            $id === 1 => [['id' => 4, 'meta' => 0, 'count' => 1]],             // stone -> cobblestone (unless silk touch)
            $id === 2 => [['id' => 3, 'meta' => 0, 'count' => 1]],             // grass -> dirt (legacy Grass::getDrops)
            $id === 60 => [['id' => 3, 'meta' => 0, 'count' => 1]],            // farmland -> dirt
            $id === 63 || $id === 68 => [['id' => 323, 'meta' => 0, 'count' => 1]], // sign -> sign item
            $id === 199 => [['id' => 389, 'meta' => 0, 'count' => 1]],         // item frame -> item frame item
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
