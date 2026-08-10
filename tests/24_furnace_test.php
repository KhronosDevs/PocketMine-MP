<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\ItemStack;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\FurnaceStore;
use pocketmine\core\resource\SmeltingRegistry;

/**
 * Phase 14.16: furnaces / smelting.
 *
 * The SmeltingRegistry holds recipe + fuel data (legacy recipes.json + Fuel
 * values); the FurnaceStore holds per-block state (3 slots + burn/cook
 * progress) with a tile-entity round-trip; the FurnaceSystem advances
 * furnaces every world tick and flips the lit/unlit block state.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$smelting = $kernel->getResourceRegistry()->get(SmeltingRegistry::class);
if (!$smelting instanceof SmeltingRegistry) {
    echo "FAIL: no SmeltingRegistry\n";
    exit(1);
}

test('smelting registry matches recipes exactly and by wildcard meta', function () use ($smelting): void {
    $iron = $smelting->matchSmelting(new ItemStack(15, 0, 1));
    same(265, $iron?->itemId, 'iron ore smelts to iron ingot');
    same(1, $iron?->count, 'one ingot per ore');

    // Wildcard meta: logs of any variant smelt to charcoal.
    $charcoal = $smelting->matchSmelting(new ItemStack(17, 2, 1));
    same(263, $charcoal?->itemId, 'oak log smelts to charcoal');
    same(1, $charcoal?->meta, 'charcoal damage 1');

    // A non-smeltable item matches nothing.
    same(null, $smelting->matchSmelting(new ItemStack(5, 0, 1)), 'planks do not smelt');

    // Exact-meta recipes: only plain stone bricks crack.
    same(2, $smelting->matchSmelting(new ItemStack(98, 0, 1))?->meta, 'stone bricks crack to meta 2');
    same(null, $smelting->matchSmelting(new ItemStack(98, 1, 1)), 'mossy stone bricks do not crack');
});

test('fuel registry returns burn ticks for exact and wildcard entries', function () use ($smelting): void {
    same(1600, $smelting->getFuelTicks(new ItemStack(263, 0, 1)), 'coal burns 1600 ticks');
    same(1600, $smelting->getFuelTicks(new ItemStack(263, 3, 1)), 'coal burns regardless of meta (wildcard)');
    same(300, $smelting->getFuelTicks(new ItemStack(5, 0, 1)), 'planks burn 300 ticks');
    same(0, $smelting->getFuelTicks(new ItemStack(1, 0, 1)), 'stone is not fuel');
    same(0, $smelting->getFuelTicks(new ItemStack(263, 0, 0)), 'an empty stack is not fuel');
});

test('furnace store round-trips slots and progress through tile snapshots', function () use ($kernel): void {
    $store = new FurnaceStore();
    $state = $store->get(10, 64, 20);
    $state['inventory']->set(FurnaceStore::SLOT_SMELTING, new ItemStack(15, 0, 3)); // 3 iron ore
    $state['inventory']->set(FurnaceStore::SLOT_FUEL, new ItemStack(263, 0, 1));     // 1 coal
    $state['burnTime'] = 1200;
    $state['cookTime'] = 80;
    $store->put(10, 64, 20, $state);

    $snapshots = $store->snapshotsForChunk(0, 1); // 10/16=0, 20/16=1
    ok(count($snapshots) === 1, 'one furnace snapshot exported');
    same(FurnaceStore::TILE_TYPE, $snapshots[0]->type, 'snapshot type is Furnace');

    $fresh = new FurnaceStore();
    $fresh->restoreFromSnapshots($snapshots);
    $restored = $fresh->get(10, 64, 20);
    $input = $restored['inventory']->get(FurnaceStore::SLOT_SMELTING);
    ok($input !== null && $input->itemId === 15 && $input->count === 3, '3 iron ore restored');
    same(1200, $restored['burnTime'], 'burn progress restored');
    same(80, $restored['cookTime'], 'cook progress restored');
});

test('furnace system smelts ore into ingots over 200 ticks and lights the block', function () use ($kernel, $world): void {
    $store = $kernel->getResourceRegistry()->get(FurnaceStore::class);
    $chunks = $kernel->getResourceRegistry()->get(ChunkStore::class);
    if (!$store instanceof FurnaceStore || !$chunks instanceof ChunkStore) {
        ok(false, 'furnace + chunk stores present');
        return;
    }
    // A furnace block at a known loaded position (chunk 0,0 near the safe
    // spawn): 61 = unlit furnace.
    $kernel?->getChunkLoadService()->loadChunk(0, 0);
    $fx = 8;
    $fy = 65;
    $fz = 8;
    $chunks->setBlock($fx, $fy, $fz, 61);
    $state = $store->get($fx, $fy, $fz);
    $state['inventory']->set(FurnaceStore::SLOT_SMELTING, new ItemStack(15, 0, 2)); // 2 iron ore
    $state['inventory']->set(FurnaceStore::SLOT_FUEL, new ItemStack(263, 0, 1));     // 1 coal (1600 ticks)
    $store->put($fx, $fy, $fz, $state);

    // Tick the world: the FurnaceSystem runs every tick.
    for ($i = 0; $i < 205; $i++) {
        $world->tick(0.05);
    }

    $after = $store->get($fx, $fy, $fz);
    $input = $after['inventory']->get(FurnaceStore::SLOT_SMELTING);
    $fuel = $after['inventory']->get(FurnaceStore::SLOT_FUEL);
    $result = $after['inventory']->get(FurnaceStore::SLOT_RESULT);
    ok($input !== null && $input->count === 1, 'one ore consumed, one remains');
    // The single coal was consumed entirely the moment the furnace lit.
    ok($fuel === null, 'one coal consumed to light the furnace');
    ok($result !== null && $result->itemId === 265 && $result->count === 1, 'one iron ingot smelted into the result slot');
    same(62, $chunks->getBlock($fx, $fy, $fz), 'furnace block is lit (62) while burning');

    // Burn the coal down: the second ore finishes at ~tick 400, then the
    // furnace cooks nothing but keeps burning until the 1600-tick coal is
    // spent (the lighting tick itself does not decrement, so 1601 total
    // ticks), at which point the block goes dark (61).
    for ($i = 0; $i < 1396; $i++) {
        $world->tick(0.05);
    }
    $done = $store->get($fx, $fy, $fz);
    $result = $done['inventory']->get(FurnaceStore::SLOT_RESULT);
    ok($result !== null && $result->count === 2, 'second ore smelted too');
    same(0, $done['burnTime'], 'coal fully burned (1600 ticks)');
    same(61, $chunks->getBlock($fx, $fy, $fz), 'furnace block went dark when the fuel ran out');
    same(0, $done['cookTime'], 'cook progress reset with nothing to smelt');
});

test('furnace store drops contents when the block is broken', function () use ($kernel): void {
    $store = $kernel->getResourceRegistry()->get(FurnaceStore::class);
    if (!$store instanceof FurnaceStore) {
        ok(false, 'furnace store present');
        return;
    }
    $state = $store->get(30, 64, 30);
    $state['inventory']->set(FurnaceStore::SLOT_SMELTING, new ItemStack(4, 0, 7));
    $state['inventory']->set(FurnaceStore::SLOT_RESULT, new ItemStack(1, 0, 1));
    $store->put(30, 64, 30, $state);

    $inv = $store->remove(30, 64, 30);
    $contents = $inv->getContents();
    same(2, count($contents), 'both slots spilled on break');
    ok(!$store->has(30, 64, 30), 'store entry forgotten after break');
});

exit(runTests());
