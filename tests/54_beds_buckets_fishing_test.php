<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/fake_client.php';

use pocketmine\api\event\DataPacketSendEvent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\constants\BlockIds;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\resource\ChunkStore;
use pocketmine\port\driven\PlayerRef;

/**
 * Bugs 9 / 8 / 13: beds (personal spawn + night skip), buckets (scoop/pour),
 * fishing rod (cast, bite, reel-in catch). Session-level logic is exercised
 * through reflection with fabricated session arrays.
 */

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$GLOBALS['world'] = $world;
$store = $world->getResourceRegistry()->get(ChunkStore::class);
if (!$store instanceof ChunkStore) {
    throw new RuntimeException('no chunk store');
}
$network = $kernel->getNetworkSessionService();

$player = $world->spawn(
    (new EntityBuilder())
        ->at(10.5, 65, 10.5)
        ->with(new HealthComponent(20, 20))
        ->with(new MetadataComponent(['entityType' => 'Player', 'gamemode' => 0, 'username' => 'Sleeper']))
        ->with(new InventoryComponent(36))
        ->withTag(PlayerTag::class)
);
// The fixtures live in chunk (0,0): load it so block writes stick.
$kernel->getChunkLoadService()->loadChunk(0, 0);
$world->tick(0.05);

$sessionRef = new PlayerRef('bed-test-uuid', $player->getId(), 'Sleeper');
function fakeSession(PlayerRef $ref): array {
    return [
        'playerRef' => $ref,
        'entityRef' => \pocketmine\core\ecs\EntityRef::create($ref->entityId, $GLOBALS['world']),
        'username' => $ref->name,
        'worldId' => 0,
        'openContainer' => null,
        'fishing' => null,
    ];
}

function invokePrivate(object $svc, string $method, array $args) {
    $m = new ReflectionMethod($svc::class, $method);
    $m->setAccessible(true);
    return $m->invokeArgs($svc, $args);
}

test('sleeping in a bed sets the personal spawn point and skips the night', function () use ($world, $kernel, $network, $store, $player, $sessionRef): void {
    $worldConfig = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
    $worldConfig->time = \pocketmine\core\system\TimeSystem::TIME_MIDNIGHT;

    $session = fakeSession($sessionRef);
    // Bed block at (12, 65, 12).
    $store->setBlock(12, 65, 12, BlockIds::BED);

    invokePrivate($network, 'sleepInBed', ['k', &$session, 12, 65, 12]);

    $meta = $world->getEntity($player->getId())?->get(MetadataComponent::class);
    same('12', (string)(int)$meta?->get('spawnX'), 'spawn X pinned to the bed');
    same('66', (string)(int)$meta?->get('spawnY'), 'spawn Y above the bed');
    same('12', (string)(int)$meta?->get('spawnZ'), 'spawn Z pinned to the bed');
    same(0, $worldConfig->time, 'night skipped to dawn');

    // Respawn honours the personal spawn over the world spawn.
    $health = $world->getEntity($player->getId())?->get(HealthComponent::class);
    $health->current = 0.0;
    $kernel->getPlayerRespawnService()->respawn(
        \pocketmine\core\ecs\EntityRef::create($player->getId(), $world)
    );
    $pos = $world->getEntity($player->getId())?->get(\pocketmine\core\component\PositionComponent::class);
    ok($pos !== null && abs($pos->x - 12.0) < 2 && abs($pos->z - 12.0) < 2,
        "respawn landed at the bed ({$pos?->x}, {$pos?->z})");
});

