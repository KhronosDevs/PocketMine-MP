<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

final class Archetype {
    private array $entities = [];
    private array $componentArrays = [];

    public function __construct(
        public readonly array $componentTypes,
    ) {
        foreach ($componentTypes as $type) {
            $this->componentArrays[$type] = [];
        }
    }

    public function addEntity(Entity $entity): void {
        $this->entities[$entity->id] = $entity;
        foreach ($this->componentTypes as $type) {
            $this->componentArrays[$type][$entity->id] = $entity->get($type);
        }
    }

    public function removeEntity(Entity $entity): void {
        unset($this->entities[$entity->id]);
        foreach ($this->componentTypes as $type) {
            unset($this->componentArrays[$type][$entity->id]);
        }
    }

    public function getEntity(int $id): ?Entity {
        return $this->entities[$id] ?? null;
    }

    public function getComponentArray(string $type): array {
        return $this->componentArrays[$type] ?? [];
    }

    public function count(): int {
        return count($this->entities);
    }

    public function getEntities(): array {
        return $this->entities;
    }

    public function hasComponentType(string $type): bool {
        return isset($this->componentArrays[$type]);
    }
}