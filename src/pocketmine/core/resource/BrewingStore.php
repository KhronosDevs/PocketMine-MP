<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\port\driven\TileEntitySnapshot;

/**
 * Brewing stand state by block coordinate ("x:y:z"). Brewing stands are
 * blocks, not ECS entities, so their 4-slot inventory + brew progress live
 * here.
 *
 * Slot layout matches the legacy BrewingInventory: 0 = ingredient (nether
 * wart, glowstone dust, ...), 1-3 = the three potion bottles.
 *
 * Persistence rides the existing chunk tile-entity round-trip exactly like
 * FurnaceStore: every brewing stand in a chunk is exported as a
 * TileEntitySnapshot (type "BrewingStand") when the chunk is saved and
 * rehydrated when loaded.
 */
final class BrewingStore {
    public const SIZE = 4;
    public const SLOT_INGREDIENT = 0;
    public const SLOT_BOTTLE_1 = 1;
    public const SLOT_BOTTLE_2 = 2;
    public const SLOT_BOTTLE_3 = 3;
    public const TILE_TYPE = 'BrewingStand';

    /**
     * @var array<string, array{
     *   inventory: InventoryComponent,
     *   brewTime: int
     * }> x:y:z => brewing stand state
     */
    private array $stands = [];

    private function key(int $x, int $y, int $z): string {
        return $x . ':' . $y . ':' . $z;
    }

    /** The brewing stand state at a position, created on first access. */
    public function get(int $x, int $y, int $z): array {
        $key = $this->key($x, $y, $z);
        return $this->stands[$key] ??= [
            'inventory' => new InventoryComponent(self::SIZE),
            'brewTime' => 0,
        ];
    }

    public function has(int $x, int $y, int $z): bool {
        return isset($this->stands[$this->key($x, $y, $z)]);
    }

    /**
     * Write back a modified stand state (the system mutates the struct it
     * read from get() and must persist the change).
     * @param array{inventory: InventoryComponent, brewTime: int} $state
     */
    public function put(int $x, int $y, int $z, array $state): void {
        $this->stands[$this->key($x, $y, $z)] = $state;
    }

    /** @return array<string, array{inventory: InventoryComponent, brewTime: int}> */
    public function getAll(): array {
        return $this->stands;
    }

    /**
     * Drop a stand's state (e.g. the block was broken). Returns the
     * inventory so the caller can spill its contents.
     */
    public function remove(int $x, int $y, int $z): InventoryComponent {
        $key = $this->key($x, $y, $z);
        $inv = $this->stands[$key]['inventory'] ?? new InventoryComponent(self::SIZE);
        unset($this->stands[$key]);
        return $inv;
    }

    /**
     * Export every brewing stand inside a chunk as tile-entity snapshots.
     * @return list<TileEntitySnapshot>
     */
    public function snapshotsForChunk(int $chunkX, int $chunkZ): array {
        $out = [];
        foreach ($this->stands as $key => $state) {
            [$x, $y, $z] = array_map('intval', explode(':', $key));
            if ($x < $chunkX * 16 || $x >= $chunkX * 16 + 16
                || $z < $chunkZ * 16 || $z >= $chunkZ * 16 + 16) {
                continue;
            }
            /** @var InventoryComponent $inv */
            $inv = $state['inventory'];
            if ($inv->getContents() === [] && $state['brewTime'] <= 0) {
                continue; // never touch disk for an untouched stand
            }
            $out[] = new TileEntitySnapshot(
                'brewing:' . $key,
                self::TILE_TYPE,
                $x,
                $y,
                $z,
                [
                    'nbt' => base64_encode(json_encode([
                        'inventory' => $inv->toArray(),
                        'brewTime' => $state['brewTime'],
                    ])),
                ],
            );
        }
        return $out;
    }

    /**
     * Rehydrate brewing stand states from the tile snapshots of a freshly
     * loaded chunk.
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
            $state['brewTime'] = max(0, (int)($decoded['brewTime'] ?? 0));
            $this->stands[$this->key($snapshot->x, $snapshot->y, $snapshot->z)] = $state;
        }
    }
}
