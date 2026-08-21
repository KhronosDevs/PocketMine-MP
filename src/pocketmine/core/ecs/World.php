<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

final class World {
    private array $entities = [];
    private array $entitiesToAdd = [];
    private array $entitiesToRemove = [];

    /** @var array<int, Archetype> entityId => archetype the entity currently lives in */
    private array $entityArchetypes = [];

    public function __construct(
        private readonly ComponentRegistry $componentRegistry,
        private readonly ResourceRegistry $resourceRegistry,
        private readonly SystemScheduler $systemScheduler,
    ) {}

    public function query(): QueryBuilder {
        return new QueryBuilder($this);
    }

    public function spawn(EntityBuilder $builder): EntityRef {
        $entity = $builder->build($this);
        $this->flushEntityChanges();
        return EntityRef::create($entity->id, $this);
    }

    public function despawn(Entity $entity): void {
        $this->entitiesToRemove[] = $entity;
    }

    public function addEntity(Entity $entity): void {
        $this->entitiesToAdd[] = $entity;
    }

    public function removeEntity(Entity $entity): void {
        $this->entitiesToRemove[] = $entity;
    }

    public function getEntity(int $id): ?Entity {
        return $this->entities[$id] ?? null;
    }

    /**
     * The archetype an entity currently lives in, or null for a despawned or
     * unknown entity. This is the authoritative map that reconcileArchetypes
     * maintains; system queries use it to avoid recomputing an archetype key
     * (array_keys + sort + implode) per entity on every tick.
     */
    public function getEntityArchetype(int $id): ?Archetype {
        return $this->entityArchetypes[$id] ?? null;
    }

    public function getEntities(): array {
        return $this->entities;
    }

    public function getResourceRegistry(): ResourceRegistry {
        return $this->resourceRegistry;
    }

    public function getComponentRegistry(): ComponentRegistry {
        return $this->componentRegistry;
    }

    public function getSystemScheduler(): SystemScheduler {
        return $this->systemScheduler;
    }

    public function tick(float $deltaTime): void {
        $this->flushEntityChanges();
        $this->systemScheduler->run($this, $deltaTime);
        // The global gameplay tick counter advances with EVERY world tick,
        // no matter what drives it (kernel loop, tests, plugin schedulers).
        // Previously only Kernel::run() incremented it, so code ticking the
        // world directly (several systems and tests) saw a frozen clock.
        $tickCounter = $this->resourceRegistry->get(\pocketmine\core\resource\TickCounter::class);
        if ($tickCounter instanceof \pocketmine\core\resource\TickCounter) {
            $tickCounter->value++;
        }
    }

    private function flushEntityChanges(): void {
        $changed = false;
        foreach ($this->entitiesToAdd as $entity) {
            $this->entities[$entity->id] = $entity;
            $changed = true;
        }
        $this->entitiesToAdd = [];

        foreach ($this->entitiesToRemove as $entity) {
            unset($this->entities[$entity->id]);
            $archetype = $this->entityArchetypes[$entity->id] ?? null;
            if ($archetype !== null) {
                $archetype->removeEntity($entity);
                unset($this->entityArchetypes[$entity->id]);
            }
            $changed = true;
        }
        $this->entitiesToRemove = [];

        // A cached Query holds a frozen entity array - spawning or despawning
        // an entity would otherwise leave every cached query (AI system
        // included) iterating a stale snapshot forever. Drop the cache on any
        // entity-set change; steady-state ticks (no add/remove) keep it.
        if ($changed) {
            QueryBuilder::clearCache();
        }

        $this->reconcileArchetypes();
    }

    /**
     * Ensure every live entity lives in the archetype matching its current
     * component set. Components added or removed after spawn (e.g. teleport
     * attaching RotationComponent) would otherwise leave the entity stranded
     * in its original archetype, invisible to system queries.
     *
     * The per-entity archetype-dirty flag (set by Entity::set/remove) lets
     * the pass skip untouched entities with a single bool check, so the
     * steady-state cost per tick is O(entities) field reads, not array_keys
     * + sort per entity. Migrations allocate a fresh index in the target
     * archetype (the old one's freed index stays in its free list for reuse
     * by later additions), so repeated component toggling grows archetype
     * indices slowly but never corrupts data.
     */
    private function reconcileArchetypes(): void {
        foreach ($this->entities as $entity) {
            if (!$entity->isArchetypeDirty()) {
                continue;
            }
            $entity->markArchetypeClean();

            $types = array_keys($entity->getComponents());
            sort($types);

            $recorded = $this->entityArchetypes[$entity->id] ?? null;
            if ($recorded !== null) {
                $recordedTypes = $recorded->componentTypes;
                sort($recordedTypes);
                if ($recordedTypes === $types) {
                    continue;
                }
                $recorded->removeEntity($entity);
            }

            $archetype = $this->componentRegistry->getArchetype($types);
            $archetype->addEntity($entity);
            $this->entityArchetypes[$entity->id] = $archetype;
        }
    }

    /** @var array<string, bool> component class => has applyPending() (memoized) */
    private static array $pendingCapable = [];

    public function applyPendingComponents(): void {
        // Double-buffer swap for parallel systems. Instead of iterating every
        // entity x every component (with a method_exists per component), walk
        // the archetypes' flat component arrays for the component TYPES that
        // actually have applyPending() - one method_exists per type, and the
        // archetype arrays already skip holes (nulled despawned slots).
        foreach ($this->componentRegistry->getArchetypes() as $archetype) {
            foreach ($archetype->componentTypes as $type) {
                if (!isset(self::$pendingCapable[$type])) {
                    self::$pendingCapable[$type] = is_object($type) || (class_exists($type) && method_exists($type, 'applyPending'));
                }
                if (!self::$pendingCapable[$type]) {
                    continue;
                }
                foreach ($archetype->getComponentArray($type) as $component) {
                    if ($component !== null) {
                        $component->applyPending();
                    }
                }
            }
        }
    }
}