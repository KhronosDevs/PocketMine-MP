<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\port\driven\TileEntitySnapshot;

/**
 * Map item data store (14.x): map id -> pixels + decorations.
 *
 * Filled maps (item 358) carry an id in their ItemStack meta; the client
 * renders whatever texture the server pushes for that id through
 * ClientboundMapItemDataPacket. The default texture is a 128x128 canvas
 * (scale 0), colored from the world surface once on creation and refreshed
 * as the holder explores uncolored chunks.
 *
 * Persistence rides the existing tile-entity snapshot round-trip: every map
 * in a chunk is exported as a TileEntitySnapshot (type "Map", keyed by the
 * block position where it was created) when the chunk is saved and restored
 * when it loads. Maps in inventories survive through the ItemStack meta.
 */
final class MapStore {
    public const TILE_TYPE = 'Map';
    public const MAP_SIZE = 128;
    public const SCALE = 0;

    /** Map item id (filled map). */
    public const ITEM_FILLED_MAP = 358;

    /**
     * mapId => {colors: list<int> row-major ABGR, dirty: bool, centerX: int, centerZ: int, scale: int}
     * @var array<int, array{colors: list<int>, dirty: bool, centerX: int, centerZ: int, scale: int}>
     */
    private array $maps = [];

    /** Next map id to hand out. */
    private int $nextId = 1;

    /**
     * All known map ids.
     * @return list<int>
     */
    public function allIds(): array {
        return array_keys($this->maps);
    }

    public function has(int $mapId): bool {
        return isset($this->maps[$mapId]);
    }

    /** Map id for a filled-map ItemStack meta (0 means "unassigned"). */
    public function idFromMeta(int $meta): int {
        return $meta > 0 ? $meta : 0;
    }

    /**
     * Get (creating on first use) a map's data. New maps start as a solid
     * unexplored canvas; centerX/centerZ anchor the 1:1 pixel block area.
     *
     * @return array{colors: list<int>, dirty: bool, centerX: int, centerZ: int, scale: int}
     */
    public function get(int $mapId): array {
        return $this->maps[$mapId] ??= [
            'colors' => array_fill(0, self::MAP_SIZE * self::MAP_SIZE, 0x00000000),
            'dirty' => true,
            'centerX' => 0,
            'centerZ' => 0,
            'scale' => self::SCALE,
        ];
    }

    /** Create a new map anchored at a world position; returns its id. */
    public function create(int $centerX, int $centerZ, int $scale = self::SCALE): int {
        $id = $this->nextId++;
        $this->maps[$id] = [
            'colors' => array_fill(0, self::MAP_SIZE * self::MAP_SIZE, 0x00000000),
            'dirty' => true,
            'centerX' => $centerX,
            'centerZ' => $centerZ,
            'scale' => $scale,
        ];
        return $id;
    }

    /**
     * Mark a map's texture changed (exploration renderer wrote new pixels).
     * @param list<int> $colors
     */
    public function updateColors(int $mapId, array $colors): void {
        if (!isset($this->maps[$mapId])) {
            return;
        }
        $this->maps[$mapId]['colors'] = $colors;
        $this->maps[$mapId]['dirty'] = true;
    }

    /** Consume the dirty flag (returns true once per actual texture change). */
    public function consumeDirty(int $mapId): bool {
        if (!isset($this->maps[$mapId]) || !$this->maps[$mapId]['dirty']) {
            return false;
        }
        $this->maps[$mapId]['dirty'] = false;
        return true;
    }

    public function remove(int $mapId): void {
        unset($this->maps[$mapId]);
    }

    public function count(): int {
        return count($this->maps);
    }

    /**
     * Export every map inside a chunk as tile-entity snapshots so the storage
     * adapter persists them with the chunk. Maps are keyed by the anchor
     * position passed at creation/export time.
     *
     * @return list<TileEntitySnapshot>
     */
    public function snapshotsForChunk(int $chunkX, int $chunkZ): array {
        $out = [];
        foreach ($this->maps as $id => $map) {
            if ($map['centerX'] >> 4 !== $chunkX || $map['centerZ'] >> 4 !== $chunkZ) {
                continue;
            }
            $out[] = new TileEntitySnapshot(
                self::TILE_TYPE . '_' . $id,
                self::TILE_TYPE,
                $map['centerX'],
                0,
                $map['centerZ'],
                [
                    'mapId' => $id,
                    'centerX' => $map['centerX'],
                    'centerZ' => $map['centerZ'],
                    'scale' => $map['scale'],
                    'colors' => base64_encode(pack('N*', ...$map['colors'])),
                ],
            );
        }
        return $out;
    }

    /**
     * Rehydrate maps from tile-entity snapshots (chunk load).
     * @param list<TileEntitySnapshot> $snapshots
     */
    public function restoreFromSnapshots(array $snapshots): void {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->type !== self::TILE_TYPE) {
                continue;
            }
            $data = $snapshot->data;
            if (!isset($data['mapId'], $data['colors'])) {
                continue;
            }
            $id = (int)$data['mapId'];
            $packed = is_string($data['colors']) ? base64_decode($data['colors'], true) : false;
            /** @var list<int> $colors */
            $colors = $packed !== false && $packed !== ''
                ? array_values(unpack('N*', $packed))
                : array_fill(0, self::MAP_SIZE * self::MAP_SIZE, 0x00000000);
            $this->maps[$id] = [
                'colors' => $colors,
                'dirty' => false,
                'centerX' => (int)($data['centerX'] ?? 0),
                'centerZ' => (int)($data['centerZ'] ?? 0),
                'scale' => (int)($data['scale'] ?? self::SCALE),
            ];
            if ($id >= $this->nextId) {
                $this->nextId = $id + 1;
            }
        }
    }
}
