<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/fake_client.php';

/**
 * FakeClient::keepAlive() — long tick-only test loops produce >10 seconds of
 * client silence, which RakNet answers with a session timeout and
 * PlayerLeaveService answers by removing + persisting the player entity.
 * Every later assertion then reads a despawned ghost (the source of several
 * flaky "entity missing" failures). keepAlive() flushes queued ACKs so the
 * server sees activity and keeps the session up.
 */

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$port = 24000 + random_int(0, 2000);
$kernel->setNetworkingEnabled(true);
$kernel->setBindPort($port);
$kernel->run(1);

function joinSleeper($kernel, $world, FakeClient $c, int $port): ?int {
    $c->handshake(fn() => $kernel->run(1));
    $c->connect(fn() => $kernel->run(1));
    $c->sendLogin('Sleeper', '77000000-0000-0000-0000-000000000077');
    $id = null;
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline && $id === null) {
        $kernel->run(1);
        $c->readGamePackets();
        usleep(2000);
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $pl) {
            if ($pl['username'] === 'Sleeper' && ($pl['chunksSent'] ?? 0) > 3) { $id = $pl['entityId']; }
        }
    }
    return $id;
}

test('keepAlive() prevents the RakNet idle timeout during long loops', function () use ($kernel, $world, $port): void {
    $c = new FakeClient($port);
    $id = joinSleeper($kernel, $world, $c, $port);
    ok($id !== null, 'client joined');
    if ($id === null) {
        return;
    }

    // 700 slow ticks stretch past the 10-second idle window; without
    // keepAlive the session is removed partway through.
    for ($i = 1; $i <= 700; $i++) {
        $kernel->run(1);
        if ($i % 20 === 0) {
            $c->keepAlive();
        }
        usleep(16000);
    }

    $stillOnline = false;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $pl) {
        if ($pl['username'] === 'Sleeper') { $stillOnline = true; }
    }
    ok($stillOnline, 'session survived past the idle-timeout window');
    ok($world->getEntity($id) !== null, 'player entity still in the world');
});

exit(runTests());
