<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

use pocketmine\port\driven\ThreadingPort;

final class SystemScheduler {
    private array $sequentialSystems = [];
    private array $parallelSystems = [];
    private array $chunkParallelSystems = [];

    /** @var array<string, bool> system class => disabled while pipeline apply mode offloads it */
    private array $disabledSystems = [];

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

    /**
     * Enable or disable a system class by name (e.g. to hand movement
     * integration over to the region worker pipeline in apply mode).
     */
    public function setEnabled(string $systemClass, bool $enabled): void {
        if ($enabled) {
            unset($this->disabledSystems[$systemClass]);
        } else {
            $this->disabledSystems[$systemClass] = true;
        }
    }

    public function unregister(System $system): void {
        $this->sequentialSystems = array_values(array_filter(
            $this->sequentialSystems,
            fn(System $s) => $s !== $system
        ));
        $this->parallelSystems = array_values(array_filter(
            $this->parallelSystems,
            fn(System $s) => $s !== $system
        ));
        $this->chunkParallelSystems = array_values(array_filter(
            $this->chunkParallelSystems,
            fn(System $s) => $s !== $system
        ));
    }

    public function run(World $world, float $deltaTime): void {
        // Sequential systems (dependencies, writes shared state)
        foreach ($this->sequentialSystems as $system) {
            if (isset($this->disabledSystems[get_class($system)])) {
                continue;
            }
            $system->run($world, $deltaTime);
        }

        // Parallel systems (archetype-isolated, no cross-archetype writes)
        if ($this->parallelSystems) {
            $futures = [];
            foreach ($this->parallelSystems as $system) {
                if (isset($this->disabledSystems[get_class($system)])) {
                    continue;
                }
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
                if (isset($this->disabledSystems[get_class($system)])) {
                    continue;
                }
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