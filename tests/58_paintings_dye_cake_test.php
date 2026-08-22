<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\constants\BlockIds;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\TileEntityStore;
use pocketmine\port\driven\PlayerRef;

/**
 * Bugs 23/25/26: paintings persist as tile entities, wool recolors with dye,
 * cakes place as sliceable blocks and get eaten.
 */

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$GLOBALS['world'] = $world;
$network = $kernel->getNetworkSessionService();
$store = $world->getResourceRegistry()->get(ChunkStore::class);
$tiles = $world->getResourceRegistry()->get(TileEntityStore::class);
if (!$store instanceof ChunkStore || !$tiles instanceof TileEntityStore) {
    throw new RuntimeException('missing stores');
}
$kernel->getChunkLoadService()->loadChunk(0, 0);
$kernel->getChunkLoadService()->loadChunk(1, 1);
$world->tick(0.05);

function fakeSession(PlayerRef $ref): array {
    return [
        'playerRef' => $ref,
        'entityRef' => EntityRef::create($ref->entityId, $GLOBALS['world']),
        'username' => $ref->name,
        'worldId' => 0,
        'openContainer' => null,
        'fishing' => null,
    ];
}

$playerRef = null;
$player = $world->spawn(
    (new EntityBuilder())->at(5.5, 65, 5.5)
        ->with(new HealthComponent(20, 20))
        ->with(new MetadataComponent(['entityType' => 'Player', 'gamemode' => 0]))
        ->with(new InventoryComponent(36))
        ->with(new \pocketmine\core\component\HungerComponent())
        ->withTag(\pocketmine\core\component\tags\PlayerTag::class)
);
$playerId = $player->getId();
$playerRef = new PlayerRef('cake-test-uuid', $playerId, 'Cakey');
$GLOBALS['networkSessions'] = null;

function injectSession($network, string $key, array $session): void {
    $prop = new ReflectionProperty($network::class, 'sessions');
    $prop->setAccessible(true);
    $all = $prop->getValue($network);
    $all[$key] = $session;
    $prop->setValue($network, $all);
}

function readSession($network, string $key): array {
    $prop = new ReflectionProperty($network::class, 'sessions');
    $prop->setAccessible(true);
    return $prop->getValue($network)[$key];
}

function invokePrivate(object $svc, string $method, array $args) {
    $m = new ReflectionMethod($svc::class, $method);
    $m->setAccessible(true);
    return $m->invokeArgs($svc, $args);
}

test('painting placement persists as a tile entity with motive + direction', function () use ($world, $network, $store, $tiles, $playerRef): void {
    // Stone wall at x=8 column, painting cell in front (carve terrain).
    $store->setBlock(8, 65, 10, 1); // stone
    $store->setBlock(9, 65, 10, 0); // ensure the hanging cell is air
    $pk = new \pocketmine\protocol\UseItemPacket();
    $pk->x = 8; $pk->y = 65; $pk->z = 10; $pk->face = 5; // east face -> cell (9,65,10)
    $held = new \pocketmine\core\component\ItemStack(ItemIds::PAINTING, 0, 1);
    $session = fakeSession($playerRef);

    $handled = invokePrivate($network, 'placePainting', ['k', &$session, $pk]);
    ok($handled === true, 'painting placement handled');
    $painting = $tiles->getPainting(9, 65, 10);
    ok(is_array($painting), 'painting tile persisted');
    if (is_array($painting)) {
        ok(is_string($painting['title']) && $painting['title'] !== '', 'painting has a motive title');
        same(3, $painting['direction'], 'east-face placement -> direction 3');
    }
});

test('dye recolors a wool block', function () use ($world, $network, $store, $playerRef): void {
    $store->setBlock(12, 65, 12, BlockIds::WOOL, 0); // white wool
    $pk = new \pocketmine\protocol\UseItemPacket();
    $pk->x = 12; $pk->y = 65; $pk->z = 12;
    // Give the player a red dye in hand and wire the fabricated session in.
    $inv = $world->getEntity($playerRef->entityId)?->get(InventoryComponent::class);
    $inv?->set($inv?->heldSlot ?? 0, new \pocketmine\core\component\ItemStack(ItemIds::DYE, 14, 1));
    injectSession($network, 'k', fakeSession($playerRef));
    $session = readSession($network, 'k');

    invokePrivate($network, 'handleUseItem', ['k', &$pk]);

    fwrite(STDERR, "[DBG] playerExists=" . var_export($world->getEntity($playerRef->entityId) !== null, true) . " comps=" . implode(',', array_keys($world->getEntity($playerRef->entityId)?->getComponents() ?? [])) . "\n");
    same(BlockIds::WOOL, $store->getBlock(12, 65, 12), 'still wool');
    same(14, $store->getBlockMeta(12, 65, 12), 'wool recolored to red (meta 14)');
});

test('cake places as a sliceable block and slices get eaten', function () use ($world, $kernel, $network, $store, $playerRef): void {
    $store->setBlock(20, 64, 20, 1); // stone floor
    $store->setBlock(20, 65, 20, 0); // air above the floor
    $store->setBlock(20, 66, 20, 0);
    $pk = new \pocketmine\protocol\UseItemPacket();
    $pk->x = 20; $pk->y = 64; $pk->z = 20; $pk->face = 1; // top face
    $held = new \pocketmine\core\component\ItemStack(ItemIds::CAKE_ITEM, 0, 1);
    injectSession($network, 'k', fakeSession($playerRef));
    // Put the cake in hand: handleUseItem reads the held slot from inventory.
    $inv2 = $world->getEntity($playerRef->entityId)?->get(InventoryComponent::class);
    $inv2?->set($inv2?->heldSlot ?? 0, $held);

    // Place via handleUseItem (consumes the cake item in survival).
    invokePrivate($network, 'handleUseItem', ['k', &$pk]);
    same(92, $store->getBlock(20, 65, 20), 'cake block placed');

    // Lower hunger below max so the slice-eating gate accepts.
    $hunger = $world->getEntity($playerRef->entityId)?->get(\pocketmine\core\component\HungerComponent::class);
    if ($hunger !== null) { $hunger->hunger = 10.0; }

    // Eat two slices.
    $session = readSession($network, 'k');
    invokePrivate($network, 'eatCakeSlice', ['k', &$session, 20, 65, 20, $store]);
    $session = readSession($network, 'k');
    invokePrivate($network, 'eatCakeSlice', ['k', &$session, 20, 65, 20, $store]);
    same(92, $store->getBlock(20, 65, 20), 'cake still present after two slices');
    same(2, $store->getBlockMeta(20, 65, 20), 'two slices eaten (meta 2)');
});

exit(runTests());
