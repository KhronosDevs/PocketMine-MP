<?php

declare(strict_types=1);

namespace pocketmine\core\constants;

/**
 * Item ids referenced in gameplay code (recipes, smelting, drops, held-item
 * checks). The big name/damage tables live in ItemRegistry - this class only
 * covers ids that code compares against directly, so a bare 261/262/367 never
 * appears in logic. Values are the protocol-84 item ids.
 */
final class ItemIds {
    // Blocks (also items)
    public const AIR = 0;
    public const STONE = 1;
    public const COBBLESTONE = 4;
    public const PLANKS = 5;
    public const SAPLING = 6;
    public const SAND = 12;
    public const LOG = 17;
    public const GLASS = 20;
    public const LAPIS_ORE = 21;
    public const GOLD_ORE = 14;
    public const IRON_ORE = 15;
    public const COAL_ORE = 16;
    public const WOOL = 35;
    public const TORCH = 50;
    public const CHEST = 54;
    public const DIAMOND_ORE = 56;
    public const CRAFTING_TABLE = 58;
    public const FURNACE = 61;
    public const REDSTONE_ORE = 73;
    public const CACTUS = 81;
    public const CLAY_BLOCK = 82;
    public const NETHERRACK = 87;
    public const STONE_BRICKS = 98;
    public const EMERALD_ORE = 129;
    public const NETHER_QUARTZ_ORE = 153;
    public const LOG_ACACIA = 162;
    public const HARDENED_CLAY = 172;

    // Materials / ingredients
    public const COAL = 263;
    public const DIAMOND = 264;
    public const IRON_INGOT = 265;
    public const GOLD_INGOT = 266;
    public const STICK = 280;
    public const STRING = 287;
    public const FEATHER = 288;
    public const GUNPOWDER = 289;
    public const REDSTONE = 331;
    public const LEATHER = 334;
    public const BRICK = 336;
    public const CLAY_BALL = 337;
    public const COAL_BLOCK = 173;
    public const DYE = 351;
    public const BONE = 352;
    public const SPIDER_EYE = 375;
    public const EMERALD = 388;
    public const NETHER_BRICK = 405;
    public const QUARTZ = 406;

    // Food
    public const RAW_PORKCHOP = 319;
    public const COOKED_PORKCHOP = 320;
    public const RAW_FISH = 349;
    public const COOKED_FISH = 350;
    public const RAW_BEEF = 363;
    public const STEAK = 364;
    public const RAW_CHICKEN = 365;
    public const COOKED_CHICKEN = 366;
    public const ROTTEN_FLESH = 367;
    public const MUTTON = 418;
    public const BAKED_POTATO = 393;
    public const POTATO = 392;
    // Legacy 1.0+ ids carried over from recipes.json (not present on the
    // 0.15.10 client, so these smelting entries are inert there).
    public const RAW_RABBIT = 411;
    public const COOKED_RABBIT = 412;
    public const RAW_SALMON = 460;
    public const COOKED_SALMON = 463;

    // Tools / weapons
    public const WOODEN_SWORD = 268;
    public const WOODEN_SHOVEL = 269;
    public const WOODEN_PICKAXE = 270;
    public const WOODEN_AXE = 271;
    public const WOODEN_HOE = 290;
    public const BOW = 261;
    public const ARROW = 262;

    // Misc
    public const BUCKET = 325;
    public const BLAZE_ROD = 369;
}
