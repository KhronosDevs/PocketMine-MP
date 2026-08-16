<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;

/**
 * Brewing recipes (14.27) - ingredient + input potion => output potion,
 * mirroring the legacy CraftingManager::registerBrewingStand table exactly.
 *
 * Each entry maps an ingredient (item id, optional meta wildcard -1) to a map
 * of inputPotionMeta => outputPotionMeta. The same transforms apply to both
 * drinkable (373) and splash (438) potions; gunpowder converts a drinkable
 * potion into its splash variant.
 */
#[Resource]
final class BrewingRegistry {
    // Ingredient item ids (protocol-84, legacy ItemIds).
    public const ING_NETHER_WART = 372;
    public const ING_GLOWSTONE_DUST = 348;
    public const ING_REDSTONE = 331;
    public const ING_FERMENTED_SPIDER_EYE = 376;
    public const ING_GHAST_TEAR = 370;
    public const ING_GLISTERING_MELON = 382;
    public const ING_BLAZE_POWDER = 377;
    public const ING_MAGMA_CREAM = 378;
    public const ING_SUGAR = 353;
    public const ING_SPIDER_EYE = 375;
    public const ING_RABBIT_FOOT = 414;
    public const ING_GOLDEN_CARROT = 396;
    public const ING_PUFFERFISH = 462;
    public const ING_GUNPOWDER = 289;

    // Potion item ids.
    public const ITEM_POTION = 373;
    public const ITEM_SPLASH_POTION = 438;

    // Base potion metas (legacy Potion constants).
    public const POTION_WATER_BOTTLE = 0;
    public const POTION_MUNDANE = 1;
    public const POTION_MUNDANE_EXTENDED = 2;
    public const POTION_THICK = 3;
    public const POTION_AWKWARD = 4;
    public const POTION_NIGHT_VISION = 5;
    public const POTION_NIGHT_VISION_T = 6;
    public const POTION_INVISIBILITY = 7;
    public const POTION_INVISIBILITY_T = 8;
    public const POTION_LEAPING = 9;
    public const POTION_LEAPING_T = 10;
    public const POTION_LEAPING_TWO = 11;
    public const POTION_FIRE_RESISTANCE = 12;
    public const POTION_FIRE_RESISTANCE_T = 13;
    public const POTION_SWIFTNESS = 14;
    public const POTION_SWIFTNESS_T = 15;
    public const POTION_SWIFTNESS_TWO = 16;
    public const POTION_SLOWNESS = 17;
    public const POTION_SLOWNESS_T = 18;
    public const POTION_WATER_BREATHING = 19;
    public const POTION_WATER_BREATHING_T = 20;
    public const POTION_HEALING = 21;
    public const POTION_HEALING_TWO = 22;
    public const POTION_HARMING = 23;
    public const POTION_HARMING_TWO = 24;
    public const POTION_POISON = 25;
    public const POTION_POISON_T = 26;
    public const POTION_POISON_TWO = 27;
    public const POTION_REGENERATION = 28;
    public const POTION_REGENERATION_T = 29;
    public const POTION_REGENERATION_TWO = 30;
    public const POTION_STRENGTH = 31;
    public const POTION_STRENGTH_T = 32;
    public const POTION_STRENGTH_TWO = 33;
    public const POTION_WEAKNESS = 34;
    public const POTION_WEAKNESS_T = 35;

    /**
     * ingredient "id:meta" => [inputPotionMeta => outputPotionMeta]
     * @var array<string, array<int, int>>
     */
    private array $recipes = [];

    private function key(int $id, int $meta): string {
        return $id . ':' . $meta;
    }

    /**
     * Register a brewing transform. A meta of -1 matches any ingredient meta.
     */
    public function register(int $ingredientId, int $ingredientMeta, int $inputMeta, int $outputMeta): void {
        $this->recipes[$this->key($ingredientId, $ingredientMeta)][$inputMeta] = $outputMeta;
    }

