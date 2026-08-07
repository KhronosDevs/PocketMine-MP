<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\worldgen;

use pmmp\thread\ThreadSafe;

/**
 * Thread-safe result cell for one chunk-generation task.
 *
 * pmmpthread v6.3 ThreadSafe properties may only hold thread-safe values
 * (scalars, ThreadSafe instances), so the ChunkData payload is transported
 * back as a serialized string.
 */
final class ChunkGenResult extends ThreadSafe {
    public mixed $value = null;   // serialized ChunkData, or null until done
    public bool $done = false;
    public ?string $error = null;
}
