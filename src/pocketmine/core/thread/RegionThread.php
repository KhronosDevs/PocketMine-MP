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
 *
 * Transport: snapshots are exchanged as compact binary blobs (one message per
 * tick, not one per entity) so the transport stays well ahead of the worker's
 * integration cost. Each entity snapshot is 52 bytes: a 4-byte little-endian
 * entity id followed by six little-endian IEEE doubles (x, y, z, vx, vy, vz),
 * which round-trips floats bit-exactly - the determinism gate relies on this.
 */
final class RegionThread extends Thread {
    public const MSG_UPDATE = 'U';   // kernel->worker: 'U' + seq(4) + count(4) + count x [id(4) + 6 doubles]
    public const MSG_DESPAWN = 'D';  // kernel->worker: 'D' + count(4) + count x [id(4)]
    public const MSG_TICK = 'T';     // kernel->worker: 'T' + seq(4)
    public const MSG_SHUTDOWN = 'S'; // kernel->worker: 'S'
    public const MSG_RESULTS = 'R';  // worker->kernel: 'R' + seq(4) + count(4) + count x [id(4) + 6 doubles]
    public const MSG_ACK = 'A';      // worker->kernel: 'A' + seq(4)

    public const ENTITY_BYTES = 52; // id(4) + 6 doubles(48)

    /** Lockstep integration constants - shared with Kernel::advanceStored so
     *  the diff-only mirror's prediction can never silently drift from the
     *  worker's integration. */
    public const TARGET_DELTA_TIME = 0.05;   // 20 TPS
    /**
     * Gravity in blocks/s^2. At the lockstep 20 TPS this is 0.08 blocks/tick^2
     * (MC parity) - and, crucially, it is bit-identical to PhysicsSystem's
     * `1.6 * dt` at dt = 0.05. The diff-only mirror and the gate's 1e-6 drift
     * check both depend on the two integrations never differing, so this must
     * stay in lockstep with PhysicsSystem.
     */
    public const GRAVITY_ACCELERATION = 1.6; // blocks/s^2 (0.08 blocks/tick^2)

    private ThreadSafeArray $commandQueue;
    private ThreadSafeArray $syncQueue;
    private ThreadSafeArray $migrationQueue;
    /** @var ThreadSafeArray<int, string> entityId => 52-byte binary snapshot */
    private ThreadSafeArray $entityData;
    private ThreadSafe $state;
    /**
     * Wait/notify condvar: the worker sleeps on it between polls instead of
     * busy-waiting at 200us (5000 wakeups/sec per idle region). The kernel
     * wakes it after pushing commands, so idle regions burn ~zero CPU.
     */
    private SnoozeHandle $sleeper;
    /** Sequence of the last processed 'tick' command, echoed back on results so
     *  the kernel can drop stale (out-of-window) results. */
    private int $currentTickSeq = 0;
    private int $regionId;
    /**
     * Chunk bounds, held in a ThreadSafe so the kernel can resize a region
     * after the thread has started (dynamic split/merge). Only the main
     * thread reads them (ownsChunk() is called from the kernel mirror), so
     * there is no contention with the worker.
     */
    private ThreadSafe $bounds;

    public function __construct(
        int $regionId,
        int $minChunkX,
        int $maxChunkX,
        int $minChunkZ,
        int $maxChunkZ,
    ) {
        $this->regionId = $regionId;
        $this->bounds = new ThreadSafe();
        $this->bounds->minX = $minChunkX;
        $this->bounds->maxX = $maxChunkX;
        $this->bounds->minZ = $minChunkZ;
        $this->bounds->maxZ = $maxChunkZ;
        $this->commandQueue = new ThreadSafeArray();
        $this->syncQueue = new ThreadSafeArray();
        $this->migrationQueue = new ThreadSafeArray();
        $this->entityData = new ThreadSafeArray();
        $this->state = new ThreadSafe();
        $this->state->running = true;
        $this->sleeper = new SnoozeHandle();
    }

    // --- Binary protocol helpers (shared with the kernel) -----------------

    /**
     * @param array<int, array{entityId: int, position: array{x: float, y: float, z: float}, velocity: array{x: float, y: float, z: float}}> $snapshots
     */
    public static function encodeUpdate(int $seq, array $snapshots): string {
        $bin = self::MSG_UPDATE . pack('NN', $seq, count($snapshots));
        foreach ($snapshots as $s) {
            $bin .= self::encodeEntity($s['entityId'], $s['position'], $s['velocity']);
        }
        return $bin;
    }

