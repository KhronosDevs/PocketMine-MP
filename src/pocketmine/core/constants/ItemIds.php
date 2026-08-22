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
    public const TNT = 46;
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
    public const FLINT_STEEL = 259;
    public const COAL = 263;
    public const DIAMOND = 264;
    public const IRON_INGOT = 265;
    public const GOLD_INGOT = 266;
    public const STICK = 280;
    public const STRING = 287;
    public const FEATHER = 288;
    public const GUNPOWDER = 289;
    public const SNOWBALL = 332;
    public const EGG = 344;
    public const POTION = 373;
    public const GLASS_BOTTLE = 374;
    public const SPLASH_POTION = 438;
    public const SLIME_BALL = 341;
    public const GLOWSTONE_DUST = 348;
    public const REDSTONE = 331;
    public const LEATHER = 334;
    public const BRICK = 336;
    public const CLAY_BALL = 337;
    public const COAL_BLOCK = 173;
    public const DYE = 351;
    public const BONE = 352;
    public const ENDER_PEARL = 368;
    public const SPIDER_EYE = 375;
    public const EMERALD = 388;
    public const NETHER_BRICK = 405;
    public const QUARTZ = 406;
    public const INK_SAC = 351; // dye meta 0

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
    public const RABBIT_HIDE = 415;
    public const RABBIT_FOOT = 414;
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
    public const STONE_HOE = 291;
    public const IRON_HOE = 292;
    public const DIAMOND_HOE = 293;
    public const GOLDEN_HOE = 294;
    public const SEEDS = 295;
    public const BOWL = 281;
    public const MUSHROOM_STEW = 282;
    public const CAKE_ITEM = 354;
    public const PAINTING = 321;
    public const WHEAT_ITEM = 337;
    public const BOW = 261;
    public const ARROW = 262;

    // Misc
    public const BUCKET = 325;
    public const FISHING_ROD = 346;
    public const SHEARS = 359;

    // Bug 33: tool and armor item ids for crafting
    public const STONE_SWORD = 272;
    public const STONE_SHOVEL = 273;
    public const STONE_PICKAXE = 274;
    public const STONE_AXE = 275;
    public const IRON_SWORD = 267;
    public const IRON_SHOVEL = 256;
    public const IRON_PICKAXE = 257;
    public const IRON_AXE = 258;
    public const GOLDEN_SWORD = 283;
    public const GOLDEN_SHOVEL = 284;
    public const GOLDEN_PICKAXE = 285;
    public const GOLDEN_AXE = 286;
    public const DIAMOND_SWORD = 276;
    public const DIAMOND_SHOVEL = 277;
    public const DIAMOND_PICKAXE = 278;
    public const DIAMOND_AXE = 279;
    public const LEATHER_CAP = 298;
    public const LEATHER_TUNIC = 299;
    public const LEATHER_PANTS = 300;
    public const LEATHER_BOOTS = 301;
    public const IRON_HELMET = 306;
    public const IRON_CHESTPLATE = 307;
    public const IRON_LEGGINGS = 308;
    public const IRON_BOOTS = 309;
    public const GOLD_HELMET = 314;
    public const GOLD_CHESTPLATE = 315;
    public const GOLD_LEGGINGS = 316;
    public const GOLD_BOOTS = 317;
    public const BREAD = 297;
    public const COMPASS = 345;
    public const WOODEN_DOOR_ITEM = 324;
    public const BED_ITEM = 355;

    public const BLAZE_ROD = 369;
    public const SIGN = 323;
    public const ITEM_FRAME = 389;
    public const BOAT = 333;
    public const MINECART = 328;
}