    /**
     * The output potion meta for (ingredient, input potion), or null when the
     * combination does not brew anything.
     */
    public function match(int $ingredientId, int $ingredientMeta, int $inputPotionMeta): ?int {
        $exact = $this->recipes[$this->key($ingredientId, $ingredientMeta)] ?? null;
        if ($exact !== null && isset($exact[$inputPotionMeta])) {
            return $exact[$inputPotionMeta];
        }
        $wild = $this->recipes[$this->key($ingredientId, -1)] ?? null;
        return $wild[$inputPotionMeta] ?? null;
    }

    public function __construct() {
        // ---- Water bottle bases (input 0) -------------------------------
        $this->register(self::ING_NETHER_WART, 0, self::POTION_WATER_BOTTLE, self::POTION_AWKWARD);
        $this->register(self::ING_GLOWSTONE_DUST, 0, self::POTION_WATER_BOTTLE, self::POTION_THICK);
        $this->register(self::ING_REDSTONE, 0, self::POTION_WATER_BOTTLE, self::POTION_MUNDANE_EXTENDED);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_WATER_BOTTLE, self::POTION_WEAKNESS);
        foreach ([
            self::ING_GHAST_TEAR, self::ING_GLISTERING_MELON, self::ING_BLAZE_POWDER,
            self::ING_MAGMA_CREAM, self::ING_SUGAR, self::ING_SPIDER_EYE, self::ING_RABBIT_FOOT,
        ] as $ing) {
            $this->register($ing, 0, self::POTION_WATER_BOTTLE, self::POTION_MUNDANE);
        }

