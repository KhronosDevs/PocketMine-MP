<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

final class QueryBuilder {
    private array $with = [];
    private array $withAny = [];
    private array $without = [];
    private $where = null;
    private $orderBy = null;
    private int $chunkSize = 0;
    private ?string $cacheKey = null;

    /**
     * Query cache, keyed by WORLD instance (WeakMap: entries vanish when
     * their world is collected) then by query shape. Two worlds sharing
     * one process (overworld + nether, or the check-plugin harness booting
     * several kernels) previously shared ONE static map keyed only by
     * component classes — world A's cached entity list was served to world
     * B whenever their query shapes collided.
     */
    private static ?\WeakMap $cacheByWorld = null;

    public function __construct(
        private readonly World $world,
    ) {}

    public function with(string ...$componentTypes): self {
        $this->with = array_merge($this->with, $componentTypes);
        $this->cacheKey = null;
        return $this;
    }

    public function withTag(string ...$tagTypes): self {
        return $this->with(...$tagTypes);
    }

    public function withAny(string ...$componentTypes): self {
        $this->withAny = array_merge($this->withAny, $componentTypes);
        $this->cacheKey = null;
        return $this;
    }

    public function without(string ...$componentTypes): self {
        $this->without = array_merge($this->without, $componentTypes);
        $this->cacheKey = null;
        return $this;
    }

    public function where(callable $filter): self {
        $this->where = $filter;
        $this->cacheKey = null; // Runtime filters invalidate cache
        return $this;
    }

    public function orderBy(callable $sorter): self {
        $this->orderBy = $sorter;
        $this->cacheKey = null; // Sorting invalidates cache
        return $this;
    }

    public function chunked(int $size): self {
        $this->chunkSize = $size;
        return $this;
    }

    private function getCacheKey(): string {
        if ($this->cacheKey !== null) {
            return $this->cacheKey;
        }
        
        $parts = [
            'with' => $this->with,
            'withAny' => $this->withAny,
            'without' => $this->without,
        ];
        $this->cacheKey = md5(serialize($parts));
        return $this->cacheKey;
    }

    public function build(): Query {
        $cacheKey = $this->getCacheKey();

        // Check cache first (only meaningful without runtime filters/sort)
        if ($this->where === null && $this->orderBy === null) {
            $cache = self::$cacheByWorld !== null
                ? (self::$cacheByWorld[$this->world] ?? [])
                : [];
            if (isset($cache[$cacheKey])) {
                return $cache[$cacheKey];
            }
        }

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

        $query = new Query($entities, $this->world);

        // Cache if no runtime filter or sort
        if ($this->where === null && $this->orderBy === null) {
            self::$cacheByWorld ??= new \WeakMap();
            $cache = self::$cacheByWorld[$this->world] ?? [];
            $cache[$cacheKey] = $query;
            self::$cacheByWorld[$this->world] = $cache;
        }

        return $query;
    }

    /**
     * Clear the query cache. With no argument every world's cache is
     * dropped (legacy behavior); with a world only that world's entries
     * are dropped — the per-spawn/despawn invalidation path uses this so
     * heavy entity churn in one world does not thrash another world's
     * cached queries.
     */
    public static function clearCache(?World $world = null): void {
        if (self::$cacheByWorld === null) {
            return;
        }
        if ($world === null) {
            foreach (self::$cacheByWorld as $w => $cache) {
                self::$cacheByWorld[$w] = [];
            }
        } else {
            self::$cacheByWorld[$world] = [];
        }
    }
}