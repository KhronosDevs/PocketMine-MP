<?php

declare(strict_types=1);

namespace pocketmine\domain\resource;

use pocketmine\domain\ecs\Resource;
use pocketmine\domain\ecs\Entity;

#[Resource]
final class SpatialIndex {
    private array $grid = [];
    private const CELL_SIZE = 16; // 16 blocks = 1 chunk

    public function __construct() {}

    public function insert(Entity $entity): void {
        $position = $entity->get(\pocketmine\domain\component\PositionComponent::class);
        if (!$position) {
            return;
        }

        $cellX = (int)floor($position->x / self::CELL_SIZE);
        $cellZ = (int)floor($position->z / self::CELL_SIZE);
        $key = $cellX . ',' . $cellZ;

        $this->grid[$key] ??= [];
        $this->grid[$key][] = $entity->id;
    }

    public function remove(Entity $entity): void {
        $position = $entity->get(\pocketmine\domain\component\PositionComponent::class);
        if (!$position) {
            return;
        }

        $cellX = (int)floor($position->x / self::CELL_SIZE);
        $cellZ = (int)floor($position->z / self::CELL_SIZE);
        $key = $cellX . ',' . $cellZ;

        if (isset($this->grid[$key])) {
            $this->grid[$key] = array_filter($this->grid[$key], fn($id) => $id !== $entity->id);
            if (empty($this->grid[$key])) {
                unset($this->grid[$key]);
            }
        }
    }

    public function getNearby(float $x, float $z, float $radius): array {
        $cellX = (int)floor($x / self::CELL_SIZE);
        $cellZ = (int)floor($z / self::CELL_SIZE);
        $cellRadius = (int)ceil($radius / self::CELL_SIZE);

        $entities = [];
        for ($dx = -$cellRadius; $dx <= $cellRadius; $dx++) {
            for ($dz = -$cellRadius; $dz <= $cellRadius; $dz++) {
                $key = ($cellX + $dx) . ',' . ($cellZ + $dz);
                if (isset($this->grid[$key])) {
                    $entities = array_merge($entities, $this->grid[$key]);
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