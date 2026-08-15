<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\component\MetadataComponent;

abstract class Animal extends Living {
    public function __construct(EntityRef $ref, \pocketmine\core\ecs\World $world) {
        parent::__construct($ref, $world);
        // Ensure Animal-specific metadata
    }

    public function getAge(): int {
        $metadata = $this->getMetadata();
        return $metadata->get(\pocketmine\core\constants\MetadataKeys::AGE, 0);
    }

    public function setAge(int $age): void {
        $metadata = $this->getMetadata();
        $metadata->set(\pocketmine\core\constants\MetadataKeys::AGE, $age);
    }

    public function isBaby(): bool {
        return $this->getAge() < 0;
    }

    public function setBaby(bool $baby): void {
        $this->setAge($baby ? -1 : 0);
    }

    public function getLoveTimer(): int {
        $metadata = $this->getMetadata();
        return $metadata->get(\pocketmine\core\constants\MetadataKeys::LOVE_TIMER, 0);
    }

    public function setLoveTimer(int $ticks): void {
        $metadata = $this->getMetadata();
        $metadata->set(\pocketmine\core\constants\MetadataKeys::LOVE_TIMER, $ticks);
    }

    public function canBreed(): bool {
        return $this->getLoveTimer() <= 0 && $this->isAdult();
    }

    public function isAdult(): bool {
        return !$this->isBaby();
    }

    public function breed(\pocketmine\api\entity\Animal $partner): ?self {
        if (!$this->canBreed() || !$partner->canBreed()) {
            return null;
        }

        $metadata = $this->getMetadata();
        $this->setLoveTimer(600);
        $partner->setLoveTimer(600);

        $position = $this->getPosition();
        $partnerPos = $partner->getPosition();

        // 14.20: the baby inherits the parent's world so it never leaks into
        // the default world when breeding in a non-default world.
        $parentWorld = $this->getEntity()?->get(\pocketmine\core\component\WorldComponent::class);
        $worldId = $parentWorld instanceof \pocketmine\core\component\WorldComponent ? $parentWorld->id : 0;

        $ref = $this->world->spawn(
            (new \pocketmine\core\ecs\EntityBuilder())
                ->with(new \pocketmine\core\component\PositionComponent(
                    ($position->x + ($partnerPos?->x ?? $position->x)) / 2,
                    $position->y,
                    ($position->z + ($partnerPos?->z ?? $position->z)) / 2
                ))
                ->with(new \pocketmine\core\component\HealthComponent(10, 10))
                ->with(new MetadataComponent([
                    'entityType' => $metadata->get(\pocketmine\core\constants\MetadataKeys::ENTITY_TYPE, get_class($this)),
                    'age' => -1, // baby
                    'owner' => $metadata->get(\pocketmine\core\constants\MetadataKeys::OWNER),
                ]))
                ->with(new \pocketmine\core\component\WorldComponent($worldId))
                ->withTag(\pocketmine\core\constants\EntityTags::ANIMAL)
        );

        $class = get_class($this);
        return new $class($ref, $this->world);
    }

    public function setOwner(\pocketmine\api\entity\Player $player): void {
        $metadata = $this->getMetadata();
        $metadata->set(\pocketmine\core\constants\MetadataKeys::OWNER, $player->getUniqueId());
    }

    public function getOwner(): ?\pocketmine\api\entity\Player {
        $metadata = $this->getMetadata();
        $ownerId = $metadata->get(\pocketmine\core\constants\MetadataKeys::OWNER);
        if (!is_string($ownerId) || $ownerId === '') {
            return null;
        }

        $query = $this->world->query()
            ->with(MetadataComponent::class)
            ->withTag(\pocketmine\core\component\tags\PlayerTag::class)
            ->build();

        foreach ($query as $entity) {
            $entityMeta = $entity->get(MetadataComponent::class);
            if ($entityMeta && $entityMeta->get(\pocketmine\core\constants\MetadataKeys::UNIQUE_ID) === $ownerId) {
                return new \pocketmine\api\entity\Player(
                    \pocketmine\core\ecs\EntityRef::create($entity->id, $this->world),
                    $this->world
                );
            }
        }
        return null;
    }

    public function isTamed(): bool {
        $metadata = $this->getMetadata();
        return $metadata->get(\pocketmine\core\constants\MetadataKeys::TAMED, false);
    }

    public function setTamed(bool $tamed): void {
        $metadata = $this->getMetadata();
        $metadata->set(\pocketmine\core\constants\MetadataKeys::TAMED, $tamed);
    }
}