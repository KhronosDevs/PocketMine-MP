<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\resource\MapStore;
use pocketmine\protocol\ClientboundMapItemDataPacket;
use pocketmine\utils\BinaryStream;

/**
 * Maps: store round-trip (create/update/persist/restore), wire encoding of
 * ClientboundMapItemDataPacket (texture bitflag, varint payload, color
 * count), and the empty-map crafting recipe registration.
 */

$kernel = \pocketmine\bootstrap();
$registry = $kernel->getWorld()->getResourceRegistry();

test('map store creates, anchors and dirties maps', function () use ($registry): void {
    $store = $registry->get(MapStore::class);
    assert($store instanceof MapStore);

    ok($store !== null, 'kernel registered the map store resource');
    $id = $store->create(100, 200);
    ok($store->has($id), 'created map exists');
    ok($store->consumeDirty($id), 'new map is dirty (needs a texture push)');
    ok(!$store->consumeDirty($id), 'dirty flag is consumed once');

    $map = $store->get($id);
    ok(count($map['colors']) === MapStore::MAP_SIZE * MapStore::MAP_SIZE, 'canvas is 128x128');
    ok($map['centerX'] === 100 && $map['centerZ'] === 200, 'anchor preserved');
    ok($map['scale'] === MapStore::SCALE, 'scale is 0 (1:1 blocks)');
});

test('map store round-trips through tile-entity snapshots', function () use ($registry): void {
    $store = $registry->get(MapStore::class);
    assert($store instanceof MapStore);

    $id = $store->create(2000, 3000);
    $colors = array_fill(0, MapStore::MAP_SIZE * MapStore::MAP_SIZE, 0);
    $colors[0] = 0xFF112233;
    $colors[16383] = 0xFF445566;
    $store->updateColors($id, $colors);
    $store->consumeDirty($id);

    $snapshots = $store->snapshotsForChunk(2000 >> 4, 3000 >> 4);
    ok(count($snapshots) === 1, 'one map snapshot exported for its chunk');
    ok($snapshots[0]->type === MapStore::TILE_TYPE, 'snapshot type is Map');

    $fresh = new MapStore();
    $fresh->restoreFromSnapshots($snapshots);
    ok($fresh->has($id), 'map restored under the same id');
    $restored = $fresh->get($id);
    ok($restored['colors'][0] === 0xFF112233 && $restored['colors'][16383] === 0xFF445566,
        'pixel buffer round-trips exactly');
    ok($restored['centerX'] === 2000 && $restored['centerZ'] === 3000, 'anchor round-trips');
    ok(!$fresh->consumeDirty($id), 'restored map is clean');
});

test('ClientboundMapItemDataPacket encodes the protocol-84 texture layout', function (): void {
    $pk = new ClientboundMapItemDataPacket();
    $pk->mapId = 7;
    $pk->scale = 0;
    $pk->width = 2;
    $pk->height = 2;
    $pk->xOffset = 0;
    $pk->yOffset = 0;
    $pk->colors = [0xFF010203, 0xFF040506, 0xFF070809, 0xFF0A0B0C];
    $pk->encode();

    $s = new BinaryStream(substr($pk->getBuffer(), 1)); // strip packet id
    ok($s->getVarInt() === 7, 'map id is a signed varint (PMMP 1.6.2 parity, not a long)');
    ok($s->getUnsignedVarInt() === ClientboundMapItemDataPacket::BITFLAG_TEXTURE_UPDATE,
        'type bitfield carries the texture flag');
    ok($s->getByte() === 0, 'scale byte present');
    ok($s->getVarInt() === 2 && $s->getVarInt() === 2, 'width/height varints');
    ok($s->getVarInt() === 0 && $s->getVarInt() === 0, 'offset varints');
    ok($s->getUnsignedVarInt() === 0xFF010203, 'first ABGR color');
    ok($s->getUnsignedVarInt() === 0xFF040506, 'second ABGR color');
    ok($s->getUnsignedVarInt() === 0xFF070809, 'third ABGR color');
    ok($s->getUnsignedVarInt() === 0xFF0A0B0C, 'last ABGR color');
    ok($s->feof(), 'payload fully consumed');
});

test('empty-map crafting recipe is registered', function () use ($registry): void {
    $recipes = $registry->get(\pocketmine\core\resource\RecipeRegistry::class);
    assert($recipes instanceof \pocketmine\core\resource\RecipeRegistry);
    $shaped = $recipes->getShapedRecipes();
    ok(isset($shaped['empty_map']), 'empty_map shaped recipe exists');
    ok($shaped['empty_map']['result']->itemId === MapStore::ITEM_FILLED_MAP,
        'result is a filled map item (meta 0 until branded)');
});

runTests();
