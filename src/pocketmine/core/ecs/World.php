<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

final class World {
    private array $entities = [];
    private array $entitiesToAdd = [];
    private array $entitiesToRemove = [];

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
            $this->componentRegistry->getOrCreateArchetype($entity)->addEntity($entity);
        }
        $this->entitiesToAdd = [];

        foreach ($this->entitiesToRemove as $entity) {
            unset($this->entities[$entity->id]);
            $archetype = $this->componentRegistry->getOrCreateArchetype($entity);
            $archetype->removeEntity($entity);
        }
        $this->entitiesToRemove = [];
    }

    public function applyPendingComponents(): void {
        // Double-buffer swap for parallel systems
        foreach ($this->entities as $entity) {
            foreach ($entity->getComponents() as $type => $component) {
                if (method_exists($component, 'applyPending')) {
                    $component->applyPending();
                }
            }
        }
    }
}