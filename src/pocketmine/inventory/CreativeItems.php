<?php

declare(strict_types=1);

/*
 *
 *  _____   _____   __   _   _   _____  __    __  _____
 * /  ___| | ____| |  \ | | | | /  ___/ \ \  / / /  ___/
 * | |     | |__   |   \| | | | | |___   \ \/ /  | |___
 * | |  _  |  __|  | |\   | | | \___  \   \  /   \___  \
 * | |_| | | |___  | | \  | | |  ___| |   / /     ___| |
 * \_____/ |_____| |_|  \_| |_| /_____/  /_/     /_____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author iTX Technologies
 * @link https://itxtech.org
 *
 */

namespace pocketmine\inventory;

use pocketmine\block\Anvil;
use pocketmine\block\DoublePlant;

use pocketmine\block\Flower;
use pocketmine\block\Leaves;
use pocketmine\block\Planks;
use pocketmine\block\Quartz;
use pocketmine\block\Sand;
use pocketmine\block\Sandstone;
use pocketmine\block\Sapling;
use pocketmine\block\SkullBlock as Skull;
use pocketmine\block\Slab as StoneSlab;

use pocketmine\block\Stone;
use pocketmine\block\StoneBricks;
use pocketmine\block\StoneWall;
use pocketmine\block\TallGrass;
use pocketmine\block\Wood;
use pocketmine\block\Wood2;
use pocketmine\item\Coal;
use pocketmine\item\Dye;

use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\Item;
use pocketmine\item\Potion;

class CreativeItems{

	const CATEGORY_BUILDING = 0;
	const CATEGORY_DECORATION = 1;
	const CATEGORY_TOOLS = 2;
	const CATEGORY_MISCELLANEOUS = 3;

