<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

use pocketmine\core\component\PositionComponent;

final class EntityBuilder {
    private const TAG_MAP = [
        \pocketmine\core\constants\EntityTags::PLAYER => \pocketmine\core\component\tags\PlayerTag::class,
        \pocketmine\core\constants\EntityTags::MONSTER => \pocketmine\core\component\tags\MonsterTag::class,
        \pocketmine\core\constants\EntityTags::DEAD => \pocketmine\core\component\tags\DeadTag::class,
        \pocketmine\core\constants\EntityTags::INVISIBLE => \pocketmine\core\component\tags\InvisibleTag::class,
        \pocketmine\core\constants\EntityTags::ON_GROUND => \pocketmine\core\component\tags\OnGroundTag::class,
        \pocketmine\core\constants\EntityTags::SPECTATOR => \pocketmine\core\component\tags\SpectatorTag::class,
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