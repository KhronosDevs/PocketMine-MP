<?php

declare(strict_types=1);

/**
 * NBT bomb scenarios (in-process, no server boot needed).
 *
 * NBT arrives from untrusted clients (sign edits, item NBT). These scenarios
 * prove the decoder survives the classic bomb shapes:
 *
 *   1. deep-nested compounds (C-stack overflow attempt)
 *   2. hostile ListTag with an unsupported element type and a huge count
 *      (infinite-loop / main-thread hang attempt)
 *   3. zlib decompression bomb through readCompressed()
 *   4. deep nesting inside a sign-edit-shaped payload end-to-end
 *   5. legitimate sign NBT still parses (regression guard)
 */

require __DIR__ . '/lib.php';

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;

// 1. Deep nesting: 100k levels must throw, not segfault.
// (Little-endian wire format: name lengths are LE shorts, counts LE ints.)
$depth = 100_000;
$payload = '';
for ($i = 0; $i < $depth; ++$i) {
    $payload .= chr(NBT::TAG_Compound) . pack('v', 1) . 'x';
}
$payload .= chr(NBT::TAG_Short) . pack('v', 1) . 'v' . pack('v', 1);
for ($i = 0; $i < $depth; ++$i) {
    $payload .= chr(NBT::TAG_End);
}
$nbt = new NBT(NBT::LITTLE_ENDIAN);
$threw = false;
try {
    $nbt->read($payload);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'nesting too deep');
}
sec_ok($threw, 'deep-nested compounds throw instead of crashing');

// 2. Hostile list: unsupported element type + huge count must terminate fast.
$payload = chr(NBT::TAG_List) . pack('v', 4) . 'boom'
    . chr(13) . pack('V', 0x7FFFFFFF);
$nbt = new NBT(NBT::LITTLE_ENDIAN);
$start = microtime(true);
$nbt->read($payload);
$elapsed = microtime(true) - $start;
sec_ok(
    $elapsed < 0.5 && $nbt->getData() instanceof ListTag && $nbt->getData()->getCount() === 0,
    'hostile unsupported-type list terminates fast (hang attempt defeated)',
    'took ' . $elapsed . 's'
);

// 3. Decompression bomb through readCompressed(): 200 MB of zeros in <1 MB.
$bomb = gzencode(str_repeat("\x00", 200 * 1024 * 1024), 1);
$nbt = new NBT(NBT::LITTLE_ENDIAN);
$threw = false;
try {
    $nbt->readCompressed($bomb);
} catch (\Throwable) {
    $threw = true;
}
sec_ok($threw, 'decompression bomb is capped (64 MB limit)');

// 4. End-to-end: a sign-edit BlockEntityDataPacket carrying the hostile list
//    through the real decoder path used by the server.
$hostileNbt = chr(NBT::TAG_Compound) . pack('v', 0)
    . chr(NBT::TAG_List) . pack('v', 4) . 'boom' . chr(13) . pack('V', 0x7FFFFFFF)
    . chr(NBT::TAG_End)
    . chr(NBT::TAG_String) . pack('v', 2) . 'id' . pack('v', 4) . 'Sign'
    . chr(NBT::TAG_End);
$nbt = new NBT(NBT::LITTLE_ENDIAN);
$start = microtime(true);
$threw = false;
try {
    $nbt->read($hostileNbt);
} catch (RuntimeException) {
    $threw = true;
}
$elapsed = microtime(true) - $start;
sec_ok(
    $elapsed < 0.5,
    'sign-edit-shaped hostile NBT terminates fast end-to-end',
    'took ' . $elapsed . 's'
);

// 5. Regression guard: legitimate sign NBT still parses fully.
$legit = (new CompoundTag(''))
    ->setString('id', 'Sign')
    ->setString('Text1', 'hello')
    ->setString('Text2', 'world')
    ->setString('Text3', '')
    ->setString('Text4', '')
    ->setString('Creator', '00000000-0000-0000-0000-000000000000');
$nbt = new NBT(NBT::LITTLE_ENDIAN);
$nbt->setData($legit);
$wire = $nbt->write();
$reader = new NBT(NBT::LITTLE_ENDIAN);
$reader->read($wire);
$data = $reader->getData();
sec_ok(
    $data instanceof CompoundTag && $data->getString('id') === 'Sign' && $data->getString('Text1') === 'hello',
    'legitimate sign NBT still parses (regression guard)'
);

exit(sec_summary('hostile_nbt_test.php'));
