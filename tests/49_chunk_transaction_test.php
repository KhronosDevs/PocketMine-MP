<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\ChunkTransaction;
use pocketmine\port\driven\ChunkData;

function section(int $y, string $blocks, string $meta = null): array {
    return [
        'y' => $y,
        'blocks' => $blocks,
        'data' => $meta ?? str_repeat("\x00", ChunkStore::SECTION_BYTES),
        'skyLight' => str_repeat("\xff", ChunkStore::LIGHT_BYTES),
        'blockLight' => str_repeat("\x00", ChunkStore::LIGHT_BYTES),
    ];
}

test('setBlock buffers without touching live data', function () {
    $store = new ChunkStore();
    $store->load(new ChunkData(
        0, 0,
        [section(0, str_repeat("\x00", ChunkStore::SECTION_BYTES))],
        array_fill(0, 256, 0),
        array_fill(0, 256, 0),
        [], [],
    ));

    $registry = new \pocketmine\core\resource\BlockRegistry();
    $tx = new ChunkTransaction($store, $registry);

    // Buffer a change — should NOT affect live chunk data.
    ok($tx->setBlock(3, 64, 5, 42, 3), 'setBlock accepted');
    same(0, $store->getBlock(3, 64, 5), 'live data unchanged before commit');
    same(1, $tx->getChangeCount() > 0 ? 1 : 0, 'change count updated');
});

test('commit applies all buffered changes in bulk', function () {
    $store = new ChunkStore();
    $store->load(new ChunkData(
        0, 0,
        [section(0, str_repeat("\x00", ChunkStore::SECTION_BYTES))],
        array_fill(0, 256, 0),
        array_fill(0, 256, 0),
        [], [],
    ));

    $registry = new \pocketmine\core\resource\BlockRegistry();
    $tx = new ChunkTransaction($store, $registry);

    // Buffer 100 block changes.
    for ($i = 0; $i < 100; $i++) {
        $x = $i & 15;
        $z = ($i >> 4) & 15;
        $y = ($i >> 8) & 15;
        $tx->setBlock($x, $y, $z, 1, 0); // stone
    }

    same(100, $tx->getChangeCount(), '100 changes buffered');

    // Live data still zero before commit.
    same(0, $store->getBlock(0, 0, 0), 'still air before commit');

    $tx->commit();

    // All 100 blocks should now be stone (id=1).
    for ($i = 0; $i < 100; $i++) {
        $x = $i & 15;
        $z = ($i >> 4) & 15;
        $y = ($i >> 8) & 15;
        same(1, $store->getBlock($x, $y, $z), "stone at ($x,$y,$z) after commit");
    }

    // Block 100+ should still be air.
    same(0, $store->getBlock(0, 16, 0), 'block outside commit range still air');

    ok($tx->isCommitted(), 'transaction marked as committed');
});

test('rollback restores pre-transaction state', function () {
    $store = new ChunkStore();
    $store->load(new ChunkData(
        0, 0,
        [section(0, str_repeat("\x00", ChunkStore::SECTION_BYTES))],
        array_fill(0, 256, 0),
        array_fill(0, 256, 0),
        [], [],
    ));

    // Pre-place some blocks.
    $store->setBlock(3, 5, 7, 10, 2); // id=10, meta=2
    $store->setBlock(3, 5, 8, 20, 4); // id=20, meta=4

    $registry = new \pocketmine\core\resource\BlockRegistry();
    $tx = new ChunkTransaction($store, $registry);

    // Change both blocks.
    $tx->setBlock(3, 5, 7, 50, 1);
    $tx->setBlock(3, 5, 8, 60, 3);
    $tx->commit();

    same(50, $store->getBlock(3, 5, 7), 'block changed after commit');
    same(1, $store->getBlockMeta(3, 5, 7), 'meta changed after commit');

    // Rollback.
    $tx->rollback();

    same(10, $store->getBlock(3, 5, 7), 'block restored after rollback');
    same(2, $store->getBlockMeta(3, 5, 7), 'meta restored after rollback');
    same(20, $store->getBlock(3, 5, 8), 'second block restored after rollback');
    same(4, $store->getBlockMeta(3, 5, 8), 'second meta restored after rollback');
});

test('rollback works after commit (undo support)', function () {
    $store = new ChunkStore();
    $store->load(new ChunkData(
        0, 0,
        [section(0, str_repeat("\x00", ChunkStore::SECTION_BYTES))],
        array_fill(0, 256, 0),
        array_fill(0, 256, 0),
        [], [],
    ));

    $store->setBlock(0, 0, 0, 7, 0);

    $registry = new \pocketmine\core\resource\BlockRegistry();
    $tx = new ChunkTransaction($store, $registry);
    $tx->setBlock(0, 0, 0, 99, 5);
    $tx->commit();

    same(99, $store->getBlock(0, 0, 0), 'block changed');

    // Undo the commit.
    $tx->rollback();

    same(7, $store->getBlock(0, 0, 0), 'block restored after undo');
    same(0, $store->getBlockMeta(0, 0, 0), 'meta restored after undo');
});

