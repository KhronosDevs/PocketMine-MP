<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\RecipeRegistry;

/**
 * Phase 12.3: crafting / recipes.
 *
 * Recipes now live in the RecipeRegistry resource (registry data, extendable
 * by plugins) instead of a hard-coded inline table, and CraftingService
 * actually commits the result to the player's inventory with an atomic
 * check-then-consume flow.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$crafting = $kernel->getCraftingService();

function spawnCraftingPlayer(World $world, float $x, float $z): EntityRef {
    return $world->spawn(
        (new EntityBuilder())
            ->at($x, 65, $z)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent())
            ->with(new InventoryComponent(36))
            ->withTag(PlayerTag::class)
    );
}

function inventory(EntityRef $ref): InventoryComponent {
    $inv = $ref->getEntity()?->get(InventoryComponent::class);
    if ($inv === null) {
        throw new RuntimeException('no inventory');
    }
    return $inv;
}

function countItem(InventoryComponent $inv, int $itemId, int $meta = 0): int {
    $count = 0;
    foreach ($inv->getContents() as $item) {
        if ($item->itemId === $itemId && $item->meta === $meta) {
            $count += $item->count;
        }
    }
    return $count;
}

function grid2x2(?ItemStack $a = null, ?ItemStack $b = null, ?ItemStack $c = null, ?ItemStack $d = null): array {
    return [$a, $b, $c, $d];
}

function grid3x3(array $slots): array {
    $grid = array_fill(0, 9, null);
    foreach ($slots as $slot => $item) {
        $grid[$slot] = $item;
    }
    return $grid;
}

test('recipe registry matches shaped patterns with wildcard meta', function () use ($kernel) {
    $registry = $kernel->getResourceRegistry()->get(RecipeRegistry::class);
    ok($registry instanceof RecipeRegistry, 'recipe registry registered');

    // 1 log -> 4 planks (2x2 grid, log in top-left).
    $match = $registry->matchShaped(grid2x2(new ItemStack(17, 0, 1)), 2);
    ok($match !== null, 'planks recipe matched');
    same(5, $match['result']->itemId, 'result is planks');
    same(4, $match['result']->count, '4 planks');

    // 3x3 wooden pickaxe pattern.
    $grid = grid3x3([
        0 => new ItemStack(5, 0, 1),
        1 => new ItemStack(5, 0, 1),
        2 => new ItemStack(5, 0, 1),
        4 => new ItemStack(280, 0, 1),
        7 => new ItemStack(280, 0, 1),
    ]);
    $pickaxe = $registry->matchShaped($grid, 3);
    ok($pickaxe !== null, 'pickaxe recipe matched');
    same(270, $pickaxe['result']->itemId, 'result is wooden pickaxe');

    // Wrong shape does not match.
    $bad = grid3x3([
        0 => new ItemStack(5, 0, 1),
        4 => new ItemStack(280, 0, 1),
    ]);
    ok($registry->matchShaped($bad, 3) === null, 'wrong shape does not match');
});

test('craft consumes ingredients and adds the result to inventory', function () use ($world, $crafting) {
    $player = spawnCraftingPlayer($world, 600, 100);
    $inv = inventory($player);
    $inv->set(0, new ItemStack(17, 0, 1)); // 1 log

    $result = $crafting->craft($player, grid2x2(new ItemStack(17, 0, 1)), 2);
    ok($result instanceof ItemStack, 'craft returned a result');
    same(5, $result->itemId, 'crafted planks');
    same(4, $result->count, 'crafted 4 planks');
    same(0, countItem($inv, 17), 'log consumed');
    same(4, countItem($inv, 5), 'planks in inventory');

    $world->despawn($player->getEntity());
    \pocketmine\Kernel::getInstance()->getWorld()->tick(0.05);
});

test('craft returns null without ingredients or on mismatch', function () use ($world, $crafting) {
    $player = spawnCraftingPlayer($world, 620, 100);
    $inv = inventory($player);

    // No ingredients at all.
    ok($crafting->craft($player, grid2x2(new ItemStack(17, 0, 1)), 2) === null, 'no log -> null');

    // Wrong item in the grid.
    $inv->set(0, new ItemStack(1, 0, 1)); // stone
    ok($crafting->craft($player, grid2x2(new ItemStack(1, 0, 1)), 2) === null, 'stone is not a log -> null');

    // Nothing was consumed or added.
    same(1, countItem($inv, 1), 'stone untouched');

    $world->despawn($player->getEntity());
    \pocketmine\Kernel::getInstance()->getWorld()->tick(0.05);
});

test('3x3 crafting requires a crafting table', function () use ($world, $crafting) {
    $player = spawnCraftingPlayer($world, 640, 100);
    $inv = inventory($player);
    $inv->set(0, new ItemStack(5, 0, 3));
    $inv->set(1, new ItemStack(280, 0, 2));

    $grid = grid3x3([
        0 => new ItemStack(5, 0, 1),
        1 => new ItemStack(5, 0, 1),
        2 => new ItemStack(5, 0, 1),
        4 => new ItemStack(280, 0, 1),
        7 => new ItemStack(280, 0, 1),
    ]);

    // Without a table: 3x3 denied.
    ok($crafting->craft($player, $grid, 3) === null, '3x3 denied without crafting table');

    // With the table flag: works.
    $player->getEntity()?->get(MetadataComponent::class)->set('craftingTable', true);
    $result = $crafting->craft($player, $grid, 3);
    ok($result instanceof ItemStack, '3x3 craft succeeds with crafting table');
    same(270, $result->itemId, 'crafted wooden pickaxe');
    same(0, countItem($inv, 5), 'planks consumed');
    same(0, countItem($inv, 280), 'sticks consumed');
    same(1, countItem($inv, 270), 'pickaxe in inventory');

    $world->despawn($player->getEntity());
    \pocketmine\Kernel::getInstance()->getWorld()->tick(0.05);
});

test('craft is atomic: no space means nothing is consumed', function () use ($world, $crafting) {
    $player = spawnCraftingPlayer($world, 660, 100);
    $inv = inventory($player);

    // Fill the entire inventory so the 4-plank result cannot fit.
    for ($i = 0; $i < 36; $i++) {
        $inv->set($i, new ItemStack(1, 0, 64)); // stone, stack of 64
    }
    // Log is the ONLY non-stone slot... but all slots are full. Put the log in
    // a stone slot by replacing slot 0, keeping everything else full.
    $inv->set(0, new ItemStack(17, 0, 1));

    // 4 planks cannot fit: 35 full stone stacks + 1 log, no room for planks.
    ok($crafting->craft($player, grid2x2(new ItemStack(17, 0, 1)), 2) === null, 'no space -> null');
    same(1, countItem($inv, 17), 'log NOT consumed on failed craft');

    $world->despawn($player->getEntity());
    \pocketmine\Kernel::getInstance()->getWorld()->tick(0.05);
});

test('grid with extra items outside the pattern does not match', function () use ($kernel) {
    $registry = $kernel->getResourceRegistry()->get(RecipeRegistry::class);

    // 1x1 planks recipe must NOT match a 2x2 grid with junk in the other slots.
    $grid = grid2x2(new ItemStack(17, 0, 1), new ItemStack(1, 0, 1));
    ok($registry->matchShaped($grid, 2) === null, 'extra item in grid rejects the recipe');

    // A 2x2 recipe must not match a 3x3 grid with junk in the rest.
    $craftingTable = grid3x3([
        0 => new ItemStack(5, 0, 1),
        1 => new ItemStack(5, 0, 1),
        2 => new ItemStack(5, 0, 1),
        3 => new ItemStack(5, 0, 1),
        8 => new ItemStack(1, 0, 1), // junk outside the 2x2 pattern
    ]);
    ok($registry->matchShaped($craftingTable, 3) === null, 'junk in a 3x3 grid rejects the 2x2 recipe');

    // Clean 2x2 in the top-left corner of a 3x3 grid matches.
    $clean = grid3x3([
        0 => new ItemStack(5, 0, 1),
        1 => new ItemStack(5, 0, 1),
        3 => new ItemStack(5, 0, 1),
        4 => new ItemStack(5, 0, 1),
    ]);
    ok($registry->matchShaped($clean, 3) !== null, '2x2 recipe matches inside a clean 3x3 grid');

    // Vanilla behavior: a 2x2 recipe placed at ANY offset (bottom-right
    // corner) of a 3x3 grid still matches.
    $bottomRight = grid3x3([
        4 => new ItemStack(5, 0, 1),
        5 => new ItemStack(5, 0, 1),
        7 => new ItemStack(5, 0, 1),
        8 => new ItemStack(5, 0, 1),
    ]);
    ok($registry->matchShaped($bottomRight, 3) !== null, '2x2 recipe matches in the bottom-right corner too');
});

test('over-stack results split across multiple slots (canAddItem/add agree)', function () use ($kernel, $world, $crafting) {
    $player = spawnCraftingPlayer($world, 740, 100);
    $inv = inventory($player);

    // Register a synthetic recipe producing 100 of an item (over one stack of
    // 64) to prove add() splits across slots instead of creating an
    // over-stack slot or failing after the consume step.
    $registry = $kernel->getResourceRegistry()->get(RecipeRegistry::class);
    $registry->registerShaped(
        'test_bulk',
        ['X'],
        ['X' => new ItemStack(5, 0, 1)],
        new ItemStack(1000, 0, 100), // stack of 100 > max stack 64
    );

    $inv->set(0, new ItemStack(5, 0, 1));
    $result = $crafting->craft($player, grid2x2(new ItemStack(5, 0, 1)), 2);
    ok($result instanceof ItemStack, 'bulk craft returned a result');
    same(100, $result->count, 'returned result keeps full count');

    $total = countItem($inv, 1000);
    same(100, $total, 'all 100 placed in inventory');

    // No single slot may exceed the max stack size.
    foreach ($inv->getContents() as $item) {
        ok($item->count <= $item->getMaxStackSize(), 'no over-stack slot created');
    }

    $world->despawn($player->getEntity());
    \pocketmine\Kernel::getInstance()->getWorld()->tick(0.05);
});

test('repeated crafts do not corrupt the registry or return wrong counts', function () use ($world, $crafting) {
    // Player starts with 2 planks already in the inventory so the 4-plank
    // result stacks onto them (the path that used to mutate the recipe).
    $player = spawnCraftingPlayer($world, 700, 100);
    $inv = inventory($player);
    $inv->set(0, new ItemStack(17, 0, 1)); // log
    $inv->set(1, new ItemStack(5, 0, 2));  // 2 existing planks

    $result = $crafting->craft($player, grid2x2(new ItemStack(17, 0, 1)), 2);
    ok($result instanceof ItemStack, 'craft succeeded');
    same(4, $result->count, 'returned result has full count 4 (not residual after stacking)');
    same(6, countItem($inv, 5), '6 planks total in inventory (2 + 4)');

    // The registry recipe itself must be untouched: another player crafting
    // planks from a log still yields 4.
    $player2 = spawnCraftingPlayer($world, 720, 100);
    inventory($player2)->set(0, new ItemStack(17, 0, 1));
    $result2 = $crafting->craft($player2, grid2x2(new ItemStack(17, 0, 1)), 2);
    same(4, $result2->count, 'registry result still 4 planks after prior stacking craft');

    $world->despawn($player->getEntity());
    $world->despawn($player2->getEntity());
    \pocketmine\Kernel::getInstance()->getWorld()->tick(0.05);
});

test('container service transfers items between player and container', function () use ($world, $kernel) {
    $player = spawnCraftingPlayer($world, 680, 100);
    $container = $world->spawn(
        (new EntityBuilder())
            ->at(680, 65, 101)
            ->with(new MetadataComponent(['containerType' => 'chest']))
            ->with(new InventoryComponent(27))
    );

    $containerService = $kernel->getContainerService();
    ok($containerService->openContainer($player, $container), 'container opened');

    // Player -> container.
    inventory($player)->set(5, new ItemStack(5, 0, 3)); // 3 planks in player slot 5
    ok($containerService->transferItem($player, 5, 0, false), 'player -> container transfer');
    same(0, countItem(inventory($player), 5), 'player no longer has planks');
    same(3, countItem(inventory($container), 5), 'container has planks');

    // Container -> player.
    ok($containerService->transferItem($player, 0, 10, true), 'container -> player transfer');
    same(3, countItem(inventory($player), 5), 'player has planks again');
    same(0, countItem(inventory($container), 5), 'container empty');

    $containerService->closeContainer($player);
    ok($player->getEntity()?->get(MetadataComponent::class)->has('openContainer') === false, 'container closed');

    $world->despawn($player->getEntity());
    $world->despawn($container->getEntity());
    \pocketmine\Kernel::getInstance()->getWorld()->tick(0.05);
});

exit(runTests());
