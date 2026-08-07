<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

/**
 * Archetype storage using struct-of-arrays (SoA) layout for optimal cache performance.
 * Components are stored in flat arrays indexed by entity index (not entity ID).
 * Entity ID to index mapping is maintained for O(1) lookups.
 */
final class Archetype {
    /** @var array<int, Entity> */
    private array $entities = [];
    
    /** @var array<int, int> entityId => index in component arrays */
    private array $entityIdToIndex = [];
    
    /** @var array<string, array> componentType => flat array of component data */
    private array $componentArrays = [];
    
    /** @var array<string, int> componentType => count of valid entries */
    private array $componentCounts = [];
    
    /** @var array<string, array> freed indices for reuse */
    private array $freeIndices = [];

    /** Monotonic index allocator: indices are never derived from the current
     *  entity count, which shifts the first entity into index -1 and makes
     *  index-based system loops (for i=0; i<count; i++) skip it. */
    private int $nextIndex = 0;

    public function __construct(
        public readonly array $componentTypes,
    ) {
        foreach ($componentTypes as $type) {
            $this->componentArrays[$type] = [];
            $this->componentCounts[$type] = 0;
            $this->freeIndices[$type] = [];
        }
    }

    public function addEntity(Entity $entity): void {
        // Get or allocate an index
        $index = $this->allocateIndex($entity->id);
        
        $this->entities[$entity->id] = $entity;
        $this->entityIdToIndex[$entity->id] = $index;
        
        foreach ($this->componentTypes as $type) {
            // Ensure array has capacity
            while (count($this->componentArrays[$type]) <= $index) {
                $this->componentArrays[$type][] = null;
            }
            $this->componentArrays[$type][$index] = $entity->get($type);
            $this->componentCounts[$type]++;
        }
    }

    public function removeEntity(Entity $entity): void {
        unset($this->entities[$entity->id]);
        
        $index = $this->entityIdToIndex[$entity->id] ?? null;
        if ($index !== null) {
            unset($this->entityIdToIndex[$entity->id]);
            
            foreach ($this->componentTypes as $type) {
                if (isset($this->componentArrays[$type][$index])) {
                    $this->componentArrays[$type][$index] = null;
                    $this->componentCounts[$type]--;
                    
                    // Add to free list for reuse
                    $this->freeIndices[$type][] = $index;
                }
            }
        }
    }

    private function allocateIndex(int $entityId): int {
        // Try to reuse a freed index from the first component type
        $firstType = $this->componentTypes[0] ?? null;
        if ($firstType && !empty($this->freeIndices[$firstType])) {
            return array_pop($this->freeIndices[$firstType]);
        }
        
        // Allocate new index (monotonic, reused from the free list when possible)
        return $this->nextIndex++;
    }

    public function getEntity(int $id): ?Entity {
        return $this->entities[$id] ?? null;
    }

    public function getComponentArray(string $type): array {
        return $this->componentArrays[$type] ?? [];
    }

    public function getValidComponentArray(string $type): array {
        $result = [];
        $array = $this->componentArrays[$type] ?? [];
        foreach ($array as $component) {
            if ($component !== null) {
                $result[] = $component;
            }
        }
        return $result;
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

    public function getComponentCount(string $type): int {
        return $this->componentCounts[$type] ?? 0;
    }

    /**
     * Iterate over valid components with their entity IDs
     * @return iterable<int, Entity>
     */
    public function iterateEntities(): iterable {
        foreach ($this->entities as $id => $entity) {
            yield $id => $entity;
        }
    }
}