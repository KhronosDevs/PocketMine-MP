<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

final class ChunkData {
    public function __construct(
        public readonly int $chunkX,
        public readonly int $chunkZ,
        public readonly array $sections, // SectionData[]
        public readonly array $biomes,   // int[256]
        public readonly array $heightmap, // int[256]
        public readonly array $entities,  // EntitySnapshot[]
        public readonly array $tileEntities, // TileEntitySnapshot[]
    ) {}
}