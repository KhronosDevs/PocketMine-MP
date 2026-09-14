<?php

declare(strict_types=1);

/**
 * Cauldron interaction test (legacy parity: block 118, water level in meta
 * 0-6). Covers bucket fill/empty and glass-bottle filling through
 * NetworkSessionService::interactCauldron() plus the block-meta updates.
 */

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\constants\BlockIds;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\resource\ChunkStore;

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$store = $kernel->getResourceRegistry()->get(ChunkStore::class);
ok($store instanceof ChunkStore, 'chunk store available');
$nss = $kernel->getNetworkSessionService();
ok($nss !== null, 'network session service available');

// Test rig coordinates (loaded chunk 6,6 => x,z in 96..111).
const CX = 100;
const CY = 65;
const CZ = 100;

/** A fake session array shaped like the real one, with a player entity. */
function makeSession(\pocketmine\core\ecs\World $world): array {
    $ref = $world->spawn(
        (new \pocketmine\core\ecs\EntityBuilder())
            ->with(new PositionComponent(CX + 0.5, CY + 1, CZ + 0.5))
            ->with(new RotationComponent())
            ->with(new VelocityComponent())
            ->with(new \pocketmine\core\component\HealthComponent())
            ->with(new MetadataComponent())
            ->with(new \pocketmine\core\component\WorldComponent(0))
            ->with(new \pocketmine\core\component\AIStateComponent())
            ->with(new InventoryComponent()),
    );
    return [
        'playerRef' => $ref,
        'entityRef' => $ref,
        'worldId' => 0,
    ];
}

/** Reflection shim: interactCauldron is private; expose it for testing. */
function callCauldron(object $nss, string $addrKey, array &$session, int $x, int $y, int $z): bool {
    $method = new ReflectionMethod($nss, 'interactCauldron');
    $method->setAccessible(true);
    $pk = new \pocketmine\protocol\UseItemPacket();
    $pk->x = $x;
    $pk->y = $y;
    $pk->z = $z;
    $pk->face = 1;
    $args = [$addrKey, &$session, $pk, \pocketmine\Kernel::getInstance()->getResourceRegistry()->get(ChunkStore::class)];
    return $method->invokeArgs($nss, $args);
}

test('water bucket empties into an empty cauldron (meta 6)', function () use ($world, $store, $nss): void {
    $kernel = \pocketmine\Kernel::getInstance();
    $kernel->getChunkLoadService()->loadChunk(6, 6);
    $store->setBlock(CX, CY, CZ, BlockIds::CAULDRON, 0x00);

    $session = makeSession($world);
    $inv = $session['entityRef']->getEntity()?->get(InventoryComponent::class);
    $inv->set(0, new ItemStack(ItemIds::BUCKET, BlockIds::WATER, 1));
    $inv->heldSlot = 0;

    ok(callCauldron($nss, 'test1', $session, CX, CY, CZ), 'interaction handled');
    same(0x06, $store->getBlockMeta(CX, CY, CZ), 'cauldron filled to level 6');
    $after = $inv->get(0);
    ok($after !== null && $after->itemId === ItemIds::BUCKET && $after->meta === 0, 'water bucket became empty bucket');
});

test('empty bucket fills only from a full cauldron', function () use ($world, $store, $nss): void {
    $session = makeSession($world);
    $inv = $session['entityRef']->getEntity()?->get(InventoryComponent::class);
    $inv->set(0, new ItemStack(ItemIds::BUCKET, 0, 1));
    $inv->heldSlot = 0;

    // Half-full cauldron: refused, level unchanged.
    $store->setBlock(CX, CY, CZ, BlockIds::CAULDRON, 0x03);
    ok(!callCauldron($nss, 'test2', $session, CX, CY, CZ), 'half-full cauldron refuses a bucket');
    same(0x03, $store->getBlockMeta(CX, CY, CZ), 'level unchanged');

    // Full cauldron: fills the bucket, empties the cauldron.
    $store->setBlock(CX, CY, CZ, BlockIds::CAULDRON, 0x06);
    ok(callCauldron($nss, 'test2b', $session, CX, CY, CZ), 'full cauldron fills the bucket');
    same(0x00, $store->getBlockMeta(CX, CY, CZ), 'cauldron emptied');
    $after = $inv->get(0);
    ok($after !== null && $after->itemId === ItemIds::BUCKET && $after->meta === BlockIds::WATER, 'bucket now holds water');
});

test('glass bottles fill from a 2/3-full cauldron (level >= 2)', function () use ($world, $store, $nss): void {
    $session = makeSession($world);
    $inv = $session['entityRef']->getEntity()?->get(InventoryComponent::class);
    $inv->set(0, new ItemStack(ItemIds::GLASS_BOTTLE, 0, 1));
    $inv->heldSlot = 0;

    // Level 1: too low, refused.
    $store->setBlock(CX, CY, CZ, BlockIds::CAULDRON, 0x01);
    ok(!callCauldron($nss, 'test3', $session, CX, CY, CZ), 'level 1 refuses a bottle');

    // Level 2: bottle fills, level drops to 0.
    $store->setBlock(CX, CY, CZ, BlockIds::CAULDRON, 0x02);
    ok(callCauldron($nss, 'test3b', $session, CX, CY, CZ), 'level 2 fills the bottle');
    same(0x00, $store->getBlockMeta(CX, CY, CZ), 'level dropped by 2');
    $after = $inv->get(0);
    ok($after !== null && $after->itemId === ItemIds::POTION && $after->meta === 0, 'glass bottle became water bottle');

    // Level 6: one bottle leaves 4.
    $inv->set(0, new ItemStack(ItemIds::GLASS_BOTTLE, 0, 1));
    $store->setBlock(CX, CY, CZ, BlockIds::CAULDRON, 0x06);
    ok(callCauldron($nss, 'test3c', $session, CX, CY, CZ), 'full cauldron fills a bottle');
    same(0x04, $store->getBlockMeta(CX, CY, CZ), 'level dropped 6 -> 4');
});

exit(runTests());
