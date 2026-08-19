<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

/**
 * Binary codec for the ECS cross-thread snapshot transport.
 *
 * Replaces the JSON-encoded payload transport: the same flat float arrays
 * are packed into a compact binary blob that is ~10x faster to encode and
 * decode on both sides of the thread boundary.
 *
 * Layout (all big-endian unless noted, floats little-endian IEEE-754):
 *   magic  "KSNA"                       4 bytes
 *   u8     version                      1 byte  (currently 1)
 *   u16    sectionCount                 2 bytes
 *   per section:
 *     u32  elementCount                 4 bytes
 *     raw  elementCount x float64le    N x 8 bytes  (pack('e*'))
 *
 * The format is transient (a snapshot lives for a single tick), so there is
 * no cross-version persistence concern beyond self-describing sections.
 */
final class SnapshotCodec {
    private const MAGIC = "KSNA";
    private const VERSION = 1;

    /**
     * @param list<float[]> $sections flat float arrays, one per component channel
     */
    public static function encode(array $sections): string {
        $out = self::MAGIC . chr(self::VERSION) . pack('n', count($sections));
        foreach ($sections as $values) {
            $out .= pack('N', count($values));
            $out .= $values === [] ? '' : pack('e*', ...$values);
        }
        return $out;
    }

    /**
     * @return list<float[]> one decoded flat array per section, in order
     */
    public static function decode(string $blob): array {
        $offset = 0;
        if (substr($blob, $offset, 4) !== self::MAGIC) {
            throw new \RuntimeException('Bad snapshot blob: missing magic');
        }
        $offset += 4;
        $version = ord($blob[$offset++]);
        if ($version !== self::VERSION) {
            throw new \RuntimeException("Bad snapshot blob: unsupported version {$version}");
        }
        $count = unpack('n', substr($blob, $offset, 2))[1];
        $offset += 2;

        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $n = unpack('N', substr($blob, $offset, 4))[1];
            $offset += 4;
            if ($n === 0) {
                $result[] = [];
                continue;
            }
            $raw = substr($blob, $offset, $n * 8);
            $offset += $n * 8;
            $values = unpack('e*', $raw);
            $result[] = $values === false ? [] : array_values($values);
        }
        return $result;
    }
}
