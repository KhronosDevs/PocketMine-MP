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
    }

    private function flushEntityChanges(): void {
        foreach ($this->entitiesToAdd as $entity) {
            $this->entities[$entity->id] = $entity;
        }
        $this->entitiesToAdd = [];

        foreach ($this->entitiesToRemove as $entity) {
            unset($this->entities[$entity->id]);
            $archetype = $this->entityArchetypes[$entity->id] ?? null;
            if ($archetype !== null) {
                $archetype->removeEntity($entity);
                unset($this->entityArchetypes[$entity->id]);
            }
        }
        $this->entitiesToRemove = [];

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

    public function applyPendingComponents(): void {
        // Double-buffer swap for parallel systems
        foreach ($this->entities as $entity) {
            foreach ($entity->getComponents() as $type => $component) {
                // Tags and other non-object components (withTag stores `true`)
                // have no pending state to apply.
                if (is_object($component) && method_exists($component, 'applyPending')) {
                    $component->applyPending();
                }
            }
        }
    }
}