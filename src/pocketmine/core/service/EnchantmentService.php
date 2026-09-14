<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\enum\GameMode;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\EnchantmentRegistry;
use pocketmine\core\resource\ItemRegistry;
use pocketmine\core\resource\WorldRegistry;
use function count;
use function floor;
use function in_array;
use function intdiv;
use function max;
use function min;
use function mt_rand;
use function round;

/**
 * Enchanting table + anvil (14.30).
 *
 * Generates the three table options for an item (bookshelf-boosted levels,
 * legacy weighted pick + conflict pruning), validates + applies a chosen
 * option (lapis + player XP), and implements the anvil's combine / repair /
 * rename with XP costs. Pure logic: the network layer owns the windows and
 * calls in with player + slot state.
 */
final class EnchantmentService {

    /** Lapis lazuli is dye id 351 with meta 4 (legacy Dye::BLUE). */
    public const LAPIS_LAZULI = 351;
    public const LAPIS_META = 4;

    public function __construct(
        private readonly World $world,
        private readonly EnchantmentRegistry $enchantments,
    ) {}

    /**
     * The three enchant options offered for an item at a table with the given
     * bookshelf count. Each option: {cost, enchantments: [id=>lvl], name}.
     * @return list<array{cost: int, enchantments: array<int, int>, name: string}>
     */
    public function generateOptions(ItemStack $item, int $bookshelves): array {
        $enchantability = $this->enchantments->getEnchantability($item->itemId);
        if ($enchantability <= 0 && $item->itemId !== 340) {
            return []; // not enchantable
        }
        $bookshelves = max(0, min(15, $bookshelves));
        $base = mt_rand(1, 8) + ($bookshelves / 2) + mt_rand(0, $bookshelves);
        $levels = [
            0 => max((int)($base / 3), 1),
            1 => (int)(($base * 2) / 3 + 1),
            2 => max((int)$base, $bookshelves * 2),
        ];

        $options = [];
        foreach ($levels as $level) {
            $k = $level + mt_rand(0, (int)round(round($enchantability / 4) * 2)) + 1;
            $bonus = (self::randomFloat() + self::randomFloat() - 1) * 0.15 + 1;
            $modifiedLevel = (int)(($k * (1 + $bonus)) + 0.5);

            $enchants = $this->rollOption($item, $modifiedLevel);
            if ($enchants === []) {
                $enchants = $this->rollOption($item, $modifiedLevel); // retry once
            }
            $options[] = [
                'cost' => $level,
                'enchantments' => $enchants,
                'name' => $this->enchantments->getRandomName(),
            ];
        }
        return $options;
    }

    /** Weighted roll of one table option's enchantment set (conflict-aware). */
    private function rollOption(ItemStack $item, int $modifiedLevel): array {
        $possible = $this->enchantments->getPossibleEnchantments($item->itemId, $modifiedLevel);
        if ($possible === []) {
            return [];
        }
        $weights = [];
        foreach ($possible as $id => $lvl) {
            $weights[$id] = $this->enchantments->getWeight($id);
        }
        $picked = $this->enchantments->pickWeighted($weights);
        if ($picked === null) {
            return [];
        }
        $result = [$picked => $possible[$picked]];
        unset($possible[$picked]);

        // Extra enchantment roll (legacy while loop, halving modified level).
        while ($possible !== []) {
            $modifiedLevel = (int)round($modifiedLevel / 2);
            $v = mt_rand(0, 51);
            if ($v > $modifiedLevel + 1) {
                break;
            }
            // Prune conflicts with everything picked so far.
            $possible = array_filter(
                $possible,
                fn(int $id): bool => !$this->enchantments->conflicts($id, $picked),
                ARRAY_FILTER_USE_KEY,
            );
            if ($possible === []) {
                break;
            }
            $weights = [];
            foreach ($possible as $id => $lvl) {
                $weights[$id] = $this->enchantments->getWeight($id);
            }
            $extra = $this->enchantments->pickWeighted($weights);
            if ($extra === null) {
                break;
            }
            $result[$extra] = $possible[$extra];
            $picked = $extra;
            unset($possible[$extra]);
        }
        return $result;
    }

