<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;

/**
 * NBT security hardening (network input is hostile).
 *
 * NBT payloads arrive from UNTRUSTED clients (sign edits, item NBT). Two
 * pre-hardening defects let a small payload take the whole server down:
 *   (1) readTag() recursed with no depth limit — deeply nested compounds
 *       overflowed the PHP C stack and crashed the process.
 *   (2) ListTag::read() looped $size times calling readValueTag(), which
 *       returns null for unknown tag types WITHOUT consuming a byte — the
 *       loop's feof() guard never fired, spinning ~2 billion no-op
 *       iterations on the main thread (remote hang via a crafted sign edit).
 * Also: readCompressed() had no decompression cap.
 */

function buildNestedCompound(int $depth): string {
    // One named compound containing either another compound (depth > 1)
    // or a single short (base case). Little-endian.
    $nbt = new NBT(NBT::LITTLE_ENDIAN);
    $leaf = (new CompoundTag('leaf'))->setShort('v', 1);
    $tag = $leaf;
    for ($i = 1; $i < $depth; ++$i) {
        $tag = new CompoundTag('c' . $i, [$tag]);
    }
    $nbt->setData($tag);
    return $nbt->write();
}

function buildHostileList(): string {
    // TAG_List named "boom", element type 13 (unsupported -> null without
    // consuming bytes), declared count 0x7FFFFFFF. Pre-fix this spun ~2
    // billion no-op iterations; post-fix it must terminate immediately.
    $payload = chr(NBT::TAG_List)
        . pack('n', 4) . 'boom'
        . chr(13)                      // unsupported element type
        . pack('N', 0x7FFFFFFF);       // huge declared count
    return $payload;
}

test('legitimate NBT still reads (roundtrip sanity)', function (): void {
    $nbt = new NBT(NBT::LITTLE_ENDIAN);
    $root = (new CompoundTag(''))
        ->setString('Text1', 'hello')
        ->setInt('x', 12)
        ->setTag('items', new ListTag('items', [
            new StringTag('', 'a'), new StringTag('', 'b'),
        ]));
    $nbt->setData($root);
    $written = $nbt->write();

    $reader = new NBT(NBT::LITTLE_ENDIAN);
    $reader->read($written);
    $data = $reader->getData();
    ok($data instanceof CompoundTag, 'root is compound');
    same('hello', $data->getString('Text1'));
    same(12, $data->getInt('x'));
    same(2, $data->getListTag('items')->getCount());
});

test('deeply nested compounds throw instead of crashing the process', function (): void {
    $nbt = new NBT(NBT::LITTLE_ENDIAN);
    $ex = null;
    try {
        // 4096 levels: far beyond MAX_DEPTH=32, and would segfault PHP
        // without the guard. (Recursion in the OLD code happened through
        // readTag -> Tag::read -> readTag; the new depth counter throws
        // a recoverable RuntimeException instead.)
        $nbt->read(buildNestedCompound(4096));
    } catch (RuntimeException $e) {
        $ex = $e;
    }
    ok($ex !== null, 'deep nesting must throw a recoverable exception');
    ok(str_contains($ex->getMessage(), 'nesting too deep'), 'message names the depth limit');
});

test('legitimate moderate nesting still reads fine', function (): void {
    $nbt = new NBT(NBT::LITTLE_ENDIAN);
    $nbt->read(buildNestedCompound(16));
    $data = $nbt->getData();
    ok($data instanceof CompoundTag, '16 levels of nesting read OK');
});

test('hostile unsupported-type list terminates instead of spinning forever', function (): void {
    $nbt = new NBT(NBT::LITTLE_ENDIAN);
    $start = microtime(true);
    $nbt->read(buildHostileList());
    $elapsed = microtime(true) - $start;
    // Post-fix this returns in microseconds. Pre-fix, ~2e9 iterations took
    // minutes of main-thread time. Assert a generous 0.5s to stay stable
    // under CI noise while still proving termination.
    ok($elapsed < 0.5, 'hostile list must terminate fast, took ' . $elapsed . 's');
    $data = $nbt->getData();
    ok($data instanceof ListTag, 'result is the (empty) list tag');
    same(0, $data->getCount(), 'no children parsed from the malformed list');
});

test('readCompressed caps decompressed size', function (): void {
    // A gzipped buffer that decompresses beyond the 64MB cap must throw
    // (caught upstream) rather than balloon memory. Build a >64MB expand
    // bomb: 200MB of zeros compresses to ~200KB.
    $bomb = str_repeat("\x00", 200 * 1024 * 1024);
    $compressed = gzencode($bomb, 1);
    ok(strlen($compressed) < 1024 * 1024, 'bomb is small compressed');
    $nbt = new NBT(NBT::LITTLE_ENDIAN);
    $ex = null;
    try {
        $nbt->readCompressed($compressed);
    } catch (\Throwable $e) {
        $ex = $e;
    }
    ok($ex !== null, 'oversized decompression must throw, not allocate');
});

test('readCompressed still reads legitimate level.dat payloads', function (): void {
    $nbt = new NBT(NBT::LITTLE_ENDIAN);
    $root = (new CompoundTag('Data'))->setString('LevelName', 'world');
    $nbt->setData($root);
    $compressed = $nbt->writeCompressed();

    $reader = new NBT(NBT::LITTLE_ENDIAN);
    $reader->readCompressed($compressed);
    $data = $reader->getData();
    ok($data instanceof CompoundTag, 'level.dat roundtrip is compound');
    same('world', $data->getString('LevelName'));
});

$passed = 0;
foreach ($GLOBALS['__tests'] as $t) {
    try {
        ($t['fn'])();
        echo 'PASS ' . $t['name'] . PHP_EOL;
        ++$passed;
    } catch (Throwable $e) {
        echo 'FAIL ' . $t['name'] . ': ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}
echo count($GLOBALS['__tests']) . " tests, {$GLOBALS['__assertions']} assertions, all passed" . PHP_EOL;
