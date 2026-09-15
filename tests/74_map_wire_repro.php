<?php

declare(strict_types=1);

/**
 * Probe: does exploration painting actually mutate the MapStore canvas?
 * Checks MapStore state directly after a chunk-column crossing.
 */

require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/fake_client.php';

use pocketmine\protocol\Info;
use pocketmine\protocol\MobEquipmentPacket;
use pocketmine\protocol\MovePlayerPacket;
use pocketmine\protocol\UseItemPacket;
use pocketmine\utils\BinaryStream;

use function pocketmine\bootstrap;

$worldsDir = getcwd() . '/worlds';
if (is_dir($worldsDir)) {
    exec('rm -rf ' . escapeshellarg($worldsDir));
}

$port = 21000 + random_int(0, 20000);
$kernel = \pocketmine\bootstrap();
$kernel->setNetworkingEnabled(true);
$kernel->setBindPort($port);
$kernel->setAutoShutdownOnRun(false);

$serverCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
if ($serverCfg !== null) {
    $serverCfg->seed = 1;
}
$worldCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
if ($worldCfg !== null) {
    $worldCfg->spawnMobs = false;
}
$lists = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\PlayerListManager::class);
if ($lists !== null) {
    $lists->addOp('alice');
}
$khronosCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\KhronosConfig::class);
$khronosCfg->chatMinIntervalSeconds = 0.0;
$khronosCfg->commandMinIntervalSeconds = 0.0;

$kernel->run(1);

$client = new FakeClient($port);
$client->handshake(fn() => $kernel->run(1));
$client->connect(fn() => $kernel->run(1));

$packets = [];
$drain = function (int $ticks = 1) use ($client, $kernel, &$packets): void {
    for ($i = 0; $i < $ticks; $i++) {
        $kernel->run(1);
    }
    foreach ($client->readGamePackets() as [$id, $buffer]) {
        $packets[] = [$id, $buffer];
    }
};

$client->sendLogin('Alice', '11111111-2222-3333-4444-555555555555');
$deadline = microtime(true) + 8.0;
$sawSpawn = false;
while (microtime(true) < $deadline && !$sawSpawn) {
    $drain();
    foreach ($packets as [$id, $buffer]) {
        if ($id === Info::PLAY_STATUS_PACKET) {
            $s = new BinaryStream($buffer, 1);
            if ($s->getInt() === 3) {
                $sawSpawn = true;
            }
        }
    }
}

$alice = null;
foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
    if ($p['username'] === 'Alice') {
        $alice = $p;
    }
}

$kernel->getCommandPort()->execute(new \pocketmine\api\command\ConsoleCommandSender(), 'give Alice 395 1');
$drain(3);

$me = new MobEquipmentPacket();
$me->eid = 0;
$me->item = [395, 1, 0, null];
$me->slot = 0;
$me->selectedSlot = 0;
$client->sendGamePacket($me);
$drain(2);

$use = new UseItemPacket();
$use->x = (int)floor($alice['x']);
$use->y = (int)floor($alice['y']) - 1;
$use->z = (int)floor($alice['z']);
$use->face = 1;
$use->item = [395, 1, 0, null];
$use->fx = $alice['x'];
$use->fy = $alice['y'];
$use->fz = $alice['z'];
$use->posX = $alice['x'];
$use->posY = $alice['y'];
$use->posZ = $alice['z'];
$client->sendGamePacket($use);
$drain(4);

$mapStore = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\MapStore::class);
$opaqueOf = function (array $map): int {
    $n = 0;
    foreach ($map['colors'] as $c) {
        if (($c >> 24) === 0xFF) {
            $n++;
        }
    }
    return $n;
};
printf("before move: maps=%d colors opaque=%d dirty=%s center=(%d,%d)\n",
    $mapStore->count(), $opaqueOf($mapStore->get(1)), $mapStore->get(1)['dirty'] ? 'Y' : 'N',
    $mapStore->get(1)['centerX'], $mapStore->get(1)['centerZ']);

$packets = [];
$move = new MovePlayerPacket();
$move->eid = 0;
$move->x = $alice['x'] + 20.5;
$move->y = $alice['y'];
$move->z = $alice['z'];
$move->yaw = 0.0;
$move->pitch = 0.0;
$move->bodyYaw = 0.0;
$move->mode = MovePlayerPacket::MODE_NORMAL;
$client->sendGamePacket($move);
$drain(8);

$map = $mapStore->get(1);
printf("after move:  opaque=%d dirty=%s\n", $opaqueOf($map), $map['dirty'] ? 'Y' : 'N');
printf("0x3b sent after move: %d\n", count(array_filter($packets, fn($p) => $p[0] === Info::CLIENTBOUND_MAP_ITEM_DATA_PACKET)));

// --- diagnose the paint loop guards directly ---
$store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
printf("store: chunks loaded=%d\n", $store->getCount());
$px = (int)floor($alice['x'] + 20.5);
$pz = (int)floor($alice['z']);
$ccx = $px >> 4;
$ccz = $pz >> 4;
printf("holder chunk: (%d,%d); neighbors loaded:\n", $ccx, $ccz);
for ($cy = $ccz - 1; $cy <= $ccz + 1; $cy++) {
    for ($cx = $ccx - 1; $cx <= $ccx + 1; $cx++) {
        printf("  chunk(%d,%d) loaded=%s", $cx, $cy, $store->isLoaded($cx, $cy) ? 'Y' : 'N');
        if ($store->isLoaded($cx, $cy)) {
            $h = $store->getHighestBlockAt($cx * 16 + 8, $cy * 16 + 8);
            $b = $store->getBlock($cx * 16 + 8, $h, $cy * 16 + 8);
            printf(" center highest=%d block=%d", $h, $b);
        }
        echo "\n";
    }
}

// reflection-invoke the painter with the live session
$nss = $kernel->getNetworkSessionService();
$m = new ReflectionMethod($nss, 'updateMapExploration');
$m->setAccessible(true);
$sessProp = new ReflectionProperty($nss, 'sessions');
$sessProp->setAccessible(true);
foreach ($sessProp->getValue($nss) as $key => $sess) {
    printf("invoking painter for session %s (worldId=%d)\n", $key, $sess['worldId'] ?? -1);
    $m->invoke($nss, $sess, $px + 0.5, $pz + 0.5);
    break;
}
$map = $mapStore->get(1);
printf("after manual paint: opaque=%d dirty=%s\n", $opaqueOf($map), $map['dirty'] ? 'Y' : 'N');

// inventory state right now
$entity = $kernel->getWorld()->getEntity($alice['entityId']);
$inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
foreach ($inv?->getContents() ?? [] as $i => $item) {
    if ($item !== null) {
        printf("inv slot %d: id=%d meta=%d\n", $i, $item->itemId, $item->meta);
    }
}

$kernel->shutdown();
echo "done\n";
