<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\api\inventory\Inventory;
use pocketmine\api\inventory\ItemStack;
use pocketmine\api\world\World;
use pocketmine\core\component\CollisionComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;

/**
 * Gameplay service tests against a real kernel: chunk loading, block
 * place/break flows with inventory consumption and drops, and the
 * API inventory facade.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$apiWorld = new World($world, 'world', 'world');
$playerRef = null;
$placedY = null;

test('chunk load produces real terrain', function () use ($apiWorld) {
    $apiWorld->loadChunk(0, 0);
    ok($apiWorld->isChunkLoaded(0, 0), 'chunk 0,0 loaded');
    ok($apiWorld->isChunkGenerated(0, 0), 'chunk 0,0 generated');
    $h = $apiWorld->getHighestBlockAt(8, 8);
    ok($h >= 40 && $h <= 120, 'surface height sane, got ' . $h);
    same(1, $apiWorld->getBlock(8, 1, 8), 'stone below surface');
    ok($apiWorld->getBlock(8, $h, 8) !== 0, 'surface block is not air');
});

test('block place consumes inventory and writes to store', function () use ($kernel, $world, $apiWorld, &$playerRef, &$placedY) {
    $h = $apiWorld->getHighestBlockAt(8, 8);
    $y = $h + 1; // first air above terrain
    $placedY = $y;
    same(0, $apiWorld->getBlock(8, $y, 8), 'target is air');

    // Spawn a creative player adjacent to the target.
    $playerRef = $world->spawn(
        (new EntityBuilder())
            ->at(8.5, $y, 8.5)
            ->with(new CollisionComponent(0.6, 1.8))
            ->with(new InventoryComponent(36))
            ->with(new MetadataComponent(['gamemode' => 1, 'heldSlot' => 0]))
            ->withTag('player')
    );

    $inv = new Inventory($playerRef, $world);
    $inv->setItem(0, new ItemStack(5, 0, 64)); // 64 planks
    same(64, $inv->countItem(5), 'player has 64 planks');

    // Place planks into the air block.
    ok($kernel->getBlockPlaceService()->placeBlock($playerRef, 8, $y, 8, 1, 5, 0), 'place succeeded');
    same(5, $apiWorld->getBlock(8, $y, 8), 'block written to store');
    same(0, $apiWorld->getBlockMeta(8, $y, 8), 'block meta stored');
    same(63, $inv->countItem(5), 'one plank consumed');

    // Cannot place into a solid block.
    ok(!$kernel->getBlockPlaceService()->placeBlock($playerRef, 8, $y, 8, 1, 5, 0), 'cannot place into solid block');

    // Cannot place a block the player does not have.
    ok(!$kernel->getBlockPlaceService()->placeBlock($playerRef, 9, $y, 8, 1, 1, 0), 'cannot place without item');
});

test('block break removes block and spawns drops', function () use ($kernel, $world, $apiWorld, &$playerRef, &$placedY) {
    ok($playerRef instanceof EntityRef, 'player exists from previous test');
    ok($placedY !== null, 'placed block position known');
    $y = $placedY;

    // Teleport down to the stone layer, break stone (drops cobblestone).
    $playerRef->teleport(8.5, 1.5, 8.5);
    $before = count($world->getEntities());
    ok($kernel->getBlockBreakService()->breakBlock($playerRef, 8, 1, 8, 1), 'stone break succeeded');
    same(0, $apiWorld->getBlock(8, 1, 8), 'block is air after break');
    ok(count($world->getEntities()) > $before, 'drop entity spawned');

    // Back to the surface: break the placed planks at the recorded Y.
    $playerRef->teleport(8.5, $y, 8.5);
    ok($kernel->getBlockBreakService()->breakBlock($playerRef, 8, $y, 8, 1), 'plank break succeeded');
    same(0, $apiWorld->getBlock(8, $y, 8), 'plank block removed');

    // Bedrock is unbreakable.
    $playerRef->teleport(8.5, 1.5, 8.5);
    $apiWorld->setBlock(9, 1, 8, 7);
    ok(!$kernel->getBlockBreakService()->breakBlock($playerRef, 9, 1, 8, 1), 'bedrock not breakable');
});

test('api inventory facade: held slot, canAddItem, removal', function () use ($world, &$playerRef) {
    $inv = new Inventory($playerRef, $world);

    same(0, $inv->getHeldSlot(), 'default held slot 0');
    $inv->setItem(2, new ItemStack(267, 0, 1)); // iron sword in slot 2
    $inv->setHeldSlot(2);
    same(2, $inv->getHeldSlot(), 'held slot updated');
    same(267, $inv->getHeldItem()?->itemId, 'held item is the iron sword');

    // canAddItem (regression: used to recurse infinitely).
    $inv->clear();
    ok($inv->canAddItem(new ItemStack(1, 0, 0)), 'zero-count item always addable');
    ok($inv->canAddItem(new ItemStack(1, 0, 100)), '100 stones fit in 36 empty slots');
    ok(!$inv->canAddItem(new ItemStack(1, 0, 10000)), '10000 stones do not fit (36 * 64)');

    // addItem + removeItemById.
    $inv->addItem(new ItemStack(1, 0, 100));
    same(100, $inv->countItem(1), '100 stones in inventory');
    same(40, $inv->removeItemById(1, 40), '40 stones removed');
    same(60, $inv->countItem(1), '60 stones remain');
    same(36, $inv->getSize(), 'inventory size 36');
    same(35, $inv->getFreeSlots(), 'one occupied slot');
});

exit(runTests());
