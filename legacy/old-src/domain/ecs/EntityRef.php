<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

use pocketmine\domain\component\Component;
use pocketmine\domain\component\MetadataComponent;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\RotationComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\component\HealthComponent;
use pocketmine\domain\component\CollisionComponent;

/**
 * Opaque entity reference for plugin API.
 * Provides stable identity across threads and migrations.
 * Plugins use this instead of direct Entity access.
 */
final class EntityRef {
    private static array $idToRef = [];

    public function __construct(
        public readonly int $entityId,
        private readonly World $world,
    ) {}

    public static function create(int $entityId, World $world): self {
        return self::$idToRef[$entityId] ??= new self($entityId, $world);
    }

    public static function get(int $entityId): ?self {
        return self::$idToRef[$entityId] ?? null;
    }

    public static function remove(int $entityId): void {
        unset(self::$idToRef[$entityId]);
    }

    public function getId(): int {
        return $this->entityId;
    }

    public function isValid(): bool {
        return $this->world->getEntity($this->entityId) !== null;
    }

    public function getEntity(): ?Entity {
        return $this->world->getEntity($this->entityId);
    }

    // Component access (read-only for plugins)
    public function getPosition(): ?PositionComponent {
        return $this->getComponent(PositionComponent::class);
    }

    public function getRotation(): ?RotationComponent {
        return $this->getComponent(RotationComponent::class);
    }

    public function getVelocity(): ?VelocityComponent {
        return $this->getComponent(VelocityComponent::class);
    }

    public function getHealth(): ?HealthComponent {
        return $this->getComponent(HealthComponent::class);
    }

    public function getCollision(): ?CollisionComponent {
        return $this->getComponent(CollisionComponent::class);
    }

    public function getMetadata(): ?MetadataComponent {
        return $this->getComponent(MetadataComponent::class);
    }

    public function getComponent(string $type): ?Component {
        $entity = $this->getEntity();
        return $entity?->get($type);
    }

    public function hasComponent(string $type): bool {
        $entity = $this->getEntity();
        return $entity?->has($type) ?? false;
    }

    // Mutable operations (for server internals)
    public function setComponent(string $type, Component $component): void {
        $entity = $this->getEntity();
        if ($entity) {
            $entity->set($type, $component);
        }
    }

    public function removeComponent(string $type): void {
        $entity = $this->getEntity();
        if ($entity) {
            $entity->remove($type);
        }
    }

    // Convenience methods
    public function teleport(float $x, float $y, float $z, float $yaw = 0.0, float $pitch = 0.0): bool {
        $entity = $this->getEntity();
        if (!$entity) return false;

        $pos = $entity->get(PositionComponent::class) ?? new PositionComponent();
        $pos->x = $x;
        $pos->y = $y;
        $pos->z = $z;
        $pos->yaw = $yaw;
        $pos->pitch = $pitch;
        $entity->set(PositionComponent::class, $pos);

        $rot = $entity->get(RotationComponent::class) ?? new RotationComponent();
        $rot->setYaw($yaw);
        $rot->setPitch($pitch);
        $entity->set(RotationComponent::class, $rot);

        return true;
    }

    public function damage(float $amount): bool {
        $health = $this->getHealth();
        if (!$health) return false;
        $health->current = max(0, $health->current - $amount);
        return $health->current <= 0;
    }

    public function heal(float $amount): void {
        $health = $this->getHealth();
        if ($health) {
            $health->current = min($health->max, $health->current + $amount);
        }
    }

    public function setVelocity(float $x, float $y, float $z): void {
        $vel = $this->getVelocity() ?? new VelocityComponent();
        $vel->x = $x;
        $vel->y = $y;
        $vel->z = $z;
        $this->setComponent(VelocityComponent::class, $vel);
    }

    public function addVelocity(float $x, float $y, float $z): void {
        $vel = $this->getVelocity() ?? new VelocityComponent();
        $vel->x += $x;
        $vel->y += $y;
        $vel->z += $z;
        $this->setComponent(VelocityComponent::class, $vel);
    }

    public function getDistanceTo(EntityRef $other): float {
        $a = $this->getPosition();
        $b = $other->getPosition();
        if (!$a || !$b) return INF;
        $dx = $a->x - $b->x;
        $dy = $a->y - $b->y;
        $dz = $a->z - $b->z;
        return sqrt($dx * $dx + $dy * $dy + $dz * $dz);
    }
}