        // ---- MUNDANE family ---------------------------------------------
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_MUNDANE, self::POTION_WEAKNESS);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_MUNDANE_EXTENDED, self::POTION_WEAKNESS_T);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_THICK, self::POTION_WEAKNESS);

        // ---- AWKWARD bases (input 4) ------------------------------------
        $this->register(self::ING_GHAST_TEAR, 0, self::POTION_AWKWARD, self::POTION_REGENERATION);
        $this->register(self::ING_BLAZE_POWDER, 0, self::POTION_AWKWARD, self::POTION_STRENGTH);
        $this->register(self::ING_SPIDER_EYE, 0, self::POTION_AWKWARD, self::POTION_POISON);
        $this->register(self::ING_GLISTERING_MELON, 0, self::POTION_AWKWARD, self::POTION_HEALING);
        $this->register(self::ING_PUFFERFISH, 0, self::POTION_AWKWARD, self::POTION_WATER_BREATHING);
        $this->register(self::ING_SUGAR, 0, self::POTION_AWKWARD, self::POTION_SWIFTNESS);
        $this->register(self::ING_MAGMA_CREAM, 0, self::POTION_AWKWARD, self::POTION_FIRE_RESISTANCE);
        $this->register(self::ING_RABBIT_FOOT, 0, self::POTION_AWKWARD, self::POTION_LEAPING);
        $this->register(self::ING_GOLDEN_CARROT, 0, self::POTION_AWKWARD, self::POTION_NIGHT_VISION);

        // ---- Redstone: extend duration (T variants) ---------------------
        $this->register(self::ING_REDSTONE, 0, self::POTION_NIGHT_VISION, self::POTION_NIGHT_VISION_T);
        $this->register(self::ING_REDSTONE, 0, self::POTION_INVISIBILITY, self::POTION_INVISIBILITY_T);
        $this->register(self::ING_REDSTONE, 0, self::POTION_LEAPING, self::POTION_LEAPING_T);
        $this->register(self::ING_REDSTONE, 0, self::POTION_FIRE_RESISTANCE, self::POTION_FIRE_RESISTANCE_T);
        $this->register(self::ING_REDSTONE, 0, self::POTION_SWIFTNESS, self::POTION_SWIFTNESS_T);
        $this->register(self::ING_REDSTONE, 0, self::POTION_SLOWNESS, self::POTION_SLOWNESS_T);
        $this->register(self::ING_REDSTONE, 0, self::POTION_WATER_BREATHING, self::POTION_WATER_BREATHING_T);
        $this->register(self::ING_REDSTONE, 0, self::POTION_POISON, self::POTION_POISON_T);
        $this->register(self::ING_REDSTONE, 0, self::POTION_REGENERATION, self::POTION_REGENERATION_T);
        $this->register(self::ING_REDSTONE, 0, self::POTION_STRENGTH, self::POTION_STRENGTH_T);
        $this->register(self::ING_REDSTONE, 0, self::POTION_WEAKNESS, self::POTION_WEAKNESS_T);

        // ---- Glowstone: strengthen (TWO variants) -----------------------
        $this->register(self::ING_GLOWSTONE_DUST, 0, self::POTION_LEAPING, self::POTION_LEAPING_TWO);
        $this->register(self::ING_GLOWSTONE_DUST, 0, self::POTION_SWIFTNESS, self::POTION_SWIFTNESS_TWO);
        $this->register(self::ING_GLOWSTONE_DUST, 0, self::POTION_HEALING, self::POTION_HEALING_TWO);
        $this->register(self::ING_GLOWSTONE_DUST, 0, self::POTION_HARMING, self::POTION_HARMING_TWO);
        $this->register(self::ING_GLOWSTONE_DUST, 0, self::POTION_POISON, self::POTION_POISON_TWO);
        $this->register(self::ING_GLOWSTONE_DUST, 0, self::POTION_REGENERATION, self::POTION_REGENERATION_TWO);
        $this->register(self::ING_GLOWSTONE_DUST, 0, self::POTION_STRENGTH, self::POTION_STRENGTH_TWO);

        // ---- Fermented spider eye: corrupt ------------------------------
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_WATER_BREATHING, self::POTION_HARMING);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_HEALING, self::POTION_HARMING);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_POISON, self::POTION_HARMING);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_HEALING_TWO, self::POTION_HARMING_TWO);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_POISON_T, self::POTION_HARMING_TWO);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_FIRE_RESISTANCE, self::POTION_SLOWNESS);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_SWIFTNESS, self::POTION_SLOWNESS);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_LEAPING, self::POTION_SLOWNESS);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_FIRE_RESISTANCE_T, self::POTION_SLOWNESS_T);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_LEAPING_T, self::POTION_SLOWNESS_T);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_SWIFTNESS_T, self::POTION_SLOWNESS_T);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_NIGHT_VISION, self::POTION_INVISIBILITY);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_NIGHT_VISION_T, self::POTION_INVISIBILITY_T);
        $this->register(self::ING_FERMENTED_SPIDER_EYE, 0, self::POTION_INVISIBILITY, self::POTION_INVISIBILITY_T);

        // ---- Gunpowder: drinkable -> splash (any potion meta) -----------
        $splashable = [
            self::POTION_WATER_BOTTLE, self::POTION_MUNDANE, self::POTION_MUNDANE_EXTENDED,
            self::POTION_THICK, self::POTION_AWKWARD, self::POTION_NIGHT_VISION,
            self::POTION_NIGHT_VISION_T, self::POTION_INVISIBILITY, self::POTION_INVISIBILITY_T,
            self::POTION_LEAPING, self::POTION_LEAPING_T, self::POTION_LEAPING_TWO,
            self::POTION_FIRE_RESISTANCE, self::POTION_FIRE_RESISTANCE_T, self::POTION_SWIFTNESS,
            self::POTION_SWIFTNESS_T, self::POTION_SWIFTNESS_TWO, self::POTION_SLOWNESS,
            self::POTION_SLOWNESS_T, self::POTION_WATER_BREATHING, self::POTION_WATER_BREATHING_T,
            self::POTION_HEALING, self::POTION_HEALING_TWO, self::POTION_HARMING,
            self::POTION_HARMING_TWO, self::POTION_POISON, self::POTION_POISON_T,
            self::POTION_POISON_TWO, self::POTION_REGENERATION, self::POTION_REGENERATION_T,
            self::POTION_REGENERATION_TWO, self::POTION_STRENGTH, self::POTION_STRENGTH_T,
            self::POTION_STRENGTH_TWO, self::POTION_WEAKNESS, self::POTION_WEAKNESS_T,
        ];
        foreach ($splashable as $meta) {
            // Gunpowder applies to any drinkable potion of that meta. The
            // output is the splash variant of the same meta; the item id
            // switch happens in the BrewingSystem (373 -> 438).
            $this->register(self::ING_GUNPOWDER, 0, $meta, $meta);
        }
    }
}
