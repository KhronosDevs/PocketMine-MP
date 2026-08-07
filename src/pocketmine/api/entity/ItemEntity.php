<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\api\inventory\ItemStack;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;

class ItemEntity extends Entity {
    public function __construct(EntityRef $ref, \pocketmine\core\ecs\World $world) {
        parent::__construct($ref, $world);
    }

    public static function create(float $x, float $y, float $z, ItemStack $item): self {
        $kernel = \pocketmine\Kernel::getInstance();
        $spawnService = $kernel->getEntitySpawnService();
        
        $entityRef = $spawnService->spawnItem($x, $y, $z, $item->toCore());
        
        $kernel = \pocketmine\Kernel::getInstance();
        $world = $kernel->getWorld();
        
        return new self($entityRef, $world);
    }

    public function getItem(): ItemStack {
        $metadata = $this->ref->getMetadata();
        $item = $metadata?->get('item');
        return $item instanceof \pocketmine\core\component\ItemStack
            ? ItemStack::fromCore($item)
            : new ItemStack(0, 0, 0);
    }

    public function setItem(ItemStack $item): void {
        $metadata = $this->ref->getMetadata();
        if (!$metadata) {
            $metadata = new \pocketmine\core\component\MetadataComponent();
            $this->ref->setComponent(\pocketmine\core\component\MetadataComponent::class, $metadata);
        }
        $metadata->set('item', $item->toCore());
    }

    public function getPickupDelay(): int {
        $metadata = $this->ref->getMetadata();
        return (int)($metadata?->get('pickupDelay') ?? 0);
    }

    public function setPickupDelay(int $ticks): void {
        $metadata = $this->ref->getMetadata();
        if (!$metadata) {
            $metadata = new \pocketmine\core\component\MetadataComponent();
            $this->ref->setComponent(\pocketmine\core\component\MetadataComponent::class, $metadata);
        }
        $metadata->set('pickupDelay', $ticks);
    }
}