<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\AttributeComponent;
use pocketmine\core\component\EffectComponent;
use pocketmine\core\component\CollisionComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\component\tags\MonsterTag;
use pocketmine\core\component\tags\OnGroundTag;
use pocketmine\core\component\tags\InvisibleTag;
use pocketmine\core\component\tags\DeadTag;
use pocketmine\core\component\tags\SpectatorTag;
use pocketmine\core\service\EntitySpawnService;
use pocketmine\core\service\EntityDespawnService;
use pocketmine\core\service\EntityInteractionService;

class Entity {
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

    public function getHealth(): float {
        return $this->ref->getHealth()?->current ?? 0.0;
    }

    public function getHealthComponent(): ?HealthComponent {
        return $this->ref->getHealth();
    }

    public function getMaxHealth(): float {
        return $this->getHealthComponent()?->max ?? 20.0;
    }

    public function setMaxHealth(float $health): void {
        $comp = $this->getHealthComponent();
        if ($comp) {
            $comp->max = max(1, $health);
            if ($comp->current > $comp->max) {
                $comp->current = $comp->max;
            }
        }
    }

    /**
     * Wrap an EntityRef into the most specific API entity subclass based on tags/metadata.
     */
    public static function wrap(EntityRef $ref, World $world): Entity {
        if ($ref->hasComponent(PlayerTag::class)) {
            return new Player($ref, $world);
        }
        $type = strtolower((string)($ref->getMetadata()?->get(\pocketmine\core\constants\MetadataKeys::ENTITY_TYPE, '')));
        return match ($type) {
            'zombie' => new Zombie($ref, $world),
            'skeleton' => new Skeleton($ref, $world),
            'creeper' => new Creeper($ref, $world),
            'pig' => new Pig($ref, $world),
            'item' => new ItemEntity($ref, $world),
            default => new Entity($ref, $world),
        };
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
        // Route through the unified death pipeline: death events, loot drops,
        // XP orbs, then despawn. (Entity::remove() remains the silent path.)
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel !== null) {
            $kernel->getCombatService()->kill($this->ref);
            return;
        }
        $this->despawnService->despawn($this->ref, true);
    }

    public function remove(): void {
        $this->despawnService->despawn($this->ref, false);
    }

    public function getInternalRef(): EntityRef {
        return $this->ref;
    }
}