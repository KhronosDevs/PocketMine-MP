<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

use pocketmine\adapter\driven\threading\ArchetypeSnapshot;
use pocketmine\adapter\driven\threading\EcsSystemTask;
use pocketmine\adapter\driven\threading\ParallelResult;
use pocketmine\adapter\driven\threading\PmmpThreadPool;
use pocketmine\adapter\driven\threading\SnapshotCodec;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\core\system\MovementSystem;
use pocketmine\core\system\PhysicsSystem;

final class SystemScheduler {
    private array $sequentialSystems = [];
    private array $parallelSystems = [];
    private array $chunkParallelSystems = [];

    /**
     * Runs after the parallel systems write PENDING positions but before
     * applyPendingComponents() commits them (block collision must see the
     * moved positions and must not fight the committed state).
     */
    private ?System $collisionSystem = null;

    /** @var array<string, bool> system class => disabled while pipeline apply mode offloads it */
    private array $disabledSystems = [];

    /** Systems that support snapshot-based cross-thread dispatch. */
    private const SNAPSHOT_SYSTEMS = [
        MovementSystem::class => 'movement',
        PhysicsSystem::class => 'physics',
    ];

    public function __construct(
        private readonly ThreadingPort $threadingPort,
    ) {
        // Workers inherit the main thread's class table when they start but
        // cannot autoload, so every class a dispatched task touches must be
        // loaded before the pool receives its first task.
        class_exists(ArchetypeSnapshot::class);
        class_exists(ParallelResult::class);
        class_exists(EcsSystemTask::class);
        class_exists(SnapshotCodec::class);
    }

    public function register(System $system, SystemPhase $phase = SystemPhase::SEQUENTIAL): void {
        match ($phase) {
            SystemPhase::SEQUENTIAL => $this->sequentialSystems[] = $system,
            SystemPhase::PARALLEL => $this->parallelSystems[] = $system,
            SystemPhase::CHUNK_PARALLEL => $this->chunkParallelSystems[] = $system,
        };
    }

    public function setEnabled(string $systemClass, bool $enabled): void {
        if ($enabled) {
            unset($this->disabledSystems[$systemClass]);
        } else {
            $this->disabledSystems[$systemClass] = true;
        }
    }

    public function setCollisionSystem(System $system): void {
        $this->collisionSystem = $system;
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

        // Parallel systems: snapshot-based dispatch via real pmmpthread Pool,
        // or synchronous fallback.
        if ($this->parallelSystems) {
            $pool = ($this->threadingPort instanceof PmmpThreadPool)
                ? $this->threadingPort
                : null;

            /**
             * Pending task descriptors: [systemType, archetype, result]
             * @var list<array{0: string, 1: Archetype, 2: ParallelResult}> $pending
             */
            $pending = [];
            $syncSystems = [];

            if ($pool !== null) {
                foreach ($this->parallelSystems as $system) {
                    if (isset($this->disabledSystems[get_class($system)])) {
                        continue;
                    }
                    if (!$system instanceof ParallelSystem) {
                        continue;
                    }
                    $className = get_class($system);
                    $systemType = self::SNAPSHOT_SYSTEMS[$className] ?? null;

                    if ($systemType === null) {
                        $syncSystems[] = $system;
                        continue;
                    }

                    foreach ($system->getTargetArchetypes($world) as $archetype) {
                        $snap = match ($systemType) {
                            'movement' => MovementSystem::snapshotArchetype($archetype, $deltaTime),
                            'physics' => PhysicsSystem::snapshotArchetype($archetype, $deltaTime),
                        };
                        // Skip pool dispatch for tiny archetypes: encode/decode
                        // + pool submit cost more than the compute itself. The
                        // wait below is event-driven (notify), not a poll, so
                        // the collect-side overhead is bounded and the parallel
                        // path stays competitive down to small archetypes.
                        if ($snap->count < 16) {
                            match ($systemType) {
                                'movement' => MovementSystem::applySnapshotSync($archetype, $snap),
                                'physics' => PhysicsSystem::applySnapshotSync($archetype, $snap),
                            };
                            continue;
                        }
                        $result = new ParallelResult();
                        $task = new EcsSystemTask($snap, $result, $systemType);
                        $pool->submitTask($task);
                        $pending[] = [$systemType, $archetype, $result];
                    }
                }

                // Await all dispatched tasks by reaping finished ones.
                // The collect() callback MUST return bool: true = reap the task, false = keep it.
                $count = count($pending);
                $collected = 0;
                $deadline = microtime(true) + 5.0;
                while ($collected < $count) {
                    $pool->collectTasks(function (EcsSystemTask $task) use (&$collected, &$pending): bool {
                        $result = $task->result;
                        if (!$result->done) {
                            return false; // not done yet, keep in pool
                        }
                        if ($result->error !== null) {
                            throw new \RuntimeException("ECS parallel task failed: {$result->error}");
                        }
                        // Find the matching descriptor and apply the result
                        foreach ($pending as $desc) {
                            if ($desc[2] === $result) {
                                match ($desc[0]) {
                                    'movement' => MovementSystem::applyResult($desc[1], $result),
                                    'physics' => PhysicsSystem::applyResult($desc[1], $result),
                                };
                                $collected++;
                                break;
                            }
                        }
                        return true; // reap the task
                    });
                    if ($collected < $count) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('ECS parallel dispatch timed out');
                        }
                        // Event-driven wait instead of usleep polling:
                        // EcsSystemTask::run() sets done=true and notify()s the
                        // result inside synchronized(), so blocking on the
                        // result's condvar wakes us the instant a worker
                        // finishes instead of up to 500us later.
                        self::awaitAnyPending($pending);
                    }
                }
            } else {
                // No real pool available — all parallel systems run synchronously
                foreach ($this->parallelSystems as $system) {
                    $syncSystems[] = $system;
                }
            }

            // Synchronous fallback for systems without snapshot support
            foreach ($syncSystems as $system) {
                if ($system instanceof ParallelSystem) {
                    foreach ($system->getTargetArchetypes($world) as $archetype) {
                        $system->runParallel($archetype, $deltaTime);
                    }
                }
            }
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

        // Post-movement collision
        if ($this->collisionSystem !== null && !isset($this->disabledSystems[get_class($this->collisionSystem)])) {
            $this->collisionSystem->run($world, $deltaTime);
        }

        // Apply pending component changes (double-buffer swap)
        $world->applyPendingComponents();
    }

    /**
     * Block on the first pending result that has not completed yet, instead of
     * polling. The check-and-wait is race-free: it runs inside the result's
     * synchronized() block, so if done flipped before we got the lock the inner
     * check skips the wait, and if it flips while we wait the worker's notify()
     * wakes us immediately. The timeout is only a safety net for pathological
     * cases; the caller's deadline still guards against a stuck pool.
     *
     * @param list<array{0: string, 1: Archetype, 2: ParallelResult}> $pending
     */
    private static function awaitAnyPending(array $pending): void {
        foreach ($pending as $desc) {
            $result = $desc[2];
            if ($result->done) {
                continue;
            }
            $result->synchronized(function () use ($result): void {
                if (!$result->done) {
                    $result->wait(100_000);
                }
            });
            return;
        }
    }
}