    /**
     * Decode an update batch into per-entity binary snapshots for direct storage.
     * @return array{seq: int, entities: array<int, string>}
     */
    public static function decodeUpdate(string $blob): array {
        $out = ['seq' => 0, 'entities' => []];
        if (strlen($blob) < 9 || $blob[0] !== self::MSG_UPDATE) {
            return $out;
        }
        $hdr = unpack('Nseq/Ncount', substr($blob, 1, 8));
        $out['seq'] = $hdr['seq'];
        $count = $hdr['count'];
        $off = 9;
        for ($i = 0; $i < $count; $i++) {
            if ($off + self::ENTITY_BYTES > strlen($blob)) {
                break; // truncated/malformed: ignore the remainder
            }
            $bin = substr($blob, $off, self::ENTITY_BYTES);
            $out['entities'][unpack('N', $bin)[1]] = $bin;
            $off += self::ENTITY_BYTES;
        }
        return $out;
    }

    /**
     * @param array<int, int> $ids
     */
    public static function encodeDespawns(array $ids): string {
        return self::MSG_DESPAWN . pack('N', count($ids)) . implode('', array_map(fn(int $id): string => pack('N', $id), $ids));
    }

    public static function encodeTick(int $seq): string {
        return self::MSG_TICK . pack('N', $seq);
    }

    public static function encodeShutdown(): string {
        return self::MSG_SHUTDOWN;
    }

    /**
     * @param array{x: float, y: float, z: float} $position
     * @param array{x: float, y: float, z: float} $velocity
     */
    public static function encodeEntity(int $id, array $position, array $velocity): string {
        return pack('N', $id)
            . pack('e6', $position['x'], $position['y'], $position['z'], $velocity['x'], $velocity['y'], $velocity['z']);
    }

    /**
     * Decode a block-layout results batch: two unpack() calls for the whole
     * batch (ids, then doubles at 6 per entity) instead of per-entity unpack.
     *
     * @return array{seq: int, ids: list<int>, doubles: list<float>}
     */
    public static function decodeResultsBlock(string $blob): array {
        if (strlen($blob) < 9 || $blob[0] !== self::MSG_RESULTS) {
            return ['seq' => -1, 'ids' => [], 'doubles' => []];
        }
        $hdr = unpack('Nseq/Ncount', substr($blob, 1, 8));
        $count = $hdr['count'];
        $idsLen = $count * 4;
        if (strlen($blob) < 9 + $idsLen) {
            return ['seq' => $hdr['seq'], 'ids' => [], 'doubles' => []];
        }
        $ids = array_values(unpack('N*', substr($blob, 9, $idsLen)));
        $doubles = array_values(unpack('e*', substr($blob, 9 + $idsLen)));
        return ['seq' => $hdr['seq'], 'ids' => $ids, 'doubles' => $doubles];
    }

    public static function encodeAck(int $seq): string {
        return self::MSG_ACK . pack('N', $seq);
    }

    /** Extract the seq from the header of a message blob, or -1 if malformed. */
    public static function decodeHeaderSeq(string $blob): int {
        if (strlen($blob) < 5) {
            return -1;
        }
        return unpack('N', substr($blob, 1, 4))[1];
    }

    // --- Thread lifecycle ---------------------------------------------------

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

    /**
     * Wake the worker after pushing commands to its queues. Safe to call from
     * the main thread; the worker re-checks its queues instead of sleeping
     * through the new work.
     */
    public function wakeup(): void {
        $this->sleeper->wakeup();
    }

    public function ownsChunk(int $chunkX, int $chunkZ): bool {
        return $chunkX >= $this->bounds->minX && $chunkX <= $this->bounds->maxX
            && $chunkZ >= $this->bounds->minZ && $chunkZ <= $this->bounds->maxZ;
    }

    public function getMinChunkX(): int {
        return $this->bounds->minX;
    }

    public function getMaxChunkX(): int {
        return $this->bounds->maxX;
    }

    public function getMinChunkZ(): int {
        return $this->bounds->minZ;
    }

    public function getMaxChunkZ(): int {
        return $this->bounds->maxZ;
    }

    /** Shrink the eastern edge of this region's column (dynamic split). */
    public function shrinkMaxX(int $newMaxX): void {
        $this->bounds->maxX = $newMaxX;
    }

    /** Expand the eastern edge of this region's column (dynamic merge). */
    public function expandMaxX(int $newMaxX): void {
        $this->bounds->maxX = $newMaxX;
    }

    /** Expand the western edge of this region's column (dynamic merge). */
    public function expandMinX(int $newMinX): void {
        $this->bounds->minX = $newMinX;
    }

