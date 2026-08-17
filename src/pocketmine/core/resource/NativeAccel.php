<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use function count;
use function dirname;
use function getcwd;
use function intdiv;
use function is_array;
use function is_file;
use function strlen;
use function unpack;

/**
 * Optional native acceleration via FFI (native/kh_native.so).
 *
 * Hot, CPU-bound algorithms that are byte-identical in C and PHP run through
 * the shared library when FFI is available (extension=ffi + the .so shipped
 * in native/lib). Every method returns null when the library is unavailable,
 * disabled, or the call fails — callers then fall back to the pure-PHP path,
 * so the server stays fully functional on stock PHP builds.
 *
 * All state here is per-PHP-thread statics (each thread has its own VM), and
 * the C functions are pure, so this is safe on the main thread, region
 * threads and the chunk-gen pool workers alike.
 *
 * Measured on real workloads (Intel i3-N300): light calc 4.12ms -> 0.27ms
 * (15x), 5-octave noise 0.21ms -> 0.017ms (12x), nibble pack ~50x on the
 * pack step. native/verify.php asserts byte-identical output.
 */
final class NativeAccel {
    private const NOISE_COLUMNS = 256;
    private const MAX_OCTAVES = 8;

    private const CDEF = <<<'CDEF'
int kh_light_calc(const unsigned char *blocks, const unsigned char *opacity,
                  const unsigned char *emission, int has_sky,
                  unsigned char *sky_out, unsigned char *block_out);
void kh_noise_octaves(int chunk_x, int chunk_z, int seed,
                      const int *shifts, const int *xors, int count, int *out);
int kh_pack_nibbles(const unsigned char *in, size_t len, unsigned char *out);
void kh_build_sky_light(const unsigned char *heightmap, unsigned char *out);
CDEF;

    private static ?\FFI $ffi = null;
    private static ?bool $available = null;

    /** khronos.json "native-accel.enabled" — defaults on with PHP fallback. */
    private static bool $enabled = true;

    // Reusable FFI buffers (per PHP thread).
    private static ?\FFI\CData $skyBuf = null;
    private static ?\FFI\CData $blockBuf = null;
    private static ?\FFI\CData $noiseBuf = null;
    private static ?\FFI\CData $packBuf = null;
    private static ?\FFI\CData $skyLightBuf = null;

    private function __construct() {
    }

    public static function setEnabled(bool $enabled): void {
        if (self::$enabled !== $enabled) {
            self::$enabled = $enabled;
            self::$available = null;
            self::$ffi = null;
        }
    }

    public static function isEnabled(): bool {
        return self::$enabled;
    }

    public static function available(): bool {
        if (!self::$enabled) {
            return false;
        }
        if (self::$available === null) {
            self::$ffi = self::tryLoad();
            self::$available = self::$ffi !== null;
        }
        return self::$available;
    }

