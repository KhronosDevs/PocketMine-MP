<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\component\tags\MonsterTag;
use pocketmine\core\component\AIStateComponent;
use pocketmine\core\component\PathComponent;

abstract class Monster extends Living {
    public function __construct(EntityRef $ref, \pocketmine\core\ecs\World $world) {
        parent::__construct($ref, $world);
        // Ensure MonsterTag is present
        if (!$this->ref->hasComponent(MonsterTag::class)) {
            $this->ref->setComponent(MonsterTag::class, new MonsterTag());
        }
    }

    public function getTarget(): ?\pocketmine\api\entity\Entity {
        $ai = $this->ref->getAIState();
        if ($ai && $ai->targetEntity !== null) {
            $world = \pocketmine\Kernel::getInstance()->getWorld();
            return Entity::wrap(\pocketmine\core\ecs\EntityRef::create($ai->targetEntity, $world), $world);
        }
        return null;
    }

    public function setTarget(\pocketmine\api\entity\Entity $target): void {
        $ai = $this->ref->getAIState();
        if ($ai) {
            $ai->setTargetEntity($target->getId());
        }
    }

    public function clearTarget(): void {
        $ai = $this->ref->getAIState();
        if ($ai) {
            $ai->clearTarget();
        }
    }

    public function getPath(): ?\pocketmine\core\component\PathComponent {
        return $this->ref->getPath();
    }

    public function setPath(array $path): void {
        $pathComp = $this->ref->getPath();
        if (!$pathComp) {
            $pathComp = new \pocketmine\core\component\PathComponent();
            $this->ref->setComponent(\pocketmine\core\component\PathComponent::class, $pathComp);
        }
        $pathComp->setPath($path);
    }

    public function getFollowRange(): float {
        $ai = $this->ref->getAIState();
        return $ai ? $ai->followRange : 16.0;
    }

    public function setFollowRange(float $range): void {
        $ai = $this->ref->getAIState();
        if ($ai) {
            $ai->followRange = $range;
        }
    }

    public function getAttackRange(): float {
        $ai = $this->ref->getAIState();
        return $ai ? $ai->attackRange : 2.0;
    }

    public function setAttackRange(float $range): void {
        $ai = $this->ref->getAIState();
        if ($ai) {
            $ai->attackRange = $range;
        }
    }

    public function getSpeedModifier(): float {
        $ai = $this->ref->getAIState();
        return $ai ? $ai->speedModifier : 1.0;
    }

    public function setSpeedModifier(float $modifier): void {
        $ai = $this->ref->getAIState();
        if ($ai) {
            $ai->speedModifier = $modifier;
        }
    }

    public function canNavigate(): bool {
        $ai = $this->ref->getAIState();
        return $ai ? $ai->canNavigate : true;
    }

    public function setCanNavigate(bool $can): void {
        $ai = $this->ref->getAIState();
        if ($ai) {
            $ai->canNavigate = $can;
        }
    }

    public function isAvoidingWater(): bool {
        $ai = $this->ref->getAIState();
        return $ai ? $ai->avoidWater : false;
    }

    public function setAvoidWater(bool $avoid): void {
        $ai = $this->ref->getAIState();
        if ($ai) {
            $ai->avoidWater = $avoid;
        }
    }

    public function isAvoidingFire(): bool {
        $ai = $this->ref->getAIState();
        return $ai ? $ai->avoidFire : true;
    }

    public function setAvoidFire(bool $avoid): void {
        $ai = $this->ref->getAIState();
        if ($ai) {
            $ai->avoidFire = $avoid;
        }
    }
}