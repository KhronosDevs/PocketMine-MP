<?php
/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace pocketmine\inventory;

use pocketmine\block\Planks;
use pocketmine\block\Quartz;
use pocketmine\block\Sandstone;
use pocketmine\block\Slab;
use pocketmine\block\Stone;
use pocketmine\block\StoneBricks;
use pocketmine\block\StoneWall;
use pocketmine\block\Wood;
use pocketmine\block\Wood2;
use pocketmine\event\Timings;
use pocketmine\item\Item;
use pocketmine\item\Potion;
use pocketmine\network\protocol\CraftingDataPacket;
use pocketmine\utils\UUID;
use pocketmine\Server;
use pocketmine\utils\MainLogger;
use pocketmine\utils\Config;

class CraftingManager
{
	/** @var Recipe[] */
	public $recipes = [];

	/** @var Recipe[][] */
	protected $recipeLookup = [];

	/** @var FurnaceRecipe[] */
	public $furnaceRecipes = [];

	/** @var BrewingRecipe[] */
	public $brewingRecipes = [];

	private static $RECIPE_COUNT = 0;

	/** @var CraftingDataPacket */
	private $craftingDataCache;

	public function __construct()
	{
		$this->registerBrewingStand();
		// load recipes from src/pocketmine/recipes.json
		$recipes = new Config(Server::getInstance()->getFilePath() . "src/pocketmine/resources/recipes.json", Config::JSON, []);

		MainLogger::getLogger()->info("Loading recipes...");
		foreach ($recipes->getAll() as $recipe) {
			switch ($recipe["Type"]) {
				case 0:
					// TODO: handle multiple result items
					if (count($recipe["Result"]) == 1) {
						$first = $recipe["Result"][0];
						$result = new ShapelessRecipe(Item::get($first["ID"], $first["Damage"], $first["Count"]));

						foreach ($recipe["Ingredients"] as $ingredient) {
							$result->addIngredient(Item::get($ingredient["ID"], $ingredient["Damage"], $ingredient["Count"]));
						}
						$this->registerRecipe($result);
					}
					break;
				case 1:
					// TODO: handle multiple result items
					if (count($recipe["Result"]) == 1) {
						$first = $recipe["Result"][0];
						$result = new ShapedRecipeFromJson(Item::get($first["ID"], $first["Damage"], $first["Count"]), $recipe["Height"], $recipe["Width"]);

						$shape = array_chunk($recipe["Ingredients"], $recipe["Width"]);
						foreach ($shape as $y => $row) {
							foreach ($row as $x => $ingredient) {
								$result->addIngredient($x, $y, Item::get($ingredient["ID"], ($ingredient["Damage"] < 0 ? -1 : $ingredient["Damage"]), $ingredient["Count"]));
							}
						}
						$this->registerRecipe($result);
					}
					break;
				case 2:
					$result = $recipe["Result"];
					$resultItem = Item::get($result["ID"], $result["Damage"], $result["Count"]);
					$this->registerRecipe(new FurnaceRecipe($resultItem, Item::get($recipe["Ingredients"], 0, 1)));
					break;
				case 3:
					$result = $recipe["Result"];
					$resultItem = Item::get($result["ID"], $result["Damage"], $result["Count"]);
					$this->registerRecipe(new FurnaceRecipe($resultItem, Item::get($recipe["Ingredients"]["ID"], $recipe["Ingredients"]["Damage"] ?? -1, 1)));
					break;
				default:
					break;
			}
		}
		
		$this->buildCraftingDataCache();
	}

	/**
	 * Rebuilds the cached CraftingDataPacket.
	 */
	public function buildCraftingDataCache(){
		Timings::$craftingDataCacheRebuildTimer->startTiming();
		$pk = new CraftingDataPacket();
		$pk->cleanRecipes = true;

		foreach($this->recipes as $recipe){
			if($recipe instanceof ShapedRecipe){
				$pk->addShapedRecipe($recipe);
			}elseif($recipe instanceof ShapelessRecipe){
				$pk->addShapelessRecipe($recipe);
			}
		}

		foreach($this->furnaceRecipes as $recipe){
			$pk->addFurnaceRecipe($recipe);
		}

		$pk->encode();
		$pk->isEncoded = true;

		$this->craftingDataCache = $pk;
		Timings::$craftingDataCacheRebuildTimer->stopTiming();
	}

	/**
	 * Returns a CraftingDataPacket for sending to players. Rebuilds the cache if it is outdated.
	 *
	 * @return CraftingDataPacket
	 */
	public function getCraftingDataPacket() : CraftingDataPacket{
		if($this->craftingDataCache === null){
			$this->buildCraftingDataCache();
		}

		return $this->craftingDataCache;
	}

