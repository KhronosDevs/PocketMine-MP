<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use function array_sum;
use function count;
use function implode;
use function in_array;
use function mt_rand;

/**
 * Enchantment catalogue (14.30) - the full 0.15-era enchantment set with
 * weights, max levels, slot masks, level ranges and conflict rules, ported
 * from the legacy Enchantment + EnchantmentLevelTable classes.
 *
 * All data is static and plain-array (like SmeltingRegistry / BrewingRegistry)
 * so the service layer can reason about enchantments without pulling in a
 * full item class hierarchy.
 */
final class EnchantmentRegistry {

    public const SLOT_NONE = 0;
    public const SLOT_ARMOR = 0b1111;
    public const SLOT_HEAD = 0b1;
    public const SLOT_TORSO = 0b10;
    public const SLOT_LEGS = 0b100;
    public const SLOT_FEET = 0b1000;
    public const SLOT_SWORD = 0b10000;
    public const SLOT_BOW = 0b100000;
    public const SLOT_TOOL = 0b111000000;
    public const SLOT_HOE = 0b1000000;
    public const SLOT_DIG = 0b111000000000;
    public const SLOT_AXE = 0b1000000000;
    public const SLOT_PICKAXE = 0b10000000000;
    public const SLOT_FISHING_ROD = 0b100000000000;

    /** Weapon-family group (shared by the conflict rule). */
    public const GROUP_WEAPON = [9, 10, 11, 12, 13, 14];
    /** Protection-family group (shared by the conflict rule). */
    public const GROUP_PROTECTION = [0, 1, 2, 3, 4];

    /** Enchantment names (id => name) - legacy order. */
    private const NAMES = [
        0 => 'Protection', 1 => 'Fire Protection', 2 => 'Feather Falling',
        3 => 'Blast Protection', 4 => 'Projectile Protection', 5 => 'Thorns',
        6 => 'Respiration', 7 => 'Depth Strider', 8 => 'Aqua Affinity',
        9 => 'Sharpness', 10 => 'Smite', 11 => 'Bane of Arthropods',
        12 => 'Knockback', 13 => 'Fire Aspect', 14 => 'Looting',
        15 => 'Efficiency', 16 => 'Silk Touch', 17 => 'Unbreaking',
        18 => 'Fortune', 19 => 'Power', 20 => 'Punch',
        21 => 'Flame', 22 => 'Infinity', 23 => 'Luck of the Sea', 24 => 'Lure',
    ];

    /** Slot mask per enchantment id. */
    private const SLOTS = [
        0 => self::SLOT_ARMOR, 1 => self::SLOT_ARMOR, 2 => self::SLOT_FEET,
        3 => self::SLOT_ARMOR, 4 => self::SLOT_ARMOR, 5 => self::SLOT_SWORD,
        6 => self::SLOT_HEAD, 7 => self::SLOT_FEET, 8 => self::SLOT_HEAD,
        9 => self::SLOT_SWORD, 10 => self::SLOT_SWORD, 11 => self::SLOT_SWORD,
        12 => self::SLOT_SWORD, 13 => self::SLOT_SWORD, 14 => self::SLOT_SWORD,
        15 => self::SLOT_TOOL, 16 => self::SLOT_TOOL, 17 => self::SLOT_TOOL,
        18 => self::SLOT_TOOL, 19 => self::SLOT_BOW, 20 => self::SLOT_BOW,
        21 => self::SLOT_BOW, 22 => self::SLOT_BOW,
        23 => self::SLOT_FISHING_ROD, 24 => self::SLOT_FISHING_ROD,
    ];

    /** Enchantment weights (weighted random pick at the table). */
    private const WEIGHTS = [
        0 => 10, 1 => 5, 2 => 2, 3 => 5, 4 => 5, 5 => 2, 6 => 2, 7 => 2,
        8 => 2, 9 => 10, 10 => 5, 11 => 5, 12 => 5, 13 => 2, 14 => 2,
        15 => 10, 16 => 1, 17 => 5, 18 => 2, 19 => 10, 20 => 2, 21 => 2,
        22 => 1, 23 => 2, 24 => 2,
    ];

    /** Max level per enchantment id (999 = uncapped). */
    private const MAX_LEVELS = [
        0 => 4, 1 => 4, 2 => 4, 3 => 4, 4 => 4, 5 => 3, 6 => 3, 7 => 3,
        8 => 1, 9 => 5, 10 => 5, 11 => 5, 12 => 2, 13 => 2, 14 => 3,
        15 => 5, 16 => 1, 17 => 3, 18 => 3, 19 => 5, 20 => 2, 21 => 1,
        22 => 1, 23 => 3, 24 => 3,
    ];

