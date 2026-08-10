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
use pocketmine\core\resource\ItemRegistry;
use pocketmine\Kernel;
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

        // Creative players are immune to attacks (legacy: no damage taken,
        // mobs never target them either). /kill goes through kill() directly,
        // so command kills keep working in creative.
        if ($cause === EntityDamageEvent::CAUSE_ENTITY_ATTACK) {
            $meta = $target->get(\pocketmine\core\component\MetadataComponent::class);
            if ($meta !== null && ($meta->get('gamemode') ?? 0) === 1) {
                return false;
            }
        }

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

        // 14.13: a landed attack wears the target's armor (fall/void/custom
        // damage does not - legacy only damaged gear on entity attacks).
        if ($cause === EntityDamageEvent::CAUSE_ENTITY_ATTACK && $damage > 0) {
            $this->wearArmorOnHit($targetRef);
        }

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

        // Armor window slots map to inventory slots 36-39: helmet, chestplate,
        // leggings, boots (see InventoryComponent::ARMOR_OFFSET).
        $totalReduction = 0;

        for ($i = 0; $i < 4; $i++) {
            $item = $inventory->get(InventoryComponent::ARMOR_OFFSET + $i);
            if ($item && $item->count > 0) {
                $totalReduction += $this->getArmorReduction($item->itemId);
            }
        }

        // Cap at 80% reduction
        $totalReduction = min(0.8, $totalReduction);

        return $damage * (1 - $totalReduction);
    }

    private function getArmorReduction(int $itemId): float {
        return match ($itemId) {
            298 => 0.04, // Leather helmet
            299 => 0.06, // Leather chestplate
            300 => 0.05, // Leather leggings
            301 => 0.02, // Leather boots
            302 => 0.04, // Chain helmet
            303 => 0.06, // Chain chestplate
            304 => 0.05, // Chain leggings
            305 => 0.02, // Chain boots
            306 => 0.08, // Iron helmet
            307 => 0.15, // Iron chestplate
            308 => 0.12, // Iron leggings
            309 => 0.06, // Iron boots
            310 => 0.12, // Diamond helmet
            311 => 0.20, // Diamond chestplate
            312 => 0.16, // Diamond leggings
            313 => 0.08, // Diamond boots
            314 => 0.08, // Gold helmet
            315 => 0.10, // Gold chestplate
            316 => 0.08, // Gold leggings
            317 => 0.04, // Gold boots
            default => 0,
        };
    }

    /**
     * 14.13: an entity-attack hit wears one random worn piece by 3 durability
     * points, breaking it (and removing it) at its max durability. Mirrors
     * legacy ArmorInventory::damage (mt_rand(1, 3) !== 1 gate + 3 per hit).
     */
    private function wearArmorOnHit(EntityRef $targetRef): void {
        $target = $targetRef->getEntity();
        if (!$target) return;

        $inventory = $target->get(InventoryComponent::class);
        if (!$inventory) return;

        // Two hits in three actually wear (legacy random gate).
        if (mt_rand(1, 3) === 1) {
            return;
        }

        $worn = [];
        for ($i = 0; $i < 4; $i++) {
            $item = $inventory->get(InventoryComponent::ARMOR_OFFSET + $i);
            if ($item !== null && $item->count > 0) {
                $worn[] = $i;
            }
        }
        if ($worn === []) {
            return;
        }

        $piece = $worn[array_rand($worn)];
        $slot = InventoryComponent::ARMOR_OFFSET + $piece;
        $item = $inventory->get($slot);
        if ($item === null) {
            return;
        }

        $maxDurability = $this->world->getResourceRegistry()->get(ItemRegistry::class)
            ?->getMaxDurability($item->itemId) ?? 0;
        if ($maxDurability <= 0) {
            return; // non-durable items in armor slots never wear
        }
        $newDamage = $item->meta + 3;

        if ($newDamage >= $maxDurability) {
            $inventory->set($slot, null); // the piece breaks and is removed
        } else {
            $inventory->set($slot, new ItemStack($item->itemId, $newDamage, $item->count, $item->nbt));
        }

        // Reflect the change on the wire: armor window + HurtArmor + the
        // MobArmorEquipment broadcast everyone (including the actor) sees.
        $sessionService = Kernel::getInstance()?->getNetworkSessionService();
        $sessionService?->syncArmorFor($target->id);
        $sessionService?->sendHurtArmorFor($target->id, min(20, $newDamage));
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

        // 14.20: XP orbs belong to the dead entity's world.
        $deadWorld = $entity->get(\pocketmine\core\component\WorldComponent::class);
        $worldId = $deadWorld instanceof \pocketmine\core\component\WorldComponent ? $deadWorld->id : 0;

        $this->world->spawn(
            (new EntityBuilder())
                ->with(new PositionComponent($position->x, $position->y + 0.5, $position->z))
                ->with(new VelocityComponent())
                ->with(new HealthComponent(1, 1))
                ->with(new MetadataComponent(['xp' => $xpAmount]))
                ->with(new \pocketmine\core\component\WorldComponent($worldId))
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
        $worldId = $this->worldIdOf($entityRef);
        foreach (self::MOB_LOOT[$entityType] ?? [] as [$itemId, $min, $max, $chance]) {
            if (mt_rand() / mt_getrandmax() > $chance) {
                continue;
            }
            $count = $min >= $max ? $min : mt_rand($min, $max);
            if ($count > 0) {
                $this->dropItemStack($position, new ItemStack($itemId, 0, $count), $worldId);
            }
        }
    }

    private function dropItemStack(PositionComponent $position, ItemStack $item, int $worldId = 0): void {
        if ($item->count <= 0) {
            return;
        }
        // Small random scatter so stacked drops do not occupy the same spot.
        $x = $position->x + (mt_rand(-20, 20) / 100);
        $z = $position->z + (mt_rand(-20, 20) / 100);
        // 14.20: loot stays in the dead entity's world.
        $this->spawnService->spawnItem($x, $position->y + 0.5, $z, $item, $worldId);
    }

    private function worldIdOf(EntityRef $entityRef): int {
        $entity = $entityRef->getEntity();
        $worldComponent = $entity?->get(\pocketmine\core\component\WorldComponent::class);
        return $worldComponent instanceof \pocketmine\core\component\WorldComponent ? $worldComponent->id : 0;
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
