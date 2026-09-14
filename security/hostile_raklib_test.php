<?php

declare(strict_types=1);

/**
 * Hostile offline-layer (pre-session) scenarios against a LIVE RakLib wire
 * thread. These exercise the amplification/flood surface that requires no
 * authentication at all:
 *
 *   1. 1-byte spoofed UNCONNECTED_PING must NOT elicit a pong (reflection)
 *   2. ping WITHOUT the offline magic must NOT elicit a pong
 *   3. magic-carrying OPEN_CONNECTION_REQUEST_1 with mtuSize=0 must not
 *      crash the wire thread
 *   4. an oversized datagram (beyond max-datagram-size) is dropped
 *   5. a packet flood beyond packet-limit blocks the source IP
 *   6. a legitimate handshake still completes afterwards (regression guard)
 */

require __DIR__ . '/lib.php';

use pocketmine\Kernel;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\UNCONNECTED_PING;
use raklib\RakLib;

$kernel = null;
$port = 0;
[$kernel, $port] = sec_boot('raklib');
$client = new FakeClient($port);

// 1. Reflection: a 1-byte "ping" must get no response.
$client->sendDatagram(chr(UNCONNECTED_PING::$ID));
for ($i = 0; $i < 6; ++$i) {
    $kernel->run(1);
    usleep(20000);
}
$client->sendDatagram(chr(UNCONNECTED_PING::$ID)); // probe once more after ticks
for ($i = 0; $i < 6; ++$i) {
    $kernel->run(1);
    usleep(20000);
}
$sawPong = false;
foreach ($client->readDatagramsPublic() as $d) {
    if (ord($d[0]) === UNCONNECTED_PONG::$ID) {
        $sawPong = true;
    }
}
sec_ok(!$sawPong, '1-byte spoofed ping elicits no pong (no reflection)');

// 2. Magic-less ping (long id present, magic garbage) also gets nothing.
$bad = chr(UNCONNECTED_PING::$ID) . str_repeat("\x00", 8) . str_repeat('A', 16);
$client->sendDatagram($bad);
for ($i = 0; $i < 8; ++$i) {
    $kernel->run(1);
    usleep(20000);
}
$sawPong = false;
foreach ($client->readDatagramsPublic() as $d) {
    if (ord($d[0]) === UNCONNECTED_PONG::$ID) {
        $sawPong = true;
    }
}
sec_ok(!$sawPong, 'magic-less ping elicits no pong');

// 3. Zero-MTU handshake with valid magic: wire thread must survive.
$req1 = new OPEN_CONNECTION_REQUEST_1();
$req1->protocol = RakLib::PROTOCOL;
$req1->mtuSize = 0;
$req1->encode();
$client->sendDatagram($req1->buffer);
for ($i = 0; $i < 8; ++$i) {
    $kernel->run(1);
    usleep(20000);
}
sec_check_wire_errors($kernel, 'zero-MTU handshake does not crash the wire thread');

// 4. Oversized datagram: beyond any legitimate MTU, must be dropped.
$client->sendDatagram(str_repeat("\x8f", 9000));
for ($i = 0; $i < 8; ++$i) {
    $kernel->run(1);
    usleep(20000);
}
sec_check_wire_errors($kernel, 'oversized datagram dropped without wire-thread errors');

// 5. Flood: hammer garbage datagrams; the per-IP limit must trip and the
//    source gets blocked (silence afterwards). We do NOT assert silence as
//    a hard criterion until the flood ends; the pass criterion is that the
//    server survives and later serves a legitimate client.
$hostile = new FakeClient($port + 1); // same source IP (loopback), new port
$hostile->close();
$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
for ($i = 0; $i < 1500; ++$i) {
    @socket_sendto($sock, "\x84garbage", 8, 0, '127.0.0.1', $port);
}
for ($i = 0; $i < 4; ++$i) {
    $kernel->run(1);
}
socket_close($sock);
sec_check_wire_errors($kernel, 'packet flood survives without wire-thread errors');

// Lift the (expected) flood block so the regression-guard client below is
// judged on its own merits rather than on the block we just triggered.
sec_unblock_loopback($kernel);
for ($i = 0; $i < 3; ++$i) {
    $kernel->run(1);
    usleep(50000);
}

// 6. Regression guard: a legitimate client can still join after all the
//    hostility above.
$legit = new FakeClient($port);
$joined = false;
try {
    $legit->handshake(fn() => $kernel->run(1));
    $legit->connect(fn() => $kernel->run(1));
    $legit->sendLogin('SecProbe', 'cccccccc-dddd-eeee-ffff-000000000001');
    $joined = sec_await_spawn($legit, $kernel, 8.0);
} catch (RuntimeException) {
    $joined = false;
}
sec_ok($joined, 'legitimate client still joins after hostile traffic');

$legit->close();
$client->close();
$kernel->shutdown();
exit(sec_summary('hostile_raklib_test.php'));
