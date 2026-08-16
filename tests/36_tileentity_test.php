<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\ItemStack;
use pocketmine\core\resource\TileEntityStore;

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$store = $world->getResourceRegistry()->get(TileEntityStore::class);

test('sign stores text and creator and round-trips through snapshots', function () use ($store): void {
    $store->setSign(10, 64, 10, ['Hello', 'World', '', ''], 'player-uuid-1');
    $sign = $store->getSign(10, 64, 10);
    ok($sign !== null, 'sign exists');
    same('Hello', $sign['text'][0], 'line 1 stored');
    same('World', $sign['text'][1], 'line 2 stored');
    same('player-uuid-1', $sign['creator'], 'creator stored');

    // Snapshot round-trip (what the storage adapter persists).
    $snapshots = $store->snapshotsForChunk(0, 0);
    ok(count($snapshots) >= 1, 'sign exported as a tile snapshot');
    $fresh = new TileEntityStore();
    $fresh->restoreFromSnapshots($snapshots);
    $restored = $fresh->getSign(10, 64, 10);
    ok($restored !== null, 'sign restored from snapshots');
    same('Hello', $restored['text'][0], 'restored line 1');
    same('player-uuid-1', $restored['creator'], 'restored creator');
});

test('item frame stores and rotates its item, and drops it via clear', function () use ($store): void {
    $store->setFrameItem(20, 64, 20, new ItemStack(260, 0, 1)); // apple
    $frame = $store->getFrame(20, 64, 20);
    ok($frame !== null && $frame['item'] !== null, 'frame holds an item');
    same(260, $frame['item']['id'], 'frame item id');

    $store->rotateFrame(20, 64, 20);
    same(1, $store->getFrame(20, 64, 20)['rotation'], 'rotation advanced to 1');
    $store->rotateFrame(20, 64, 20);
    same(2, $store->getFrame(20, 64, 20)['rotation'], 'rotation advanced to 2');

    // Rotation wraps at 8.
    $store->setFrameItem(21, 64, 20, new ItemStack(261, 0, 1));
    for ($i = 0; $i < 8; $i++) {
        $store->rotateFrame(21, 64, 20);
    }
    same(0, $store->getFrame(21, 64, 20)['rotation'], 'rotation wraps 7 -> 0');

    $store->clearFrame(20, 64, 20);
    same(null, $store->getFrame(20, 64, 20)['item'], 'frame item cleared');

    // Frame snapshots round-trip too.
    $snapshots = $store->snapshotsForChunk(1, 1);
    $fresh = new TileEntityStore();
    $fresh->restoreFromSnapshots($snapshots);
    $restored = $fresh->getFrame(21, 64, 20);
    ok($restored !== null && $restored['item'] !== null, 'frame restored from snapshots');
    same(261, $restored['item']['id'], 'restored frame item');
});

test('removing a tile entity clears both sign and frame data', function () use ($store): void {
    $store->setSign(30, 64, 30, ['Bye', '', '', ''], 'u');
    $store->setFrameItem(31, 64, 30, new ItemStack(1, 0, 1));
    $store->remove(30, 64, 30);
    $store->remove(31, 64, 30);
    same(null, $store->getSign(30, 64, 30), 'sign removed');
    same(null, $store->getFrame(31, 64, 30), 'frame removed');
});

// NOTE: no shutdown - the next test boots its own kernel.
exit(runTests());
