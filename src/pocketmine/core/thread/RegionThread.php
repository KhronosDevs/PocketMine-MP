<?php

declare(strict_types=1);

namespace pocketmine\core\thread;

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

/**
 * Region simulation worker thread.
 *
 * pmmpthread v6.3 only permits thread-safe values (scalars, ThreadSafe,
 * ThreadSafeArray) as properties of a Thread subclass. The ECS World and all
 * ports (Network/Storage/WorldGen) therefore live on the main thread; this
 * thread owns a thread-safe store of serialized entity component snapshots and
 * performs data-oriented simulation work (movement integration) over them.
 * Results are exchanged back to the main thread through thread-safe queues.
 */
final class RegionThread extends Thread {
    private ThreadSafeArray $commandQueue;
    private ThreadSafeArray $syncQueue;
    private ThreadSafeArray $migrationQueue;
    /** @var ThreadSafeArray<int, string> entityId => json-encoded component snapshot */
    private ThreadSafeArray $entityData;
    private ThreadSafe $state;
    /** Sequence of the last processed 'tick' command, echoed back on results so
     *  the kernel can drop stale (out-of-window) results. */
    private int $currentTickSeq = 0;
    private int $regionId;
    private int $minChunkX;
    private int $maxChunkX;
    private int $minChunkZ;
    private int $maxChunkZ;
    private float $targetDeltaTime;

    public function __construct(
        int $regionId,
        int $minChunkX,
        int $maxChunkX,
        int $minChunkZ,
        int $maxChunkZ,
    ) {
        $this->regionId = $regionId;
        $this->minChunkX = $minChunkX;
        $this->maxChunkX = $maxChunkX;
        $this->minChunkZ = $minChunkZ;
        $this->maxChunkZ = $maxChunkZ;
        $this->targetDeltaTime = 0.05; // 20 TPS
        $this->commandQueue = new ThreadSafeArray();
        $this->syncQueue = new ThreadSafeArray();
        $this->migrationQueue = new ThreadSafeArray();
        $this->entityData = new ThreadSafeArray();
        $this->state = new ThreadSafe();
        $this->state->running = true;
    }

    public function getRegionId(): int {
        return $this->regionId;
    }

    public function getCommandQueue(): ThreadSafeArray {
        return $this->commandQueue;
    }

    public function getSyncQueue(): ThreadSafeArray {
        return $this->syncQueue;
    }

    public function getMigrationQueue(): ThreadSafeArray {
        return $this->migrationQueue;
    }

    public function ownsChunk(int $chunkX, int $chunkZ): bool {
        return $chunkX >= $this->minChunkX && $chunkX <= $this->maxChunkX
            && $chunkZ >= $this->minChunkZ && $chunkZ <= $this->maxChunkZ;
    }

    public function run(): void {
        while ($this->state->running) {
            $this->processCommands();
            $this->processMigrations();

            // Lockstep: the kernel drives integration cadence by sending a
            // 'tick' command after mirroring entity snapshots. The worker does
            // NOT advance snapshots on its own timer, so results are exactly
            // one integration per kernel tick - deterministic by construction.
            usleep(200); // low-latency command polling
        }
    }

    private function processCommands(): void {
        while (($cmd = $this->commandQueue->shift()) !== null) {
            $decoded = json_decode($cmd, true);
            if (!is_array($decoded)) {
                continue;
            }
            $type = $decoded['type'] ?? '';
            $entityId = (int)($decoded['entityId'] ?? -1);
            switch ($type) {
                case 'spawn':
                case 'update':
                    if ($entityId >= 0 && isset($decoded['snapshot'])) {
                        $this->entityData[(string)$entityId] = json_encode($decoded['snapshot']);
                    }
                    break;
                case 'despawn':
                    $this->entityData->offsetUnset((string)$entityId);
                    break;
                case 'tick':
                    $this->currentTickSeq = (int)($decoded['seq'] ?? 0);
                    $this->tickSnapshots();
                    $this->syncQueue[] = json_encode(['type' => 'tick_ack', 'regionId' => $this->regionId, 'seq' => $this->currentTickSeq]);
                    break;
                case 'shutdown':
                    $this->state->running = false;
                    return;
            }
        }
    }

    private function processMigrations(): void {
        while (($migration = $this->migrationQueue->shift()) !== null) {
            $decoded = json_decode($migration, true);
            if (!is_array($decoded)) {
                continue;
            }
            $entityId = (int)($decoded['entityId'] ?? -1);
            if ($entityId >= 0 && isset($decoded['snapshot'])) {
                $this->entityData[(string)$entityId] = json_encode($decoded['snapshot']);
            }
        }
    }

    /**
     * Advance position from velocity for all snapshot entities, then apply
     * gravity to velocity - matching the main thread's MovementSystem +
     * PhysicsSystem order exactly (integrate with pre-gravity velocity, then
     * decelerate) so results are bit-for-bit comparable.
     *
     * Pure data transform on JSON snapshots - no shared object state.
     */
    private function tickSnapshots(): void {
        $updated = [];
        foreach ($this->entityData as $entityId => $snapshotJson) {
            $snapshot = json_decode($snapshotJson, true);
            if (!is_array($snapshot)) {
                continue;
            }
            if (!isset($snapshot['position'], $snapshot['velocity'])) {
                continue;
            }
            $snapshot['position']['x'] += $snapshot['velocity']['x'] * $this->targetDeltaTime;
            $snapshot['position']['y'] += $snapshot['velocity']['y'] * $this->targetDeltaTime;
            $snapshot['position']['z'] += $snapshot['velocity']['z'] * $this->targetDeltaTime;
            // Gravity: same constant as PhysicsSystem (0.08 blocks/tick^2).
            $snapshot['velocity']['y'] -= 0.08 * $this->targetDeltaTime;
            $this->entityData[(string)$entityId] = json_encode($snapshot);
            $updated[] = ['entityId' => (int)$entityId, 'snapshot' => $snapshot];
        }
        if (!empty($updated)) {
            $this->syncQueue[] = json_encode([
                'type' => 'snapshots',
                'regionId' => $this->regionId,
                'seq' => $this->currentTickSeq,
                'entities' => $updated,
            ]);
        }
    }

    public function shutdown(): void {
        $this->state->running = false;
        $this->commandQueue[] = json_encode(['type' => 'shutdown']);
    }
}
