<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\port\driven\TileEntitySnapshot;

/**
 * Furnace state by block coordinate ("x:y:z"). Furnaces are blocks, not ECS
 * entities, so their 3-slot inventory + burn/cook progress live here.
 *
 * Slot layout matches the legacy FurnaceInventory: 0 = smelting input,
 * 1 = fuel, 2 = result.
 *
 * Persistence rides the existing chunk tile-entity round-trip exactly like
 * ChestStore: every furnace in a chunk is exported as a TileEntitySnapshot
 * (type "Furnace") when the chunk is saved and rehydrated when loaded, so
 * in-progress smelts and contents survive restarts.
 */
final class FurnaceStore {
    public const SIZE = 3;
    public const SLOT_SMELTING = 0;
    public const SLOT_FUEL = 1;
    public const SLOT_RESULT = 2;
    public const TILE_TYPE = 'Furnace';

    /**
     * @var array<string, array{
     *   inventory: InventoryComponent,
     *   burnTime: int,
     *   cookTime: int
     * }> x:y:z => furnace state
     */
    private array $furnaces = [];

    private function key(int $x, int $y, int $z): string {
        return $x . ':' . $y . ':' . $z;
    }

    /** The furnace state at a position, created on first access. */
    public function get(int $x, int $y, int $z): array {
        $key = $this->key($x, $y, $z);
        return $this->furnaces[$key] ??= [
            'inventory' => new InventoryComponent(self::SIZE),
            'burnTime' => 0,
            'cookTime' => 0,
        ];
    }

    public function has(int $x, int $y, int $z): bool {
        return isset($this->furnaces[$this->key($x, $y, $z)]);
    }

    /**
     * Write back a modified furnace state (the system mutates the struct it
     * read from get() and must persist the change).
     * @param array{inventory: InventoryComponent, burnTime: int, cookTime: int} $state
     */
    public function put(int $x, int $y, int $z, array $state): void {
        $this->furnaces[$this->key($x, $y, $z)] = $state;
    }

    /**
     * @return array<string, array{inventory: InventoryComponent, burnTime: int, cookTime: int}> x:y:z => state
     */
    public function getAll(): array {
        return $this->furnaces;
    }

    /**
     * Drop a furnace's state (e.g. the block was broken). Returns the
     * inventory so the caller can spill its contents.
     */
    public function remove(int $x, int $y, int $z): InventoryComponent {
        $key = $this->key($x, $y, $z);
        $inv = $this->furnaces[$key]['inventory'] ?? new InventoryComponent(self::SIZE);
        unset($this->furnaces[$key]);
        return $inv;
    }

    /**
     * Export every furnace inside a chunk as tile-entity snapshots so the
     * storage adapter persists them with the chunk.
     * @return list<TileEntitySnapshot>
     */
    public function snapshotsForChunk(int $chunkX, int $chunkZ): array {
        $out = [];
        foreach ($this->furnaces as $key => $state) {
            [$x, $y, $z] = array_map('intval', explode(':', $key));
            if ($x < $chunkX * 16 || $x >= $chunkX * 16 + 16
                || $z < $chunkZ * 16 || $z >= $chunkZ * 16 + 16) {
                continue;
            }
            /** @var InventoryComponent $inv */
            $inv = $state['inventory'];
            if ($inv->getContents() === [] && $state['burnTime'] <= 0 && $state['cookTime'] <= 0) {
                continue; // never touch disk for an untouched furnace
            }
            $out[] = new TileEntitySnapshot(
                'furnace:' . $key,
                self::TILE_TYPE,
                $x,
                $y,
                $z,
                [
                    'nbt' => base64_encode(json_encode([
                        'inventory' => $inv->toArray(),
                        'burnTime' => $state['burnTime'],
                        'cookTime' => $state['cookTime'],
                    ])),
                ],
            );
        }
        return $out;
    }

    /**
     * Rehydrate furnace states from the tile snapshots of a freshly loaded
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
            $state = $this->get($snapshot->x, $snapshot->y, $snapshot->z);
            /** @var InventoryComponent $inv */
            $inv = $state['inventory'];
            $items = $decoded['inventory'] ?? [];
            if (is_array($items)) {
                foreach ($items as $slot => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $stack = ItemStack::fromArray($item);
                    if ($stack !== null && $stack->itemId > 0) {
                        $inv->set((int)$slot, $stack);
                    }
                }
            }
            $state['burnTime'] = max(0, (int)($decoded['burnTime'] ?? 0));
            $state['cookTime'] = max(0, (int)($decoded['cookTime'] ?? 0));
            $this->furnaces[$this->key($snapshot->x, $snapshot->y, $snapshot->z)] = $state;
        }
    }
}
