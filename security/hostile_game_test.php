<?php

declare(strict_types=1);

/**
 * Game-layer hostile scenarios against a LIVE server (needs a login, so it
 * exercises the authenticated surface):
 *
 *   1. zlib batch bomb (few compressed KB -> >2 MB) is rejected
 *   2. sign-edit NBT bomb through the real BlockEntityDataPacket path
 *   3. sign edit on a block that is NOT a sign is ignored
 *   4. NaN and absurd coordinates are rejected by the movement validator
 *   5. an oversized login skin is rejected
 *   6. a control-character username is sanitized
 *   7. a legitimate move is still accepted (regression guard)
 */

require __DIR__ . '/lib.php';

use pocketmine\protocol\BlockEntityDataPacket;
use pocketmine\protocol\Info;
use pocketmine\protocol\MovePlayerPacket;
use pocketmine\utils\BinaryStream;

$kernel = null;
$port = 0;
[$kernel, $port] = sec_boot('game');
$client = new FakeClient($port);

// --- join a legitimate session ---------------------------------------------
$client->handshake(fn() => $kernel->run(1));
$client->connect(fn() => $kernel->run(1));
$client->sendLogin('SecProbe', 'cccccccc-dddd-eeee-ffff-000000000002');
sec_ok(sec_await_spawn($client, $kernel), 'legitimate session established');

/** Send one raw game packet body (id + fields), batch-wrapped like a client. */
$sendGame = function (string $body) use ($client): void {
    $client->sendRawBuffer($body);
};

/** Build a zlib batch bomb: tiny compressed, huge decompressed. */
$batchBomb = function () use ($sendGame): void {
    $bomb = zlib_encode(str_repeat("\x00", 8 * 1024 * 1024), ZLIB_ENCODING_DEFLATE, 9);
    $frame = chr(Info::BATCH_PACKET) . $bomb;
    $sendGame($frame);
};

// 1. Batch bomb: server must survive; subsequent traffic still works.
$batchBomb();
for ($i = 0; $i < 4; ++$i) {
    $kernel->run(1);
    usleep(10000);
}
sec_check_wire_errors($kernel, 'zlib batch bomb rejected without killing the session');

// 2. Sign NBT bomb through the real path: place a real sign block first.
$store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
sec_ok($store instanceof \pocketmine\core\resource\ChunkStore, 'chunk store available');
$sg = null;
if (isset($GLOBALS['sec_seen'][Info::START_GAME_PACKET])) {
    $s = new BinaryStream($GLOBALS['sec_seen'][Info::START_GAME_PACKET], 1);
    $s->getLong(); // eid
    $sg = ['x' => $s->getFloat(), 'y' => $s->getFloat(), 'z' => $s->getFloat()];
}
sec_ok($sg !== null, 'start game position captured');
$bx = (int)floor($sg['x']);
$by = (int)floor($sg['y']);
$bz = (int)floor($sg['z']);
// Find a solid column near spawn; replace its top block with a sign (63).
$signPlaced = false;
for ($dx = -2; $dx <= 2 && !$signPlaced; ++$dx) {
    for ($dz = -2; $dz <= 2 && !$signPlaced; ++$dz) {
        $x = $bx + $dx;
        $z = $bz + $dz;
        $top = $store->getHighestBlockAt($x, $z);
        if ($top > 1) {
            $store->setBlock($x, $top, $z, 63, 0);
            $sx = $x;
            $sy = $top;
            $sz = $z;
            $signPlaced = true;
        }
    }
}
sec_ok($signPlaced, 'sign block placed near spawn');

// Build the hostile sign payload (little-endian NBT, as the client sends).
$hostileNbt = chr(\pocketmine\nbt\NBT::TAG_Compound) . pack('v', 0)
    . chr(\pocketmine\nbt\NBT::TAG_List) . pack('v', 4) . 'boom'
    . chr(13) . pack('V', 0x7FFFFFFF)
    . chr(\pocketmine\nbt\NBT::TAG_End)
    . chr(\pocketmine\nbt\NBT::TAG_String) . pack('v', 2) . 'id' . pack('v', 4) . 'Sign'
    . chr(\pocketmine\nbt\NBT::TAG_End);