test('cross-chunk changes', function () {
    $store = new ChunkStore();
    // Load two adjacent chunks.
    $empty = str_repeat("\x00", ChunkStore::SECTION_BYTES);
    $store->load(new ChunkData(
        0, 0, [section(0, $empty)],
        array_fill(0, 256, 0), array_fill(0, 256, 0), [], [],
    ));
    $store->load(new ChunkData(
        1, 0, [section(0, $empty)],
        array_fill(0, 256, 0), array_fill(0, 256, 0), [], [],
    ));

    $registry = new \pocketmine\core\resource\BlockRegistry();
    $tx = new ChunkTransaction($store, $registry);

    // Set blocks in both chunks.
    $tx->setBlock(5, 10, 5, 1, 0);   // chunk 0,0
    $tx->setBlock(20, 10, 5, 2, 0);  // chunk 1,0

    same(2, $tx->getChangeCount(), '2 changes across 2 chunks');

    $tx->commit();

    same(1, $store->getBlock(5, 10, 5), 'block in chunk 0,0');
    same(2, $store->getBlock(20, 10, 5), 'block in chunk 1,0');

    $chunks = $tx->getAffectedChunks();
    same(2, count($chunks), '2 affected chunks');
});

test('rollback with cross-chunk changes', function () {
    $store = new ChunkStore();
    $empty = str_repeat("\x00", ChunkStore::SECTION_BYTES);
    $store->load(new ChunkData(
        0, 0, [section(0, $empty)],
        array_fill(0, 256, 0), array_fill(0, 256, 0), [], [],
    ));
    $store->load(new ChunkData(
        1, 0, [section(0, $empty)],
        array_fill(0, 256, 0), array_fill(0, 256, 0), [], [],
    ));

    $store->setBlock(5, 10, 5, 7, 0);
    $store->setBlock(20, 10, 5, 8, 0);

    $registry = new \pocketmine\core\resource\BlockRegistry();
    $tx = new ChunkTransaction($store, $registry);
    $tx->setBlock(5, 10, 5, 99, 0);
    $tx->setBlock(20, 10, 5, 88, 0);
    $tx->commit();

    $tx->rollback();

    same(7, $store->getBlock(5, 10, 5), 'chunk 0,0 restored');
    same(8, $store->getBlock(20, 10, 5), 'chunk 1,0 restored');
});

test('setBiome buffering and commit', function () {
    $store = new ChunkStore();
    $store->load(new ChunkData(
        0, 0,
        [section(0, str_repeat("\x00", ChunkStore::SECTION_BYTES))],
        array_fill(0, 256, 0),
        array_fill(0, 256, 0),
        [], [],
    ));

    $registry = new \pocketmine\core\resource\BlockRegistry();
    $tx = new ChunkTransaction($store, $registry);

    $tx->setBiome(3, 5, 7); // biome id 7
    same(0, $store->getBiome(3, 5), 'biome unchanged before commit');

    $tx->commit();
    same(7, $store->getBiome(3, 5), 'biome updated after commit');
});

test('commit is idempotent (second commit is no-op)', function () {
    $store = new ChunkStore();
    $store->load(new ChunkData(
        0, 0,
        [section(0, str_repeat("\x00", ChunkStore::SECTION_BYTES))],
        array_fill(0, 256, 0),
        array_fill(0, 256, 0),
        [], [],
    ));

    $registry = new \pocketmine\core\resource\BlockRegistry();
    $tx = new ChunkTransaction($store, $registry);
    $tx->setBlock(0, 0, 0, 5, 0);
    $tx->commit();

    same(5, $store->getBlock(0, 0, 0), 'block set');

    // Second commit should be no-op.
    $tx->commit();
    same(5, $store->getBlock(0, 0, 0), 'block unchanged on second commit');
});

test('setBlock to same value still snapshots correctly', function () {
    $store = new ChunkStore();
    $store->load(new ChunkData(
        0, 0,
        [section(0, str_repeat("\x00", ChunkStore::SECTION_BYTES))],
        array_fill(0, 256, 0),
        array_fill(0, 256, 0),
        [], [],
    ));

    $store->setBlock(0, 0, 0, 10, 3);

    $registry = new \pocketmine\core\resource\BlockRegistry();
    $tx = new ChunkTransaction($store, $registry);

    // Set to same value — snapshot should still capture the original.
    $tx->setBlock(0, 0, 0, 10, 3);
    $tx->setBlock(0, 0, 0, 99, 9); // then change to something else
    $tx->commit();

    same(99, $store->getBlock(0, 0, 0), 'final value correct');

    $tx->rollback();
    same(10, $store->getBlock(0, 0, 0), 'rolled back to original');
    same(3, $store->getBlockMeta(0, 0, 0), 'meta rolled back to original');
});

test('unloaded chunk rejected', function () {
    $store = new ChunkStore();
    $registry = new \pocketmine\core\resource\BlockRegistry();
    $tx = new ChunkTransaction($store, $registry);

    ok(!$tx->setBlock(5, 10, 5, 1, 0), 'setBlock rejected for unloaded chunk');
    same(0, $tx->getChangeCount(), 'no changes recorded');
});

runTests();
