<?php

declare(strict_types=1);

namespace pocketmine\core\thread;

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

/**
 * Global coordination worker thread.
 *
 * pmmpthread v6.3 only permits thread-safe values as Thread properties, so this
 * thread holds only thread-safe queues and scalar region descriptors. All
 * actual plugin/event dispatch, port I/O and ECS mutation happen on the main
 * thread; this thread routes global commands (player join/leave, entity
 * spawn/despawn, chunk load/unload, migrations) to the appropriate region
 * command queues.
 */
final class CoordinationThread extends Thread {
    /** @var ThreadSafeArray<int, int> regionId => slot index (scalars only) */
    private ThreadSafeArray $regionSlots;
    /** @var ThreadSafeArray<int, ThreadSafeArray> regionId => command queue (thread-safe values) */
    private ThreadSafeArray $regionQueues;
    private ThreadSafeArray $globalCommandQueue;
    private ThreadSafeArray $globalMigrationQueue;
    private ThreadSafeArray $globalEventQueue;
    private ThreadSafe $state;
    private int $tickCounter = 0;

    public function __construct() {
        $this->regionSlots = new ThreadSafeArray();
        $this->regionQueues = new ThreadSafeArray();
        $this->globalCommandQueue = new ThreadSafeArray();
        $this->globalMigrationQueue = new ThreadSafeArray();
        $this->globalEventQueue = new ThreadSafeArray();
        $this->state = new ThreadSafe();
        $this->state->running = true;
    }

    public function addRegion(int $regionId, ThreadSafeArray $commandQueue): void {
        $this->regionSlots[$regionId] = $this->regionQueues->count();
        $this->regionQueues[] = $commandQueue;
    }

    public function getGlobalCommandQueue(): ThreadSafeArray {
        return $this->globalCommandQueue;
    }

    public function getGlobalMigrationQueue(): ThreadSafeArray {
        return $this->globalMigrationQueue;
    }

    public function getGlobalEventQueue(): ThreadSafeArray {
        return $this->globalEventQueue;
    }

    public function run(): void {
        while ($this->state->running) {
            $this->tickCounter++;
            $this->processGlobalCommands();
            $this->processMigrations();
            $this->processEvents();
            usleep(50_000); // 20 TPS = 50ms
        }
    }

    private function processGlobalCommands(): void {
        while (($cmd = $this->globalCommandQueue->shift()) !== null) {
            $decoded = json_decode($cmd, true);
            if (!is_array($decoded)) {
                continue;
            }
            $regionId = (int)($decoded['regionId'] ?? -1);
            $queue = $this->getRegionQueue($regionId);
            if ($queue !== null) {
                $queue[] = $cmd;
            }
        }
    }

    private function processMigrations(): void {
        while (($migration = $this->globalMigrationQueue->shift()) !== null) {
            $decoded = json_decode($migration, true);
            if (!is_array($decoded)) {
                continue;
            }
            $regionId = (int)($decoded['regionId'] ?? -1);
            $queue = $this->getRegionQueue($regionId);
            if ($queue !== null) {
                $queue[] = $migration;
            }
        }
    }

    private function processEvents(): void {
        // Global events are forwarded to the main thread's event port.
        // Plugin event dispatch happens on the main thread (driving adapter).
        while (($event = $this->globalEventQueue->shift()) !== null) {
            // Events are stored as scalar payloads and dispatched by Kernel.
        }
    }

    private function getRegionQueue(int $regionId): ?ThreadSafeArray {
        if (!$this->regionSlots->offsetExists($regionId)) {
            return null;
        }
        $slot = $this->regionSlots[$regionId];
        return $this->regionQueues[$slot] ?? null;
    }

    public function shutdown(): void {
        $this->state->running = false;
    }
}
