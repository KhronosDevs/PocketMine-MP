<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;
use pocketmine\core\ecs\Entity;
use pocketmine\core\ecs\World;

#[Resource]
final class SpatialIndex {
    /**
     * Entities bucketed by chunk column. Keyed with raw ints (cellX => cellZ
     * => list of entity ids) instead of a "x,z" string key: the index is
     * rebuilt every AI tick and queried per mob acquisition, so the string
     * concat + hash per entity was measurable hot cost (~25% of rebuild).
     */
    private array $grid = [];
    private const CELL_SIZE = 16; // 16 blocks = 1 chunk

    public function __construct() {}

    public function insert(Entity $entity): void {
        $position = $entity->get(\pocketmine\core\component\PositionComponent::class);
        if (!$position) {
            return;
        }

        $cellX = (int)floor($position->x / self::CELL_SIZE);
        $cellZ = (int)floor($position->z / self::CELL_SIZE);

        $this->grid[$cellX][$cellZ][] = $entity->id;
    }

    public function remove(Entity $entity): void {
        $position = $entity->get(\pocketmine\core\component\PositionComponent::class);
        if (!$position) {
            return;
        }

        $cellX = (int)floor($position->x / self::CELL_SIZE);
        $cellZ = (int)floor($position->z / self::CELL_SIZE);

        $column = $this->grid[$cellX] ?? null;
        if ($column === null || !isset($column[$cellZ])) {
            return;
        }
        $this->grid[$cellX][$cellZ] = array_values(array_filter(
            $this->grid[$cellX][$cellZ],
            fn($id) => $id !== $entity->id,
        ));
        if ($this->grid[$cellX][$cellZ] === []) {
            unset($this->grid[$cellX][$cellZ]);
            if ($this->grid[$cellX] === []) {
                unset($this->grid[$cellX]);
            }
        }
    }

    public function getNearby(float $x, float $z, float $radius): array {
        $cellX = (int)floor($x / self::CELL_SIZE);
        $cellZ = (int)floor($z / self::CELL_SIZE);
        $cellRadius = (int)ceil($radius / self::CELL_SIZE);

        $entities = [];
        for ($dx = -$cellRadius; $dx <= $cellRadius; $dx++) {
            $column = $this->grid[$cellX + $dx] ?? null;
            if ($column === null) {
                continue;
            }
            for ($dz = -$cellRadius; $dz <= $cellRadius; $dz++) {
                if (isset($column[$cellZ + $dz])) {
                    foreach ($column[$cellZ + $dz] as $id) {
                        $entities[] = $id;
                    }
                }
            }
        }
        return $entities;
    }

    public function clear(): void {
        $this->grid = [];
    }

    public function rebuild(World $world): void {
        $this->clear();
        foreach ($world->getEntities() as $entity) {
            $this->insert($entity);
        }
    }
}
