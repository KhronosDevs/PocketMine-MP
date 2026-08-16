<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\ItemStack;
use pocketmine\core\resource\EnchantmentRegistry;
use pocketmine\core\service\EnchantmentService;

/**
 * Phase 14.30: enchanting table + anvils.
 *
 * The EnchantmentRegistry holds the 0.15 enchantment catalogue; the
 * EnchantmentService generates the three table options, applies a chosen
 * option (lapis + XP), and implements anvil combine/rename. ItemStack stores
 * enchantments in NBT ('ench' list) so they round-trip through the wire.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$registry = $world->getResourceRegistry()->get(EnchantmentRegistry::class);
if (!$registry instanceof EnchantmentRegistry) {
    echo "FAIL: no EnchantmentRegistry\n";
    exit(1);
}
$service = $kernel->getEnchantmentService();
if (!$service instanceof EnchantmentService) {
    echo "FAIL: no EnchantmentService\n";
    exit(1);
}

test('registry has the 0.15 enchantment catalogue with legacy values', function () use ($registry): void {
    same('Sharpness', $registry->getEnchantmentName(9), 'sharpness id 9');
    same(5, $registry->getMaxLevel(9), 'sharpness max level 5');
    same(10, $registry->getWeight(9), 'sharpness weight 10');
    same(15, $registry->getEnchantability(270), 'wooden pickaxe enchantability 15');
    same(0, $registry->getEnchantability(1), 'stone (block) is not enchantable');
    // Silk touch vs fortune conflict; two weapons conflict.
    ok($registry->conflicts(16, 18), 'silk touch conflicts with fortune');
    ok($registry->conflicts(9, 10), 'sharpness conflicts with smite');
    ok(!$registry->conflicts(9, 15), 'sharpness does not conflict with efficiency');
    // A diamond sword can take sharpness but not bow power.
    $possible = $registry->getPossibleEnchantments(276, 30);
    ok(isset($possible[9]) || isset($possible[10]) || isset($possible[12]), 'sword offers weapon enchantments');
    ok(!isset($possible[19]), 'bow power is not offered for a sword');
});

test('ItemStack enchantments round-trip through NBT', function (): void {
    $sword = new ItemStack(276, 0, 1);
    same(false, $sword->hasEnchantments(), 'fresh sword has no enchantments');
    $enchanted = $sword->withEnchantment(9, 3);
    ok($enchanted->hasEnchantments(), 'sword has enchantments after withEnchantment');
    same(3, $enchanted->getEnchantmentLevel(9), 'sharpness level 3 readable');
    same(0, $enchanted->getEnchantmentLevel(19), 'no power on the sword');
    $raised = $enchanted->withEnchantment(9, 1);
    same(3, $raised->getEnchantmentLevel(9), 'withEnchantment keeps the higher level');
    $renamed = $enchanted->withCustomName('Excalibur');
    same('Excalibur', $renamed->getCustomName(), 'custom name round-trips');
    $sword2 = ItemStack::fromArray($enchanted->toArray());
    same(3, $sword2->getEnchantmentLevel(9), 'enchantments survive toArray/fromArray (persistence)');
});

test('generateOptions returns three costed options for an enchantable item', function () use ($service): void {
    $options = $service->generateOptions(new ItemStack(276, 0, 1), 0); // diamond sword, no bookshelves
    same(3, count($options), 'three options offered');
    foreach ($options as $option) {
        ok($option['cost'] >= 1, 'option cost is positive');
        ok($option['enchantments'] !== [], 'every option has at least one enchantment');
        ok($option['name'] !== '', 'every option has a random name');
    }
    // Bookshelves raise the top cost (legacy max(base, bookshelves*2)).
    $boosted = $service->generateOptions(new ItemStack(276, 0, 1), 15);
    ok($boosted[2]['cost'] >= $options[2]['cost'], 'bookshelves raise the highest option cost');
    // Non-enchantable items offer nothing.
    same([], $service->generateOptions(new ItemStack(1, 0, 1), 15), 'stone is not enchantable');
});

test('applyEnchant consumes lapis + levels and returns the enchanted item', function () use ($kernel, $service): void {
    $spawnService = $kernel->getPlayerJoinService();
    // Use the network session player path via a real join.
    $playerRef = \serviceTestPlayer($kernel);
    if ($playerRef === null) {
        ok(false, 'test player spawned');
        return;
    }
    $meta = $playerRef->getEntity()?->get(\pocketmine\core\component\MetadataComponent::class);
    $meta?->set(\pocketmine\core\constants\MetadataKeys::XP_LEVEL, 10);

    $target = new ItemStack(276, 0, 1);
    $lapis = new ItemStack(EnchantmentService::LAPIS_LAZULI, EnchantmentService::LAPIS_META, 1);
    $option = ['cost' => 3, 'enchantments' => [9 => 2], 'name' => 'test'];
    $result = $service->applyEnchant($playerRef, $target, 0, $option, $lapis);
    ok($result instanceof ItemStack, 'apply returns an item');
    same(2, $result?->getEnchantmentLevel(9), 'sharpness II applied');
    same(7, $service->playerLevels($playerRef), 'three levels were taken (10 - 3)');
});

test('applyEnchant refuses without lapis or levels', function () use ($kernel, $service): void {
    $playerRef = \serviceTestPlayer($kernel);
    if ($playerRef === null) {
        ok(false, 'test player spawned');
        return;
    }
    $meta = $playerRef->getEntity()?->get(\pocketmine\core\component\MetadataComponent::class);
    $meta?->set(\pocketmine\core\constants\MetadataKeys::XP_LEVEL, 0);

    $target = new ItemStack(276, 0, 1);
    $option = ['cost' => 5, 'enchantments' => [9 => 1], 'name' => 'test'];
    // No lapis at all.
    same(null, $service->applyEnchant($playerRef, $target, 0, $option, null), 'no lapis -> refused');
    // Lapis present but too few levels.
    $lapis = new ItemStack(EnchantmentService::LAPIS_LAZULI, EnchantmentService::LAPIS_META, 1);
    same(null, $service->applyEnchant($playerRef, $target, 0, $option, $lapis), 'no levels -> refused');
});

test('anvil combine merges enchantments from a book', function () use ($service): void {
    $sword = (new ItemStack(276, 0, 1))->withEnchantment(9, 2);
    $book = (new ItemStack(340, 0, 1))->withEnchantment(14, 3); // looting III book
    $combined = $service->combine($sword, $book);
    ok($combined !== null, 'combine returns a result');
    same(2, $combined[0]->getEnchantmentLevel(9), 'target sharpness kept');
    same(3, $combined[0]->getEnchantmentLevel(14), 'book looting transferred');
    ok($combined[1] >= 2, 'combine costs 1 + per-enchantment');
    // Non-combinable items return null.
    same(null, $service->combine($sword, new ItemStack(1, 0, 1)), 'unrelated items do not combine');
});

test('anvil rename returns the renamed item with a level cost', function () use ($service): void {
    $sword = new ItemStack(276, 0, 1);
    $renamed = $sword->withCustomName('Excalibur');
    $rename = $service->rename($sword, $renamed);
    ok($rename !== null, 'rename allowed');
    same('Excalibur', $rename[0]->getCustomName(), 'renamed item carries the name');
    ok($rename[1] >= 1, 'rename costs at least one level');
});

test('enchantment effects are wired into gameplay', function () use ($kernel, $service): void {
    // Sharpness: a sharp sword deals more damage through EntityInteractionService.
    $interaction = $kernel->getEntityInteractionService();
    $ref = new \ReflectionMethod($interaction, 'calculateDamage');
    $ref->setAccessible(true);

    $attacker = \serviceTestPlayer($kernel);
    if ($attacker === null) {
        ok(false, 'test player spawned');
        return;
    }
    $inv = $attacker->getEntity()?->get(\pocketmine\core\component\InventoryComponent::class);
    if ($inv === null) {
        ok(false, 'inventory present');
        return;
    }
    // Plain iron sword (267).
    $inv->set($inv->heldSlot, new ItemStack(267, 0, 1));
    $base = $ref->invoke($interaction, $attacker, $attacker);
    // Sharpness II iron sword.
    $inv->set($inv->heldSlot, (new ItemStack(267, 0, 1))->withEnchantment(9, 2));
    $sharp = $ref->invoke($interaction, $attacker, $attacker);
    ok($sharp > $base, 'sharpness adds melee damage');
    same(1.0, round($sharp - $base, 1), 'sharpness II adds exactly 1.0 damage (0.5 * 2)');

    // Efficiency: an efficiency pickaxe mines faster than a plain one.
    $breakService = $kernel->getBlockBreakService();
    $calcSpeed = new \ReflectionMethod($breakService, 'calculateBreakSpeed');
    $calcSpeed->setAccessible(true);
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $kernel->getChunkLoadService()->loadChunk(0, 0);
    $flat = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    if ($flat !== null) {
        // Find a stone block in a loaded chunk.
        $stone = null;
        foreach ($flat->getLoadedChunkCoordinates() as $c) {
            for ($by = 0; $by < 60 && $stone === null; $by++) {
                for ($bz = 0; $bz < 16 && $stone === null; $bz++) {
                    for ($bx = 0; $bx < 16 && $stone === null; $bx++) {
                        if ($flat->getBlock($c[0] * 16 + $bx, $by, $c[1] * 16 + $bz) === 1) {
                            $stone = [$c[0] * 16 + $bx, $by, $c[1] * 16 + $bz];
                        }
                    }
                }
            }
            if ($stone !== null) {
                break;
            }
        }
        if ($stone !== null) {
            $plain = $calcSpeed->invoke($breakService, new ItemStack(257, 0, 1), $stone[0], $stone[1], $stone[2], 0);
            $eff = $calcSpeed->invoke($breakService, (new ItemStack(257, 0, 1))->withEnchantment(15, 3), $stone[0], $stone[1], $stone[2], 0);
            ok($eff > $plain, 'efficiency speeds up mining');
        }
    }
});

$kernel->shutdown();
exit(runTests());

/** Spawn a throwaway player (or reuse the last one) for service-level tests. */
function serviceTestPlayer(\pocketmine\Kernel $kernel): ?\pocketmine\core\ecs\EntityRef {
    static $ref = null;
    if ($ref !== null) {
        return $ref;
    }
    $spawn = $kernel->getPlayerJoinService();
    $playerRef = new \pocketmine\port\driven\PlayerRef('enchant-tester-uuid', 999999, 'EnchantTester');
    $ref = $spawn->handleJoin($playerRef, 'EnchantTester');
    return $ref;
}
