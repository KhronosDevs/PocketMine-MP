<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\worldgen;

/**
 * 2D simplex noise for terrain generation.
 *
 * Ported from Nukkit's cn.nukkit.level.generator.noise.Simplex.
 * Produces values in approximately [-1, 1].
 */
final class SimplexNoise {

    private const F2 = 0.3660254037844386; // (sqrt(3) - 1) / 2
    private const G2 = 0.21132486540518713; // (3 - sqrt(3)) / 6

    private array $perm = [];
    private float $frequency;
    private float $lacunarity;
    private float $persistence;

    public function __construct(int &$rngState, float $frequency = 1.0, float $lacunarity = 0.5, float $persistence = 0.25) {
        $this->frequency = $frequency;
        $this->lacunarity = $lacunarity;
        $this->persistence = $persistence;

        // Build permutation table from RNG state
        $this->perm = array_fill(0, 512, 0);
        $base = [];
        for ($i = 0; $i < 256; $i++) {
            $base[$i] = $i;
        }
        // Fisher-Yates shuffle using the RNG state
        for ($i = 255; $i > 0; $i--) {
            $rngState = ($rngState * 1103515245 + 12345) & 0x7FFFFFFF;
            $j = $rngState % ($i + 1);
            [$base[$i], $base[$j]] = [$base[$j], $base[$i]];
        }
        for ($i = 0; $i < 256; $i++) {
            $this->perm[$i] = $base[$i];
            $this->perm[$i + 256] = $base[$i];
        }
    }

    public function noise2D(float $x, float $y): float {
        $x *= $this->frequency;
        $y *= $this->frequency;

        // Skew input space
        $s = ($x + $y) * self::F2;
        $i = (int)floor($x + $s);
        $j = (int)floor($y + $s);

        // Unskew
        $t = ($i + $j) * self::G2;
        $x0 = $x - ($i - $t);
        $y0 = $y - ($j - $t);

        // Determine simplex
        if ($x0 > $y0) {
            $i1 = 1;
            $j1 = 0;
        } else {
            $i1 = 0;
            $j1 = 1;
        }

        $x1 = $x0 - $i1 + self::G2;
        $y1 = $y0 - $j1 + self::G2;
        $x2 = $x0 - 1.0 + 2.0 * self::G2;
        $y2 = $y0 - 1.0 + 2.0 * self::G2;

        // Hash corners
        $ii = $i & 255;
        $jj = $j & 255;
        $gi0 = $this->perm[$ii + $this->perm[$jj]] % 8;
        $gi1 = $this->perm[$ii + $i1 + $this->perm[$jj + $j1]] % 8;
        $gi2 = $this->perm[$ii + 1 + $this->perm[$jj + 1]] % 8;

        // Gradient dot products
        $n0 = $this->dotGrad($gi0, $x0, $y0);
        $n1 = $this->dotGrad($gi1, $x1, $y1);
        $n2 = $this->dotGrad($gi2, $x2, $y2);

        // Contribution falloff
        $t0 = 0.5 - $x0 * $x0 - $y0 * $y0;
        $n0 = $t0 < 0 ? 0.0 : $t0 * $t0 * $t0 * $t0 * $n0;

        $t1 = 0.5 - $x1 * $x1 - $y1 * $y1;
        $n1 = $t1 < 0 ? 0.0 : $t1 * $t1 * $t1 * $t1 * $n1;

        $t2 = 0.5 - $x2 * $x2 - $y2 * $y2;
        $n2 = $t2 < 0 ? 0.0 : $t2 * $t2 * $t2 * $t2 * $n2;

        // Scale to [-1, 1]
        return 70.0 * ($n0 + $n1 + $n2);
    }

    private static array $GRAD2 = [
        [1, 1], [-1, 1], [1, -1], [-1, -1],
        [1, 0], [-1, 0], [0, 1], [0, -1],
    ];

    private function dotGrad(int $hash, float $x, float $y): float {
        $g = self::$GRAD2[$hash];
        return $g[0] * $x + $g[1] * $y;
    }
}