	const ITEMS = [
		self::CATEGORY_BUILDING => [
			["id" => Item::COBBLESTONE],
			["id" => Item::STONE_BRICKS],
			["id" => Item::STONE_BRICKS, "meta" => StoneBricks::MOSSY],
			["id" => Item::STONE_BRICKS, "meta" => StoneBricks::CRACKED],
			["id" => Item::STONE_BRICKS, "meta" => StoneBricks::CHISELED],
			["id" => Item::MOSS_STONE],
			["id" => Item::PLANKS, "meta" => Planks::OAK],
			["id" => Item::PLANKS, "meta" => Planks::SPRUCE],
			["id" => Item::PLANKS, "meta" => Planks::BIRCH],
			["id" => Item::PLANKS, "meta" => Planks::JUNGLE],
			["id" => Item::PLANKS, "meta" => Planks::ACACIA],
			["id" => Item::PLANKS, "meta" => Planks::DARK_OAK],
			["id" => Item::BRICKS],
			["id" => Item::STONE],
			["id" => Item::STONE, "meta" => Stone::GRANITE],
			["id" => Item::STONE, "meta" => Stone::POLISHED_GRANITE],
			["id" => Item::STONE, "meta" => Stone::DIORITE],
			["id" => Item::STONE, "meta" => Stone::POLISHED_DIORITE],
			["id" => Item::STONE, "meta" => Stone::ANDESITE],
			["id" => Item::STONE, "meta" => Stone::POLISHED_ANDESITE],
			["id" => Item::DIRT],
			["id" => Item::PODZOL],
			["id" => Item::GRASS],
			["id" => Item::MYCELIUM],
			["id" => Item::CLAY_BLOCK],
			["id" => Item::HARDENED_CLAY],
			["id" => Item::STAINED_CLAY, "meta" => 0], //TODO: Make a class containing colour constants
			["id" => Item::STAINED_CLAY, "meta" => 1],
			["id" => Item::STAINED_CLAY, "meta" => 2],
			["id" => Item::STAINED_CLAY, "meta" => 3],
			["id" => Item::STAINED_CLAY, "meta" => 4],
			["id" => Item::STAINED_CLAY, "meta" => 5],
			["id" => Item::STAINED_CLAY, "meta" => 6],
			["id" => Item::STAINED_CLAY, "meta" => 7],
			["id" => Item::STAINED_CLAY, "meta" => 8],
			["id" => Item::STAINED_CLAY, "meta" => 9],
			["id" => Item::STAINED_CLAY, "meta" => 10],
			["id" => Item::STAINED_CLAY, "meta" => 11],
			["id" => Item::STAINED_CLAY, "meta" => 12],
			["id" => Item::STAINED_CLAY, "meta" => 13],
			["id" => Item::STAINED_CLAY, "meta" => 14],
			["id" => Item::STAINED_CLAY, "meta" => 15],
			["id" => Item::SANDSTONE],
			["id" => Item::SANDSTONE, "meta" => Sandstone::CHISELED], //Creeper sandstone
			["id" => Item::SANDSTONE, "meta" => Sandstone::SMOOTH],
			["id" => Item::RED_SANDSTONE],
			["id" => Item::RED_SANDSTONE, "meta" => Sandstone::CHISELED], //Wither sandstone
			["id" => Item::RED_SANDSTONE, "meta" => Sandstone::SMOOTH],
			["id" => Item::SAND],
			["id" => Item::SAND, "meta" => Sand::RED],
			["id" => Item::GRAVEL],
			["id" => Item::WOOD, "meta" => Wood::OAK],
			["id" => Item::WOOD, "meta" => Wood::SPRUCE],
			["id" => Item::WOOD, "meta" => Wood::BIRCH],
			["id" => Item::WOOD, "meta" => Wood::JUNGLE],
			["id" => Item::WOOD2, "meta" => Wood2::ACACIA], #blamemojang
			["id" => Item::WOOD2, "meta" => Wood2::DARK_OAK],
			["id" => Item::NETHER_BRICK_BLOCK],
			["id" => Item::NETHERRACK],
			["id" => Item::SOUL_SAND],
			["id" => Item::BEDROCK],
			["id" => Item::COBBLE_STAIRS],
			["id" => Item::OAK_WOOD_STAIRS],
			["id" => Item::SPRUCE_WOOD_STAIRS],
			["id" => Item::BIRCH_WOOD_STAIRS],
			["id" => Item::JUNGLE_WOOD_STAIRS],
			["id" => Item::ACACIA_WOOD_STAIRS],
			["id" => Item::DARK_OAK_WOOD_STAIRS],
			["id" => Item::BRICK_STAIRS],
			["id" => Item::SANDSTONE_STAIRS],
			["id" => Item::RED_SANDSTONE_STAIRS],
			["id" => Item::STONE_BRICK_STAIRS],
			["id" => Item::NETHER_BRICK_STAIRS],
			["id" => Item::QUARTZ_STAIRS],
			["id" => Item::STONE_SLAB],
			["id" => Item::STONE_SLAB, "meta" => StoneSlab::COBBLESTONE],
			["id" => Item::WOODEN_SLAB, "meta" => Planks::OAK],
			["id" => Item::WOODEN_SLAB, "meta" => Planks::SPRUCE],
			["id" => Item::WOODEN_SLAB, "meta" => Planks::BIRCH],
			["id" => Item::WOODEN_SLAB, "meta" => Planks::JUNGLE],
			["id" => Item::WOODEN_SLAB, "meta" => Planks::ACACIA],
			["id" => Item::WOODEN_SLAB, "meta" => Planks::DARK_OAK],
			["id" => Item::STONE_SLAB, "meta" => StoneSlab::BRICK],
			["id" => Item::STONE_SLAB, "meta" => StoneSlab::SANDSTONE],
			["id" => Item::RED_SANDSTONE_SLAB], #blamemojang
			["id" => Item::STONE_SLAB, "meta" => StoneSlab::STONE_BRICK],
			["id" => Item::STONE_SLAB, "meta" => StoneSlab::QUARTZ],
			["id" => Item::STONE_SLAB, "meta" => StoneSlab::NETHER_BRICK],
			["id" => Item::QUARTZ_BLOCK],
			["id" => Item::QUARTZ_BLOCK, "meta" => Quartz::QUARTZ_PILLAR],
			["id" => Item::QUARTZ_BLOCK, "meta" => Quartz::QUARTZ_CHISELED],
			["id" => Item::COAL_ORE],
			["id" => Item::IRON_ORE],
			["id" => Item::GOLD_ORE],
			["id" => Item::DIAMOND_ORE],
			["id" => Item::LAPIS_ORE],
			["id" => Item::REDSTONE_ORE],
			["id" => Item::EMERALD_ORE],
			["id" => Item::NETHER_QUARTZ_ORE],
			["id" => Item::OBSIDIAN],
			["id" => Item::ICE],
			["id" => Item::PACKED_ICE],
			["id" => Item::SNOW_BLOCK],
			["id" => Item::END_STONE],
		],
		self::CATEGORY_DECORATION => [
			["id" => Item::COBBLESTONE_WALL],
			["id" => Item::COBBLESTONE_WALL, "meta" => StoneWall::MOSSY_WALL],
			["id" => Item::LILY_PAD],
			["id" => Item::GOLD_BLOCK],
			["id" => Item::IRON_BLOCK],
			["id" => Item::DIAMOND_BLOCK],
			["id" => Item::LAPIS_BLOCK],
			["id" => Item::COAL_BLOCK],
			["id" => Item::EMERALD_BLOCK],
			["id" => Item::REDSTONE_BLOCK],
			["id" => Item::SNOW_LAYER],
			["id" => Item::GLASS],
			["id" => Item::GLOWSTONE],
			["id" => Item::VINES],
			["id" => Item::LADDER],
			["id" => Item::SPONGE],
			["id" => Item::GLASS_PANE],
			["id" => Item::OAK_DOOR],
			["id" => Item::SPRUCE_DOOR],
			["id" => Item::BIRCH_DOOR],
			["id" => Item::JUNGLE_DOOR],
			["id" => Item::ACACIA_DOOR],
			["id" => Item::DARK_OAK_DOOR],
			["id" => Item::IRON_DOOR],
			["id" => Item::WOODEN_TRAPDOOR],
			["id" => Item::IRON_TRAPDOOR],
			["id" => Item::FENCE, "meta" => Planks::OAK],
			["id" => Item::FENCE, "meta" => Planks::SPRUCE],
			["id" => Item::FENCE, "meta" => Planks::BIRCH],
			["id" => Item::FENCE, "meta" => Planks::JUNGLE],
			["id" => Item::FENCE, "meta" => Planks::ACACIA],
			["id" => Item::FENCE, "meta" => Planks::DARK_OAK],
			["id" => Item::NETHER_BRICK_FENCE],
			["id" => Item::OAK_FENCE_GATE],
			["id" => Item::SPRUCE_FENCE_GATE],
			["id" => Item::BIRCH_FENCE_GATE],
			["id" => Item::JUNGLE_FENCE_GATE],
			["id" => Item::ACACIA_FENCE_GATE],
			["id" => Item::DARK_OAK_FENCE_GATE],
			["id" => Item::IRON_BARS],
			["id" => Item::BED],
			["id" => Item::BOOKSHELF],
			["id" => Item::SIGN],
			["id" => Item::PAINTING],
			["id" => Item::ITEM_FRAME],
			["id" => Item::CRAFTING_TABLE],
			["id" => Item::STONECUTTER],
			["id" => Item::CHEST],
			["id" => Item::TRAPPED_CHEST],
			["id" => Item::FURNACE],
			["id" => Item::BREWING_STAND],
			["id" => Item::CAULDRON],
			["id" => Item::NOTEBLOCK],
			["id" => Item::END_PORTAL_FRAME],
			["id" => Item::ANVIL],
			["id" => Item::ANVIL, "meta" => Anvil::SLIGHTLY_DAMAGED],
			["id" => Item::ANVIL, "meta" => Anvil::VERY_DAMAGED],
			["id" => Item::DANDELION],
			["id" => Item::POPPY, "meta" => Flower::TYPE_POPPY],
			["id" => Item::POPPY, "meta" => Flower::TYPE_BLUE_ORCHID],
			["id" => Item::POPPY, "meta" => Flower::TYPE_ALLIUM],
			["id" => Item::POPPY, "meta" => Flower::TYPE_AZURE_BLUET],
			["id" => Item::POPPY, "meta" => Flower::TYPE_RED_TULIP],
			["id" => Item::POPPY, "meta" => Flower::TYPE_ORANGE_TULIP],
			["id" => Item::POPPY, "meta" => Flower::TYPE_WHITE_TULIP],
			["id" => Item::POPPY, "meta" => Flower::TYPE_PINK_TULIP],
			["id" => Item::POPPY, "meta" => Flower::TYPE_OXEYE_DAISY],
			["id" => Item::DOUBLE_PLANT, "meta" => DoublePlant::SUNFLOWER],
			["id" => Item::DOUBLE_PLANT, "meta" => DoublePlant::LILAC],
			["id" => Item::DOUBLE_PLANT, "meta" => DoublePlant::DOUBLE_TALLGRASS],
			["id" => Item::DOUBLE_PLANT, "meta" => DoublePlant::LARGE_FERN],
			["id" => Item::DOUBLE_PLANT, "meta" => DoublePlant::ROSE_BUSH],
			["id" => Item::DOUBLE_PLANT, "meta" => DoublePlant::PEONY],
			["id" => Item::BROWN_MUSHROOM],
			["id" => Item::RED_MUSHROOM],
			["id" => Item::BROWN_MUSHROOM_BLOCK, "meta" => 14],
			["id" => Item::RED_MUSHROOM_BLOCK, "meta" => 14],
			["id" => Item::BROWN_MUSHROOM_BLOCK, "meta" => 0],
			["id" => Item::BROWN_MUSHROOM_BLOCK, "meta" => 15],
			["id" => Item::CACTUS],
			["id" => Item::MELON_BLOCK],
			["id" => Item::PUMPKIN],
			["id" => Item::JACK_O_LANTERN],
			["id" => Item::COBWEB],
			["id" => Item::HAY_BALE],
			["id" => Item::TALL_GRASS, "meta" => TallGrass::NORMAL],
			["id" => Item::TALL_GRASS, "meta" => TallGrass::FERN],
			["id" => Item::DEAD_BUSH],
			["id" => Item::SAPLING, "meta" => Sapling::OAK],
			["id" => Item::SAPLING, "meta" => Sapling::SPRUCE],
			["id" => Item::SAPLING, "meta" => Sapling::BIRCH],
			["id" => Item::SAPLING, "meta" => Sapling::JUNGLE],
			["id" => Item::SAPLING, "meta" => Sapling::ACACIA],
			["id" => Item::SAPLING, "meta" => Sapling::DARK_OAK],
			["id" => Item::LEAVES, "meta" => Leaves::OAK],
			["id" => Item::LEAVES, "meta" => Leaves::SPRUCE],
			["id" => Item::LEAVES, "meta" => Leaves::BIRCH],
			["id" => Item::LEAVES, "meta" => Leaves::JUNGLE],
			["id" => Item::LEAVES2, "meta" => Leaves::ACACIA], #blamemojang
			["id" => Item::LEAVES2, "meta" => Leaves::DARK_OAK],
			["id" => Item::CAKE],
			["id" => Item::SKULL, "meta" => Skull::SKELETON],
			["id" => Item::SKULL, "meta" => Skull::WITHER_SKELETON],
			["id" => Item::SKULL, "meta" => Skull::ZOMBIE_HEAD],
			["id" => Item::SKULL, "meta" => Skull::STEVE_HEAD],
			["id" => Item::SKULL, "meta" => Skull::CREEPER_HEAD],
			["id" => Item::FLOWER_POT],
			["id" => Item::MONSTER_SPAWNER],
			["id" => Item::ENCHANTMENT_TABLE],
			["id" => Item::SLIME_BLOCK],
			["id" => Item::WOOL, "meta" => 0], //White
			["id" => Item::WOOL, "meta" => 8], //Light grey
			["id" => Item::WOOL, "meta" => 7], //Dark grey
			["id" => Item::WOOL, "meta" => 15], //Black
			["id" => Item::WOOL, "meta" => 12], //Brown
			["id" => Item::WOOL, "meta" => 14], //Red
			["id" => Item::WOOL, "meta" => 1], //Orange
			["id" => Item::WOOL, "meta" => 4], //Yellow
			["id" => Item::WOOL, "meta" => 5], //Lime
			["id" => Item::WOOL, "meta" => 13], //Green
			["id" => Item::WOOL, "meta" => 9], //Cyan
			["id" => Item::WOOL, "meta" => 3], //Light blue
			["id" => Item::WOOL, "meta" => 11], //Blue
			["id" => Item::WOOL, "meta" => 10], //Purple
			["id" => Item::WOOL, "meta" => 2], //Magenta
			["id" => Item::WOOL, "meta" => 6], //Pink
			["id" => Item::CARPET, "meta" => 0], //White
			["id" => Item::CARPET, "meta" => 8], //Light grey
			["id" => Item::CARPET, "meta" => 7], //Dark grey
			["id" => Item::CARPET, "meta" => 15], //Black
			["id" => Item::CARPET, "meta" => 12], //Brown
			["id" => Item::CARPET, "meta" => 14], //Red
			["id" => Item::CARPET, "meta" => 1], //Orange
			["id" => Item::CARPET, "meta" => 4], //Yellow
			["id" => Item::CARPET, "meta" => 5], //Lime
			["id" => Item::CARPET, "meta" => 13], //Green
			["id" => Item::CARPET, "meta" => 9], //Cyan
			["id" => Item::CARPET, "meta" => 3], //Light blue
			["id" => Item::CARPET, "meta" => 11], //Blue
			["id" => Item::CARPET, "meta" => 10], //Purple
			["id" => Item::CARPET, "meta" => 2], //Magenta
			["id" => Item::CARPET, "meta" => 6], //Pink
		],
		self::CATEGORY_TOOLS => [
			["id" => Item::RAIL],
			["id" => Item::POWERED_RAIL],
			["id" => Item::DETECTOR_RAIL],
			["id" => Item::ACTIVATOR_RAIL],
			["id" => Item::TORCH],
			["id" => Item::BUCKET],
			["id" => Item::BUCKET, "meta" => 1],
			["id" => Item::BUCKET, "meta" => 8],
			["id" => Item::BUCKET, "meta" => 10],
			["id" => Item::TNT],
			["id" => Item::LEAD],
			["id" => Item::NAMETAG],
			["id" => Item::REDSTONE_DUST],
			["id" => Item::BOW],
			["id" => Item::FISHING_ROD],
			["id" => Item::FLINT_STEEL],
			["id" => Item::SHEARS],
			["id" => Item::CLOCK],
			["id" => Item::COMPASS],
			["id" => Item::MINECART],
			["id" => Item::MINECART_WITH_CHEST],
			["id" => Item::MINECART_WITH_HOPPER],
			["id" => Item::MINECART_WITH_TNT],
			["id" => Item::BOAT, "meta" => Planks::OAK],
			["id" => Item::BOAT, "meta" => Planks::SPRUCE],
			["id" => Item::BOAT, "meta" => Planks::BIRCH],
			["id" => Item::BOAT, "meta" => Planks::JUNGLE],
			["id" => Item::BOAT, "meta" => Planks::ACACIA],
			["id" => Item::BOAT, "meta" => Planks::DARK_OAK],
			["id" => Item::SADDLE],
			["id" => Item::LEATHER_HORSE_ARMOR],
			["id" => Item::IRON_HORSE_ARMOR],
			["id" => Item::GOLD_HORSE_ARMOR],
			["id" => Item::DIAMOND_HORSE_ARMOR],
			["id" => Item::SPAWN_EGG, "meta" => 15], //Villager
			["id" => Item::SPAWN_EGG, "meta" => 10], //Chicken
			["id" => Item::SPAWN_EGG, "meta" => 11], //Cow
			["id" => Item::SPAWN_EGG, "meta" => 12], //Pig
			["id" => Item::SPAWN_EGG, "meta" => 13], //Sheep
			["id" => Item::SPAWN_EGG, "meta" => 14], //Wolf
			["id" => Item::SPAWN_EGG, "meta" => 22], //Ocelot
			["id" => Item::SPAWN_EGG, "meta" => 16], //Mooshroom
			["id" => Item::SPAWN_EGG, "meta" => 19], //Bat
			["id" => Item::SPAWN_EGG, "meta" => 18], //Rabbit
			["id" => Item::SPAWN_EGG, "meta" => 23], //Horse
			["id" => Item::SPAWN_EGG, "meta" => 24], //Donkey
			["id" => Item::SPAWN_EGG, "meta" => 25], //Mule
			["id" => Item::SPAWN_EGG, "meta" => 26], //Skeleton Horse
			["id" => Item::SPAWN_EGG, "meta" => 27], //Zombie horse
			["id" => Item::SPAWN_EGG, "meta" => 33], //Creeper
			["id" => Item::SPAWN_EGG, "meta" => 38], //Enderman
			["id" => Item::SPAWN_EGG, "meta" => 39], //Silverfish
			["id" => Item::SPAWN_EGG, "meta" => 34], //Skeleton
			["id" => Item::SPAWN_EGG, "meta" => 48], //Wither skeleton
			["id" => Item::SPAWN_EGG, "meta" => 46], //Stray
			["id" => Item::SPAWN_EGG, "meta" => 37], //Slime
			["id" => Item::SPAWN_EGG, "meta" => 35], //Spider
			["id" => Item::SPAWN_EGG, "meta" => 32], //Zombie
			["id" => Item::SPAWN_EGG, "meta" => 36], //Zombie Pigman
			["id" => Item::SPAWN_EGG, "meta" => 47], //Husk
			["id" => Item::SPAWN_EGG, "meta" => 17], //Squid
			["id" => Item::SPAWN_EGG, "meta" => 40], //Cave Spider
			["id" => Item::SPAWN_EGG, "meta" => 45], //Witch
			["id" => Item::SPAWN_EGG, "meta" => 42], //Magma Cube
			["id" => Item::SPAWN_EGG, "meta" => 41], //Ghast
			["id" => Item::SPAWN_EGG, "meta" => 43], //Blaze
			["id" => Item::FIRE_CHARGE],
			["id" => Item::WOODEN_SWORD],
			["id" => Item::WOODEN_HOE],
			["id" => Item::WOODEN_SHOVEL],
			["id" => Item::WOODEN_PICKAXE],
			["id" => Item::WOODEN_AXE],
			["id" => Item::STONE_SWORD],
			["id" => Item::STONE_HOE],
			["id" => Item::STONE_SHOVEL],
			["id" => Item::STONE_PICKAXE],
			["id" => Item::STONE_AXE],
			["id" => Item::IRON_SWORD],
			["id" => Item::IRON_HOE],
			["id" => Item::IRON_SHOVEL],
			["id" => Item::IRON_PICKAXE],
			["id" => Item::IRON_AXE],
			["id" => Item::DIAMOND_SWORD],
			["id" => Item::DIAMOND_HOE],
			["id" => Item::DIAMOND_SHOVEL],
			["id" => Item::DIAMOND_PICKAXE],
			["id" => Item::DIAMOND_AXE],
			["id" => Item::GOLD_SWORD],
			["id" => Item::GOLD_HOE],
			["id" => Item::GOLD_SHOVEL],
			["id" => Item::GOLD_PICKAXE],
			["id" => Item::GOLD_AXE],
			["id" => Item::LEATHER_CAP],
			["id" => Item::LEATHER_TUNIC],
			["id" => Item::LEATHER_PANTS],
			["id" => Item::LEATHER_BOOTS],
			["id" => Item::CHAIN_HELMET],
			["id" => Item::CHAIN_CHESTPLATE],
			["id" => Item::CHAIN_LEGGINGS],
			["id" => Item::CHAIN_BOOTS],
			["id" => Item::IRON_HELMET],
			["id" => Item::IRON_CHESTPLATE],
			["id" => Item::IRON_LEGGINGS],
			["id" => Item::IRON_BOOTS],
			["id" => Item::DIAMOND_HELMET],
			["id" => Item::DIAMOND_CHESTPLATE],
			["id" => Item::DIAMOND_LEGGINGS],
			["id" => Item::DIAMOND_BOOTS],
			["id" => Item::GOLD_HELMET],
			["id" => Item::GOLD_CHESTPLATE],
			["id" => Item::GOLD_LEGGINGS],
			["id" => Item::GOLD_BOOTS],
			["id" => Item::LEVER],
			["id" => Item::REDSTONE_LAMP],
			["id" => Item::REDSTONE_TORCH],
			["id" => Item::WOODEN_PRESSURE_PLATE],
			["id" => Item::STONE_PRESSURE_PLATE],
			["id" => Item::GOLD_PRESSURE_PLATE],
			["id" => Item::IRON_PRESSURE_PLATE],
			["id" => Item::WOODEN_BUTTON, "meta" => 5], //meta 5: Fix inventory icon looking wrong
			["id" => Item::STONE_BUTTON, "meta" => 5],
			["id" => Item::DAYLIGHT_SENSOR],
			["id" => Item::TRIPWIRE_HOOK],
			["id" => Item::REPEATER],
			["id" => Item::COMPARATOR],
			["id" => Item::DISPENSER],
			["id" => Item::DROPPER],
			["id" => Item::PISTON],
			["id" => Item::STICKY_PISTON],
			["id" => Item::OBSERVER],
			["id" => Item::HOPPER],
			["id" => Item::SNOWBALL] #blamemojang
		],
		self::CATEGORY_MISCELLANEOUS => [
			["id" => Item::COAL],
			["id" => Item::COAL, "meta" => Coal::CHARCOAL],
			["id" => Item::DIAMOND],
			["id" => Item::IRON_INGOT],
			["id" => Item::GOLD_INGOT],
			["id" => Item::EMERALD],
			["id" => Item::STICK],
			["id" => Item::BOWL],
			["id" => Item::STRING],
			["id" => Item::FEATHER],
			["id" => Item::FLINT],
			["id" => Item::LEATHER],
			["id" => Item::RABBIT_HIDE],
			["id" => Item::CLAY],
			["id" => Item::SUGAR],
			["id" => Item::QUARTZ],
			["id" => Item::PAPER],
			["id" => Item::BOOK],
			["id" => Item::ARROW],
			["id" => Item::BONE],
			["id" => Item::EMPTY_MAP],
			["id" => Item::SUGARCANE],
			["id" => Item::WHEAT],
			["id" => Item::ARROW, "meta" => 6], //TODO: Pull these out into the Arrow class
			["id" => Item::ARROW, "meta" => 7],
			["id" => Item::ARROW, "meta" => 8],
			["id" => Item::ARROW, "meta" => 9],
			["id" => Item::ARROW, "meta" => 10],
			["id" => Item::ARROW, "meta" => 11],
			["id" => Item::ARROW, "meta" => 12],
			["id" => Item::ARROW, "meta" => 13],
			["id" => Item::ARROW, "meta" => 14],
			["id" => Item::ARROW, "meta" => 15],
			["id" => Item::ARROW, "meta" => 16],
			["id" => Item::ARROW, "meta" => 17],
			["id" => Item::ARROW, "meta" => 18],
			["id" => Item::ARROW, "meta" => 19],
			["id" => Item::ARROW, "meta" => 20],
			["id" => Item::ARROW, "meta" => 21],
			["id" => Item::ARROW, "meta" => 22],
			["id" => Item::ARROW, "meta" => 23],
			["id" => Item::ARROW, "meta" => 24],
			["id" => Item::ARROW, "meta" => 25],
			["id" => Item::ARROW, "meta" => 26],
			["id" => Item::ARROW, "meta" => 27],
			["id" => Item::ARROW, "meta" => 28],
			["id" => Item::ARROW, "meta" => 29],
			["id" => Item::ARROW, "meta" => 30],
			["id" => Item::ARROW, "meta" => 31],
			["id" => Item::ARROW, "meta" => 32],
			["id" => Item::ARROW, "meta" => 33],
			["id" => Item::ARROW, "meta" => 34],
			["id" => Item::ARROW, "meta" => 35],
			["id" => Item::ARROW, "meta" => 36],
			["id" => Item::SEEDS],
			["id" => Item::PUMPKIN_SEEDS],
			["id" => Item::MELON_SEEDS],
			["id" => Item::BEETROOT_SEEDS],
			["id" => Item::EGG],
			["id" => Item::APPLE],
			["id" => Item::GOLDEN_APPLE],
			["id" => Item::ENCHANTED_GOLDEN_APPLE],
			["id" => Item::RAW_FISH],
			["id" => Item::RAW_SALMON],
			["id" => Item::CLOWN_FISH],
			["id" => Item::PUFFER_FISH],
			["id" => Item::COOKED_FISH],
			["id" => Item::COOKED_SALMON],
			["id" => Item::ROTTEN_FLESH],
			["id" => Item::MUSHROOM_STEW],
			["id" => Item::BREAD],
			["id" => Item::RAW_PORKCHOP],
			["id" => Item::COOKED_PORKCHOP],
			["id" => Item::RAW_CHICKEN],
			["id" => Item::COOKED_CHICKEN],
			["id" => Item::RAW_MUTTON],
			["id" => Item::COOKED_MUTTON],
			["id" => Item::RAW_BEEF],
			["id" => Item::STEAK],
			["id" => Item::MELON],
			["id" => Item::CARROT],
			["id" => Item::POTATO],
			["id" => Item::BAKED_POTATO],
			["id" => Item::POISONOUS_POTATO],
			["id" => Item::BEETROOT],
			["id" => Item::COOKIE],
			["id" => Item::PUMPKIN_PIE],
			["id" => Item::RAW_RABBIT],
			["id" => Item::COOKED_RABBIT],
			["id" => Item::RABBIT_STEW],
			["id" => Item::MAGMA_CREAM],
			["id" => Item::BLAZE_ROD],
			["id" => Item::GOLD_NUGGET],
			["id" => Item::GOLDEN_CARROT],
			["id" => Item::GLISTERING_MELON],
			["id" => Item::RABBIT_FOOT],
			["id" => Item::GHAST_TEAR],
			["id" => Item::SLIMEBALL],
			["id" => Item::BLAZE_POWDER],
			["id" => Item::NETHER_WART],
			["id" => Item::GUNPOWDER],
			["id" => Item::GLOWSTONE_DUST],
			["id" => Item::SPIDER_EYE],
			["id" => Item::FERMENTED_SPIDER_EYE],
			["id" => Item::CARROT_ON_A_STICK],
			["id" => Item::BOTTLE_O_ENCHANTING],
			/*["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_PROTECTION, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_PROTECTION, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_PROTECTION, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_PROTECTION, "lvl" => 4]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_FIRE_PROTECTION, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_FIRE_PROTECTION, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_FIRE_PROTECTION, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_FIRE_PROTECTION, "lvl" => 4]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_FALL_PROTECTION, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_FALL_PROTECTION, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_FALL_PROTECTION, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_FALL_PROTECTION, "lvl" => 4]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_EXPLOSION_PROTECTION, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_EXPLOSION_PROTECTION, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_EXPLOSION_PROTECTION, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_EXPLOSION_PROTECTION, "lvl" => 4]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_PROJECTILE_PROTECTION, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_PROJECTILE_PROTECTION, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_PROJECTILE_PROTECTION, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_PROJECTILE_PROTECTION, "lvl" => 4]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_THORNS, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_THORNS, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_ARMOR_THORNS, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WATER_BREATHING, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WATER_BREATHING, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WATER_BREATHING, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WATER_SPEED, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WATER_SPEED, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WATER_SPEED, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WATER_AFFINITY, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_SHARPNESS, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_SHARPNESS, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_SHARPNESS, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_SHARPNESS, "lvl" => 4]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_SHARPNESS, "lvl" => 5]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_SMITE, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_SMITE, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_SMITE, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_SMITE, "lvl" => 4]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_SMITE, "lvl" => 5]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_ARTHROPODS, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_ARTHROPODS, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_ARTHROPODS, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_ARTHROPODS, "lvl" => 4]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_ARTHROPODS, "lvl" => 5]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_KNOCKBACK, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_KNOCKBACK, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_FIRE_ASPECT, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_FIRE_ASPECT, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_LOOTING, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_LOOTING, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_WEAPON_LOOTING, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_EFFICIENCY, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_EFFICIENCY, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_EFFICIENCY, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_EFFICIENCY, "lvl" => 4]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_EFFICIENCY, "lvl" => 5]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_SILK_TOUCH, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_DURABILITY, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_DURABILITY, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_DURABILITY, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_FORTUNE, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_FORTUNE, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_MINING_FORTUNE, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_BOW_POWER, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_BOW_POWER, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_BOW_POWER, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_BOW_POWER, "lvl" => 4]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_BOW_POWER, "lvl" => 5]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_BOW_KNOCKBACK, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_BOW_KNOCKBACK, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_BOW_FLAME, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_BOW_INFINITY, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_FISHING_FORTUNE, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_FISHING_FORTUNE, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_FISHING_FORTUNE, "lvl" => 3]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_FISHING_LURE, "lvl" => 1]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_FISHING_LURE, "lvl" => 2]]],
			["id" => Item::ENCHANTED_BOOK, "ench" => [["id" => Enchantment::TYPE_FISHING_LURE, "lvl" => 3]]],*/
			["id" => Item::DYE, "meta" => Dye::BLACK],
			["id" => Item::DYE, "meta" => Dye::GRAY],
			["id" => Item::DYE, "meta" => Dye::LIGHT_GRAY],
			["id" => Item::DYE, "meta" => Dye::BONE_MEAL],
			["id" => Item::DYE, "meta" => Dye::LIGHT_BLUE],
			["id" => Item::DYE, "meta" => Dye::ORANGE],
			["id" => Item::DYE, "meta" => Dye::RED],
			["id" => Item::DYE, "meta" => Dye::LAPIS_LAZULI],
			["id" => Item::DYE, "meta" => Dye::PURPLE],
			["id" => Item::DYE, "meta" => Dye::MAGENTA],
			["id" => Item::DYE, "meta" => Dye::PINK],
			["id" => Item::DYE, "meta" => Dye::COCOA_BEANS],
			["id" => Item::DYE, "meta" => Dye::YELLOW],
			["id" => Item::DYE, "meta" => Dye::LIME],
			["id" => Item::DYE, "meta" => Dye::GREEN],
			["id" => Item::DYE, "meta" => Dye::CYAN],
			["id" => Item::GLASS_BOTTLE],
			["id" => Item::POTION, "meta" => Potion::WATER_BOTTLE],
			["id" => Item::POTION, "meta" => Potion::MUNDANE],
			["id" => Item::POTION, "meta" => Potion::MUNDANE_EXTENDED],
			["id" => Item::POTION, "meta" => Potion::THICK],
			["id" => Item::POTION, "meta" => Potion::AWKWARD],
			["id" => Item::POTION, "meta" => Potion::NIGHT_VISION],
			["id" => Item::POTION, "meta" => Potion::NIGHT_VISION_T],
			["id" => Item::POTION, "meta" => Potion::INVISIBILITY],
			["id" => Item::POTION, "meta" => Potion::INVISIBILITY_T],
			["id" => Item::POTION, "meta" => Potion::LEAPING],
			["id" => Item::POTION, "meta" => Potion::LEAPING_T],
			["id" => Item::POTION, "meta" => Potion::LEAPING_TWO],
			["id" => Item::POTION, "meta" => Potion::FIRE_RESISTANCE],
			["id" => Item::POTION, "meta" => Potion::FIRE_RESISTANCE_T],
			["id" => Item::POTION, "meta" => Potion::SWIFTNESS],
			["id" => Item::POTION, "meta" => Potion::SWIFTNESS_T],
			["id" => Item::POTION, "meta" => Potion::SWIFTNESS_TWO],
			["id" => Item::POTION, "meta" => Potion::SLOWNESS],
			["id" => Item::POTION, "meta" => Potion::SLOWNESS_T],
			["id" => Item::POTION, "meta" => Potion::WATER_BREATHING],
			["id" => Item::POTION, "meta" => Potion::WATER_BREATHING_T],
			["id" => Item::POTION, "meta" => Potion::HEALING],
			["id" => Item::POTION, "meta" => Potion::HEALING_TWO],
			["id" => Item::POTION, "meta" => Potion::HARMING],
			["id" => Item::POTION, "meta" => Potion::HARMING_TWO],
			["id" => Item::POTION, "meta" => Potion::POISON],
			["id" => Item::POTION, "meta" => Potion::POISON_T],
			["id" => Item::POTION, "meta" => Potion::POISON_TWO],
			["id" => Item::POTION, "meta" => Potion::REGENERATION],
			["id" => Item::POTION, "meta" => Potion::REGENERATION_T],
			["id" => Item::POTION, "meta" => Potion::REGENERATION_TWO],
			["id" => Item::POTION, "meta" => Potion::STRENGTH],
			["id" => Item::POTION, "meta" => Potion::STRENGTH_T],
			["id" => Item::POTION, "meta" => Potion::STRENGTH_TWO],
			["id" => Item::POTION, "meta" => Potion::WEAKNESS],
			["id" => Item::POTION, "meta" => Potion::WEAKNESS_T],
			["id" => Item::SPLASH_POTION, "meta" => Potion::WATER_BOTTLE],
			["id" => Item::SPLASH_POTION, "meta" => Potion::MUNDANE],
			["id" => Item::SPLASH_POTION, "meta" => Potion::MUNDANE_EXTENDED],
			["id" => Item::SPLASH_POTION, "meta" => Potion::THICK],
			["id" => Item::SPLASH_POTION, "meta" => Potion::AWKWARD],
			["id" => Item::SPLASH_POTION, "meta" => Potion::NIGHT_VISION],
			["id" => Item::SPLASH_POTION, "meta" => Potion::NIGHT_VISION_T],
			["id" => Item::SPLASH_POTION, "meta" => Potion::INVISIBILITY],
			["id" => Item::SPLASH_POTION, "meta" => Potion::INVISIBILITY_T],
			["id" => Item::SPLASH_POTION, "meta" => Potion::LEAPING],
			["id" => Item::SPLASH_POTION, "meta" => Potion::LEAPING_T],
			["id" => Item::SPLASH_POTION, "meta" => Potion::LEAPING_TWO],
			["id" => Item::SPLASH_POTION, "meta" => Potion::FIRE_RESISTANCE],
			["id" => Item::SPLASH_POTION, "meta" => Potion::FIRE_RESISTANCE_T],
			["id" => Item::SPLASH_POTION, "meta" => Potion::SWIFTNESS],
			["id" => Item::SPLASH_POTION, "meta" => Potion::SWIFTNESS_T],
			["id" => Item::SPLASH_POTION, "meta" => Potion::SWIFTNESS_TWO],
			["id" => Item::SPLASH_POTION, "meta" => Potion::SLOWNESS],
			["id" => Item::SPLASH_POTION, "meta" => Potion::SLOWNESS_T],
			["id" => Item::SPLASH_POTION, "meta" => Potion::WATER_BREATHING],
			["id" => Item::SPLASH_POTION, "meta" => Potion::WATER_BREATHING_T],
			["id" => Item::SPLASH_POTION, "meta" => Potion::HEALING],
			["id" => Item::SPLASH_POTION, "meta" => Potion::HEALING_TWO],
			["id" => Item::SPLASH_POTION, "meta" => Potion::HARMING],
			["id" => Item::SPLASH_POTION, "meta" => Potion::HARMING_TWO],
			["id" => Item::SPLASH_POTION, "meta" => Potion::POISON],
			["id" => Item::SPLASH_POTION, "meta" => Potion::POISON_T],
			["id" => Item::SPLASH_POTION, "meta" => Potion::POISON_TWO],
			["id" => Item::SPLASH_POTION, "meta" => Potion::REGENERATION],
			["id" => Item::SPLASH_POTION, "meta" => Potion::REGENERATION_T],
			["id" => Item::SPLASH_POTION, "meta" => Potion::REGENERATION_TWO],
			["id" => Item::SPLASH_POTION, "meta" => Potion::STRENGTH],
			["id" => Item::SPLASH_POTION, "meta" => Potion::STRENGTH_T],
			["id" => Item::SPLASH_POTION, "meta" => Potion::STRENGTH_TWO],
			["id" => Item::SPLASH_POTION, "meta" => Potion::WEAKNESS],
			["id" => Item::SPLASH_POTION, "meta" => Potion::WEAKNESS_T],
		],
	];

}
