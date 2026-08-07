<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

interface ChunkParallelSystem extends System {
    public function runChunkParallel(int $chunkX, int $chunkZ, \pocketmine\port\driven\ChunkData $chunk, float $deltaTime): void;

    public function getTargetChunks(World $world): iterable;
}