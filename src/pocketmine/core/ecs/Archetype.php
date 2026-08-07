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
    
    /** @var list<int> freed indices for reuse. One index space is shared by
     *  ALL component arrays (an entity occupies the same index in each), so a
     *  single flat list is correct. A per-type list was a leak: allocateIndex
     *  only ever popped the FIRST type's list, so every other type's list
     *  accumulated duplicate stale entries forever (unbounded growth). */
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
            
            $released = false;
            foreach ($this->componentTypes as $type) {
                if (isset($this->componentArrays[$type][$index])) {
                    $this->componentArrays[$type][$index] = null;
                    $this->componentCounts[$type]--;
                    $released = true;
                }
            }
            
            // The index is reusable across all component arrays at once.
            if ($released) {
                $this->freeIndices[] = $index;
            }
        }
    }

    private function allocateIndex(int $entityId): int {
        // Reuse a freed index when one exists (shared across all component
        // arrays), else grow the monotonic high-water mark.
        if (!empty($this->freeIndices)) {
            return array_pop($this->freeIndices);
        }
        
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

    /**
     * Memory-stats snapshot for profiling: how many array slots each
     * component type has allocated vs how many are actually valid, plus the
     * index high-water mark and free-list size. Wasted slots = capacity -
     * valid (nulls left behind by removals that have not been reused yet).
     *
     * @return array{entities: int, indexHighWater: int, freeIndices: int, components: array<string, array{capacity: int, valid: int, wasted: int}>}
     */
    public function getStats(): array {
        $components = [];
        foreach ($this->componentTypes as $type) {
            $capacity = count($this->componentArrays[$type]);
            $valid = $this->componentCounts[$type];
            $components[$type] = [
                'capacity' => $capacity,
                'valid' => $valid,
                'wasted' => max(0, $capacity - $valid),
            ];
        }
        return [
            'entities' => count($this->entities),
            'indexHighWater' => $this->nextIndex,
            'freeIndices' => count($this->freeIndices),
            'components' => $components,
        ];
    }
}