<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

final class ComponentRegistry {
    private array $componentTypes = [];
    private array $archetypes = [];

    public function register(string $componentType): void {
        $this->componentTypes[$componentType] = true;
    }

    public function isRegistered(string $componentType): bool {
        return isset($this->componentTypes[$componentType]);
    }

    public function getArchetype(array $componentTypes): Archetype {
        $key = $this->getArchetypeKey($componentTypes);
        return $this->archetypes[$key] ??= new Archetype($componentTypes);
    }

    private function getArchetypeKey(array $componentTypes): string {
        sort($componentTypes);
        return implode(',', $componentTypes);
    }

    public function getOrCreateArchetype(Entity $entity): Archetype {
        $types = array_keys($entity->getComponents());
        return $this->getArchetype($types);
    }

    public function onEntityChanged(Entity $entity, array $oldTypes): void {
        $oldArchetype = $this->getArchetype($oldTypes);
        $oldArchetype->removeEntity($entity);

        $newArchetype = $this->getOrCreateArchetype($entity);
        $newArchetype->addEntity($entity);
    }
}