    public function run(): void {
        while ($this->state->running) {
            // Block on the condvar instead of busy-polling: the kernel wakes
            // us after every command batch, so an idle region sleeps (near)
            // zero CPU instead of spinning 5000x/sec. The 10ms timeout is a
            // safety net for shutdown/edge cases, not the steady-state path.
            $this->sleeper->sleep(10_000);
            // Consume the wakeup that woke us (or that arrived while we were
            // sleeping) so the next sleep() blocks; any wakeup that arrives
            // DURING processing stays in the count and makes the next sleep()
            // return immediately - no lost wakeups, no busy spin.
            $this->sleeper->consumeWakeups();
            $this->processCommands();
            $this->processMigrations();

            // Lockstep: the kernel drives integration cadence by sending a
            // 'tick' command after mirroring entity snapshots. The worker does
            // NOT advance snapshots on its own timer, so results are exactly
            // one integration per kernel tick - deterministic by construction.
        }
    }

    private function processCommands(): void {
        while (($cmd = $this->commandQueue->shift()) !== null) {
            if (!is_string($cmd) || $cmd === '') {
                continue;
            }
            switch ($cmd[0]) {
                case self::MSG_UPDATE:
                    $decoded = self::decodeUpdate($cmd);
                    foreach ($decoded['entities'] as $entityId => $bin) {
                        $this->entityData[(string)$entityId] = $bin;
                    }
                    break;
                case self::MSG_DESPAWN:
                    $this->processDespawns($cmd);
                    break;
                case self::MSG_TICK:
                    $this->currentTickSeq = self::decodeHeaderSeq($cmd);
                    // The kernel pushes migrations before the tick for the same
                    // pass, so any pending migration is already queued here.
                    // Draining before integrating guarantees a migrated entity
                    // is stored before this tick integrates it - no lost or
                    // doubled integration at region boundaries.
                    $this->processMigrations();
                    $this->tickSnapshots();
                    $this->syncQueue[] = self::encodeAck($this->currentTickSeq);
                    break;
                case self::MSG_SHUTDOWN:
                    $this->state->running = false;
                    return;
            }
        }
    }

    private function processDespawns(string $cmd): void {
        if (strlen($cmd) < 5) {
            return;
        }
        $count = unpack('N', substr($cmd, 1, 4))[1];
        $off = 5;
        for ($i = 0; $i < $count; $i++) {
            if ($off + 4 > strlen($cmd)) {
                break;
            }
            $id = unpack('N', substr($cmd, $off, 4))[1];
            $this->entityData->offsetUnset((string)$id);
            $off += 4;
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
                $snapshot = $decoded['snapshot'];
                $position = $snapshot['position'] ?? ['x' => 0.0, 'y' => 0.0, 'z' => 0.0];
                $velocity = $snapshot['velocity'] ?? ['x' => 0.0, 'y' => 0.0, 'z' => 0.0];
                $this->entityData[(string)$entityId] = self::encodeEntity($entityId, $position, $velocity);
            }
        }
    }

    /**
     * Advance position from velocity for all snapshot entities, then apply
     * gravity to velocity - matching the main thread's MovementSystem +
     * PhysicsSystem order exactly (integrate with pre-gravity velocity, then
     * decelerate) so results are bit-for-bit comparable.
     *
     * Works directly on the packed doubles (one unpack + one pack per entity,
     * no intermediate arrays) and emits the results in the block layout the
     * kernel decodes with two unpack() calls.
     */
    private function tickSnapshots(): void {
        $ids = '';
        $doubles = '';
        $count = 0;
        foreach ($this->entityData as $entityId => $bin) {
            if (!is_string($bin) || strlen($bin) < self::ENTITY_BYTES) {
                continue;
            }
            $id = (int)$entityId; // entityData is keyed by the entity id
            $d = unpack('e6', substr($bin, 4, 48));
            $x = $d[1] + $d[4] * self::TARGET_DELTA_TIME;
            $y = $d[2] + $d[5] * self::TARGET_DELTA_TIME;
            $z = $d[3] + $d[6] * self::TARGET_DELTA_TIME;
            // Gravity + terminal velocity: same constant and clamp as
            // PhysicsSystem, bit-exact for the mirror's determinism gate.
            $vy = $d[5] - self::GRAVITY_ACCELERATION * self::TARGET_DELTA_TIME;
            if ($vy < -78.4) {
                $vy = -78.4;
            }
            $this->entityData[(string)$entityId] = pack('N', $id)
                . pack('e6', $x, $y, $z, $d[4], $vy, $d[6]);
            $ids .= pack('N', $id);
            $doubles .= pack('e6', $x, $y, $z, $d[4], $vy, $d[6]);
            $count++;
        }
        if ($count > 0) {
            $this->syncQueue[] = self::MSG_RESULTS . pack('NN', $this->currentTickSeq, $count) . $ids . $doubles;
        }
    }

    public function shutdown(): void {
        $this->state->running = false;
        $this->commandQueue[] = self::encodeShutdown();
        $this->sleeper->wakeup(); // break the worker out of sleep() immediately
    }
}