test('buckets scoop water and pour it back', function () use ($world, $kernel, $network, $store, $player, $sessionRef): void {
    $kernel->run(1); // flush pending despawn from the bed test
    $session = fakeSession($sessionRef);
    // Water pool at (14, 65, 14), player clicks it face-top.
    for ($y = 64; $y <= 65; $y++) {
        $store->setBlock(14, $y, 14, BlockIds::WATER);
    }
    $held = new \pocketmine\core\component\ItemStack(ItemIds::BUCKET, 0, 1);
    $pk = new \pocketmine\protocol\UseItemPacket();
    $pk->x = 14; $pk->y = 65; $pk->z = 14; $pk->face = 1;

    $handled = invokePrivate($network, 'handleBucketUse', ['k', &$session, $pk, $held]);
    ok($handled === true, 'scooping water handled');
    $inv = $world->getEntity($player->getId())?->get(InventoryComponent::class);
    $filled = $inv?->get($inv?->heldSlot);
    ok($filled !== null && $filled->itemId === ItemIds::BUCKET && $filled->meta === 8,
        'bucket filled with water (meta 8)');
    same(0, $store->getBlock(14, 65, 14), 'water source consumed');

    // Pour it back into air: click the floor block (14,64,14) face=up so the
    // water lands in the cell above (same loaded chunk).
    $store->setBlock(14, 64, 14, 1); // stone under the pool
    $pk->x = 14; $pk->y = 64; $pk->z = 14; $pk->face = 1;
    $held = $inv?->get($inv?->heldSlot);
    $handled = invokePrivate($network, 'handleBucketUse', ['k', &$session, $pk, $held]);
    ok($handled === true, 'pouring handled');
    same(BlockIds::WATER, $store->getBlock(14, 65, 14), 'water placed above the clicked floor');
    $emptied = $inv?->get($inv?->heldSlot);
    ok($emptied !== null && $emptied->meta === 0, 'bucket emptied');
});

test('fishing rod casts, bites and reels in a fish', function () use ($world, $kernel, $network, $store, $player): void {
    $session = fakeSession(new PlayerRef('bed-test-uuid', $player->getId(), 'Sleeper'));
    // A water column within casting range.
    for ($y = 63; $y <= 65; $y++) {
        $store->setBlock(20, $y, 20, BlockIds::WATER);
    }

    invokePrivate($network, 'castBobber', ['k', &$session]);
    $fishing = $session['fishing'] ?? null;
    ok(is_array($fishing), 'bobber cast');
    if (!is_array($fishing)) { return; }
    ok($world->getEntity((int)$fishing['bobber']) !== null, 'bobber entity exists');

    // Inject the fabricated session so the per-tick pass can drive it, then
    // force a bite and run the pass.
    $prop = new ReflectionProperty($network::class, 'sessions');
    $prop->setAccessible(true);
    $all = $prop->getValue($network);
    $all['k'] = $session;
    $prop->setValue($network, $all);

    $session['fishing']['biteAt'] = 0;
    $all['k'] = $session;
    $prop->setValue($network, $all);
    invokePrivate($network, 'processFishing', []);
    $sessions = $prop->getValue($network);
    $session = $sessions['k'] ?? null;
    ok(is_array($session['fishing'] ?? null) && !empty($session['fishing']['bitten']), 'bite announced');

    invokePrivate($network, 'handleFishingRod', ['k', &$session]);
    $inv = $world->getEntity($player->getId())?->get(InventoryComponent::class);
    $fish = 0;
    foreach (range(0, 35) as $slot) {
        $stack = $inv?->get($slot);
        if ($stack !== null && $stack->itemId === ItemIds::RAW_FISH) {
            $fish += $stack->count;
        }
    }
    ok($fish >= 1, "reeling in a bite yields raw fish ($fish)");
    ok($session['fishing'] === null, 'rod state cleared after reeling');
});

