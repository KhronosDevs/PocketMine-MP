<?php

declare(strict_types=1);

/**
 * Live flood / DoS driver. Demonstrates and verifies the server's flood
 * responses end-to-end:
 *
 *   1. Unauthenticated session churn (handshake attempts that never
 *      complete) is absorbed — server survives, temporals are reaped.
 *   2. A packet flood trips the per-IP wire limit: subsequent traffic from
 *      that source is ignored until unblocked, then works again.
 *   3. A login flood trips the per-IP login throttle: beyond
 *      attempts-per-minute, attempts are rejected with a disconnect.
 *
 * SAFETY: all traffic targets 127.0.0.1 on random high ports of a server
 * this script booted itself. Total runtime is capped (~60s). Nothing here
 * is suitable or intended for use against any other host.
 */

require __DIR__ . '/lib.php';

use pocketmine\protocol\Info;

$kernel = null;
$port = 0;
[$kernel, $port] = sec_boot('flood');

// 1. Unauthenticated session churn ------------------------------------------
// Open-connection requests from many fresh sockets, never completing the
// handshake. The wire thread creates temporal sessions and reaps them.
$churnSock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
for ($i = 0; $i < 400; ++$i) {
    @socket_sendto($churnSock, "\x05" . str_repeat("\x00", 40), 41, 0, '127.0.0.1', $port);
    if ($i % 50 === 0) {
        $kernel->run(1);
    }
}
socket_close($churnSock);
for ($i = 0; $i < 10; ++$i) {
    $kernel->run(1);
    usleep(20000);
}
sec_check_wire_errors($kernel, 'unauthenticated session churn is absorbed');

// 2. Wire block trip + recovery ---------------------------------------------
// Flood garbage past the per-IP packet limit (default 350/tick).
$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
for ($i = 0; $i < 1200; ++$i) {
    @socket_sendto($sock, "\x84flood", 6, 0, '127.0.0.1', $port);
}
socket_close($sock);
for ($i = 0; $i < 4; ++$i) {
    $kernel->run(1);
}
sec_check_wire_errors($kernel, 'packet flood trips the wire limit without errors');

// While blocked, a fresh client gets nothing (silence = block). Send a raw
// ping directly and read for a short window instead of a full handshake
// (handshake() would only throw after its timeout on the same evidence).
$blocked = new FakeClient($port);
$blocked->sendDatagram(chr(0x01) . str_repeat("\x00", 8) . "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78");
for ($i = 0; $i < 5; ++$i) {
    $kernel->run(1);
    usleep(40000);
}
$silent = true;
foreach ($blocked->readDatagramsPublic() as $d) {
    if (ord($d[0]) === 0x1f) { // UNCONNECTED_PONG
        $silent = false;
    }
}
sec_ok($silent, 'blocked source receives no handshake replies');
$blocked->close();

// Unblock (admin action) -> a legitimate client joins again.
sec_unblock_loopback($kernel);
for ($i = 0; $i < 3; ++$i) {
    $kernel->run(1);
    usleep(50000);
}
$legit = new FakeClient($port);
$joined = false;
try {
    $legit->handshake(fn() => $kernel->run(1));
    $legit->connect(fn() => $kernel->run(1));
    $legit->sendLogin('FloodProbe', 'cccccccc-dddd-eeee-ffff-000000000003');
    $joined = sec_await_spawn($legit, $kernel, 8.0);
} catch (RuntimeException) {
    $joined = false;
}
sec_ok($joined, 'legitimate client joins after unblock');
$legit->close();

// 3. Login throttling ---------------------------------------------------------
// A fresh transport session sending logins past attempts-per-minute (30)
// must start receiving disconnects.
$throttled = new FakeClient($port);
$throttled->handshake(fn() => $kernel->run(1));
$throttled->connect(fn() => $kernel->run(1));
$sawReject = false;
for ($i = 0; $i < 40 && !$sawReject; ++$i) {
    $throttled->sendLogin('FloodProbe', 'cccccccc-dddd-eeee-ffff-000000000003');
    $kernel->run(2);
    foreach ($throttled->readGamePackets() as [$id, $buffer]) {
        if ($id === Info::DISCONNECT_PACKET) {
            $sawReject = true;
        }
    }
}
sec_ok($sawReject, 'login flood trips the per-IP login throttle (disconnect)');
$throttled->close();

$kernel->shutdown();
exit(sec_summary('flood_test.php'));
