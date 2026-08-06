<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\component\HealthComponent;
use pocketmine\domain\component\MetadataComponent;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\RotationComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\ecs\EntityBuilder;
use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;
use pocketmine\port\driven\StoragePort;

final class EntitySpawnService {
    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
    ) {}

    public function spawnEntity(string $entityType, float $x, float $y, float $z, float $yaw = 0, float $pitch = 0, array $metadata = []): EntityRef {
        $entityRef = $this->world->spawn(
            (new EntityBuilder())
                ->with(new PositionComponent($x, $y, $z))
                ->with(new RotationComponent($yaw, $pitch))
                ->with(new VelocityComponent())
                ->with(new HealthComponent())
                ->with(new MetadataComponent())
                ->build($this->world)
        );
        
        $entity = $entityRef->getEntity();
        if ($entity) {
            // Set entity type metadata
            $meta = $entity->get(MetadataComponent::class);
            if ($meta) {
                $meta->set('entityType', $entityType);
                foreach ($metadata as $key => $value) {
                    $meta->set($key, $value);
                }
            }
            
            // Apply type-specific initialization
            $this->initializeEntity($entityRef, $entityType);
        }
        
        return $entityRef;
    }

    public function spawnMob(string $mobType, float $x, float $y, float $z): EntityRef {
        $entityRef = $this->spawnEntity($mobType, $x, $y, $z);
        
        $entity = $entityRef->getEntity();
        if ($entity) {
            $meta = $entity->get(MetadataComponent::class);
            if ($meta) {
                $meta->set('mobType', $mobType);
            }
        }
        
        return $entityRef;
    }

    public function spawnItem(float $x, float $y, float $z, \pocketmine\domain\component\ItemStack $item): EntityRef {
        $entityRef = $this->world->spawn(
            (new EntityBuilder())
                ->with(new PositionComponent($x, $y, $z))
                ->with(new RotationComponent(0, 0))
                ->with(new VelocityComponent(
                    (mt_rand(-10, 10) / 100),
                    0.2,
                    (mt_rand(-10, 10) / 100)
                ))
                ->with(new HealthComponent(5, 5))
                ->with(new MetadataComponent())
                ->withTag('item')
                ->build($this->world)
        );
        
        $entity = $entityRef->getEntity();
        if ($entity) {
            $meta = $entity->get(\pocketmine\domain\component\MetadataComponent::class);
            if ($meta) {
                $meta->set('item', $item);
            }
        }
        
        return $entityRef;
    }

    public function spawnProjectile(string $projectileType, float $x, float $y, float $z, float $velX, float $velY, float $velZ, EntityRef $shooter): EntityRef {
        $entityRef = $this->spawnEntity($projectileType, $x, $y, $z);
        
        $entity = $entityRef->getEntity();
        if ($entity) {
            $velocity = $entity->get(\pocketmine\domain\component\VelocityComponent::class);
            if ($velocity) {
                $velocity->x = $velX;
                $velocity->y = $velY;
                $velocity->z = $velZ;
            }
            
            $meta = $entity->get(\pocketmine\domain\component\MetadataComponent::class);
            if ($meta) {
                $meta->set('projectileType', $projectileType);
                $meta->set('shooterId', $shooter->getId());
            }
        }
        
        return $entityRef;
    }

    private function initializeEntity(EntityRef $entityRef, string $entityType): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $meta = $entity->get(MetadataComponent::class);
        if (!$meta) return;
        
        // Type-specific initialization
        match ($entityType) {
            'Zombie', 'Skeleton', 'Creeper', 'Spider' => $this->initHostileMob($entityRef),
            'Cow', 'Pig', 'Sheep', 'Chicken' => $this->initPassiveMob($entityRef),
            'Item' => $this->initItem($entityRef),
            default => null,
        };
    }

    private function initHostileMob(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $meta = $entity->get(MetadataComponent::class);
        if ($meta) {
            $meta->set('hostile', true);
            $meta->set('detectionRange', 16.0);
        }
    }

    private function initPassiveMob(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $meta = $entity->get(MetadataComponent::class);
        if ($meta) {
            $meta->set('passive', true);
        }
    }

    private function initItem(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $meta = $entity->get(MetadataComponent::class);
        if ($meta) {
            $meta->set('pickupDelay', 10); // 10 ticks before pickup
        }
    }
}