test('spectators are flagged invisible to other viewers', function () use ($world, $kernel, $store): void {
    $port = 21000 + random_int(0, 9999);
    $kernel->setNetworkingEnabled(true);
    $kernel->setBindPort($port);
    $kernel->run(1);

    $join = function (string $name, string $uuid) use ($kernel, $port): array {
        $c = new FakeClient($port);
        $c->handshake(fn() => $kernel->run(1));
        $c->connect(fn() => $kernel->run(1));
        $c->sendLogin($name, $uuid);
        $id = null;
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline && $id === null) {
            $kernel->run(1);
            $c->readGamePackets();
            usleep(3000);
            foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $pl) {
                if ($pl['username'] === $name && ($pl['chunksSent'] ?? 0) > 3) { $id = $pl['entityId']; }
            }
        }
        $c->readGamePackets();
        return [$c, $id];
    };

    $uuidV = sprintf('%08d-0000-0000-0000-000000000054', random_int(0, 99999999));
    $uuidS = sprintf('%08d-0000-0000-0000-000000000064', random_int(0, 99999999));
    [$viewer, $viewerId] = $join('Viewer', $uuidV);
    [$spec, $specId] = $join('Spooky', $uuidS);
    if ($viewerId === null || $specId === null) {
        ok(false, 'both clients joined');
        return;
    }
    ok(true, 'both clients joined');

    // Flip Spooky to spectator through the same metadata the engine reads.
    $meta = $world->getEntity($specId)?->get(MetadataComponent::class);
    $meta?->set(\pocketmine\core\constants\MetadataKeys::GAMEMODE, 3);
    fwrite(STDERR, "[FLIP] gamemode now=" . var_export($meta?->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE), true) . " sameObj=" . var_export($world->getEntity($specId)?->get(MetadataComponent::class) === $meta, true) . "\n");
    foreach ([['Viewer', $viewerId], ['Spooky', $specId]] as [$nm, $eid2]) {
        $pe = $world->getEntity($eid2);
        $pp = $pe?->get(\pocketmine\core\component\PositionComponent::class);
        fwrite(STDERR, "[POS] $nm id=$eid2 pos=" . ($pp ? "({$pp->x},{$pp->y},{$pp->z})" : 'null') . "\n");
    }

    // Watch Viewer's wire for Spooky's flags byte with bit 5 (invisible).
    $sentCount = 0;
    $kernel->getEventPort()->subscribe(DataPacketSendEvent::class, function (DataPacketSendEvent $e) use (&$sentCount, $specId) {
        $p = $e->getPacket();
        if ($p instanceof \pocketmine\protocol\SetEntityDataPacket && $p->eid === $specId) {
            $sentCount++;
            foreach ($p->metadata as $idx => [$t, $v]) {
            }
        }
    });
    $sawInvisible = false;
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline && !$sawInvisible) {
        $kernel->run(1);
        foreach ($viewer->readGamePackets() as [$id, $buffer]) {
            if ($id === \pocketmine\protocol\Info::ADD_PLAYER_PACKET || $id === \pocketmine\protocol\Info::ADD_ENTITY_PACKET || $id === \pocketmine\protocol\Info::ADD_ITEM_ENTITY_PACKET) {
                }
            if ($id !== \pocketmine\protocol\Info::SET_ENTITY_DATA_PACKET) {
                continue;
            }
            $eid = unpack('J', substr($buffer, 1, 8))[1]; // big-endian long
            if ($eid !== $specId) {
                continue;
            }
            fwrite(STDERR, "[SD] eid=$eid body=" . bin2hex(substr($buffer, 9)) . "\n");
            $off = 9;
            while ($off < strlen($buffer)) {
                $b = ord($buffer[$off]);
                $off++;
                if ($b === 127) { break; }
                $type = $b >> 5; $idx = $b & 31;
                if ($type === 0 && $idx === 0) { // DATA_FLAGS byte
                    $flags = ord($buffer[$off]);
                    if (($flags & 0x20) !== 0) { $sawInvisible = true; }
                    break 2;
                }
                // Skip other entry types by their wire size.
                $off += match ($type) {
                    0 => 1,          // byte
                    1 => 2,          // short
                    2 => 4,          // int
                    3 => 4,          // float
                    4 => 2 + unpack('v', substr($buffer, $off, 2))[1], // string
                    5 => 5,          // slot
                    6 => 12,         // pos
                    7 => 8,          // long
                    default => 0,
                };
            }
        }
        usleep(2000);
    }
    ok($sawInvisible, 'spectator entity carries the invisible metadata flag for viewers');
});

exit(runTests());
