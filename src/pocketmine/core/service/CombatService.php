<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\api\event\EntityDamageEvent;
use pocketmine\api\event\EntityDeathEvent;
use pocketmine\api\event\PlayerDeathEvent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\DeadTag;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\Hunger;
use pocketmine\port\driving\EventPort;

final class CombatService {
    /**
     * Simple mob loot table: entity type => drops. Each drop is
     * [itemId, minCount, maxCount, chance(0..1)]. Rolls are resolved at
     * death time in dropLoot().
     *
     * @var array<string, list<array{0: int, 1: int, 2: int, 3: float}>>
     */
    private const MOB_LOOT = [
        'Zombie' => [[367, 0, 2, 1.0], [263, 0, 1, 0.25]],   // rotten flesh, coal
        'Skeleton' => [[352, 0, 2, 1.0], [262, 0, 2, 0.5]],  // bone, arrow
        'Creeper' => [[289, 0, 2, 1.0]],                     // gunpowder
        'Spider' => [[287, 0, 2, 1.0], [375, 0, 1, 0.33]],   // string, spider eye
        'Cow' => [[363, 1, 3, 1.0], [334, 0, 2, 0.75]],      // raw beef, leather
        'Pig' => [[319, 1, 3, 1.0]],                         // raw porkchop
        'Sheep' => [[35, 1, 2, 1.0], [418, 1, 2, 1.0]],      // wool, mutton
        'Chicken' => [[365, 1, 1, 1.0], [288, 0, 2, 0.5]],   // raw chicken, feather
    ];

    public function __construct(
        private readonly World $world,
        private readonly EventPort $eventPort,
        private readonly EntitySpawnService $spawnService,
    ) {}

    /**
     * Apply damage through the full combat pipeline: cancellable damage
     * event -> armor reduction -> knockback -> health -> death handling.
     *
     * @return bool true when the damage was applied (not cancelled)
     */
    public function applyDamage(EntityRef $targetRef, float $damage, ?EntityRef $source = null, int $cause = EntityDamageEvent::CAUSE_CUSTOM): bool {
        $target = $targetRef->getEntity();
        if (!$target) return false;

        $health = $target->get(HealthComponent::class);
        if (!$health) return false;

        // Cancellable damage event: plugins can modify or cancel entirely.
        $event = new EntityDamageEvent(
            $this->wrapApiEntity($targetRef),
            $cause,
            $damage,
        );
        $this->eventPort->emit($event);
        if ($event->isCancelled()) {
            return false;
        }
        $damage = max(0.0, $event->getFinalDamage());

        // Apply damage reduction from armor
        $damage = $this->applyArmorReduction($targetRef, $damage, $cause);

        // Apply damage
        $health->current = max(0, $health->current - $damage);

        // 14.11: taking damage exhausts a player (legacy CAUSE_DAMAGE 0.3).
        // No-op for mobs (no HungerComponent).
        Hunger::exhaust($targetRef, 0.3);

        // Apply knockback if source exists
        if ($source && $damage > 0) {
            $this->applyKnockback($source, $targetRef, $damage);
        }

        // Check death
        if ($health->current <= 0) {
            $this->handleDeath($targetRef, $source);
        }

        return true;
    }

    public function heal(EntityRef $targetRef, float $amount): void {
        $target = $targetRef->getEntity();
        if (!$target) return;

        $health = $target->get(HealthComponent::class);
        if (!$health) return;

        $health->current = min($health->max, $health->current + $amount);
    }

    /**
     * Kill without going through the damage event (e.g. /kill, void damage
     * that bypasses armor, plugin-forced deaths). Fires the death events and
     * drops loot exactly like a fatal damage hit.
     */
    public function kill(EntityRef $targetRef, ?EntityRef $killer = null): void {
        // Zero health so DamageService::isAlive() (health-only check) agrees
        // with the api Entity::isAlive() (DeadTag + health) before despawn.
        $target = $targetRef->getEntity();
        if ($target) {
            $health = $target->get(HealthComponent::class);
            if ($health) {
                $health->current = 0;
            }
        }
        $this->handleDeath($targetRef, $killer);
    }

