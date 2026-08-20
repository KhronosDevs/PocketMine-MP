<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;
use pocketmine\domain\ecs\EntityBuilder;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\RotationComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\component\HealthComponent;
use pocketmine\domain\component\MetadataComponent;
use pocketmine\domain\component\InventoryComponent;
use pocketmine\domain\component\AttributeComponent;
use pocketmine\domain\component\EffectComponent;
use pocketmine\domain\component\CollisionComponent;
use pocketmine\domain\component\tags\PlayerTag;
use pocketmine\domain\component\tags\MonsterTag;
use pocketmine\domain\component\tags\OnGroundTag;
use pocketmine\domain\component\tags\InvisibleTag;
use pocketmine\domain\component\tags\DeadTag;
use pocketmine\domain\component\tags\SpectatorTag;
use pocketmine\domain\service\EntitySpawnService;
use pocketmine\domain\service\EntityDespawnService;
use pocketmine\domain\service\EntityInteractionService;

abstract class Entity {
    protected EntityRef $ref;
    protected World $world;
    
    protected EntitySpawnService $spawnService;
    protected EntityDespawnService $despawnService;
    protected EntityInteractionService $interactionService;

    public function __construct(EntityRef $ref, World $world) {
        $this->ref = $ref;
        $this->world = $world;
        
        $kernel = \pocketmine\Kernel::getInstance();
        $this->spawnService = $kernel->getEntitySpawnService();
        $this->despawnService = $kernel->getEntityDespawnService();
        $this->interactionService = $kernel->getEntityInteractionService();
    }

    public function getId(): int {
        return $this->ref->getId();
    }

    public function getUniqueId(): string {
        return $this->ref->getUniqueId() ?? "entity_{$this->getId()}";
    }

    public function isValid(): bool {
        return $this->ref->isValid();
    }

    public function getPosition(): PositionComponent {
        return $this->ref->getPosition() ?? new PositionComponent();
    }

    public function getRotation(): RotationComponent {
        return $this->ref->getRotation() ?? new RotationComponent();
    }

    public function getVelocity(): VelocityComponent {
        return $this->ref->getVelocity() ?? new VelocityComponent();
    }

    public function getHealth(): HealthComponent {
        return $this->ref->getHealth() ?? new HealthComponent();
    }

    public function getMetadata(): MetadataComponent {
        return $this->ref->getMetadata() ?? new MetadataComponent();
    }

    public function getInventory(): InventoryComponent {
        return $this->ref->getInventory() ?? new InventoryComponent();
    }

    public function getAttributes(): AttributeComponent {
        return $this->ref->getAttributes() ?? new AttributeComponent();
    }

    public function getEffects(): EffectComponent {
        return $this->ref->getEffects() ?? new EffectComponent();
    }

    public function getCollision(): CollisionComponent {
        return $this->ref->getCollision() ?? new CollisionComponent();
    }

    public function isPlayer(): bool {
        return $this->ref->hasComponent(PlayerTag::class);
    }

    public function isMonster(): bool {
        return $this->ref->hasComponent(MonsterTag::class);
    }

    public function isOnGround(): bool {
        return $this->ref->hasComponent(OnGroundTag::class);
    }

    public function isInvisible(): bool {
        return $this->ref->hasComponent(InvisibleTag::class);
    }

    public function isDead(): bool {
        return $this->ref->hasComponent(DeadTag::class);
    }

    public function isSpectator(): bool {
        return $this->ref->hasComponent(SpectatorTag::class);
    }

    public function teleport(float $x, float $y, float $z, float $yaw = 0, float $pitch = 0): bool {
        return $this->ref->teleport($x, $y, $z, $yaw, $pitch);
    }

    public function damage(float $amount): bool {
        return $this->ref->damage($amount);
    }

    public function heal(float $amount): void {
        $this->ref->heal($amount);
    }

    public function setVelocity(float $x, float $y, float $z): void {
        $this->ref->setVelocity($x, $y, $z);
    }

    public function addVelocity(float $x, float $y, float $z): void {
        $this->ref->addVelocity($x, $y, $z);
    }

    public function getDistanceTo(Entity $other): float {
        return $this->ref->getDistanceTo($other->ref);
    }

    public function kill(): void {
        $this->despawnService->despawn($this->ref, true);
    }

    public function remove(): void {
        $this->despawnService->despawn($this->ref, false);
    }

    public function getInternalRef(): EntityRef {
        return $this->ref;
    }

    public static function create(string $type, float $x, float $y, float $z): self {
        $kernel = \pocketmine\Kernel::getInstance();
        $spawnService = $kernel->getEntitySpawnService();
        
        $entityRef = $spawnService->spawnEntity($type, func_get_arg(1), func_get_arg(2), func_get_arg(3));
        
        $kernel = \pocketmine\Kernel::getInstance();
        $world = $kernel->getWorld();
        
        return new static($entityRef, $world);
    }
}