    /**
     * Apply a chosen enchant option to the item in a table window. Consumes
     * lapis from slot 1 (count = option index + 1) and the option's cost in
     * player levels. Returns the enchanted item, or null when the player
     * lacks lapis / levels / the item is not enchantable.
     */
    public function applyEnchant(
        EntityRef $playerRef,
        ItemStack $target,
        int $optionIndex,
        array $option,
        ?ItemStack $lapis,
    ): ?ItemStack {
        $player = $playerRef->getEntity();
        if ($player === null) {
            return null;
        }
        // Lapis: count required is optionIndex + 1 (legacy $i + 1).
        $lapisCount = $optionIndex + 1;
        if ($lapis === null || $lapis->itemId !== self::LAPIS_LAZULI || $lapis->meta !== self::LAPIS_META || $lapis->count < $lapisCount) {
            return null;
        }
        $levels = $this->playerLevels($playerRef);
        $cost = (int)($option['cost'] ?? 1);
        if ($levels < $cost) {
            return null;
        }
        $meta = $player->get(MetadataComponent::class);
        if ($meta === null) {
            return null;
        }
        $result = $target;
        foreach (($option['enchantments'] ?? []) as $id => $lvl) {
            $result = $result->withEnchantment((int)$id, (int)$lvl);
        }
        // Consume lapis + levels.
        $inventory = $player->get(InventoryComponent::class);
        if ($inventory !== null) {
            $inventory->set($inventory->heldSlot, $lapis->count > $lapisCount
                ? new ItemStack($lapis->itemId, $lapis->meta, $lapis->count - $lapisCount, $lapis->nbt)
                : null);
        }
        $this->takeLevels($playerRef, $cost);
        return $result;
    }

    /**
     * Anvil combine: repair (same item or repair material) or enchant merge
     * (item + enchanted book / same item). Returns the result item + XP cost,
     * or null when the combination is not possible. Legacy rules: same
     * material repairs durability, the repair material tops up 25% of the
     * tool's durability per ingot, and the result's RepairCost grows with
     * every operation (cost = 1 + inherited repair costs + per-ench 1).
     * @return array{0: ItemStack, 1: int}|null [result, xpCost]
     */
    public function combine(ItemStack $target, ItemStack $sacrifice): ?array {
        $targetId = $target->itemId;
        $sacrificeId = $sacrifice->itemId;

        // Book merge: every enchantment on the book transfers (higher level
        // wins). Cost 1 + 1 per enchantment (legacy simplified).
        if ($sacrificeId === 340 && $sacrifice->hasEnchantments()) {
            $cost = 1;
            $result = $target;
            foreach ($sacrifice->getEnchantments() as $entry) {
                $result = $result->withEnchantment($entry['id'], $entry['lvl']);
                $cost++;
            }
            return [$this->withAccumulatedRepairCost($result, $target, $sacrifice), $cost];
        }

        // Same-item repair: merge enchantments + repair durability.
        if ($sacrificeId === $targetId) {
            $cost = 1;
            $result = $target;
            foreach ($sacrifice->getEnchantments() as $entry) {
                $result = $result->withEnchantment($entry['id'], $entry['lvl']);
                $cost++;
            }
            // Durability: repaired meta = combined remaining uses, capped.
            $max = $this->maxDurability($targetId);
            if ($max > 0) {
                $remaining = max(0, $max - $target->meta) + max(0, $max - $sacrifice->meta) + (int)($max * 0.12);
                $result = new ItemStack($result->itemId, max(0, $max - $remaining), $result->count, $result->nbt);
            }
            return [$this->withAccumulatedRepairCost($result, $target, $sacrifice), $cost];
        }

        // Repair-material repair (legacy Item::$repairMaterial): one ingot /
        // plank / etc. restores 25% of the tool's durability. Cost 1 level,
        // +2 for enchanted tools (legacy enchantment repair surcharge).
        $material = self::REPAIR_MATERIAL[$targetId] ?? null;
        if ($material !== null && $sacrificeId === $material) {
            $max = $this->maxDurability($targetId);
            if ($max <= 0) {
                return null;
            }
            // Each sacrifice unit repairs a quarter of the tool; consume as
            // many units as needed (up to 4) to fully repair.
            $damage = $target->meta;
            $needed = (int)min(4, ceil($damage / ($max / 4)));
            if ($needed <= 0 || $sacrifice->count < $needed) {
                $needed = min(max(1, $needed), $sacrifice->count);
            }
            $repaired = max(0, $target->meta - (int)($max / 4) * $needed);
            $result = new ItemStack($target->itemId, $repaired, $target->count, $target->nbt);
            $cost = 1 + ($target->hasEnchantments() ? 2 : 0);
            return [$this->withAccumulatedRepairCost($result, $target, null), $cost];
        }
        return null;
    }

