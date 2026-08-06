<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

final class TileEntitySnapshot {
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $x,
        public readonly int $y,
        public readonly int $z,
        public readonly array $data,
    ) {}
}