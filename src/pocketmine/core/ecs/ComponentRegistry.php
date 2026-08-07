<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

final class ComponentRegistry {
    private array $componentTypes = [];
    private array $archetypes = [];
    
    // Archetype index: componentType => archetypeKey => Archetype
    private array $archetypeIndex = [];

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
        
        // Update index
        $this->updateIndex($newArchetype);
    }

    public function getArchetypesWithComponent(string $componentType): array {
        return $this->archetypeIndex[$componentType] ?? [];
    }

    public function getArchetypesWithAllComponents(array $componentTypes): array {
        if (empty($componentTypes)) {
            return array_values($this->archetypes);
        }

        // Start with the rarest component type
        $rarestType = null;
        $minCount = PHP_INT_MAX;
        
        foreach ($componentTypes as $type) {
            $count = count($this->archetypeIndex[$type] ?? []);
            if ($count < $minCount) {
                $minCount = $count;
                $rarestType = $type;
            }
        }

        if ($rarestType === null) {
            return [];
        }

        $candidates = $this->archetypeIndex[$rarestType] ?? [];
        $result = [];

        foreach ($candidates as $key => $archetype) {
            $hasAll = true;
            foreach ($componentTypes as $type) {
                if (!$archetype->hasComponentType($type)) {
                    $hasAll = false;
                    break;
                }
            }
            if ($hasAll) {
                $result[$key] = $archetype;
            }
        }

        return $result;
    }

    private function updateIndex(Archetype $archetype): void {
        $key = $this->getArchetypeKey($archetype->componentTypes);
        
        foreach ($archetype->componentTypes as $type) {
            if (!isset($this->archetypeIndex[$type])) {
                $this->archetypeIndex[$type] = [];
            }
            $this->archetypeIndex[$type][$key] = $archetype;
        }
    }

    public function removeArchetypeFromIndex(Archetype $archetype): void {
        $key = $this->getArchetypeKey($archetype->componentTypes);
        
        foreach ($archetype->componentTypes as $type) {
            if (isset($this->archetypeIndex[$type][$key])) {
                unset($this->archetypeIndex[$type][$key]);
            }
        }
    }
}