    /**
     * Level ranges per enchantment id (legacy EnchantmentLevelTable): each
     * index is the enchantment level, the range is the modified-level window
     * that can produce it.
     * @var array<int, list<array{0: int, 1: int}>>
     */
    private const LEVEL_RANGES = [
        0 => [[1, 21], [12, 32], [23, 43], [34, 54]],
        1 => [[10, 22], [18, 30], [26, 38], [34, 46]],
        2 => [[5, 12], [11, 21], [17, 27], [23, 33]],
        3 => [[5, 17], [13, 25], [21, 33], [29, 41]],
        4 => [[3, 18], [9, 24], [15, 30], [21, 36]],
        6 => [[10, 40], [20, 50], [30, 60]],
        8 => [[10, 41]],
        5 => [[10, 60], [30, 80], [50, 100]],
        9 => [[1, 21], [12, 32], [23, 43], [34, 54], [45, 65]],
        10 => [[5, 25], [13, 33], [21, 41], [29, 49], [37, 57]],
        11 => [[5, 25], [13, 33], [21, 41], [29, 49], [37, 57]],
        12 => [[5, 55], [25, 75]],
        13 => [[10, 60], [30, 80]],
        14 => [[15, 65], [24, 74], [33, 83]],
        19 => [[1, 16], [11, 26], [21, 36], [31, 46], [41, 56]],
        20 => [[12, 37], [32, 57]],
        21 => [[20, 50]],
        22 => [[20, 50]],
        15 => [[1, 51], [11, 61], [21, 71], [31, 81], [41, 91]],
        16 => [[15, 65]],
        17 => [[5, 55], [13, 63], [21, 71]],
        18 => [[15, 55], [24, 74], [33, 83]],
        23 => [[15, 65], [24, 74], [33, 83]],
        24 => [[15, 65], [24, 74], [33, 83]],
    ];

    /** Random name words (legacy table for the option label). */
    private const WORDS = [
        'the', 'elder', 'scrolls', 'klaatu', 'berata', 'niktu', 'xyzzy',
        'bless', 'curse', 'light', 'darkness', 'fire', 'air', 'earth',
        'water', 'hot', 'dry', 'cold', 'wet', 'ignite', 'snuff', 'embiggen',
        'twist', 'shorten', 'stretch', 'fiddle', 'destroy', 'imbue',
        'galvanize', 'enchant', 'free', 'limited', 'range', 'of', 'towards',
        'inside', 'sphere', 'cube', 'self', 'other', 'ball', 'mental',
        'physical', 'grow', 'shrink', 'demon', 'elemental', 'spirit',
        'animal', 'creature', 'beast', 'humanoid', 'undead', 'fresh', 'stale',
    ];

    /**
     * Enchantability per item id (legacy Enchantment::getEnchantAbility).
     * 0 = not enchantable.
     */
    private const ENCHANTABILITY = [
        // book, bow, fishing rod
        340 => 4, 261 => 4, 346 => 4,
        // leather armor (298-301), chain (302-305)
        298 => 15, 299 => 15, 300 => 15, 301 => 15,
        302 => 12, 303 => 12, 304 => 12, 305 => 12,
        // iron armor (306-309), diamond (310-313), gold (314-317)
        306 => 9, 307 => 9, 308 => 9, 309 => 9,
        310 => 10, 311 => 10, 312 => 10, 313 => 10,
        314 => 25, 315 => 25, 316 => 25, 317 => 25,
        // wooden tools/swords (268-271, 290)
        268 => 15, 269 => 15, 270 => 15, 271 => 15, 290 => 15,
        // stone tools (272-275, 291)
        272 => 5, 273 => 5, 274 => 5, 275 => 5, 291 => 5,
        // diamond tools (276-279, 293)
        276 => 10, 277 => 10, 278 => 10, 279 => 10, 293 => 10,
        // iron tools (256-258, 267, 292)
        256 => 14, 257 => 14, 258 => 14, 267 => 14, 292 => 14,
        // gold tools (283-286, 294)
        283 => 22, 284 => 22, 285 => 22, 286 => 22, 294 => 22,
    ];

    public function getEnchantmentName(int $id): string {
        return self::NAMES[$id] ?? 'Unknown';
    }

    public function getMaxLevel(int $id): int {
        return self::MAX_LEVELS[$id] ?? 1;
    }

    public function getWeight(int $id): int {
        return self::WEIGHTS[$id] ?? 0;
    }

    public function getSlotMask(int $id): int {
        return self::SLOTS[$id] ?? self::SLOT_NONE;
    }

