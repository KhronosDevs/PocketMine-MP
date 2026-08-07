<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

use pocketmine\port\driven\ThreadingPort;

final class SystemScheduler {
    private array $sequentialSystems = [];
    private array $parallelSystems = [];
    private array $chunkParallelSystems = [];

    public function __construct(
        private readonly ThreadingPort $threadingPort,
    ) {}

    public function register(System $system, SystemPhase $phase = SystemPhase::SEQUENTIAL): void {
        match ($phase) {
            SystemPhase::SEQUENTIAL => $this->sequentialSystems[] = $system,
            SystemPhase::PARALLEL => $this->parallelSystems[] = $system,
            SystemPhase::CHUNK_PARALLEL => $this->chunkParallelSystems[] = $system,
        };
    }

    public function run(World $world, float $deltaTime): void {
        // Sequential systems (dependencies, writes shared state)
        foreach ($this->sequentialSystems as $system) {
            $system->run($world, $deltaTime);
        }

        // Parallel systems (archetype-isolated, no cross-archetype writes)
        if ($this->parallelSystems) {
            $futures = [];
            foreach ($this->parallelSystems as $system) {
                if ($system instanceof ParallelSystem) {
                    foreach ($system->getTargetArchetypes($world) as $archetype) {
                        $futures[] = $this->threadingPort->submit(
                            fn() => $system->runParallel($archetype, $deltaTime)
                        );
                    }
                }
            }
            $this->threadingPort->awaitAll($futures);
        }

        // Chunk-parallel systems
        if ($this->chunkParallelSystems) {
            $futures = [];
            foreach ($this->chunkParallelSystems as $system) {
                if ($system instanceof ChunkParallelSystem) {
                    foreach ($system->getTargetChunks($world) as $chunkInfo) {
                        [$chunkX, $chunkZ, $chunkData] = $chunkInfo;
                        $futures[] = $this->threadingPort->submit(
                            fn() => $system->runChunkParallel($chunkX, $chunkZ, $chunkData, $deltaTime)
                        );
                    }
                }
            }
            $this->threadingPort->awaitAll($futures);
        }

        // Apply pending component changes (double-buffer swap)
        $world->applyPendingComponents();
    }
}