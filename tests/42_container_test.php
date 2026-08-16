<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\ItemStack;
use pocketmine\core\resource\BrewingRegistry;
use pocketmine\core\resource\BrewingStore;
use pocketmine\core\resource\ChestStore;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\ContainerStore;
use pocketmine\core\service\NetworkSessionService;

/**
 * Phase 14.27: tile containers.
 *
 * ContainerStore holds dispenser (9) / hopper (5) inventories per block
 * position; BrewingStore holds the brewing stand's 4-slot inventory + brew
 * progress; BrewingRegistry mirrors the legacy brewing recipe table;
 * HopperSystem transfers items between containers; the dispenser dispense
 * hook ejects items on (future) redstone power.
 */

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();

// Load chunk 0,0 so setBlock/getBlock has a real chunk to write to.
$kernel->getChunkLoadService()->loadChunk(0, 0);

test('container store round-trips dispenser + hopper inventories through tile snapshots', function () use ($kernel): void {
    $store = new ContainerStore();
    $dispenser = $store->get(ContainerStore::TYPE_DISPENSER, 10, 64, 20);
    $dispenser->set(0, new ItemStack(261, 0, 1)); // bow
    $dispenser->set(5, new ItemStack(262, 0, 8)); // arrows
    $hopper = $store->get(ContainerStore::TYPE_HOPPER, 12, 64, 22);
    $hopper->set(2, new ItemStack(265, 0, 3)); // iron ingots

    $snapshots = $store->snapshotsForChunk(0, 1); // 10/16=0, 20/16=1
    ok(count($snapshots) === 2, 'two container snapshots exported (dispenser + hopper)');

    $fresh = new ContainerStore();
    $fresh->restoreFromSnapshots($snapshots);
    $d = $fresh->get(ContainerStore::TYPE_DISPENSER, 10, 64, 20);
    ok($d->get(0)?->itemId === 261 && $d->get(0)?->count === 1, 'bow restored to dispenser slot 0');
    ok($d->get(5)?->itemId === 262 && $d->get(5)?->count === 8, 'arrows restored to dispenser slot 5');
    $h = $fresh->get(ContainerStore::TYPE_HOPPER, 12, 64, 22);
    ok($h->get(2)?->itemId === 265 && $h->get(2)?->count === 3, 'iron restored to hopper slot 2');
});

test('brewing store round-trips slots and progress through tile snapshots', function (): void {
    $store = new BrewingStore();
    $state = $store->get(10, 64, 20);
    $state['inventory']->set(BrewingStore::SLOT_INGREDIENT, new ItemStack(BrewingRegistry::ING_NETHER_WART, 0, 1));
    $state['inventory']->set(BrewingStore::SLOT_BOTTLE_1, new ItemStack(BrewingRegistry::ITEM_POTION, BrewingRegistry::POTION_WATER_BOTTLE, 1));
    $state['brewTime'] = 120;
    $store->put(10, 64, 20, $state);

    $snapshots = $store->snapshotsForChunk(0, 1);
    ok(count($snapshots) === 1, 'one brewing snapshot exported');
    same(BrewingStore::TILE_TYPE, $snapshots[0]->type, 'snapshot type is BrewingStand');

    $fresh = new BrewingStore();
    $fresh->restoreFromSnapshots($snapshots);
    $restored = $fresh->get(10, 64, 20);
    $ing = $restored['inventory']->get(BrewingStore::SLOT_INGREDIENT);
    ok($ing !== null && $ing->itemId === BrewingRegistry::ING_NETHER_WART, 'nether wart restored');
    $bottle = $restored['inventory']->get(BrewingStore::SLOT_BOTTLE_1);
    ok($bottle !== null && $bottle->itemId === BrewingRegistry::ITEM_POTION && $bottle->meta === 0, 'water bottle restored');
    same(120, $restored['brewTime'], 'brew progress restored');
});