    public function applyKnockback(EntityRef $sourceRef, EntityRef $targetRef, float $force): void {
        $source = $sourceRef->getEntity();
        $target = $targetRef->getEntity();

        if (!$source || !$target) return;

        $sourcePos = $source->get(PositionComponent::class);
        $targetPos = $target->get(PositionComponent::class);
        $targetVel = $target->get(VelocityComponent::class);

        if (!$sourcePos || !$targetPos || !$targetVel) return;

        $dx = $targetPos->x - $sourcePos->x;
        $dz = $targetPos->z - $sourcePos->z;
        $dist = sqrt($dx * $dx + $dz * $dz);

        if ($dist > 0) {
            $knockback = $force * 0.4;
            $targetVel->x += ($dx / $dist) * $knockback;
            $targetVel->z += ($dz / $dist) * $knockback;
            $targetVel->y = $force * 0.2;
        }
    }

    private function applyArmorReduction(EntityRef $targetRef, float $damage, int $cause): float {
        $target = $targetRef->getEntity();
        if (!$target) return $damage;

        $inventory = $target->get(InventoryComponent::class);
        if (!$inventory) return $damage;

        $armorSlots = [5, 6, 7, 8]; // Helmet, Chestplate, Leggings, Boots
        $totalReduction = 0;

        foreach ($armorSlots as $slot) {
            $item = $inventory->get($slot);
            if ($item && $item->count > 0) {
                $reduction = $this->getArmorReduction($item->itemId);
                $totalReduction += $reduction;
            }
        }

        // Cap at 80% reduction
        $totalReduction = min(0.8, $totalReduction);

        return $damage * (1 - $totalReduction);
    }

    private function getArmorReduction(int $itemId): float {
        return match ($itemId) {
            302 => 0.04, // Leather helmet
            303 => 0.06, // Leather chestplate
            304 => 0.05, // Leather leggings
            305 => 0.02, // Leather boots
            306 => 0.06, // Chain helmet
            307 => 0.10, // Chain chestplate
            308 => 0.08, // Chain leggings
            309 => 0.04, // Chain boots
            310 => 0.08, // Iron helmet
            311 => 0.15, // Iron chestplate
            312 => 0.12, // Iron leggings
            313 => 0.06, // Iron boots
            314 => 0.08, // Gold helmet
            315 => 0.10, // Gold chestplate
            316 => 0.08, // Gold leggings
            317 => 0.04, // Gold boots
            318 => 0.12, // Diamond helmet
            319 => 0.20, // Diamond chestplate
            320 => 0.16, // Diamond leggings
            321 => 0.08, // Diamond boots
            default => 0,
        };
    }

    private function handleDeath(EntityRef $targetRef, ?EntityRef $killerRef): void {
        $target = $targetRef->getEntity();
        if (!$target) return;

        // Guard against re-entrancy: despawn is queued until the next tick, so
        // a second applyDamage/kill/setHealth on an entity already at 0 HP
        // would otherwise fire duplicate death events and double the loot.
        if ($target->has(DeadTag::class)) {
            return;
        }

        // Mark dead before the event so handlers observe a dead entity.
        $target->set(DeadTag::class, new DeadTag());

        $health = $target->get(HealthComponent::class);

        // Fired death events are cancellable: a plugin can keep the entity
        // alive (restore 1 HP) and skip drops/despawn.
        $isPlayer = $target->has(PlayerTag::class);
        if ($isPlayer) {
            $event = new PlayerDeathEvent(
                $this->wrapApiPlayer($targetRef),
                $killerRef !== null ? $this->wrapApiEntity($killerRef) : null,
                $this->buildDeathMessage($targetRef, $killerRef),
            );
        } else {
            $event = new EntityDeathEvent(
                $this->wrapApiEntity($targetRef),
                $killerRef !== null ? $this->wrapApiEntity($killerRef) : null,
            );
        }
        $this->eventPort->emit($event);

        if ($event->isCancelled()) {
            // Remove the death mark and restore health so the entity lives.
            $target->remove(DeadTag::class);
            if ($health) {
                $health->current = max(1, $health->current);
            }
            return;
        }

        // Drop experience
        $this->dropExperience($targetRef);

        // Drop loot (inventory contents + mob loot table)
        $this->dropLoot($targetRef);

        // Mobs despawn on death; players stay in the world as a dead entity
        // (DeadTag + 0 health) so PlayerRespawnService can revive them. This
        // mirrors legacy: the corpse remains visible until respawn.
        if (!$isPlayer) {
            $this->world->despawn($target);
        }
    }

