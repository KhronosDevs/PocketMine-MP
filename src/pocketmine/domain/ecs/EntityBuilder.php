<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

final class EntityBuilder {
    private array $components = [];

    public function with(mixed $component): self {
        $type = is_object($component) ? get_class($component) : $component;
        $this->components[$type] = $component;
        return $this;
    }

    public function withTag(string $tag): self {
        $this->components[$tag] = true;
        return $this;
    }

    public function at(float $x, float $y, float $z): self {
        $this->components[PositionComponent::class] = new PositionComponent($x, $y, $z);
        return $this;
    }

    public function build(World $world): Entity {
        $entity = new Entity(Entity::generateId(), $this->components);
        $world->addEntity($entity);
        return $entity;
    }
}