test('brewing registry matches the legacy recipe table', function () use ($kernel): void {
    $recipes = $kernel->getResourceRegistry()->get(BrewingRegistry::class);
    if (!$recipes instanceof BrewingRegistry) {
        ok(false, 'brewing registry present');
        return;
    }
    // Water bottle + nether wart -> awkward.
    same(
        BrewingRegistry::POTION_AWKWARD,
        $recipes->match(BrewingRegistry::ING_NETHER_WART, 0, BrewingRegistry::POTION_WATER_BOTTLE),
        'nether wart on water bottle makes awkward',
    );
    // Awkward + blaze powder -> strength.
    same(
        BrewingRegistry::POTION_STRENGTH,
        $recipes->match(BrewingRegistry::ING_BLAZE_POWDER, 0, BrewingRegistry::POTION_AWKWARD),
        'blaze powder on awkward makes strength',
    );
    // Strength + redstone -> extended.
    same(
        BrewingRegistry::POTION_STRENGTH_T,
        $recipes->match(BrewingRegistry::ING_REDSTONE, 0, BrewingRegistry::POTION_STRENGTH),
        'redstone extends strength',
    );
    // Strength + glowstone -> II.
    same(
        BrewingRegistry::POTION_STRENGTH_TWO,
        $recipes->match(BrewingRegistry::ING_GLOWSTONE_DUST, 0, BrewingRegistry::POTION_STRENGTH),
        'glowstone strengthens strength to II',
    );
    // Healing + fermented spider eye -> harming.
    same(
        BrewingRegistry::POTION_HARMING,
        $recipes->match(BrewingRegistry::ING_FERMENTED_SPIDER_EYE, 0, BrewingRegistry::POTION_HEALING),
        'fermented spider eye corrupts healing to harming',
    );
    // Gunpowder matches any drinkable potion meta.
    same(
        BrewingRegistry::POTION_HEALING,
        $recipes->match(BrewingRegistry::ING_GUNPOWDER, 0, BrewingRegistry::POTION_HEALING),
        'gunpowder accepts any drinkable potion',
    );
    // A non-recipe combination matches nothing.
    same(null, $recipes->match(BrewingRegistry::ING_NETHER_WART, 0, BrewingRegistry::POTION_HEALING), 'nether wart does nothing to healing');
});

test('brewing system brews a water bottle into awkward after 400 ticks and consumes the wart', function () use ($kernel, $world): void {
    $store = $kernel->getResourceRegistry()->get(BrewingStore::class);
    if (!$store instanceof BrewingStore) {
        ok(false, 'brewing store present');
        return;
    }
    $bx = 20;
    $by = 65;
    $bz = 20;
    $chunks = $kernel->getResourceRegistry()->get(ChunkStore::class);
    if ($chunks instanceof ChunkStore) {
        $chunks->setBlock($bx, $by, $bz, 117); // brewing stand
    }
    $state = $store->get($bx, $by, $bz);
    $state['inventory']->set(BrewingStore::SLOT_INGREDIENT, new ItemStack(BrewingRegistry::ING_NETHER_WART, 0, 1));
    $state['inventory']->set(BrewingStore::SLOT_BOTTLE_1, new ItemStack(BrewingRegistry::ITEM_POTION, BrewingRegistry::POTION_WATER_BOTTLE, 1));
    $state['inventory']->set(BrewingStore::SLOT_BOTTLE_2, new ItemStack(BrewingRegistry::ITEM_POTION, BrewingRegistry::POTION_WATER_BOTTLE, 1));
    $store->put($bx, $by, $bz, $state);

    for ($i = 0; $i < 400; $i++) {
        $world->tick(0.05);
    }

    $after = $store->get($bx, $by, $bz);
    $b1 = $after['inventory']->get(BrewingStore::SLOT_BOTTLE_1);
    $b2 = $after['inventory']->get(BrewingStore::SLOT_BOTTLE_2);
    $ing = $after['inventory']->get(BrewingStore::SLOT_INGREDIENT);
    ok($b1 !== null && $b1->itemId === BrewingRegistry::ITEM_POTION && $b1->meta === BrewingRegistry::POTION_AWKWARD, 'bottle 1 brewed to awkward');
    ok($b2 !== null && $b2->meta === BrewingRegistry::POTION_AWKWARD, 'bottle 2 brewed to awkward too');
    ok($ing === null, 'one nether wart consumed');
});

