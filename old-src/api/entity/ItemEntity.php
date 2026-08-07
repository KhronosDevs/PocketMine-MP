<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;
use pocketmine\domain\component\InventoryComponent;
use pocketmine\domain\component\ItemStack;
use pocketmine\domain\component\MetadataComponent;

class ItemEntity extends Entity {
    public function __construct(EntityRef $ref, \pocketmine\domain\ecs\World $world) {
        parent::__construct($ref, $world);
    }

    public static function create(float $x, float $y, float $z, \pocketmine\domain\component\ItemStack $item): self {
        $kernel = \pocketmine\Kernel::getInstance();
        $spawnService = $kernel->getEntitySpawnService();
        
        $entityRef = $spawnService->spawnItem($x, $y, $z, $item);
        
        $kernel = \pocketmine\Kernel::getInstance();
        $world = $kernel->getWorld();
        
        return new self($entityRef, $world);
    }

    public function getItem(): \pocketmine\domain\component\ItemStack {
        $metadata = $this->ref->get(\pocketmine\domain\component\MetadataComponent::class);
        return $metadata->get('item', new \pocketmine\domain\component\ItemStack(0, 0, 0));
    }

    public function setItem(\pocketmine\domain\component\ItemStack $item): void {
        $metadata = $this->ref->get(\pocketmine\domain\component\MetadataComponent::class);
        if (!$metadata) {
            $metadata = new \pocketmine\domain\component\MetadataComponent();
            $this->ref->setComponent(\pocketmine\domain\component\MetadataComponent::class, $metadata);
        }
        $metadata->set('item', $item);
    }

    public function getPickupDelay(): int {
        $metadata = $this->ref->get(\pocketmine\domain\component\MetadataComponent::class);
        return $metadata->get('pickupDelay', 0);
    }

    public function setPickupDelay(int $ticks): void {
        $metadata = $this->ref->get(\pocketmine\domain\component\MetadataComponent::class);
        if (!$metadata) {
            $metadata = new \pocketmine\domain\component\MetadataComponent();
            $this->ref->setComponent(\pocketmine\domain\component\MetadataComponent::class, $metadata);
        }
        $metadata->set('pickupDelay', $ticks);
    }
}