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

    private ThreadSafeArray $commandQueue;
    private ThreadSafeArray $syncQueue;
    private ThreadSafeArray $migrationQueue;
    /** @var ThreadSafeArray<int, string> entityId => 52-byte binary snapshot */
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
     * @return array{entityId: int, position: array{x: float, y: float, z: float}, velocity: array{x: float, y: float, z: float}}
     */
    public static function decodeEntity(string $bin): array {
        $d = unpack('Nid/e6', $bin);
        return [
            'entityId' => $d['id'],
            'position' => ['x' => $d[1], 'y' => $d[2], 'z' => $d[3]],
            'velocity' => ['x' => $d[4], 'y' => $d[5], 'z' => $d[6]],
        ];
    }

    public static function encodeResults(int $seq, array $entities): string {
        $bin = self::MSG_RESULTS . pack('NN', $seq, count($entities));
        foreach ($entities as $e) {
            $bin .= self::encodeEntity($e['entityId'], $e['position'], $e['velocity']);
        }
        return $bin;
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
     * Pure data transform on binary snapshots - no shared object state.
     */
    private function tickSnapshots(): void {
        $updated = [];
        foreach ($this->entityData as $entityId => $bin) {
            if (!is_string($bin) || strlen($bin) < self::ENTITY_BYTES) {
                continue;
            }
            $snapshot = self::decodeEntity($bin);
            $snapshot['position']['x'] += $snapshot['velocity']['x'] * $this->targetDeltaTime;
            $snapshot['position']['y'] += $snapshot['velocity']['y'] * $this->targetDeltaTime;
            $snapshot['position']['z'] += $snapshot['velocity']['z'] * $this->targetDeltaTime;
            // Gravity: same constant as PhysicsSystem (0.08 blocks/tick^2).
            $snapshot['velocity']['y'] -= 0.08 * $this->targetDeltaTime;
            $this->entityData[(string)$entityId] = self::encodeEntity($snapshot['entityId'], $snapshot['position'], $snapshot['velocity']);
            $updated[] = $snapshot;
        }
        if (!empty($updated)) {
            $this->syncQueue[] = self::encodeResults($this->currentTickSeq, $updated);
        }
    }

    public function shutdown(): void {
        $this->state->running = false;
        $this->commandQueue[] = self::encodeShutdown();
    }
}