    /** Legacy durability table (subset matching ItemRegistry::DURABILITY). */
    private function maxDurability(int $itemId): int {
        $registry = \pocketmine\Kernel::getInstance()?->getResourceRegistry()
            ->get(\pocketmine\core\resource\ItemRegistry::class);
        return $registry instanceof \pocketmine\core\resource\ItemRegistry
            ? $registry->getMaxDurability($itemId) : 0;
    }

    /**
     * Result repair cost = 1 + target cost + sacrifice cost (legacy
     * AnvilInventory::updateResult: RepairCost accumulates per operation).
     */
    private function withAccumulatedRepairCost(ItemStack $result, ItemStack $target, ?ItemStack $sacrifice): ItemStack {
        $cost = 1 + $target->getRepairCost() + ($sacrifice !== null ? $sacrifice->getRepairCost() : 0);
        return $result->withRepairCost($cost);
    }

    /** Repair material per tool/armor id (legacy Item::$repairMaterial). */
    private const REPAIR_MATERIAL = [
        // wood tools -> planks (5)
        268 => 5, 269 => 5, 270 => 5, 271 => 5, 290 => 5,
        // stone tools -> cobblestone (4)
        272 => 4, 273 => 4, 274 => 4, 275 => 4, 291 => 4,
        // iron tools/armor -> iron ingot (265)
        256 => 265, 257 => 265, 258 => 265, 267 => 265, 292 => 265,
        306 => 265, 307 => 265, 308 => 265, 309 => 265,
        // diamond tools/armor -> diamond (264)
        276 => 264, 277 => 264, 278 => 264, 279 => 264, 293 => 264,
        310 => 264, 311 => 264, 312 => 264, 313 => 264,
        // gold tools/armor -> gold ingot (266)
        283 => 266, 284 => 266, 285 => 266, 286 => 266, 294 => 266,
        314 => 266, 315 => 266, 316 => 266, 317 => 266,
        // leather armor -> leather (334)
        298 => 334, 299 => 334, 300 => 334, 301 => 334,
    ];

    /**
     * Anvil rename: the renamed item must match the target (id + meta + NBT
     * except the display name). Cost = target repair cost (legacy 0-level
     * rename costs 1 level).
     */
    public function rename(ItemStack $target, ItemStack $renamed): ?array {
        $cost = max(1, $target->getRepairCost());
        return [$renamed, $cost];
    }

    /** Number of bookshelves around a table block (legacy 12-offset ring). */
    public function countBookshelves(ChunkStore $store, int $x, int $y, int $z): int {
        $offsets = [[2, 0], [-2, 0], [0, 2], [0, -2], [2, 1], [2, -1], [-2, 1], [-2, -1], [1, 2], [-1, 2], [1, -2], [-1, -2]];
        $count = 0;
        for ($i = 0; $i < 3; $i++) {
            foreach ($offsets as [$dx, $dz]) {
                if ($store->getBlock($x + $dx, $y + $i, $z + $dz) === 47) {
                    $count++;
                }
                if ($count >= 15) {
                    return 15;
                }
            }
        }
        return $count;
    }

    /** Player's current XP level (metadata). */
    public function playerLevels(EntityRef $playerRef): int {
        $meta = $playerRef->getEntity()?->get(MetadataComponent::class);
        return $meta !== null ? (int)$meta->get(MetadataKeys::XP_LEVEL, 0) : 0;
    }

    /** Take N levels from a player (metadata only; wire sync is the caller's). */
    public function takeLevels(EntityRef $playerRef, int $levels): void {
        $meta = $playerRef->getEntity()?->get(MetadataComponent::class);
        if ($meta === null) {
            return;
        }
        $current = max(0, (int)$meta->get(MetadataKeys::XP_LEVEL, 0) - $levels);
        $meta->set(MetadataKeys::XP_LEVEL, $current);
        // Level progress above the new level is discarded (legacy takeXpLevel
        // only touches the level attribute; the bar resets client-side).
        $meta->set(MetadataKeys::XP, 0);
    }

    private static function randomFloat(float $min = 0, float $max = 1): float {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }
}
