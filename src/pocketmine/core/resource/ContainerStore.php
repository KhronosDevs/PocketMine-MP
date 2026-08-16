<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\port\driven\TileEntitySnapshot;

/**
 * Per-position container inventories for simple N-slot container blocks
 * (dispenser = 9, hopper = 5). Like ChestStore/FurnaceStore these are blocks,
 * not ECS entities, so their inventories live here keyed "type:x:y:z".
 *
 * Persistence rides the existing chunk tile-entity round-trip exactly like the
 * other stores: every dispenser/hopper in a chunk is exported as a
 * TileEntitySnapshot (type "Dispenser" / "Hopper") when the chunk is saved and
 * rehydrated when it is loaded.
 */
final class ContainerStore {
    public const TYPE_DISPENSER = 'dispenser';
    public const TYPE_HOPPER = 'hopper';

    public const DISPENSER_SIZE = 9;
    public const HOPPER_SIZE = 5;

    public const TILE_DISPENSER = 'Dispenser';
    public const TILE_HOPPER = 'Hopper';

    /** @var array<string, InventoryComponent> "type:x:y:z" => inventory */
    private array $inventories = [];

    private function key(string $type, int $x, int $y, int $z): string {
        return $type . ':' . $x . ':' . $y . ':' . $z;
    }

    public function sizeFor(string $type): int {
        return $type === self::TYPE_HOPPER ? self::HOPPER_SIZE : self::DISPENSER_SIZE;
    }

    /** The container inventory at a position, created on first access. */
    public function get(string $type, int $x, int $y, int $z): InventoryComponent {
        $key = $this->key($type, $x, $y, $z);
        return $this->inventories[$key] ??= new InventoryComponent($this->sizeFor($type));
    }

    public function has(string $type, int $x, int $y, int $z): bool {
        return isset($this->inventories[$this->key($type, $x, $y, $z)]);
    }

    /**
     * @return array<string, InventoryComponent> "type:x:y:z" => inventory
     */
    public function all(): array {
        return $this->inventories;
    }

    /** Drop a container's contents (e.g. the block was broken). */
    public function remove(string $type, int $x, int $y, int $z): InventoryComponent {
        $key = $this->key($type, $x, $y, $z);
        $inv = $this->inventories[$key] ?? new InventoryComponent($this->sizeFor($type));
        unset($this->inventories[$key]);
        return $inv;
    }

    /**
     * Export every dispenser/hopper inside a chunk as tile-entity snapshots.
     * @return list<TileEntitySnapshot>
     */
    public function snapshotsForChunk(int $chunkX, int $chunkZ): array {
        $out = [];
        foreach ($this->inventories as $key => $inv) {
            $tokens = explode(':', $key);
            $type = (string)$tokens[0];
            [$x, $y, $z] = [(int)$tokens[1], (int)$tokens[2], (int)$tokens[3]];
            if ($x < $chunkX * 16 || $x >= $chunkX * 16 + 16
                || $z < $chunkZ * 16 || $z >= $chunkZ * 16 + 16) {
                continue;
            }
            if ($inv->getContents() === []) {
                continue; // never touch disk for an untouched container
            }
            $out[] = new TileEntitySnapshot(
                $type . ':' . $x . ':' . $y . ':' . $z,
                $type === self::TYPE_HOPPER ? self::TILE_HOPPER : self::TILE_DISPENSER,
                $x,
                $y,
                $z,
                ['nbt' => base64_encode(json_encode($inv->toArray()))],
            );
        }
        return $out;
    }

    /**
     * Rehydrate container inventories from the tile snapshots of a freshly
     * loaded chunk.
     * @param list<TileEntitySnapshot> $snapshots
     */
    public function restoreFromSnapshots(array $snapshots): void {
        foreach ($snapshots as $snapshot) {
            $type = match ($snapshot->type) {
                self::TILE_HOPPER => self::TYPE_HOPPER,
                self::TILE_DISPENSER => self::TYPE_DISPENSER,
                default => null,
            };
            if ($type === null) {
                continue;
            }
            $decoded = json_decode((string)base64_decode($snapshot->data['nbt'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $inv = $this->get($type, $snapshot->x, $snapshot->y, $snapshot->z);
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