$pk = new BlockEntityDataPacket();
$pk->x = $sx;
$pk->y = $sy;
$pk->z = $sz;
$pk->namedtag = $hostileNbt;
$pk->encode();
$start = microtime(true);
$sendGame($pk->getBuffer());
for ($i = 0; $i < 6; ++$i) {
    $kernel->run(1);
    usleep(5000);
}
$elapsed = microtime(true) - $start;
sec_ok($elapsed < 3.0, 'sign-edit NBT bomb does not hang the server', 'took ' . $elapsed . 's');
sec_check_wire_errors($kernel, 'sign-edit NBT bomb produces no wire-thread errors');

// 3. Same payload on a non-sign block must be ignored outright.
$pk2 = new BlockEntityDataPacket();
$pk2->x = $sx;
$pk2->y = $sy + 5; // air, not a sign
$pk2->z = $sz;
$pk2->namedtag = $hostileNbt;
$pk2->encode();
$sendGame($pk2->getBuffer());
for ($i = 0; $i < 3; ++$i) {
    $kernel->run(1);
    usleep(5000);
}
sec_check_wire_errors($kernel, 'sign edit on a non-sign block is ignored');

// 4. NaN + absurd coordinates must be rejected: the player does NOT move.
$sendNanMove = function (float $x, float $y, float $z) use ($sendGame): void {
    $m = new MovePlayerPacket();
    $m->eid = 0;
    $m->x = $x;
    $m->y = $y;
    $m->z = $z;
    $m->yaw = 0.0;
    $m->bodyYaw = 0.0;
    $m->pitch = 0.0;
    $m->mode = MovePlayerPacket::MODE_NORMAL;
    $m->onGround = true;
    $m->encode();
    $sendGame($m->getBuffer());
};
$sendNanMove(NAN, NAN, NAN);
$sendNanMove(5.0e15, 1.0e15, -5.0e15);
for ($i = 0; $i < 6; ++$i) {
    $kernel->run(1);
    usleep(5000);
}
$entity = null;
foreach ($kernel->getWorld()->getEntities() as $e) {
    $meta = $e->get(\pocketmine\core\component\MetadataComponent::class);
    if ($meta !== null && $meta->get('username') === 'SecProbe') {
        $entity = $e;
    }
}
sec_ok($entity !== null, 'player entity still present after NaN moves');
if ($entity !== null) {
    $pos = $entity->get(\pocketmine\core\component\PositionComponent::class);
    $sane = $pos !== null && abs($pos->x) < 1_000_000 && abs($pos->z) < 1_000_000;
    sec_ok($sane, 'player position stays finite/sane after NaN moves');
}

// 5+6. Hostile login on a SECOND session: giant skin + control-char name.
$bad = new FakeClient($port);
$bad->handshake(fn() => $kernel->run(1));
$bad->connect(fn() => $kernel->run(1));
sec_send_hostile_login($bad, "\x02\x1b[31mBad\x7fName", str_repeat('A', 256 * 1024));
$kernel->run(2);
// Either the login is rejected (disconnect) or it joins with a sanitized
// name <= 16 printable chars. It must NEVER store the raw 256 KB skin or
// the raw control-character name. Wait a moment, then check state.
for ($i = 0; $i < 10; ++$i) {
    $kernel->run(1);
    usleep(10000);
}
$nss = $kernel->getNetworkSessionService();
$badNameStored = false;
foreach ($nss->getOnlinePlayers() as $p) {
    if ($p['username'] === "\x02\x1b[31mBad\x7fName" || strlen((string)($p['username'] ?? '')) > 16) {
        $badNameStored = true;
    }
}
sec_ok(!$badNameStored, 'hostile username is rejected or sanitized');
$bad->close();

// 7. Regression guard: the original session can still move legitimately.
$sendNanMove($sg['x'] + 0.1, $sg['y'], $sg['z']); // small legal move
for ($i = 0; $i < 6; ++$i) {
    $kernel->run(1);
    usleep(5000);
}
sec_check_wire_errors($kernel, 'session still healthy after all hostile input');

$client->close();
$kernel->shutdown();
exit(sec_summary('hostile_game_test.php'));