    /** Enchantability of an item (0 = not enchantable). */
    public function getEnchantability(int $itemId): int {
        return self::ENCHANTABILITY[$itemId] ?? 0;
    }

    /**
     * The enchantments a table option at $modifiedLevel can produce for an
     * item, filtered by slot compatibility and level ranges (legacy
     * EnchantmentLevelTable::getPossibleEnchantments). Returns
     * [id => level] pairs.
     * @return array<int, int>
     */
    public function getPossibleEnchantments(int $itemId, int $modifiedLevel): array {
        $result = [];
        $ids = $this->applicableEnchantmentIds($itemId);
        foreach ($ids as $id) {
            $ranges = self::LEVEL_RANGES[$id] ?? [];
            foreach ($ranges as $level => $range) {
                if ($modifiedLevel >= $range[0] && $modifiedLevel <= $range[1]) {
                    $result[$id] = $level + 1;
                    break; // highest applicable level wins
                }
            }
        }
        return $result;
    }

    /** Enchantment ids that can apply to an item's slot. */
    private function applicableEnchantmentIds(int $itemId): array {
        $slot = $this->itemSlotMask($itemId);
        $ids = [];
        foreach (self::SLOTS as $id => $mask) {
            if (($mask & $slot) !== 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** Slot mask for an item id (legacy isSword/isTool/isArmor/isBow...). */
    public function itemSlotMask(int $itemId): int {
        if ($itemId === 340) { // book: any enchantment
            return self::SLOT_ALL;
        }
        if ($itemId === 261) { // bow
            return self::SLOT_BOW;
        }
        if ($itemId === 346) { // fishing rod
            return self::SLOT_FISHING_ROD;
        }
        // Armor pieces.
        if ($itemId >= 298 && $itemId <= 317) {
            $armorSlot = self::SLOT_ARMOR;
            if ($itemId === 298 || $itemId === 302 || $itemId === 306 || $itemId === 310 || $itemId === 314) {
                $armorSlot = self::SLOT_HEAD;
            } elseif ($itemId === 300 || $itemId === 304 || $itemId === 308 || $itemId === 312 || $itemId === 316) {
                $armorSlot = self::SLOT_LEGS;
            } elseif ($itemId === 301 || $itemId === 305 || $itemId === 309 || $itemId === 313 || $itemId === 317) {
                $armorSlot = self::SLOT_FEET;
            }
            return $armorSlot;
        }
        // Swords.
        if (in_array($itemId, [268, 272, 267, 276, 283], true)) {
            return self::SLOT_SWORD;
        }
        // Tools (pickaxe/axe/shovel/hoe).
        if (in_array($itemId, [269, 273, 257, 284, 277], true)) {
            return self::SLOT_PICKAXE; // shovel
        }
        if (in_array($itemId, [270, 274, 258, 285, 278], true)) {
            return self::SLOT_PICKAXE;
        }
        if (in_array($itemId, [271, 275, 256, 286, 279], true)) {
            return self::SLOT_AXE;
        }
        if (in_array($itemId, [290, 291, 292, 294, 293], true)) {
            return self::SLOT_HOE;
        }
        return self::SLOT_NONE;
    }

    /** Any item can accept any enchantment (book covers every slot). */
    public const SLOT_ALL = 0b11111111111111;

    /**
     * Weighted pick among candidates (id => weight). Returns the chosen id
     * or null when the list is empty.
     */
    public function pickWeighted(array $candidates): ?int {
        $total = array_sum($candidates);
        if ($total <= 0) {
            return null;
        }
        $roll = mt_rand(1, $total);
        $sum = 0;
        foreach ($candidates as $id => $weight) {
            $sum += $weight;
            if ($roll <= $sum) {
                return (int)$id;
            }
        }
        return null;
    }

    /** Two enchantments conflict (same family / mutually exclusive). */
    public function conflicts(int $a, int $b): bool {
        if ($a === $b) {
            return true;
        }
        if (in_array($a, self::GROUP_PROTECTION, true) && in_array($b, self::GROUP_PROTECTION, true)) {
            return true;
        }
        if (in_array($a, self::GROUP_WEAPON, true) && in_array($b, self::GROUP_WEAPON, true)) {
            return true;
        }
        if (($a === 16 && $b === 18) || ($a === 18 && $b === 16)) {
            return true; // silk touch vs fortune
        }
        return false;
    }

    /** A random table-option name (legacy getRandomName). */
    public function getRandomName(): string {
        $count = mt_rand(3, 6);
        $set = [];
        $total = count(self::WORDS);
        while (count($set) < $count) {
            $set[self::WORDS[mt_rand(0, $total - 1)]] = true;
        }
        return implode(' ', array_keys($set));
    }
}
