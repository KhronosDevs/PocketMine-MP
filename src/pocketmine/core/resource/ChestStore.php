<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\port\driven\TileEntitySnapshot;

/**
 * Chest contents by block coordinate ("x:y:z"). Chests are blocks, not ECS
 * entities, so their inventories live here instead of on a component.
 *
 * Persistence rides the existing chunk tile-entity round-trip: every chest in
 * a chunk is exported as a TileEntitySnapshot (type "Chest") when the chunk is
 * saved and rehydrated when it is loaded, so chest contents survive restarts
 * exactly like the terrain does.
 */
final class ChestStore {
    public const CHEST_SIZE = 27;
    public const TILE_TYPE = 'Chest';

    /** @var array<string, InventoryComponent> x:y:z => chest inventory */
    private array $inventories = [];

    private function key(int $x, int $y, int $z): string {
        return $x . ':' . $y . ':' . $z;
    }

    /** The chest inventory at a position, created on first access. */
    public function get(int $x, int $y, int $z): InventoryComponent {
        $key = $this->key($x, $y, $z);
        return $this->inventories[$key] ??= new InventoryComponent(self::CHEST_SIZE);
    }

    public function has(int $x, int $y, int $z): bool {
        return isset($this->inventories[$this->key($x, $y, $z)]);
    }

    /** Drop a chest's contents (e.g. the block was broken). */
    public function remove(int $x, int $y, int $z): InventoryComponent {
        $key = $this->key($x, $y, $z);
        $inv = $this->inventories[$key] ?? new InventoryComponent(self::CHEST_SIZE);
        unset($this->inventories[$key]);
        return $inv;
    }

    /** True when no chest has ever been created in the given chunk. */
    public function isEmptyChunk(int $chunkX, int $chunkZ): bool {
        $prefix = $chunkX * 16 . ':';
        $suffix = ':' . $chunkZ * 16;
        foreach ($this->inventories as $key => $_) {
            [$x, $y, $z] = explode(':', $key);
            if ((int)$x >= $chunkX * 16 && (int)$x < $chunkX * 16 + 16
                && (int)$z >= $chunkZ * 16 && (int)$z < $chunkZ * 16 + 16) {
                return false;
            }
        }
        return true;
    }

    /**
     * Export every chest inside a chunk as tile-entity snapshots so the
     * storage adapter persists them with the chunk.
     * @return list<TileEntitySnapshot>
     */
    public function snapshotsForChunk(int $chunkX, int $chunkZ): array {
        $out = [];
        foreach ($this->inventories as $key => $inv) {
            [$x, $y, $z] = array_map('intval', explode(':', $key));
            if ($x < $chunkX * 16 || $x >= $chunkX * 16 + 16
                || $z < $chunkZ * 16 || $z >= $chunkZ * 16 + 16) {
                continue;
            }
            if ($inv->getContents() === []) {
                continue; // never touch disk for an untouched chest
            }
            $out[] = new TileEntitySnapshot(
                'chest:' . $key,
                self::TILE_TYPE,
                $x,
                $y,
                $z,
                ['nbt' => base64_encode(json_encode($inv->toArray()))],
            );
        }
        return $out;
    }

    /**
     * Rehydrate chest inventories from the tile snapshots of a freshly loaded
     * chunk (storage adapter decodes the opaque nbt blob).
     * @param list<TileEntitySnapshot> $snapshots
     */
    public function restoreFromSnapshots(array $snapshots): void {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->type !== self::TILE_TYPE) {
                continue;
            }
            $decoded = json_decode((string)base64_decode($snapshot->data['nbt'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $inv = $this->get($snapshot->x, $snapshot->y, $snapshot->z);
            foreach ($decoded as $slot => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $stack = ItemStack::fromArray($item);
                if ($stack !== null && $stack->itemId > 0) {
                    $inv->set((int)$slot, $stack);
                }
            }
        }
    }
}
