<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

class Query implements \IteratorAggregate {
    private array $entities = [];

    public function __construct(array $entities) {
        $this->entities = $entities;
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
            $archetype = $registry->getOrCreateArchetype($entity);
            $key = spl_object_id($archetype);
            $archetypeMap[$key] ??= $archetype;
        }
        return $archetypeMap;
    }
}