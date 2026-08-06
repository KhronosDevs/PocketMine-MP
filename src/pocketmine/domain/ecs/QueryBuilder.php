<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

final class QueryBuilder {
    private array $with = [];
    private array $withAny = [];
    private array $without = [];
    private ?callable $where = null;
    private ?callable $orderBy = null;
    private int $chunkSize = 0;

    public function __construct(
        private readonly World $world,
    ) {}

    public function with(string ...$componentTypes): self {
        $this->with = array_merge($this->with, $componentTypes);
        return $this;
    }

    public function withAny(string ...$componentTypes): self {
        $this->withAny = array_merge($this->withAny, $componentTypes);
        return $this;
    }

    public function without(string ...$componentTypes): self {
        $this->without = array_merge($this->without, $componentTypes);
        return $this;
    }

    public function where(callable $filter): self {
        $this->where = $filter;
        return $this;
    }

    public function orderBy(callable $sorter): self {
        $this->orderBy = $sorter;
        return $this;
    }

    public function chunked(int $size): self {
        $this->chunkSize = $size;
        return $this;
    }

    public function build(): Query {
        $entities = $this->world->getEntities();

        // Filter by required components (ALL must be present)
        if ($this->with) {
            $entities = array_filter($entities, function (Entity $entity) {
                foreach ($this->with as $type) {
                    if (!$entity->has($type)) {
                        return false;
                    }
                }
                return true;
            });
        }

        // Filter by any-of components (AT LEAST ONE must be present)
        if ($this->withAny) {
            $entities = array_filter($entities, function (Entity $entity) {
                foreach ($this->withAny as $type) {
                    if ($entity->has($type)) {
                        return true;
                    }
                }
                return false;
            });
        }

        // Filter by excluded components (NONE must be present)
        if ($this->without) {
            $entities = array_filter($entities, function (Entity $entity) {
                foreach ($this->without as $type) {
                    if ($entity->has($type)) {
                        return false;
                    }
                }
                return true;
            });
        }

        // Runtime filter
        if ($this->where) {
            $entities = array_filter($entities, $this->where);
        }

        // Sort
        if ($this->orderBy) {
            uasort($entities, function (Entity $a, Entity $b) {
                return ($this->orderBy)($a) <=> ($this->orderBy)($b);
            });
        }

        // Re-index
        $entities = array_values($entities);

        return new Query($entities);
    }
}