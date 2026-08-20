<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

final class LightData {
    public function __construct(
        public readonly array $skyLight,    // int[chunkSections][4096]
        public readonly array $blockLight,  // int[chunkSections][4096]
    ) {}
}