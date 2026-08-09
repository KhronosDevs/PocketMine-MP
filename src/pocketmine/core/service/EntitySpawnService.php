<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\AIStateComponent;
use pocketmine\core\component\CollisionComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\MonsterTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\port\driven\StoragePort;

final class EntitySpawnService {
    /**
     * Per-mob AI + combat stats, applied to the AIStateComponent at spawn so
     * the AISystem (12.1) can drive real behaviors without hard-coding types.
     *
     * @var array<string, array{health: int, damage: float, speed: float, follow: float, range: float, retreat: float}>
     */
    private const MOB_STATS = [
        // Hostile
        'Zombie' => ['health' => 20, 'damage' => 3.0, 'speed' => 1.0, 'follow' => 16.0, 'range' => 2.0, 'retreat' => 0.0],
        'Skeleton' => ['health' => 20, 'damage' => 2.5, 'speed' => 1.0, 'follow' => 24.0, 'range' => 3.0, 'retreat' => 0.0],
        'Creeper' => ['health' => 20, 'damage' => 4.0, 'speed' => 1.1, 'follow' => 16.0, 'range' => 1.5, 'retreat' => 0.0],
        'Spider' => ['health' => 16, 'damage' => 2.0, 'speed' => 1.4, 'follow' => 16.0, 'range' => 2.0, 'retreat' => 0.0],
        // Passive - low speed, no attack, retreat when hurt
        'Cow' => ['health' => 10, 'damage' => 0.0, 'speed' => 0.8, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
        'Pig' => ['health' => 10, 'damage' => 0.0, 'speed' => 0.8, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
        'Sheep' => ['health' => 8, 'damage' => 0.0, 'speed' => 0.8, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
        'Chicken' => ['health' => 4, 'damage' => 0.0, 'speed' => 0.9, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
    ];

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

    public function spawnItem(float $x, float $y, float $z, \pocketmine\core\component\ItemStack $item): EntityRef {
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
                // A small box so drops fall with gravity and land on the
                // ground (BlockCollisionSystem) instead of sinking through.
                ->with(new CollisionComponent(width: 0.25, height: 0.25))
                ->withTag('item')

        );

        $entity = $entityRef->getEntity();
        if ($entity) {
            $meta = $entity->get(\pocketmine\core\component\MetadataComponent::class);
            if ($meta) {
                $meta->set('item', $item);
                // Legacy pickupDelay: a fresh drop is uncollectable for 10
                // ticks so it cannot instantly re-enter the thrower's own
                // inventory (the ItemPickupSystem honours this countdown).
                $meta->set('pickupDelay', 10);
            }
        }

        return $entityRef;
    }

    public function spawnProjectile(string $projectileType, float $x, float $y, float $z, float $velX, float $velY, float $velZ, EntityRef $shooter): EntityRef {
        $entityRef = $this->spawnEntity($projectileType, $x, $y, $z);

        $entity = $entityRef->getEntity();
        if ($entity) {
            $velocity = $entity->get(\pocketmine\core\component\VelocityComponent::class);
            if ($velocity) {
                $velocity->x = $velX;
                $velocity->y = $velY;
                $velocity->z = $velZ;
            }

            $meta = $entity->get(\pocketmine\core\component\MetadataComponent::class);
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
            'Zombie', 'Skeleton', 'Creeper', 'Spider' => $this->initHostileMob($entityRef, $entityType),
            'Cow', 'Pig', 'Sheep', 'Chicken' => $this->initPassiveMob($entityRef, $entityType),
            'Item' => $this->initItem($entityRef),
            default => null,
        };
    }

    private function applyMobStats(EntityRef $entityRef, string $entityType): void {
        $stats = self::MOB_STATS[$entityType] ?? null;
        if ($stats === null) return;

        $entity = $entityRef->getEntity();
        if (!$entity) return;

        // Health from the stat table (mobs previously all had the default 20).
        $health = $entity->get(HealthComponent::class);
        if ($health) {
            $health->max = $stats['health'];
            $health->current = $stats['health'];
        }

        // AI state drives the AISystem - without this the entity is invisible
        // to AI processing even though it has 'hostile' metadata.
        $ai = $entity->get(AIStateComponent::class);
        if ($ai === null) {
            $ai = new AIStateComponent();
            $entity->set(AIStateComponent::class, $ai);
        }

        // A standard 0.6x1.8 mob box so BlockCollisionSystem keeps the mob
        // out of walls and standing on the terrain (previously mobs drifted
        // through blocks).
        if (!$entity->has(CollisionComponent::class)) {
            $entity->set(CollisionComponent::class, new CollisionComponent());
        }
        $ai->attackDamage = $stats['damage'];
        $ai->speedModifier = $stats['speed'];
        $ai->followRange = $stats['follow'];
        $ai->attackRange = $stats['range'];
        $ai->retreatHealthPercent = $stats['retreat'];
    }

    private function initHostileMob(EntityRef $entityRef, string $entityType): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;

        $this->applyMobStats($entityRef, $entityType);

        $meta = $entity->get(MetadataComponent::class);
        if ($meta) {
            $meta->set('hostile', true);
            $meta->set('detectionRange', 16.0);
        }

        // MonsterTag marks the entity as a monster for queries/API wrapping.
        if (!$entity->has(MonsterTag::class)) {
            $entity->set(MonsterTag::class, new MonsterTag());
        }
    }

    private function initPassiveMob(EntityRef $entityRef, string $entityType): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;

        $this->applyMobStats($entityRef, $entityType);

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
