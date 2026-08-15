<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\CollisionComponent;
use pocketmine\core\component\AIStateComponent;
use pocketmine\core\component\PathComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\AttributeComponent;
use pocketmine\core\component\EffectComponent;

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

    public function getUniqueId(): ?string {
        $metadata = $this->getMetadata();
        $value = $metadata?->get(\pocketmine\core\constants\MetadataKeys::UNIQUE_ID);
        return is_string($value) ? $value : null;
    }

    public function isValid(): bool {
        return $this->world->getEntity($this->entityId) !== null;
    }

    public function getEntity(): ?Entity {
        return $this->world->getEntity($this->entityId);
    }

    // Component access (read-only for plugins)
    public function getPosition(): ?PositionComponent {
        $c = $this->getComponent(PositionComponent::class);
        return $c instanceof PositionComponent ? $c : null;
    }

    public function getRotation(): ?RotationComponent {
        $c = $this->getComponent(RotationComponent::class);
        return $c instanceof RotationComponent ? $c : null;
    }

    public function getVelocity(): ?VelocityComponent {
        $c = $this->getComponent(VelocityComponent::class);
        return $c instanceof VelocityComponent ? $c : null;
    }

    public function getHealth(): ?HealthComponent {
        $c = $this->getComponent(HealthComponent::class);
        return $c instanceof HealthComponent ? $c : null;
    }

    public function getCollision(): ?CollisionComponent {
        $c = $this->getComponent(CollisionComponent::class);
        return $c instanceof CollisionComponent ? $c : null;
    }

    public function getMetadata(): ?MetadataComponent {
        $c = $this->getComponent(MetadataComponent::class);
        return $c instanceof MetadataComponent ? $c : null;
    }

    public function getAIState(): ?AIStateComponent {
        $c = $this->getComponent(AIStateComponent::class);
        return $c instanceof AIStateComponent ? $c : null;
    }

    public function getPath(): ?PathComponent {
        $c = $this->getComponent(PathComponent::class);
        return $c instanceof PathComponent ? $c : null;
    }

    public function getInventory(): ?InventoryComponent {
        $c = $this->getComponent(InventoryComponent::class);
        return $c instanceof InventoryComponent ? $c : null;
    }

    public function getAttributes(): ?AttributeComponent {
        $c = $this->getComponent(AttributeComponent::class);
        return $c instanceof AttributeComponent ? $c : null;
    }

    public function getEffects(): ?EffectComponent {
        $c = $this->getComponent(EffectComponent::class);
        return $c instanceof EffectComponent ? $c : null;
    }

    public function getComponent(string $type): ?object {
        $entity = $this->getEntity();
        $value = $entity?->get($type);
        return is_object($value) ? $value : null;
    }

    public function hasComponent(string $type): bool {
        $entity = $this->getEntity();
        return $entity?->has($type) ?? false;
    }

    // Mutable operations (for server internals)
    public function setComponent(string $type, object $component): void {
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