	protected function registerBrewingStand()
	{
		//Potion
		//WATER_BOTTLE
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::AWKWARD, 1), Item::get(Item::NETHER_WART, 0, 1), Item::get(Item::POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::THICK, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::MUNDANE_EXTENDED, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::WEAKNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::WATER_BOTTLE, 1)));

		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::MUNDANE, 1), Item::get(Item::GHAST_TEAR, 0, 1), Item::get(Item::POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::MUNDANE, 1), Item::get(Item::GLISTERING_MELON, 0, 1), Item::get(Item::POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::MUNDANE, 1), Item::get(Item::BLAZE_POWDER, 0, 1), Item::get(Item::POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::MUNDANE, 1), Item::get(Item::MAGMA_CREAM, 0, 1), Item::get(Item::POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::MUNDANE, 1), Item::get(Item::SUGAR, 0, 1), Item::get(Item::POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::MUNDANE, 1), Item::get(Item::SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::MUNDANE, 1), Item::get(Item::RABBIT_FOOT, 0, 1), Item::get(Item::POTION, Potion::WATER_BOTTLE, 1)));
		//To WEAKNESS
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::WEAKNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::MUNDANE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::WEAKNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::THICK, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::WEAKNESS_T, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::MUNDANE_EXTENDED, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::WEAKNESS_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::WEAKNESS, 1)));
		//GHAST_TEAR and BLAZE_POWDER
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::REGENERATION, 1), Item::get(Item::GHAST_TEAR, 0, 1), Item::get(Item::POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::REGENERATION_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::REGENERATION, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::REGENERATION_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::REGENERATION, 1)));

		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::STRENGTH, 1), Item::get(Item::BLAZE_POWDER, 0, 1), Item::get(Item::POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::STRENGTH_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::STRENGTH, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::STRENGTH_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::STRENGTH, 1)));
		//SPIDER_EYE GLISTERING_MELON and PUFFERFISH
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::POISON, 1), Item::get(Item::SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::POISON_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::POISON, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::POISON_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::POISON, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::HEALING, 1), Item::get(Item::GLISTERING_MELON, 0, 1), Item::get(Item::POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::HEALING_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::HEALING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::WATER_BREATHING, 1), Item::get(Item::PUFFER_FISH, 0, 1), Item::get(Item::POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::WATER_BREATHING_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::WATER_BREATHING, 1)));

		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::HARMING, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::WATER_BREATHING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::HARMING, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::HEALING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::HARMING, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::POISON, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::HARMING_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::HARMING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::HARMING_TWO, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::HEALING_TWO, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::HARMING_TWO, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::POISON_T, 1)));
		//SUGAR MAGMA_CREAM and RABBIT_FOOT
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::SWIFTNESS, 1), Item::get(Item::SUGAR, 0, 1), Item::get(Item::POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::SWIFTNESS_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::SWIFTNESS, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::SWIFTNESS_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::SWIFTNESS, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::FIRE_RESISTANCE, 1), Item::get(Item::MAGMA_CREAM, 0, 1), Item::get(Item::POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::FIRE_RESISTANCE_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::FIRE_RESISTANCE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::LEAPING, 1), Item::get(Item::RABBIT_FOOT, 0, 1), Item::get(Item::POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::LEAPING_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::LEAPING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::LEAPING_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::LEAPING, 1)));

		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::SLOWNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::FIRE_RESISTANCE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::SLOWNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::SWIFTNESS, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::SLOWNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::LEAPING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::SLOWNESS_T, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::FIRE_RESISTANCE_T, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::SLOWNESS_T, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::LEAPING_T, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::SLOWNESS_T, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::SWIFTNESS_T, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::SLOWNESS_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::SLOWNESS, 1)));
		//GOLDEN_CARROT
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::NIGHT_VISION, 1), Item::get(Item::GOLDEN_CARROT, 0, 1), Item::get(Item::POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::NIGHT_VISION_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::NIGHT_VISION, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::INVISIBILITY, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::NIGHT_VISION, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::INVISIBILITY_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::POTION, Potion::INVISIBILITY, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::POTION, Potion::INVISIBILITY_T, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::POTION, Potion::NIGHT_VISION_T, 1)));
		//===================================================================分隔符=======================================================================
		//SPLASH_POTION
		//WATER_BOTTLE
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::AWKWARD, 1), Item::get(Item::NETHER_WART, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::THICK, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::MUNDANE_EXTENDED, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::WEAKNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BOTTLE, 1)));

		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::MUNDANE, 1), Item::get(Item::GHAST_TEAR, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::MUNDANE, 1), Item::get(Item::GLISTERING_MELON, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::MUNDANE, 1), Item::get(Item::BLAZE_POWDER, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::MUNDANE, 1), Item::get(Item::MAGMA_CREAM, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::MUNDANE, 1), Item::get(Item::SUGAR, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::MUNDANE, 1), Item::get(Item::SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BOTTLE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::MUNDANE, 1), Item::get(Item::RABBIT_FOOT, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BOTTLE, 1)));
		//To WEAKNESS
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::WEAKNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::MUNDANE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::WEAKNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::THICK, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::WEAKNESS_T, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::MUNDANE_EXTENDED, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::WEAKNESS_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WEAKNESS, 1)));
		//GHAST_TEAR and BLAZE_POWDER
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::REGENERATION, 1), Item::get(Item::GHAST_TEAR, 0, 1), Item::get(Item::SPLASH_POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::REGENERATION_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::REGENERATION, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::REGENERATION_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::REGENERATION, 1)));

		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::STRENGTH, 1), Item::get(Item::BLAZE_POWDER, 0, 1), Item::get(Item::SPLASH_POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::STRENGTH_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::STRENGTH, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::STRENGTH_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::STRENGTH, 1)));
		//SPIDER_EYE GLISTERING_MELON and PUFFERFISH
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::POISON, 1), Item::get(Item::SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::POISON_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::POISON, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::POISON_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::POISON, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::HEALING, 1), Item::get(Item::GLISTERING_MELON, 0, 1), Item::get(Item::SPLASH_POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::HEALING_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::HEALING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::WATER_BREATHING, 1), Item::get(Item::PUFFER_FISH, 0, 1), Item::get(Item::SPLASH_POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::WATER_BREATHING_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BREATHING, 1)));

		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::HARMING, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::WATER_BREATHING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::HARMING, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::HEALING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::HARMING, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::POISON, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::HARMING_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::HARMING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::HARMING_TWO, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::HEALING_TWO, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::HARMING_TWO, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::POISON_T, 1)));
		//SUGAR MAGMA_CREAM and RABBIT_FOOT
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::SWIFTNESS, 1), Item::get(Item::SUGAR, 0, 1), Item::get(Item::SPLASH_POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::SWIFTNESS_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::SWIFTNESS, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::SWIFTNESS_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::SWIFTNESS, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::FIRE_RESISTANCE, 1), Item::get(Item::MAGMA_CREAM, 0, 1), Item::get(Item::SPLASH_POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::FIRE_RESISTANCE_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::FIRE_RESISTANCE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::LEAPING, 1), Item::get(Item::RABBIT_FOOT, 0, 1), Item::get(Item::SPLASH_POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::LEAPING_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::LEAPING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::LEAPING_TWO, 1), Item::get(Item::GLOWSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::LEAPING, 1)));

		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::SLOWNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::FIRE_RESISTANCE, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::SLOWNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::SWIFTNESS, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::SLOWNESS, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::LEAPING, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::SLOWNESS_T, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::FIRE_RESISTANCE_T, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::SLOWNESS_T, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::LEAPING_T, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::SLOWNESS_T, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::SWIFTNESS_T, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::SLOWNESS_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::SLOWNESS, 1)));
		//GOLDEN_CARROT
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::NIGHT_VISION, 1), Item::get(Item::GOLDEN_CARROT, 0, 1), Item::get(Item::SPLASH_POTION, Potion::AWKWARD, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::NIGHT_VISION_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::NIGHT_VISION, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::INVISIBILITY, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::NIGHT_VISION, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::INVISIBILITY_T, 1), Item::get(Item::REDSTONE_DUST, 0, 1), Item::get(Item::SPLASH_POTION, Potion::INVISIBILITY, 1)));
		$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, Potion::INVISIBILITY_T, 1), Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1), Item::get(Item::SPLASH_POTION, Potion::NIGHT_VISION_T, 1)));
		//===================================================================分隔符=======================================================================
		//普通药水升级成喷溅
		foreach (Potion::POTIONS as $potion => $effect) {
			$this->registerBrewingRecipe(new BrewingRecipe(Item::get(Item::SPLASH_POTION, $potion, 1), Item::get(Item::GUNPOWDER, 0, 1), Item::get(Item::POTION, $potion, 1)));
		}
	}

	protected function registerFurnace()
	{
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::STONE, 0, 1), Item::get(Item::COBBLESTONE, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::STONE_BRICK, 2, 1), Item::get(Item::STONE_BRICK, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::GLASS, 0, 1), Item::get(Item::SAND, null, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::COAL, 1, 1), Item::get(Item::TRUNK, null, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::COAL, 1, 1), Item::get(Item::TRUNK2, null, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::GOLD_INGOT, 0, 1), Item::get(Item::GOLD_ORE, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::IRON_INGOT, 0, 1), Item::get(Item::IRON_ORE, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::EMERALD, 0, 1), Item::get(Item::EMERALD_ORE, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::DIAMOND, 0, 1), Item::get(Item::DIAMOND_ORE, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::NETHER_BRICK, 0, 1), Item::get(Item::NETHERRACK, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::COOKED_PORKCHOP, 0, 1), Item::get(Item::RAW_PORKCHOP, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::BRICK, 0, 1), Item::get(Item::CLAY, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::COOKED_FISH, 0, 1), Item::get(Item::RAW_FISH, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::COOKED_FISH, 1, 1), Item::get(Item::RAW_FISH, 1, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::DYE, 2, 1), Item::get(Item::CACTUS, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::DYE, 1, 1), Item::get(Item::RED_MUSHROOM, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::STEAK, 0, 1), Item::get(Item::RAW_BEEF, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::COOKED_CHICKEN, 0, 1), Item::get(Item::RAW_CHICKEN, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::BAKED_POTATO, 0, 1), Item::get(Item::POTATO, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::HARDENED_CLAY, 0, 1), Item::get(Item::CLAY_BLOCK, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::COOKED_RABBIT, 0, 1), Item::get(Item::RAW_RABBIT, 0, 1)));
		$this->registerRecipe(new FurnaceRecipe(Item::get(Item::COOKED_MUTTON, 0, 1), Item::get(Item::RAW_MUTTON, 0, 1)));
	}

	private function sortAndAddRecipesArray(&$recipes)
	{
		// Sort the recipes based on the result item name with the bubblesort algoritm.
		for ($i = 0; $i < count($recipes); ++$i) {
			$current = $recipes[$i];
			$result = $current->getResult();
			for ($j = count($recipes) - 1; $j > $i; --$j) {
				if ($this->sort($result, $recipes[$j]->getResult()) > 0) {
					$swap = $current;
					$current = $recipes[$j];
					$recipes[$j] = $swap;
					$result = $current->getResult();
				}
			}
			$this->registerRecipe($current);
		}
	}

	private function createOneIngedientRecipe($recipeshape, $resultitem, $resultitemmeta, $resultitemamound, $ingedienttype, $ingredientmeta, $ingredientname, $inventoryType = "")
	{
		$ingredientamount = 0;
		$height = 0;
		// count how many of the ingredient are in the recipe and check height for big or small recipe.
		foreach ($recipeshape as $line) {
			$height += 1;
			$width = strlen($line);
			$ingredientamount += substr_count($line, $ingredientname);
		}
		$recipe = null;
		if ($height < 3) {
			// Process small recipe
			$fullClassName = "pocketmine\\inventory\\" . $inventoryType . "ShapedRecipe"; // $ShapeClass."ShapedRecipe";
			$recipe = ((new $fullClassName(
				Item::get($resultitem, $resultitemmeta, $resultitemamound),
				...$recipeshape
			))->setIngredient($ingredientname, Item::get($ingedienttype, $ingredientmeta, $ingredientamount)));
		} else {
			// Process big recipe
			$fullClassName = "pocketmine\\inventory\\" . $inventoryType . "BigShapedRecipe";
			$recipe = ((new $fullClassName(
				Item::get($resultitem, $resultitemmeta, $resultitemamound),
				...$recipeshape
			))->setIngredient($ingredientname, Item::get($ingedienttype, $ingredientmeta, $ingredientamount)));
		}
		return $recipe;
	}

	protected function registerFood()
	{
		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::MELON_BLOCK, 0, 1),
			"XXX",
			"XXX",
			"XXX"
		))->setIngredient("X", Item::get(Item::MELON_SLICE, 0, 9)));

		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::BEETROOT_SOUP, 0, 1),
			"XXX",
			"XXX",
			" Y "
		))->setIngredient("X", Item::get(Item::BEETROOT, 0, 6))->setIngredient("Y", Item::get(Item::BOWL, 0, 1)));


		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::BREAD, 0, 1),
			"   ",
			"   ",
			"XXX"
		))->setIngredient("X", Item::get(Item::WHEAT, 0, 3)));

		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::CAKE, 0, 1),
			"XXX",
			"YZY",
			"AAA"
		))->setIngredient("X", Item::get(Item::BUCKET, 1, 3))->setIngredient("Y", Item::get(Item::SUGAR, 0, 2))->setIngredient("Z", Item::get(Item::EGG, 0, 1))->setIngredient("A", Item::get(Item::WHEAT, 0, 3)));

		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::COOKIE, 0, 1),
			"   ",
			"   ",
			"XYX"
		))->setIngredient("X", Item::get(Item::WHEAT, 0, 2))->setIngredient("Y", Item::get(Item::DYE, 3, 1)));

		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::GOLDEN_APPLE, 0, 1),
			"XXX",
			"XYX",
			"XXX"
		))->setIngredient("X", Item::get(Item::GOLD_INGOT, 0, 9))->setIngredient("Y", Item::get(Item::APPLE, 0, 1)));

		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::ENCHANTED_GOLDEN_APPLE, 1, 1),
			"XXX",
			"XYX",
			"XXX"
		))->setIngredient("X", Item::get(Item::GOLD_BLOCK, 0, 9))->setIngredient("Y", Item::get(Item::APPLE, 0, 1)));

		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::MUSHROOM_STEW, 0, 1),
			" X ",
			" Y ",
			" Z "
		))->setIngredient("X", Item::get(Item::RED_MUSHROOM, 0, 1))->setIngredient("Y", Item::get(Item::BROWN_MUSHROOM, 0, 1))->setIngredient("Z", Item::get(Item::BOWL, 0, 1)));

		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::PUMPKIN_PIE, 0, 1),
			"   ",
			"XY ",
			"Z  "
		))->setIngredient("X", Item::get(Item::PUMPKIN, 0, 1))->setIngredient("Y", Item::get(Item::EGG, 0, 1))->setIngredient("Z", Item::get(Item::SUGAR, 0, 1)));

		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::MELON_SEEDS, 0, 1),
			"X ",
			"  "
		))->setIngredient("X", Item::get(Item::MELON_SLICE, 0, 1)));

		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::PUMPKIN_SEEDS, 0, 4),
			"X ",
			"  "
		))->setIngredient("X", Item::get(Item::PUMPKIN, 0, 1)));

		$this->registerRecipe((new ShapedRecipe(
				Item::get(Item::RABBIT_STEW, 0, 1),
				" A ",
				"BCD",
				" E "
			))->setIngredient("A", Item::get(Item::COOKED_RABBIT, 0, 1))
				->setIngredient("B", Item::get(Item::CARROT, 0, 1))
				->setIngredient("C", Item::get(Item::BAKED_POTATO, 0, 1))
				->setIngredient("D", Item::get(Item::BROWN_MUSHROOM, 0, 1))
				->setIngredient("E", Item::get(Item::BOWL, 0, 1))
		);

		$this->registerRecipe((new ShapedRecipe(
				Item::get(Item::RABBIT_STEW, 0, 1),
				" A ",
				"BCD",
				" E "
			))->setIngredient("A", Item::get(Item::COOKED_RABBIT, 0, 1))
				->setIngredient("B", Item::get(Item::CARROT, 0, 1))
				->setIngredient("C", Item::get(Item::BAKED_POTATO, 0, 1))
				->setIngredient("D", Item::get(Item::RED_MUSHROOM, 0, 1))
				->setIngredient("E", Item::get(Item::BOWL, 0, 1))
		);
	}

	protected function registerArmor()
	{
		$types = [
			[Item::LEATHER, Item::FIRE, Item::IRON_INGOT, Item::DIAMOND, Item::GOLD_INGOT],
			[Item::LEATHER_CAP, Item::CHAIN_HELMET, Item::IRON_HELMET, Item::DIAMOND_HELMET, Item::GOLD_HELMET],
			[
				Item::LEATHER_TUNIC,
				Item::CHAIN_CHESTPLATE,
				Item::IRON_CHESTPLATE,
				Item::DIAMOND_CHESTPLATE,
				Item::GOLD_CHESTPLATE
			],
			[
				Item::LEATHER_PANTS,
				Item::CHAIN_LEGGINGS,
				Item::IRON_LEGGINGS,
				Item::DIAMOND_LEGGINGS,
				Item::GOLD_LEGGINGS
			],
			[Item::LEATHER_BOOTS, Item::CHAIN_BOOTS, Item::IRON_BOOTS, Item::DIAMOND_BOOTS, Item::GOLD_BOOTS],
		];
		$shapes = [
			[
				"XXX",
				"X X",
				"   "
			],
			[
				"X X",
				"XXX",
				"XXX"
			],
			[
				"XXX",
				"X X",
				"X X"
			],
			[
				"   ",
				"X X",
				"X X"
			]
		];
		for ($i = 1; $i < 5; ++$i) {
			foreach ($types[$i] as $j => $type) {
				$this->registerRecipe((new BigShapedRecipe(Item::get($type, 0, 1), ...$shapes[$i - 1]))->setIngredient("X", Item::get($types[0][$j], 0, 1)));
			}
		}
	}

	protected function registerWeapons()
	{
		$types = [
			[Item::WOODEN_PLANK, Item::COBBLESTONE, Item::IRON_INGOT, Item::DIAMOND, Item::GOLD_INGOT],
			[Item::WOODEN_SWORD, Item::STONE_SWORD, Item::IRON_SWORD, Item::DIAMOND_SWORD, Item::GOLD_SWORD],
		];
		for ($i = 1; $i < 2; ++$i) {
			foreach ($types[$i] as $j => $type) {
				$this->registerRecipe((new BigShapedRecipe(
					Item::get($type, 0, 1),
					" X ",
					" X ",
					" I "
				))->setIngredient("X", Item::get($types[0][$j], null))->setIngredient("I", Item::get(Item::STICK)));
			}
		}
		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::ARROW, 0, 1),
			" F ",
			" S ",
			" P "
		))->setIngredient("S", Item::get(Item::STICK))->setIngredient("F", Item::get(Item::FLINT))->setIngredient("P", Item::get(Item::FEATHER)));
		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::BOW, 0, 1),
			" X~",
			"X ~",
			" X~"
		))->setIngredient("~", Item::get(Item::STRING))->setIngredient("X", Item::get(Item::STICK)));
	}

	protected function registerTools()
	{
		$types = [
			[Item::WOODEN_PLANK, Item::COBBLESTONE, Item::IRON_INGOT, Item::DIAMOND, Item::GOLD_INGOT],
			[Item::WOODEN_PICKAXE, Item::STONE_PICKAXE, Item::IRON_PICKAXE, Item::DIAMOND_PICKAXE, Item::GOLD_PICKAXE],
			[Item::WOODEN_SHOVEL, Item::STONE_SHOVEL, Item::IRON_SHOVEL, Item::DIAMOND_SHOVEL, Item::GOLD_SHOVEL],
			[Item::WOODEN_AXE, Item::STONE_AXE, Item::IRON_AXE, Item::DIAMOND_AXE, Item::GOLD_AXE],
			[Item::WOODEN_HOE, Item::STONE_HOE, Item::IRON_HOE, Item::DIAMOND_HOE, Item::GOLD_HOE],
		];
		$shapes = [
			[
				"XXX",
				" I ",
				" I "
			],
			[
				" X ",
				" I ",
				" I "
			],
			[
				"XX ",
				"XI ",
				" I "
			],
			[
				"XX ",
				" I ",
				" I "
			]
		];
		for ($i = 1; $i < 5; ++$i) {
			foreach ($types[$i] as $j => $type) {
				$this->registerRecipe((new BigShapedRecipe(Item::get($type, 0, 1), ...$shapes[$i - 1]))->setIngredient("X", Item::get($types[0][$j], null))->setIngredient("I", Item::get(Item::STICK)));
			}
		}
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::FLINT_AND_STEEL, 0, 1),
			" S",
			"F "
		))->setIngredient("F", Item::get(Item::FLINT))->setIngredient("S", Item::get(Item::IRON_INGOT)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::SHEARS, 0, 1),
			" X",
			"X "
		))->setIngredient("X", Item::get(Item::IRON_INGOT)));
	}

	protected function registerDyes()
	{
		/*$this->registerRecipe((new BigShapedRecipe(Item::get(Item::POTION, Potion::SWIFTNESS_TWO, 1),
			"XXX",
			"XXX",
			"XXX"
		))->setIngredient("X", Item::get(Item::COBBLESTONE, 0, 9)));*/
		for ($i = 0; $i < 16; ++$i) {
			$this->registerRecipe((new ShapedRecipe(
				Item::get(Item::WOOL, 15 - $i, 1),
				"X ",
				"Y "
			))->setIngredient("X", Item::get(Item::DYE, $i, 1))->setIngredient("Y", Item::get(Item::WOOL, 0, 1)));
			$this->registerRecipe((new BigShapedRecipe(
				Item::get(Item::STAINED_CLAY, 15 - $i, 8),
				"YYY",
				"YXY",
				"YYY"
			))->setIngredient("X", Item::get(Item::DYE, $i, 1))->setIngredient("Y", Item::get(Item::HARDENED_CLAY, 0, 8)));
			//TODO: add glass things?
			$this->registerRecipe((new ShapedRecipe(
				Item::get(Item::WOOL, 15 - $i, 1),
				"X ",
				"Y "
			))->setIngredient("X", Item::get(Item::DYE, $i, 1))->setIngredient("Y", Item::get(Item::WOOL, 0, 1)));
			$this->registerRecipe((new ShapedRecipe(
				Item::get(Item::WOOL, 15 - $i, 1),
				"X ",
				"Y "
			))->setIngredient("X", Item::get(Item::DYE, $i, 1))->setIngredient("Y", Item::get(Item::WOOL, 0, 1)));
			$this->registerRecipe((new ShapedRecipe(
				Item::get(Item::WOOL, 15 - $i, 1),
				"X ",
				"Y "
			))->setIngredient("X", Item::get(Item::DYE, $i, 1))->setIngredient("Y", Item::get(Item::WOOL, 0, 1)));
			$this->registerRecipe((new ShapedRecipe(
				Item::get(Item::CARPET, $i, 3),
				"XX"
			))->setIngredient("X", Item::get(Item::WOOL, $i, 2)));
		}
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 11, 2),
			"X"
		))->setIngredient("X", Item::get(Item::DANDELION, 0, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 15, 3),
			"X"
		))->setIngredient("X", Item::get(Item::BONE, 0, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 3, 2),
			"X ",
			"Y "
		))->setIngredient("X", Item::get(Item::DYE, 14, 1))->setIngredient("Y", Item::get(Item::DYE, 0, 1)));
		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::DYE, 3, 3),
			" X ",
			" Y ",
			" Z "
		))->setIngredient("X", Item::get(Item::DYE, 1, 1))->setIngredient("Y", Item::get(Item::DYE, 0, 1))->setIngredient("Z", Item::get(Item::DYE, 11, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 9, 2),
			"X ",
			"Y "
		))->setIngredient("X", Item::get(Item::DYE, 15, 1))->setIngredient("Y", Item::get(Item::DYE, 1, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 14, 2),
			"X ",
			"Y "
		))->setIngredient("X", Item::get(Item::DYE, 11, 1))->setIngredient("Y", Item::get(Item::DYE, 1, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 10, 2),
			"X ",
			"Y "
		))->setIngredient("X", Item::get(Item::DYE, 2, 1))->setIngredient("Y", Item::get(Item::DYE, 15, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 12, 2),
			"X ",
			"Y "
		))->setIngredient("X", Item::get(Item::DYE, 4, 1))->setIngredient("Y", Item::get(Item::DYE, 15, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 6, 2),
			"X ",
			"Y "
		))->setIngredient("X", Item::get(Item::DYE, 4, 1))->setIngredient("Y", Item::get(Item::DYE, 2, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 5, 2),
			"X ",
			"Y "
		))->setIngredient("X", Item::get(Item::DYE, 4, 1))->setIngredient("Y", Item::get(Item::DYE, 1, 1)));
		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::DYE, 13, 3),
			" X ",
			" Y ",
			" Z "
		))->setIngredient("X", Item::get(Item::DYE, 4, 1))->setIngredient("Y", Item::get(Item::DYE, 1, 1))->setIngredient("Z", Item::get(Item::DYE, 15, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 1, 1),
			"X"
		))->setIngredient("X", Item::get(Item::BEETROOT, 0, 1)));
		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::DYE, 13, 4),
			" X ",
			"Y Y",
			" Z "
		))->setIngredient("X", Item::get(Item::DYE, 15, 1))->setIngredient("Y", Item::get(Item::DYE, 1, 2))->setIngredient("Z", Item::get(Item::DYE, 4, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 13, 2),
			"X ",
			"Y "
		))->setIngredient("X", Item::get(Item::DYE, 5, 1))->setIngredient("Y", Item::get(Item::DYE, 9, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 8, 2),
			"X ",
			"Y "
		))->setIngredient("X", Item::get(Item::DYE, 0, 1))->setIngredient("Y", Item::get(Item::DYE, 15, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 7, 3),
			"X ",
			"YY"
		))->setIngredient("X", Item::get(Item::DYE, 0, 1))->setIngredient("Y", Item::get(Item::DYE, 15, 2)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::DYE, 7, 2),
			"X ",
			"Y "
		))->setIngredient("X", Item::get(Item::DYE, 0, 1))->setIngredient("Y", Item::get(Item::DYE, 8, 1)));
	}

	protected function registerIngots()
	{
		$ingots = [
			Item::GOLD_BLOCK => Item::GOLD_INGOT,
			Item::IRON_BLOCK => Item::IRON_INGOT,
			Item::DIAMOND_BLOCK => Item::DIAMOND,
			Item::EMERALD_BLOCK => Item::EMERALD,
			Item::REDSTONE_BLOCK => Item::REDSTONE_DUST,
			Item::COAL_BLOCK => Item::COAL,
			Item::HAY_BALE => Item::WHEAT,
			Item::GOLD_INGOT => Item::GOLD_NUGGET
		];
		foreach ($ingots as $block => $ingot) {
			$this->registerRecipe((new BigShapedRecipe(
				Item::get($ingot, 0, 9),
				"   ",
				" D ",
				"   "
			))->setIngredient("D", Item::get($block, 0, 1)));
			$this->registerRecipe((new BigShapedRecipe(
				Item::get($block, 0, 1),
				"GGG",
				"GGG",
				"GGG"
			))->setIngredient("G", Item::get($ingot, 0, 9)));
		}
		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::LAPIS_BLOCK, 0, 1),
			"GGG",
			"GGG",
			"GGG"
		))->setIngredient("G", Item::get(Item::DYE, 4, 9)));
		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::DYE, 4, 9),
			"   ",
			" L ",
			"   "
		))->setIngredient("L", Item::get(Item::LAPIS_BLOCK, 0, 1)));
	}

	protected function registerPotions()
	{
		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::BREWING_STAND, 0, 1),
			"   ",
			" B ",
			"CCC"
		))->setIngredient("B", Item::get(Item::BLAZE_ROD, 0, 1))->setIngredient("C", Item::get(Item::COBBLE, 0, 3)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::BLAZE_POWDER, 0, 2),
			"B ",
			"  "
		))->setIngredient("B", Item::get(Item::BLAZE_ROD, 0, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::MAGMA_CREAM, 0, 1),
			"BS",
			"  "
		))->setIngredient("B", Item::get(Item::BLAZE_ROD, 0, 1))->setIngredient("S", Item::get(Item::SLIMEBALL, 0, 1)));
		$this->registerRecipe((new ShapedRecipe(
			Item::get(Item::FERMENTED_SPIDER_EYE, 0, 1),
			"XY",
			" Z"
		))->setIngredient("X", Item::get(Item::SPIDER_EYE, 0, 1))->setIngredient("Y", Item::get(Item::SUGAR, 0, 1))->setIngredient("Z", Item::get(Item::BROWN_MUSHROOM, 0, 1)));
		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::GLISTERING_MELON, 0, 1),
			"YYY",
			"YXY",
			"YYY"
		))->setIngredient("X", Item::get(Item::MELON, 0, 1))->setIngredient("Y", Item::get(Item::GOLD_NUGGET, 0, 8)));
		$this->registerRecipe((new BigShapedRecipe(
			Item::get(Item::GOLDEN_CARROT, 0, 1),
			"YYY",
			"YXY",
			"YYY"
		))->setIngredient("X", Item::get(Item::CARROT, 0, 1))->setIngredient("Y", Item::get(Item::GOLD_NUGGET, 0, 8)));
	}

	public function sort(Item $i1, Item $i2)
	{
		if ($i1->getId() > $i2->getId()) {
			return 1;
		} elseif ($i1->getId() < $i2->getId()) {
			return -1;
		} elseif ($i1->getDamage() > $i2->getDamage()) {
			return 1;
		} elseif ($i1->getDamage() < $i2->getDamage()) {
			return -1;
		} elseif ($i1->getCount() > $i2->getCount()) {
			return 1;
		} elseif ($i1->getCount() < $i2->getCount()) {
			return -1;
		} else {
			return 0;
		}
	}

	/**
	 * @param UUID $id
	 * @return Recipe
	 */
	public function getRecipe(UUID $id)
	{
		$index = $id->toBinary();
		return $this->recipes[$index] ?? null;
	}

	/**
	 * @return Recipe[]
	 */
	public function getRecipes()
	{
		return $this->recipes;
	}

	public function getRecipesByResult(Item $item)
	{
		return @array_values($this->recipeLookup[$item->getId() . ":" . $item->getDamage()]) ?? [];
	}
	/**
	 * @return FurnaceRecipe[]
	 */
	public function getFurnaceRecipes()
	{
		return $this->furnaceRecipes;
	}

	/**
	 * @param Item $input
	 *
	 * @return FurnaceRecipe
	 */
	public function matchFurnaceRecipe(Item $input)
	{
		if (isset($this->furnaceRecipes[$input->getId() . ":" . $input->getDamage()])) {
			return $this->furnaceRecipes[$input->getId() . ":" . $input->getDamage()];
		} elseif (isset($this->furnaceRecipes[$input->getId() . ":?"])) {
			return $this->furnaceRecipes[$input->getId() . ":?"];
		}
		return null;
	}


	/**
	 * @param Item $input
	 * @param Item $potion
	 *
	 * @return BrewingRecipe
	 */
	public function matchBrewingRecipe(Item $input, Item $potion)
	{
		$subscript = $input->getId() . ":" . ($input->getDamage() === null ? "0" : $input->getDamage()) . ":" . $potion->getId() . ":" . ($potion->getDamage() === null ? "0" : $potion->getDamage());
		if (isset($this->brewingRecipes[$subscript])) {
			return $this->brewingRecipes[$subscript];
		}
		return null;
	}

	/**
	 * @param ShapedRecipe $recipe
	 */
	public function registerShapedRecipe(ShapedRecipe $recipe)
	{
		$result = $recipe->getResult();
		$this->recipes[$recipe->getId()->toBinary()] = $recipe;
		$ingredients = $recipe->getIngredientMap();
		$hash = "";
		foreach ($ingredients as $v) {
			foreach ($v as $item) {
				if ($item !== null) {
					/** @var Item $item */
					$hash .= $item->getId() . ":" . ($item->hasAnyDamageValue() ? "?" : $item->getDamage()) . "x" . $item->getCount() . ",";
				}
			}
			$hash .= ";";
		}
		$this->recipeLookup[$result->getId() . ":" . $result->getDamage()][$hash] = $recipe;
		$this->craftingDataCache = null;
	}

	/**
	 * @param ShapelessRecipe $recipe
	 */
	public function registerShapelessRecipe(ShapelessRecipe $recipe)
	{
		$result = $recipe->getResult();
		$this->recipes[$recipe->getId()->toBinary()] = $recipe;
		$hash = "";
		$ingredients = $recipe->getIngredientList();
		usort($ingredients, [$this, "sort"]);
		foreach ($ingredients as $item) {
			$hash .= $item->getId() . ":" . ($item->hasAnyDamageValue() ? "?" : $item->getDamage()) . "x" . $item->getCount() . ",";
		}
		$this->recipeLookup[$result->getId() . ":" . $result->getDamage()][$hash] = $recipe;
		$this->craftingDataCache = null;
	}

	/**
	 * @param FurnaceRecipe $recipe
	 */
	public function registerFurnaceRecipe(FurnaceRecipe $recipe)
	{
		$input = $recipe->getInput();
		$this->furnaceRecipes[$input->getId() . ":" . ($input->getDamage() === null ? "?" : $input->getDamage())] = $recipe;
		$this->craftingDataCache = null;
	}

	/**
	 * @param BrewingRecipe $recipe
	 */
	public function registerBrewingRecipe(BrewingRecipe $recipe)
	{
		$input = $recipe->getInput();
		$potion = $recipe->getPotion();
		$this->brewingRecipes[$input->getId() . ":" . ($input->getDamage() === null ? "0" : $input->getDamage()) . ":" . $potion->getId() . ":" . ($potion->getDamage() === null ? "0" : $potion->getDamage())] = $recipe;
	}

	/**
	 * @param ShapelessRecipe $recipe
	 * @return bool
	 */
	public function matchRecipe(ShapelessRecipe $recipe)
	{
		if (!isset($this->recipeLookup[$idx = $recipe->getResult()->getId() . ":" . $recipe->getResult()->getDamage()])) {
			return false;
		}
		$hash = "";
		$ingredients = $recipe->getIngredientList();
		usort($ingredients, [$this, "sort"]);
		foreach ($ingredients as $item) {
			$hash .= $item->getId() . ":" . ($item->hasAnyDamageValue() ? "?" : $item->getDamage()) . "x" . $item->getCount() . ",";
		}
		if (isset($this->recipeLookup[$idx][$hash])) {
			return true;
		}
		$hasRecipe = null;
		foreach ($this->recipeLookup[$idx] as $recipe) {
			if ($recipe instanceof ShapelessRecipe) {
				if ($recipe->getIngredientCount() !== count($ingredients)) {
					continue;
				}
				$checkInput = $recipe->getIngredientList();
				foreach ($ingredients as $item) {
					$amount = $item->getCount();
					foreach ($checkInput as $k => $checkItem) {
						if ($checkItem->equals($item, !$checkItem->hasAnyDamageValue(), $checkItem->hasCompoundTag())) {
							$remove = min($checkItem->getCount(), $amount);
							$checkItem->setCount($checkItem->getCount() - $remove);
							if ($checkItem->getCount() === 0) {
								unset($checkInput[$k]);
							}
							$amount -= $remove;
							if ($amount === 0) {
								break;
							}
						}
					}
				}
				if (count($checkInput) === 0) {
					$hasRecipe = $recipe;
					break;
				}
			}
			if ($hasRecipe instanceof Recipe) {
				break;
			}
		}
		return $hasRecipe !== null;
	}

	/**
	 * @param Recipe $recipe
	 */
	public function registerRecipe(Recipe $recipe)
	{
		$recipe->setId(UUID::fromData(++self::$RECIPE_COUNT, $recipe->getResult()->getId(), $recipe->getResult()->getDamage(), $recipe->getResult()->getCount(), $recipe->getResult()->getCompoundTag()));
		if ($recipe instanceof ShapedRecipe) {
			$this->registerShapedRecipe($recipe);
		} elseif ($recipe instanceof ShapelessRecipe) {
			$this->registerShapelessRecipe($recipe);
		} elseif ($recipe instanceof FurnaceRecipe) {
			$this->registerFurnaceRecipe($recipe);
		}
	}
}