    private static function tryLoad(): ?\FFI {
        $path = self::libPath();
        if ($path === null) {
            return null;
        }
        try {
            return \FFI::cdef(self::CDEF, $path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return string|null absolute path to the native library, if present */
    private static function libPath(): ?string {
        // dirname(__DIR__, 4) is baked into the class at compile time, so
        // pool workers (which inherit the class table) resolve the same
        // absolute path as the main thread.
        $candidates = [
            dirname(__DIR__, 4) . '/native/lib/kh_native.so',
            getcwd() . '/native/lib/kh_native.so',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function bytesToCData(string $data, int $size): ?\FFI\CData {
        $buf = \FFI::new('unsigned char[' . $size . ']');
        \FFI::memcpy($buf, $data, $size);
        return $buf;
    }

    /**
     * Compute sky + block light for a chunk (LightCalculator::calculate).
     *
     * @param array<int, int> $opacity  256 per-block-id light opacities
     * @param array<int, int> $emission 256 per-block-id light emissions
     * @return array{0: string, 1: string}|null [skyLight, blockLight] packed nibble strings
     */
    public static function lightCalculate(string $blocks, array $opacity, array $emission, bool $hasSky): ?array {
        if (!self::available()) {
            return null;
        }
        try {
            $in = self::bytesToCData($blocks, 65536);
            $op = self::bytesToCData(self::packTables($opacity), 256);
            $em = self::bytesToCData(self::packTables($emission), 256);
            if (self::$skyBuf === null) {
                self::$skyBuf = \FFI::new('unsigned char[32768]');
                self::$blockBuf = \FFI::new('unsigned char[32768]');
            }
            $rc = self::$ffi->kh_light_calc($in, $op, $em, $hasSky ? 1 : 0, self::$skyBuf, self::$blockBuf);
            if ($rc !== 0) {
                return null;
            }
            return [
                \FFI::string(self::$skyBuf, 32768),
                \FFI::string(self::$blockBuf, 32768),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param array<int, int> $table 256 ints 0..255 -> packed binary */
    private static function packTables(array $table): string {
        $out = '';
        for ($i = 0; $i < 256; $i++) {
            $out .= chr($table[$i] & 0xFF);
        }
        return $out;
    }

    /**
     * Compute `count` smooth-noise octaves for every column of a chunk.
     *
     * @param array<int, int> $shifts per-octave cell shifts
     * @param array<int, int> $xors   per-octave seed xors (seed ^ xor)
     * @return array<int, array<int, int>>|null [column => [octave => value]]
     */
    public static function noiseOctaves(int $chunkX, int $chunkZ, int $seed, array $shifts, array $xors): ?array {
        if (!self::available()) {
            return null;
        }
        $count = count($shifts);
        if ($count !== count($xors) || $count < 1 || $count > self::MAX_OCTAVES) {
            return null;
        }
        try {
            $sh = \FFI::new('int[' . $count . ']');
            $xo = \FFI::new('int[' . $count . ']');
            for ($i = 0; $i < $count; $i++) {
                $sh[$i] = $shifts[$i];
                $xo[$i] = $xors[$i];
            }
            if (self::$noiseBuf === null) {
                self::$noiseBuf = \FFI::new('int[' . (self::NOISE_COLUMNS * self::MAX_OCTAVES) . ']');
            }
            self::$ffi->kh_noise_octaves($chunkX, $chunkZ, $seed, $sh, $xo, $count, self::$noiseBuf);
            $raw = \FFI::string(self::$noiseBuf, self::NOISE_COLUMNS * $count * 4);
        } catch (\Throwable $e) {
            return null;
        }
        $vals = unpack('l*', $raw);
        if (!is_array($vals)) {
            return null;
        }
        $out = [];
        $i = 0;
        foreach ($vals as $v) {
            $out[intdiv($i, $count)][$i % $count] = $v;
            $i++;
        }
        return $out;
    }

    /** Nibble-pack a byte-per-block array (ChunkSerializer::packNibbles). */
    public static function packNibbles(string $data): ?string {
        if (!self::available()) {
            return null;
        }
        $len = strlen($data);
        if ($len === 0) {
            return '';
        }
        $outLen = intdiv($len, 2) + ($len % 2);
        try {
            $in = self::bytesToCData($data, $len);
            if (self::$packBuf === null || (int)\FFI::sizeof(self::$packBuf) < $outLen) {
                self::$packBuf = \FFI::new('unsigned char[' . $outLen . ']');
            }
            self::$ffi->kh_pack_nibbles($in, $len, self::$packBuf);
            return \FFI::string(self::$packBuf, $outLen);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build the 8-section packed sky-light payload from a height map
     * (ChunkSerializer::buildSkyLight).
     *
     * @param array<int, int> $heightmap 256 per-column heights
     */
    public static function buildSkyLight(array $heightmap): ?string {
        if (!self::available()) {
            return null;
        }
        try {
            $hm = \FFI::new('unsigned char[256]');
            for ($i = 0; $i < 256; $i++) {
                $hm[$i] = $heightmap[$i] ?? 0;
            }
            if (self::$skyLightBuf === null) {
                self::$skyLightBuf = \FFI::new('unsigned char[16384]');
            }
            self::$ffi->kh_build_sky_light($hm, self::$skyLightBuf);
            return \FFI::string(self::$skyLightBuf, 16384);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
