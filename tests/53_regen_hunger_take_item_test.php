<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/fake_client.php';

use pocketmine\api\event\DataPacketSendEvent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\HungerComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\system\TimeSystem;

/**
 * Survival polish:
 *  - natural regen costs hunger (old-src exhaust(3.0, CAUSE_HEALTH_REGEN))
 *  - item/XP pickup broadcasts TakeItemEntityPacket to viewers
 */

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();

function mkSurvivor($world, float $x, float $z) {
    return $world->spawn(
        (new EntityBuilder())
            ->at($x, 65, $z)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['entityType' => 'Player', 'gamemode' => 0]))
            ->with(new HungerComponent())
            ->with(new \pocketmine\core\component\InventoryComponent(36))
            ->withTag(PlayerTag::class)
    );
}

test('natural regen consumes hunger (exhaust 3.0 per HP)', function () use ($world, $kernel): void {
    $p = mkSurvivor($world, 10, 10);
    $world->tick(0.05);
    $health = $world->getEntity($p->getId())?->get(HealthComponent::class);
    $hunger = $world->getEntity($p->getId())?->get(HungerComponent::class);
    $health->current = 15.0;
    $satBefore = $hunger->saturation;

    // REGEN_DELAY (100) + first regen tick.
    for ($i = 0; $i < 102; $i++) {
        $world->tick(0.05);
    }
    ok($health->current > 15.0, "regen healed the player ({$health->current})");
    ok($hunger->saturation < $satBefore || $hunger->hunger < 20.0,
        "regen consumed food (sat {$satBefore} -> {$hunger->saturation}, hunger={$hunger->hunger})");
});

test('item pickup broadcasts TakeItemEntityPacket', function () use ($kernel, $world): void {
    $taken = [];
    $kernel->getEventPort()->subscribe(DataPacketSendEvent::class, function (DataPacketSendEvent $e) use (&$taken) {
        if ($e->getPacket() instanceof \pocketmine\protocol\TakeItemEntityPacket) {
            $taken[] = $e->getPacket();
        }
    });

    // A real connection so broadcastWorldEvent has a session to route to.
    $port = 20000 + random_int(0, 20000);
    $kernel->setNetworkingEnabled(true);
    $kernel->setBindPort($port);
    $kernel->run(1);

    $client = new FakeClient($port);
    $client->handshake(fn() => $kernel->run(1));
    $client->connect(fn() => $kernel->run(1));
    $client->sendLogin('Picker', '53000000-0000-0000-0000-000000000053');
    $playerId = null;
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline && $playerId === null) {
        $kernel->run(1);
        $client->readGamePackets();
        usleep(3000);
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $pl) {
            if ($pl['username'] === 'Picker' && ($pl['chunksSent'] ?? 0) > 3) {
                $playerId = $pl['entityId'];
            }
        }
    }
    if ($playerId === null) {
        ok(false, 'Picker joined');
        return;
    }
    $client->readGamePackets();
    ok(true, 'Picker joined');

    // Drop an item right on top of them with no pickup delay.
    $pos = $world->getEntity($playerId)?->get(\pocketmine\core\component\PositionComponent::class);
    $item = $kernel->getEntitySpawnService()->spawnItem(
        $pos->x + 0.2, $pos->y, $pos->z + 0.2,
        new \pocketmine\core\component\ItemStack(3, 1, 0),
    );
    $meta = $world->getEntity($item->getId())?->get(MetadataComponent::class);
    $meta?->set(\pocketmine\core\constants\MetadataKeys::PICKUP_DELAY, 0);

    for ($i = 0; $i < 20; $i++) {
        $kernel->run(1);
        $client->readGamePackets();
        usleep(2000);
    }

    ok(count($taken) >= 1, 'TakeItemEntityPacket fired through DataPacketSendEvent');
    if (count($taken) >= 1) {
        same($item->getId(), $taken[0]->target, 'target is the collected item entity');
        same($playerId, $taken[0]->eid, 'eid is the collecting player');
    }
});

exit(runTests());
