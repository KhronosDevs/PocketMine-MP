<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\component\ItemStack;
use pocketmine\port\driven\TileEntitySnapshot;

/**
 * Tile-entity data by block coordinate ("x:y:z") for blocks whose state is
 * more than a block id/meta: signs (4 text lines + placing player) and item
 * frames (held item + rotation).
 *
 * Like ChestStore/FurnaceStore this is a per-world resource: two worlds with
 * a sign at the same coordinates must never share text. Persistence rides the
 * existing chunk tile-entity round-trip - every sign/frame in a chunk is
 * exported as a TileEntitySnapshot (type "Sign" / "ItemFrame") when the chunk
 * is saved and rehydrated when it is loaded.
 */
final class TileEntityStore {
    public const TILE_SIGN = 'Sign';
    public const TILE_ITEM_FRAME = 'ItemFrame';
    public const TILE_PAINTING = 'Painting';

    /** @var array<string, array{text: string[], creator: string}> x:y:z => sign state */
    private array $signs = [];
    /** @var array<string, array{item: ?array{id: int, meta: int, count: int}, rotation: int}> x:y:z => frame state */
    private array $frames = [];
    /** @var array<string, array{title: string, direction: int}> x:y:z => painting state (bug 23) */
    private array $paintings = [];

    private function key(int $x, int $y, int $z): string {
        return $x . ':' . $y . ':' . $z;
    }

    // --- Signs -----------------------------------------------------------

    /**
     * @return array{text: string[], creator: string}|null
     */
    public function getSign(int $x, int $y, int $z): ?array {
        return $this->signs[$this->key($x, $y, $z)] ?? null;
    }

    /** Create or replace a sign's text (4 lines) and creator. */
    public function setSign(int $x, int $y, int $z, array $text, string $creator): void {
        $this->signs[$this->key($x, $y, $z)] = [
            'text' => array_map('strval', array_slice($text, 0, 4)),
            'creator' => $creator,
        ];
    }

    // --- Item frames -----------------------------------------------------

    /**
     * @return array{item: ?array{id: int, meta: int, count: int}, rotation: int}|null
     */
    public function getFrame(int $x, int $y, int $z): ?array {
        return $this->frames[$this->key($x, $y, $z)] ?? null;
    }

    public function setFrameItem(int $x, int $y, int $z, ItemStack $item): void {
        $key = $this->key($x, $y, $z);
        $frame = $this->frames[$key] ?? ['item' => null, 'rotation' => 0];
        $frame['item'] = ['id' => $item->itemId, 'meta' => $item->meta, 'count' => $item->count];
        $frame['rotation'] = 0;
        $this->frames[$key] = $frame;
    }

    /** Advance the frame's item rotation by one step (0..7, wraps). */
    public function rotateFrame(int $x, int $y, int $z): void {
        $key = $this->key($x, $y, $z);
        $frame = $this->frames[$key] ?? null;
        if ($frame === null) {
            return;
        }
        $frame['rotation'] = ($frame['rotation'] + 1) % 8;
        $this->frames[$key] = $frame;
    }

    public function clearFrame(int $x, int $y, int $z): void {
        $this->frames[$this->key($x, $y, $z)] = ['item' => null, 'rotation' => 0];
    }

    // --- Lifecycle -------------------------------------------------------

    /** Drop the tile entity at a position (e.g. the block was broken). */
    public function remove(int $x, int $y, int $z): void {
        $key = $this->key($x, $y, $z);
        unset($this->signs[$key], $this->frames[$key]);
    }

    /**
     * Export every sign/frame inside a chunk as tile-entity snapshots so the
     * storage adapter persists them with the chunk.
     * @return list<TileEntitySnapshot>
     */
    public function snapshotsForChunk(int $chunkX, int $chunkZ): array {
        $out = [];
        foreach ($this->signs as $key => $sign) {
            [$x, $y, $z] = array_map('intval', explode(':', $key));
            if ($x < $chunkX * 16 || $x >= $chunkX * 16 + 16
                || $z < $chunkZ * 16 || $z >= $chunkZ * 16 + 16) {
                continue;
            }
            $out[] = new TileEntitySnapshot(
                'sign:' . $key,
                self::TILE_SIGN,
                $x,
                $y,
                $z,
                ['text' => $sign['text'], 'creator' => $sign['creator']],
            );
        }
        foreach ($this->frames as $key => $frame) {
            [$x, $y, $z] = array_map('intval', explode(':', $key));
            if ($x < $chunkX * 16 || $x >= $chunkX * 16 + 16
                || $z < $chunkZ * 16 || $z >= $chunkZ * 16 + 16) {
                continue;
            }
            $out[] = new TileEntitySnapshot(
                'frame:' . $key,
                self::TILE_ITEM_FRAME,
                $x,
                $y,
                $z,
                ['item' => $frame['item'], 'rotation' => $frame['rotation']],
            );
        }
        foreach ($this->paintings as $key => $painting) {
            [$x, $y, $z] = array_map('intval', explode(':', $key));
            if ($x < $chunkX * 16 || $x >= $chunkX * 16 + 16
                || $z < $chunkZ * 16 || $z >= $chunkZ * 16 + 16) {
                continue;
            }
            $out[] = new TileEntitySnapshot(
                'painting:' . $key,
                self::TILE_PAINTING,
                $x,
                $y,
                $z,
                ['title' => $painting['title'], 'direction' => $painting['direction']],
            );
        }
        return $out;
    }

    /**
     * Rehydrate signs/frames from the tile snapshots of a freshly loaded
     * chunk.
     * @param list<TileEntitySnapshot> $snapshots
     */
    public function restoreFromSnapshots(array $snapshots): void {
        foreach ($snapshots as $snapshot) {
            $key = $snapshot->x . ':' . $snapshot->y . ':' . $snapshot->z;
            if ($snapshot->type === self::TILE_SIGN) {
                $text = $snapshot->data['text'] ?? [];
                $this->signs[$key] = [
                    'text' => array_map('strval', array_slice((array)$text, 0, 4)),
                    'creator' => (string)($snapshot->data['creator'] ?? ''),
                ];
            } elseif ($snapshot->type === self::TILE_ITEM_FRAME) {
                $item = $snapshot->data['item'] ?? null;
                $this->frames[$key] = [
                    'item' => is_array($item)
                        ? ['id' => (int)($item['id'] ?? 0), 'meta' => (int)($item['meta'] ?? 0), 'count' => (int)($item['count'] ?? 1)]
                        : null,
                    'rotation' => (int)($snapshot->data['rotation'] ?? 0),
                ];
            } elseif ($snapshot->type === self::TILE_PAINTING) {
                $this->paintings[$key] = [
                    'title' => (string)($snapshot->data['title'] ?? 'Kebab'),
                    'direction' => (int)($snapshot->data['direction'] ?? 0),
                ];
            }
        }
    }

    // --- Paintings (bug 23) -------------------------------------------------

    public function getPainting(int $x, int $y, int $z): ?array {
        return $this->paintings[$this->key($x, $y, $z)] ?? null;
    }

    public function setPainting(int $x, int $y, int $z, string $title, int $direction): void {
        $this->paintings[$this->key($x, $y, $z)] = ['title' => $title, 'direction' => $direction];
    }

    /** @return array{title: string, direction: int}|null Removed painting. */
    public function removePainting(int $x, int $y, int $z): ?array {
        $p = $this->paintings[$this->key($x, $y, $z)] ?? null;
        unset($this->paintings[$this->key($x, $y, $z)]);
        return $p;
    }
}
