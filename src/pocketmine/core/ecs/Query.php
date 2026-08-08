<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

class Query implements \IteratorAggregate {
    private array $entities = [];
    private World $world;

    public function __construct(array $entities, World $world) {
        $this->entities = $entities;
        $this->world = $world;
    }

    public function getIterator(): \Traversable {
        return new \ArrayIterator($this->entities);
    }

    public function count(): int {
        return count($this->entities);
    }

    public function each(callable $fn): void {
        foreach ($this->entities as $entity) {
            $fn($entity);
        }
    }

    public function archetypes(ComponentRegistry $registry): iterable {
        $archetypeMap = [];
        foreach ($this->entities as $entity) {
            // Prefer the world's maintained entityId => archetype map (O(1)
            // lookup, kept in sync by World::reconcileArchetypes) over
            // recomputing the archetype key (array_keys + sort + implode) for
            // every entity on every tick - that is the single hottest
            // per-tick cost at scale. The map is authoritative: it is exactly
            // where reconcileArchetypes put the entity.
            $archetype = $this->world->getEntityArchetype($entity->id);
            if ($archetype === null) {
                // Unknown entity (should not happen for live queries): fall
                // back to the registry so a stale map entry can never strand
                // an entity invisibly.
                $archetype = $registry->getOrCreateArchetype($entity);
            }
            $key = spl_object_id($archetype);
            $archetypeMap[$key] ??= $archetype;
        }
        return $archetypeMap;
    }
}