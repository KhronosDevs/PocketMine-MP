<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pmmp\thread\Runnable;

/**
 * A task that runs an ECS parallel system on a worker thread.
 *
 * Carries JSON-encoded snapshot data (flat float arrays) and a result cell.
 * The system's static compute method is called on the worker; the main thread
 * merges results back into pending component fields after awaitAll().
 *
 * Forces class loading on the main thread before pool creation so workers
 * inherit the class table (workers cannot autoload).
 */
final class EcsSystemTask extends Runnable {
    public function __construct(
        public readonly ArchetypeSnapshot $snapshot,
        public readonly ParallelResult $result,
        public readonly string $systemType,
    ) {}

    public function run(): void {
        try {
            match ($this->systemType) {
                'movement' => \pocketmine\core\system\MovementSystem::computeOnSnapshot(
                    $this->snapshot,
                    $this->result,
                ),
                'physics' => \pocketmine\core\system\PhysicsSystem::computeOnSnapshot(
                    $this->snapshot,
                    $this->result,
                ),
                default => throw new \RuntimeException("Unknown system type: {$this->systemType}"),
            };

            $this->result->synchronized(function () {
                $this->result->done = true;
                $this->result->notify();
            });
        } catch (\Throwable $e) {
            $this->result->synchronized(function () use ($e) {
                $this->result->error = $e->getMessage();
                $this->result->done = true;
                $this->result->notify();
            });
        }
    }
}
