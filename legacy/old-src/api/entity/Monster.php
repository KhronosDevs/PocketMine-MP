<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;
use pocketmine\domain\component\tags\MonsterTag;
use pocketmine\domain\component\AIStateComponent;
use pocketmine\domain\component\PathComponent;

abstract class Monster extends Living {
    public function __construct(EntityRef $ref, \pocketmine\domain\ecs\World $world) {
        parent::__construct($ref, $world);
        // Ensure MonsterTag is present
        if (!$this->ref->hasComponent(MonsterTag::class)) {
            $this->ref->setComponent(MonsterTag::class, new MonsterTag());
        }
    }

    public function getTarget(): ?\pocketmine\api\entity\Entity {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        if ($ai && $ai->targetEntity !== null) {
            $targetRef = \pocketmine\domain\ecs\EntityRef::create($ai->targetEntity, \pocketmine\Kernel::getInstance()->getWorld());
            return new Entity($targetRef, \pocketmine\Kernel::getInstance()->getWorld());
        }
        return null;
    }

    public function setTarget(\pocketmine\api\entity\Entity $target): void {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        if ($ai) {
            $ai->setTargetEntity($target->getId());
        }
    }

    public function clearTarget(): void {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        if ($ai) {
            $ai->clearTarget();
        }
    }

    public function getPath(): ?\pocketmine\domain\component\PathComponent {
        return $this->ref->get(\pocketmine\domain\component\PathComponent::class);
    }

    public function setPath(array $path): void {
        $pathComp = $this->ref->get(\pocketmine\domain\component\PathComponent::class);
        if (!$pathComp) {
            $pathComp = new \pocketmine\domain\component\PathComponent();
            $this->ref->setComponent(\pocketmine\domain\component\PathComponent::class, $pathComp);
        }
        $pathComp->setPath($path);
    }

    public function getFollowRange(): float {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        return $ai ? $ai->followRange : 16.0;
    }

    public function setFollowRange(float $range): void {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        if ($ai) {
            $ai->followRange = $range;
        }
    }

    public function getAttackRange(): float {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        return $ai ? $ai->attackRange : 2.0;
    }

    public function setAttackRange(float $range): void {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        if ($ai) {
            $ai->attackRange = $range;
        }
    }

    public function getSpeedModifier(): float {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        return $ai ? $ai->speedModifier : 1.0;
    }

    public function setSpeedModifier(float $modifier): void {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        if ($ai) {
            $ai->speedModifier = $modifier;
        }
    }

    public function canNavigate(): bool {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        return $ai ? $ai->canNavigate : true;
    }

    public function setCanNavigate(bool $can): void {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        if ($ai) {
            $ai->canNavigate = $can;
        }
    }

    public function isAvoidingWater(): bool {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        return $ai ? $ai->avoidWater : false;
    }

    public function setAvoidWater(bool $avoid): void {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        if ($ai) {
            $ai->avoidWater = $avoid;
        }
    }

    public function isAvoidingFire(): bool {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        return $ai ? $ai->avoidFire : true;
    }

    public function setAvoidFire(bool $avoid): void {
        $ai = $this->ref->get(\pocketmine\domain\component\AIStateComponent::class);
        if ($ai) {
            $ai->avoidFire = $avoid;
        }
    }
}