test('hopper pulls from the chest above and pushes into the chest below', function () use ($kernel, $world): void {
    $chunks = $kernel->getResourceRegistry()->get(ChunkStore::class);
    $chests = $kernel->getResourceRegistry()->get(ChestStore::class);
    if (!$chunks instanceof ChunkStore || !$chests instanceof ChestStore) {
        ok(false, 'chunk + chest stores present');
        return;
    }
    $hx = 30;
    $hy = 65;
    $hz = 30;
    $kernel->getChunkLoadService()->loadChunk(1, 1); // 30/16 = 1
    // Chest below with a stack of iron; hopper in the middle; chest above empty.
    $chunks->setBlock($hx, $hy - 1, $hz, 54); // chest below
    $chunks->setBlock($hx, $hy, $hz, 154);    // hopper
    $chunks->setBlock($hx, $hy + 1, $hz, 54); // chest above
    $below = $chests->get($hx, $hy - 1, $hz);
    $above = $chests->get($hx, $hy + 1, $hz);
    $above->set(0, new ItemStack(265, 0, 5)); // 5 iron in the top chest
    // The hopper store entry is created when the block is placed/opened (the
    // same lifecycle as furnaces); tick until a transfer happens (every 8
    // ticks).
    $containers = $kernel->getResourceRegistry()->get(ContainerStore::class);
    if ($containers instanceof ContainerStore) {
        $containers->get(ContainerStore::TYPE_HOPPER, $hx, $hy, $hz);
    }
    // The hopper transfers on every 8th tick; give it one full cadence.
    for ($i = 0; $i < 8; $i++) {
        $world->tick(0.05);
    }

    // One item pulled from above into the hopper, then pushed down: the top
    // chest lost 1, the bottom chest gained 1, the hopper is empty.
    ok($above->get(0)?->count === 4, 'top chest lost one item to the hopper');
    ok($below->get(0)?->count === 1, 'bottom chest gained one item from the hopper');
});

test('dispenser dispense hook ejects an item and decrements the slot', function () use ($kernel): void {
    $containers = $kernel->getResourceRegistry()->get(ContainerStore::class);
    $network = $kernel->getNetworkSessionService();
    if (!$containers instanceof ContainerStore || !$network instanceof NetworkSessionService) {
        ok(false, 'container store + network service present');
        return;
    }
    $dx = 40;
    $dy = 65;
    $dz = 40;
    $inv = $containers->get(ContainerStore::TYPE_DISPENSER, $dx, $dy, $dz);
    $inv->set(0, new ItemStack(261, 0, 1)); // a single bow

    $ok = $network->dispenseDispenser($dx, $dy, $dz);
    ok($ok, 'dispense fired');
    ok($inv->get(0) === null, 'the dispensed item left the dispenser');
});

test('a container block break spills contents through the network hook', function () use ($kernel): void {
    // The onTileContainerBroken path is exercised end-to-end in the wire test
    // (tests/17); here we verify the store-side spill contract directly.
    $store = new ContainerStore();
    $inv = $store->get(ContainerStore::TYPE_DISPENSER, 50, 64, 50);
    $inv->set(3, new ItemStack(264, 0, 2)); // 2 diamonds
    $spilled = $store->remove(ContainerStore::TYPE_DISPENSER, 50, 64, 50);
    ok($spilled->get(3)?->itemId === 264 && $spilled->get(3)?->count === 2, 'dispenser contents returned on remove');
    ok(!$store->has(ContainerStore::TYPE_DISPENSER, 50, 64, 50), 'store entry forgotten after break');
});

exit(runTests());
