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
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\enum\EntityType;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\ProjectileRegistry;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driving\EventPort;
use function atan2;
use function sqrt;

final class EntitySpawnService {
    /**
     * Per-mob AI + combat stats, applied to the AIStateComponent at spawn so
     * the AISystem (12.1) can drive real behaviors without hard-coding types.
     *
     * @var array<string, array{health: int, damage: float, speed: float, follow: float, range: float, retreat: float}>
     */
    private const MOB_STATS = [
        // Hostile
        EntityType::Zombie->value => ['health' => 20, 'damage' => 3.0, 'speed' => 1.0, 'follow' => 16.0, 'range' => 2.0, 'retreat' => 0.0],
        EntityType::Skeleton->value => ['health' => 20, 'damage' => 2.5, 'speed' => 1.0, 'follow' => 24.0, 'range' => 3.0, 'retreat' => 0.0],
        EntityType::Creeper->value => ['health' => 20, 'damage' => 4.0, 'speed' => 1.1, 'follow' => 16.0, 'range' => 1.5, 'retreat' => 0.0],
        EntityType::Spider->value => ['health' => 16, 'damage' => 2.0, 'speed' => 1.4, 'follow' => 16.0, 'range' => 2.0, 'retreat' => 0.0],
        EntityType::Slime->value => ['health' => 8, 'damage' => 2.0, 'speed' => 1.0, 'follow' => 16.0, 'range' => 1.5, 'retreat' => 0.0],
        EntityType::Enderman->value => ['health' => 40, 'damage' => 7.0, 'speed' => 1.2, 'follow' => 32.0, 'range' => 2.0, 'retreat' => 0.0],
        EntityType::Silverfish->value => ['health' => 8, 'damage' => 1.0, 'speed' => 1.1, 'follow' => 12.0, 'range' => 1.5, 'retreat' => 0.0],
        EntityType::CaveSpider->value => ['health' => 12, 'damage' => 2.0, 'speed' => 1.6, 'follow' => 16.0, 'range' => 2.0, 'retreat' => 0.0],
        EntityType::PigZombie->value => ['health' => 20, 'damage' => 5.0, 'speed' => 1.1, 'follow' => 16.0, 'range' => 2.0, 'retreat' => 0.0],
        EntityType::Blaze->value => ['health' => 20, 'damage' => 5.0, 'speed' => 1.1, 'follow' => 24.0, 'range' => 3.0, 'retreat' => 0.0],
        EntityType::LavaSlime->value => ['health' => 16, 'damage' => 4.0, 'speed' => 1.0, 'follow' => 16.0, 'range' => 2.0, 'retreat' => 0.0],
        EntityType::Ghast->value => ['health' => 10, 'damage' => 6.0, 'speed' => 1.0, 'follow' => 32.0, 'range' => 6.0, 'retreat' => 0.0],
        EntityType::Witch->value => ['health' => 26, 'damage' => 3.0, 'speed' => 1.0, 'follow' => 16.0, 'range' => 3.0, 'retreat' => 0.0],
        EntityType::Stray->value => ['health' => 20, 'damage' => 2.5, 'speed' => 1.0, 'follow' => 24.0, 'range' => 3.0, 'retreat' => 0.0],
        EntityType::Husk->value => ['health' => 20, 'damage' => 3.0, 'speed' => 1.0, 'follow' => 16.0, 'range' => 2.0, 'retreat' => 0.0],
        EntityType::ZombieVillager->value => ['health' => 20, 'damage' => 3.0, 'speed' => 1.0, 'follow' => 16.0, 'range' => 2.0, 'retreat' => 0.0],
        // Passive / neutral - low speed, no attack, retreat when hurt
        EntityType::Cow->value => ['health' => 10, 'damage' => 0.0, 'speed' => 0.8, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
        EntityType::Pig->value => ['health' => 10, 'damage' => 0.0, 'speed' => 0.8, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
        EntityType::Sheep->value => ['health' => 8, 'damage' => 0.0, 'speed' => 0.8, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
        EntityType::Chicken->value => ['health' => 4, 'damage' => 0.0, 'speed' => 0.9, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
        EntityType::Villager->value => ['health' => 20, 'damage' => 0.0, 'speed' => 0.6, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.4],
        EntityType::Mooshroom->value => ['health' => 10, 'damage' => 0.0, 'speed' => 0.7, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
        EntityType::Squid->value => ['health' => 10, 'damage' => 0.0, 'speed' => 0.8, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
        EntityType::Rabbit->value => ['health' => 3, 'damage' => 0.0, 'speed' => 1.2, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
        EntityType::Bat->value => ['health' => 6, 'damage' => 0.0, 'speed' => 0.9, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.4],
        EntityType::Ocelot->value => ['health' => 10, 'damage' => 0.0, 'speed' => 1.0, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.4],
        EntityType::SnowGolem->value => ['health' => 4, 'damage' => 0.0, 'speed' => 0.6, 'follow' => 0.0, 'range' => 0.0, 'retreat' => 0.3],
        // Neutral guardians - defend when provoked (Wolf, IronGolem)
        EntityType::Wolf->value => ['health' => 8, 'damage' => 3.0, 'speed' => 1.1, 'follow' => 16.0, 'range' => 2.0, 'retreat' => 0.0],
        EntityType::IronGolem->value => ['health' => 100, 'damage' => 15.0, 'speed' => 0.8, 'follow' => 16.0, 'range' => 3.0, 'retreat' => 0.0],
    ];

    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
        private readonly EventPort $eventPort,
    ) {}

    public function spawnEntity(EntityType $entityType, float $x, float $y, float $z, float $yaw = 0, float $pitch = 0, array $metadata = [], int $worldId = 0): EntityRef {
        return $this->spawnBuilt(
            $entityType, $x, $y, $z, $yaw, $pitch, $metadata, $worldId,
            fn(EntityBuilder $b) => $b,
            false,
        );
    }

    public function spawnMob(EntityType $mobType, float $x, float $y, float $z, int $worldId = 0): EntityRef {
        // DragComponent is attached in the BUILDER, not post-spawn: old-src
        // Living drag = 0.02 (0.98 friction/tick), so a mob hit by knockback
        // decelerates and stops instead of sliding forever. PhysicsSystem
        // applies its drag path to any archetype carrying DragComponent.
        return $this->spawnBuilt(
            $mobType, $x, $y, $z, 0, 0, [], $worldId,
            function (EntityBuilder $b): EntityBuilder {
                return $b->with(new \pocketmine\core\component\DragComponent());
            },
            true,
        );
    }

    /**
     * Shared spawn pipeline: build (with caller extras), set type metadata,
     * run type-specific initialization, fire EntitySpawnEvent. Extras are
     * applied at build time so the entity is born into its final archetype -
     * post-spawn component adds force an archetype migration on the next
     * tick, which has historically been fragile.
     */
    private function spawnBuilt(EntityType $entityType, float $x, float $y, float $z, float $yaw, float $pitch, array $metadata, int $worldId, \Closure $extraComponents, bool $setMobType): EntityRef {
        $builder = $extraComponents(
            (new EntityBuilder())
                ->with(new PositionComponent($x, $y, $z))
                ->with(new RotationComponent($yaw, $pitch))
                ->with(new VelocityComponent())
                ->with(new HealthComponent())
                ->with(new MetadataComponent())
                ->with(new \pocketmine\core\component\WorldComponent($worldId))
        );
        $entityRef = $this->world->spawn($builder);

        $entity = $entityRef->getEntity();
        if ($entity) {
            // Set entity type metadata
            $meta = $entity->get(MetadataComponent::class);
            if ($meta) {
                $meta->set(MetadataKeys::ENTITY_TYPE, $entityType->value);
                if ($setMobType) {
                    $meta->set(MetadataKeys::MOB_TYPE, $entityType->value);
                }
                foreach ($metadata as $key => $value) {
                    $meta->set($key, $value);
                }
            }

            // Apply type-specific initialization
            $this->initializeEntity($entityRef, $entityType);
        }

        // Blocker 4: EntitySpawnEvent fires after initialization so the API
        // entity carries its type metadata (Entity::wrap dispatches on it).
        $this->eventPort->emit(new \pocketmine\api\event\EntitySpawnEvent(
            \pocketmine\api\entity\Entity::wrap($entityRef, $this->world),
        ));

        return $entityRef;
    }

    public function spawnItem(float $x, float $y, float $z, \pocketmine\core\component\ItemStack $item, int $worldId = 0): EntityRef {
        $entityRef = $this->world->spawn(
            (new EntityBuilder())
                ->with(new PositionComponent($x, $y, $z))
                ->with(new RotationComponent(0, 0))
                ->with(new VelocityComponent(
                    (mt_rand(-10, 10) / 5),
                    0.0,
                    (mt_rand(-10, 10) / 5)
                ))
                ->with(new HealthComponent(5, 5))
                ->with(new MetadataComponent())
                // A small box so drops fall with gravity and land on the
                // ground (BlockCollisionSystem) instead of sinking through.
                ->with(new CollisionComponent(width: 0.25, height: 0.25))
                ->with(new \pocketmine\core\component\WorldComponent($worldId))
                ->withTag(\pocketmine\core\constants\EntityTags::ITEM)
                ->with(new \pocketmine\core\component\DragComponent())

        );

        $entity = $entityRef->getEntity();
        if ($entity) {
            $meta = $entity->get(\pocketmine\core\component\MetadataComponent::class);
            if ($meta) {
                $meta->set(MetadataKeys::ENTITY_TYPE, 'item');
                $meta->set(MetadataKeys::ITEM, $item);
                // Vanilla MCPE pickupDelay: 40 ticks (2 s) so the item
                // lands before anyone can collect it.
                $meta->set(MetadataKeys::PICKUP_DELAY, 40);
            }
        }

        $this->eventPort->emit(new \pocketmine\api\event\EntitySpawnEvent(
            \pocketmine\api\entity\Entity::wrap($entityRef, $this->world),
        ));
        // Events breadth audit: ItemSpawnEvent alongside EntitySpawnEvent for
        // dropped items (plugins hooking item drops specifically).
        $this->eventPort->emit(new \pocketmine\api\event\ItemSpawnEvent(
            \pocketmine\api\entity\Entity::wrap($entityRef, $this->world),
        ));

        return $entityRef;
    }

    public function spawnProjectile(EntityType $projectileType, float $x, float $y, float $z, float $velX, float $velY, float $velZ, EntityRef $shooter): EntityRef {
        // The projectile registry is the single source of truth: an unregistered
        // type is a programming error (the client would have no entity id to
        // render, so the arrow could never be seen).
        $registry = $this->world->getResourceRegistry()->get(ProjectileRegistry::class);
        if (!$registry instanceof ProjectileRegistry || !$registry->isProjectile($projectileType->value)) {
            throw new \InvalidArgumentException("Unknown projectile type: {$projectileType->value}");
        }

        // 14.20: the projectile belongs to the shooter's world so the entity
        // broadcast filters it correctly when worlds differ.
        $shooterEntity = $shooter->getEntity();
        $shooterWorld = $shooterEntity?->get(\pocketmine\core\component\WorldComponent::class);
        $worldId = $shooterWorld instanceof \pocketmine\core\component\WorldComponent ? $shooterWorld->id : 0;

        $entityRef = $this->spawnEntity($projectileType, $x, $y, $z, 0, 0, [], $worldId);

        $entity = $entityRef->getEntity();
        if ($entity) {
            $velocity = $entity->get(VelocityComponent::class);
            if ($velocity) {
                $velocity->x = $velX;
                $velocity->y = $velY;
                $velocity->z = $velZ;
            }

            $meta = $entity->get(MetadataComponent::class);
            if ($meta) {
                $meta->set(MetadataKeys::PROJECTILE_TYPE, $projectileType->value);
                $meta->set(MetadataKeys::SHOOTER_ID, $shooter->getId());
            }

            // Legacy Projectile::onUpdate: a projectile renders pointing along
            // its motion vector (yaw = atan2(vx, vz), pitch = atan2(vy, |vxz|)),
            // so the client's first AddEntityPacket already shows the arrow
            // aimed correctly, and ArrowSystem keeps it aligned while flying.
            $rot = $entity->get(RotationComponent::class);
            if ($rot) {
                $f = sqrt($velX * $velX + $velZ * $velZ);
                $rot->yaw = atan2($velX, $velZ) * 180 / M_PI;
                $rot->pitch = atan2($velY, $f) * 180 / M_PI;
            }
        }

        return $entityRef;
    }

    /**
     * Spawn a lit PrimedTNT entity (14.22). The fuse counts down in
     * TNTExplosionSystem; the TNT entity renders as legacy PrimedTNT (network
     * id 65) and carries a small upward kick so it pops off the ground like
     * legacy, plus a collision box so it settles on terrain. Fuse defaults to
     * the legacy 80 ticks (4 seconds).
     */
    public function spawnPrimedTNT(float $x, float $y, float $z, int $worldId = 0, int $fuse = 80): EntityRef {
        $entityRef = $this->world->spawn(
            (new EntityBuilder())
                ->with(new PositionComponent($x + 0.5, $y, $z + 0.5))
                ->with(new RotationComponent(0, 0))
                ->with(new VelocityComponent(
                    (mt_rand(-10, 10) / 100),
                    0.2,
                    (mt_rand(-10, 10) / 100)
                ))
                ->with(new HealthComponent())
                ->with(new MetadataComponent())
                ->with(new CollisionComponent(width: 0.98, height: 0.98))
                ->with(new \pocketmine\core\component\WorldComponent($worldId))
                ->withTag(\pocketmine\core\constants\EntityTags::PRIMED_TNT)
        );

        $entity = $entityRef->getEntity();
        if ($entity) {
            $meta = $entity->get(MetadataComponent::class);
            if ($meta) {
                $meta->set(MetadataKeys::ENTITY_TYPE, EntityType::PrimedTNT->value);
                $meta->set(MetadataKeys::FUSE_TICKS, $fuse);
                $meta->set(MetadataKeys::FUSE_LENGTH, $fuse);
            }
        }

        $this->eventPort->emit(new \pocketmine\api\event\EntitySpawnEvent(
            \pocketmine\api\entity\Entity::wrap($entityRef, $this->world),
        ));

        // TNT prime sound
        $kernel = \pocketmine\Kernel::getInstance();
        $wes = $kernel?->getWorldEventService();
        if ($wes !== null) {
            $chunkX = (int)floor($x / 16);
            $chunkZ = (int)floor($z / 16);
            $wes->playSound($worldId, $chunkX, $chunkZ, $x + 0.5, $y, $z + 0.5, \pocketmine\core\service\WorldEventService::SOUND_TNT);
        }

        return $entityRef;
    }

    /**
     * 14.25: spawn a rideable vehicle (boat or minecart). Vehicles are plain
     * ECS entities tagged VEHICLE with a wide low collision box and the type
     * in metadata so the network renderer emits the right AddEntityPacket
     * (Boat 90 / Minecart 84) and VehicleSystem can drive them. A vehicle
     * starts riderless; right-clicking it mounts a player (link handled by
     * NetworkSessionService).
     */
    public function spawnVehicle(EntityType $vehicleType, float $x, float $y, float $z, int $worldId = 0, float $yaw = 0.0): EntityRef {
        if (!$vehicleType->isVehicle()) {
            throw new \InvalidArgumentException("Not a vehicle type: {$vehicleType->value}");
        }
        $width = $vehicleType === EntityType::Boat ? 1.6 : 0.98;
        $entityRef = $this->world->spawn(
            (new EntityBuilder())
                ->with(new PositionComponent($x + 0.5, $y, $z + 0.5))
                ->with(new RotationComponent($yaw, 0))
                ->with(new VelocityComponent())
                ->with(new HealthComponent(40, 40))
                ->with(new MetadataComponent())
                ->with(new CollisionComponent(width: $width, height: 0.7))
                ->with(new \pocketmine\core\component\WorldComponent($worldId))
                ->withTag(\pocketmine\core\constants\EntityTags::VEHICLE)
        );

        $entity = $entityRef->getEntity();
        if ($entity) {
            $meta = $entity->get(MetadataComponent::class);
            if ($meta) {
                $meta->set(MetadataKeys::ENTITY_TYPE, $vehicleType->value);
                $meta->set(MetadataKeys::VEHICLE_TYPE, $vehicleType->value);
            }
        }

        $this->eventPort->emit(new \pocketmine\api\event\EntitySpawnEvent(
            \pocketmine\api\entity\Entity::wrap($entityRef, $this->world),
        ));

        return $entityRef;
    }

    private function initializeEntity(EntityRef $entityRef, EntityType $entityType): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;

        $meta = $entity->get(MetadataComponent::class);
        if (!$meta) return;

        // Type-specific initialization
        if ($entityType->isHostile()) {
            $this->initHostileMob($entityRef, $entityType);
        } elseif ($entityType->isPassive()) {
            $this->initPassiveMob($entityRef, $entityType);
        } elseif ($entityType->isExplosive()) {
            // PrimedTNT is spawned through spawnPrimedTNT (not spawnEntity),
            // but keep the guard for API callers that go through spawnEntity.
            $meta->set(MetadataKeys::FUSE_TICKS, 80);
            $meta->set(MetadataKeys::FUSE_LENGTH, 80);
        } else {
            // Neutral guardians (Wolf, IronGolem) and projectiles: stats
            // only - no hostile/passive tag.
            $this->applyMobStats($entityRef, $entityType);
        }
    }

    private function applyMobStats(EntityRef $entityRef, EntityType $entityType): void {
        $stats = self::MOB_STATS[$entityType->value] ?? null;
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

    private function initHostileMob(EntityRef $entityRef, EntityType $entityType): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;

        $this->applyMobStats($entityRef, $entityType);

        $meta = $entity->get(MetadataComponent::class);
        if ($meta) {
            $meta->set(MetadataKeys::HOSTILE, true);
            $meta->set(MetadataKeys::DETECTION_RANGE, 16.0);
        }

        // MonsterTag marks the entity as a monster for queries/API wrapping.
        if (!$entity->has(MonsterTag::class)) {
            $entity->set(MonsterTag::class, new MonsterTag());
        }
    }

    private function initPassiveMob(EntityRef $entityRef, EntityType $entityType): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;

        $this->applyMobStats($entityRef, $entityType);

        $meta = $entity->get(MetadataComponent::class);
        if ($meta) {
            $meta->set(MetadataKeys::PASSIVE, true);
        }
    }
}