    private function dropExperience(EntityRef $entityRef): void {
        // Spawn XP orbs
        $entity = $entityRef->getEntity();
        if (!$entity) return;

        $position = $entity->get(PositionComponent::class);
        if (!$position) return;

        // Calculate XP drop based on entity type
        $xpAmount = $this->getXpDrop($entityRef);

        $this->world->spawn(
            (new EntityBuilder())
                ->with(new PositionComponent($position->x, $position->y + 0.5, $position->z))
                ->with(new VelocityComponent())
                ->with(new HealthComponent(1, 1))
                ->with(new MetadataComponent(['xp' => $xpAmount]))
                ->withTag('xp_orb')
        );
    }

    private function getXpDrop(EntityRef $entityRef): int {
        $entity = $entityRef->getEntity();
        if (!$entity) return 5;

        $meta = $entity->get(MetadataComponent::class);
        $entityType = $meta?->get('entityType') ?? '';

        return match ($entityType) {
            'Zombie', 'Skeleton', 'Spider' => 5,
            'Creeper' => 5,
            'Cow', 'Pig', 'Sheep', 'Chicken' => 1 + mt_rand(0, 3),
            default => $entity->has(PlayerTag::class) ? 7 : 5,
        };
    }

    private function dropLoot(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;

        $position = $entity->get(PositionComponent::class);
        if (!$position) return;

        // 1. Drop inventory contents (players and any entity with inventory).
        $inventory = $entity->get(InventoryComponent::class);
        if ($inventory) {
            foreach ($inventory->getContents() as $item) {
                $this->dropItemStack($position, $item);
            }
            $inventory->clear();
        }

        // 2. Drop mob loot table rolls.
        $meta = $entity->get(MetadataComponent::class);
        $entityType = $meta?->get('entityType') ?? '';
        foreach (self::MOB_LOOT[$entityType] ?? [] as [$itemId, $min, $max, $chance]) {
            if (mt_rand() / mt_getrandmax() > $chance) {
                continue;
            }
            $count = $min >= $max ? $min : mt_rand($min, $max);
            if ($count > 0) {
                $this->dropItemStack($position, new ItemStack($itemId, 0, $count));
            }
        }
    }

    private function dropItemStack(PositionComponent $position, ItemStack $item): void {
        if ($item->count <= 0) {
            return;
        }
        // Small random scatter so stacked drops do not occupy the same spot.
        $x = $position->x + (mt_rand(-20, 20) / 100);
        $z = $position->z + (mt_rand(-20, 20) / 100);
        $this->spawnService->spawnItem($x, $position->y + 0.5, $z, $item);
    }

    private function buildDeathMessage(EntityRef $targetRef, ?EntityRef $killerRef): string {
        $target = $targetRef->getEntity();
        if (!$target) return '';

        $meta = $target->get(MetadataComponent::class);
        $name = (string)($meta?->get('username') ?? $meta?->get('displayName') ?? 'Entity');

        if ($killerRef !== null) {
            $killer = $killerRef->getEntity();
            $killerMeta = $killer?->get(MetadataComponent::class);
            $killerName = (string)($killerMeta?->get('username') ?? $killerMeta?->get('displayName') ?? 'Entity');
            return "$name was slain by $killerName";
        }
        return "$name died";
    }

    public function canEntityAttack(EntityRef $attackerRef, EntityRef $targetRef): bool {
        $attacker = $attackerRef->getEntity();
        $target = $targetRef->getEntity();

        if (!$attacker || !$target) return false;

        // Check if both are in PvP mode
        $attackerMeta = $attacker->get(MetadataComponent::class);
        $targetMeta = $target->get(MetadataComponent::class);

        if ($attackerMeta && $targetMeta) {
            $attackerPvP = $attackerMeta->get('pvpEnabled') ?? true;
            $targetPvP = $targetMeta->get('pvpEnabled') ?? true;

            if (!$attackerPvP || !$targetPvP) {
                return false;
            }
        }

        return true;
    }

    /**
     * Wrap a core EntityRef into its typed API entity. Entity::wrap takes the
     * core ECS World (not the API facade) as its second argument.
     */
    private function wrapApiEntity(EntityRef $ref): \pocketmine\api\entity\Entity {
        return \pocketmine\api\entity\Entity::wrap($ref, $this->world);
    }

    private function wrapApiPlayer(EntityRef $ref): \pocketmine\api\entity\Player {
        $entity = $this->wrapApiEntity($ref);
        return $entity instanceof \pocketmine\api\entity\Player
            ? $entity
            : new \pocketmine\api\entity\Player($ref, $this->world);
    }
}
