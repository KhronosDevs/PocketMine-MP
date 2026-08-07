<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

final class EntityBuilder {
    private const TAG_MAP = [
        'player' => \pocketmine\core\component\tags\PlayerTag::class,
        'monster' => \pocketmine\core\component\tags\MonsterTag::class,
        'dead' => \pocketmine\core\component\tags\DeadTag::class,
        'invisible' => \pocketmine\core\component\tags\InvisibleTag::class,
        'on_ground' => \pocketmine\core\component\tags\OnGroundTag::class,
        'spectator' => \pocketmine\core\component\tags\SpectatorTag::class,
    ];

    private array $components = [];

    public function with(mixed $component): self {
        $type = is_object($component) ? get_class($component) : $component;
        $this->components[$type] = $component;
        return $this;
    }

    public function withTag(string $tag): self {
        $tagClass = self::TAG_MAP[$tag] ?? null;
        if ($tagClass !== null) {
            $this->components[$tagClass] = new $tagClass();
        } else {
            $this->components[$tag] = true;
        }
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