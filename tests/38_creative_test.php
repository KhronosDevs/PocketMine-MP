<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/fake_client.php'; // FakeClient

use pocketmine\core\component\InventoryComponent;
use pocketmine\protocol\ContainerSetContentPacket;
use pocketmine\protocol\ContainerSetSlotPacket;
use pocketmine\protocol\Info;
use pocketmine\utils\BinaryStream;

$port = 21000 + random_int(0, 20000);

$kernel = \pocketmine\bootstrap();
$kernel->setNetworkingEnabled(true);
$kernel->setBindPort($port);
$kernel->setAutoShutdownOnRun(false);

// Keep the world deterministic (mobs off, fixed seed) like the network test.
$cfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
if ($cfg instanceof \pocketmine\core\resource\ServerConfig) {
    $cfg->seed = 1;
}
$worldCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
if ($worldCfg instanceof \pocketmine\core\resource\WorldConfig) {
    $worldCfg->spawnMobs = false;
}

$kernel->run(1); // bind socket + start the RakNet thread + first tick

$client = new FakeClient($port);

test('creative contents are sent on join', function () use ($client, $kernel): void {
    $client->handshake(fn() => $kernel->run(1));
    $client->connect(fn() => $kernel->run(1));

    $client->sendLogin('Alice', '11111111-2222-3333-4444-555555555555');
    $kernel->run(2);

    $deadline = microtime(true) + 8.0;
    $creativeSeen = false;
    $creativeCount = 0;
    while (microtime(true) < $deadline) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::CONTAINER_SET_CONTENT_PACKET) {
                // ContainerSetContentPacket::decode() is a no-op (encode-only
                // packet) - parse the raw wire fields directly.
                $s = new BinaryStream($buffer, 1);
                $windowId = $s->getByte();
                $count = $s->getShort();
                if ($windowId === ContainerSetContentPacket::SPECIAL_CREATIVE) {
                    $creativeSeen = true;
                    $creativeCount = $count;
                }
            }
        }
        if ($creativeSeen) {
            break;
        }
        $kernel->run(1);
    }

    ok($creativeSeen, 'server sent the creative contents packet (window 0x79)');
    ok($creativeCount > 100, "creative list has items ($creativeCount entries)");
});

test('a creative pick lands the item in the inventory slot', function () use ($client, $kernel): void {
    // Client picks a diamond (id 264) into slot 3 of its inventory.
    $pick = new ContainerSetSlotPacket();
    $pick->windowid = ContainerSetContentPacket::SPECIAL_CREATIVE;
    $pick->slot = 3;
    $pick->hotbarSlot = 3;
    $pick->item = [264, 1, 0, null];
    $client->sendGamePacket($pick);
    $kernel->run(2);

    $aliceId = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $aliceId = $p['entityId'];
        }
    }
    ok($aliceId !== null, 'Alice is online');
    $inv = $kernel->getWorld()->getEntity($aliceId)?->get(InventoryComponent::class);
    ok($inv !== null, 'Alice inventory present');
    if ($inv !== null) {
        $picked = $inv->get(3);
        ok($picked !== null && $picked->itemId === 264, 'picked diamond lands in slot 3');
    }

    // The server mirrors the changed slot back (window 0, slot 3).
    $mirrorSeen = false;
    foreach ($client->readGamePackets() as [$id, $buffer]) {
        if ($id === Info::CONTAINER_SET_SLOT_PACKET) {
            $pk = new ContainerSetSlotPacket();
            $pk->setBuffer($buffer, 1);
            $pk->decode();
            if ($pk->windowid === ContainerSetContentPacket::SPECIAL_INVENTORY && $pk->slot === 3) {
                $mirrorSeen = true;
            }
        }
    }
    ok($mirrorSeen, 'server mirrored the picked slot back (window 0, slot 3)');
});

test('shutdown completes cleanly', function () use ($client, $kernel): void {
    $client->close();
    $kernel->shutdown();
    ok(true, 'kernel thread stopped');
});

exit(runTests());
