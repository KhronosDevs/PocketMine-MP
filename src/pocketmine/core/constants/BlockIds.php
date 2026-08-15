<?php

declare(strict_types=1);

namespace pocketmine\core\constants;

/**
 * Block ids referenced in gameplay code (terrain checks, liquid checks,
 * worldgen). The big per-block property tables live in BlockRegistry - this
 * class only covers ids that code compares against directly.
 */
final class BlockIds {
    public const AIR = 0;
    public const STONE = 1;
    public const GRASS = 2;
    public const DIRT = 3;
    public const COBBLESTONE = 4;
    public const BEDROCK = 7;
    public const WATER = 8;
    public const STILL_WATER = 9;
    public const LAVA = 10;
    public const STILL_LAVA = 11;
    public const SAND = 12;
    public const GRAVEL = 13;
    public const LOG = 17;
    public const LEAVES = 18;
    public const SANDSTONE = 24;
    public const TALL_GRASS = 31;
    public const DEAD_BUSH = 32;
    public const DANDELION = 37;
    public const POPPY = 38;
    public const TORCH = 50;
    public const SNOW_LAYER = 78;
    public const ICE = 79;
    public const CACTUS = 81;

    /** Liquid blocks (water/lava, both flowing and still) - a mob cannot spawn in these. */
    public const LIQUIDS = [self::WATER, self::STILL_WATER, self::LAVA, self::STILL_LAVA];
}
