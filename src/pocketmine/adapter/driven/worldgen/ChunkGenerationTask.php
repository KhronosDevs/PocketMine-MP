<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\worldgen;

use pocketmine\core\resource\NativeAccel;
use pmmp\thread\Runnable;
use function serialize;

/**
 * Pure chunk-generation task that runs on a real pmmpthread Pool worker.
 *
 * Holds only scalar inputs (chunk coords, generator type, seed) plus a
 * ThreadSafe result cell - no object/closure captures, which is what makes
 * it safe to hand to a worker thread. The generation itself is a pure,
 * deterministic function (ParallelGeneratorAdapter::generateChunkPure), so
 * execution order across workers never affects results.
 */
final class ChunkGenerationTask extends Runnable {
    public function __construct(
        private int $chunkX,
        private int $chunkZ,
        private string $generatorType,
        private int $seed,
        private ChunkGenResult $out,
        /**
         * Worker-scoped copy of the native-accel switch: workers start with
         * their own default statics (class tables are inherited, static
         * VALUES are not), so the task carries the main thread's setting.
         */
        private bool $nativeAccel = true,
    ) {}

    public function getChunkX(): int {
        return $this->chunkX;
    }

    public function getChunkZ(): int {
        return $this->chunkZ;
    }

    public function getOut(): ChunkGenResult {
        return $this->out;
    }

    public function run(): void {
        try {
            // Workers cannot autoload (they inherit the main thread's class
            // table AND its included-files table, so requiring autoload.php
            // here is a no-op that never registers the loader). The adapter
            // force-loads every class this task constructs on the main thread
            // before creating the pool, so they are all inherited here.
            NativeAccel::setEnabled($this->nativeAccel);
            $data = ParallelGeneratorAdapter::generateChunkPure(
                $this->chunkX,
                $this->chunkZ,
                $this->generatorType,
                $this->seed
            );
            $encoded = serialize($data);
            $this->out->synchronized(function () use ($encoded) {
                $this->out->value = $encoded;
                $this->out->done = true;
                $this->out->notify();
            });
        } catch (\Throwable $e) {
            $this->out->synchronized(function () use ($e) {
                $this->out->error = $e->getMessage();
                $this->out->done = true;
                $this->out->notify();
            });
        }
    }
}
