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
use pocketmine\core\constants\ItemIds;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\enum\EntityType;
use pocketmine\core\enum\GameMode;
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
        EntityType::Zombie->value => [[ItemIds::ROTTEN_FLESH, 0, 2, 1.0], [ItemIds::COAL, 0, 1, 0.25]],
        EntityType::Skeleton->value => [[ItemIds::BONE, 0, 2, 1.0], [ItemIds::ARROW, 0, 2, 0.5]],
        EntityType::Creeper->value => [[ItemIds::GUNPOWDER, 0, 2, 1.0]],
        EntityType::Spider->value => [[ItemIds::STRING, 0, 2, 1.0], [ItemIds::SPIDER_EYE, 0, 1, 0.33]],
        EntityType::Slime->value => [[ItemIds::SLIME_BALL, 0, 2, 1.0]],
        EntityType::Enderman->value => [[ItemIds::ENDER_PEARL, 0, 1, 0.5]],
        EntityType::Silverfish->value => [],
        EntityType::CaveSpider->value => [[ItemIds::STRING, 0, 2, 1.0], [ItemIds::SPIDER_EYE, 0, 1, 0.33]],
        EntityType::PigZombie->value => [[ItemIds::ROTTEN_FLESH, 0, 2, 1.0], [ItemIds::GOLD_INGOT, 0, 1, 0.25]],
        EntityType::Blaze->value => [[ItemIds::BLAZE_ROD, 0, 1, 1.0]],
        EntityType::LavaSlime->value => [],
        EntityType::Ghast->value => [[ItemIds::GUNPOWDER, 0, 2, 1.0]],
        EntityType::Witch->value => [[ItemIds::GLOWSTONE_DUST, 0, 2, 0.5], [ItemIds::REDSTONE, 0, 2, 0.5], [ItemIds::GUNPOWDER, 0, 2, 0.5]],
        EntityType::Stray->value => [[ItemIds::BONE, 0, 2, 1.0], [ItemIds::ARROW, 0, 2, 0.5]],
        EntityType::Husk->value => [[ItemIds::ROTTEN_FLESH, 0, 2, 1.0]],
        EntityType::ZombieVillager->value => [[ItemIds::ROTTEN_FLESH, 0, 2, 1.0]],
        EntityType::Cow->value => [[ItemIds::RAW_BEEF, 1, 3, 1.0], [ItemIds::LEATHER, 0, 2, 0.75]],
        EntityType::Pig->value => [[ItemIds::RAW_PORKCHOP, 1, 3, 1.0]],
        EntityType::Sheep->value => [[ItemIds::WOOL, 1, 2, 1.0], [ItemIds::MUTTON, 1, 2, 1.0]],
        EntityType::Chicken->value => [[ItemIds::RAW_CHICKEN, 1, 1, 1.0], [ItemIds::FEATHER, 0, 2, 0.5]],
        EntityType::Villager->value => [],
        EntityType::Mooshroom->value => [[ItemIds::RAW_BEEF, 1, 3, 1.0], [ItemIds::LEATHER, 0, 2, 0.75]],
        EntityType::Squid->value => [[ItemIds::INK_SAC, 0, 3, 1.0]],
        EntityType::Rabbit->value => [[ItemIds::RABBIT_HIDE, 0, 1, 0.75], [ItemIds::RABBIT_FOOT, 0, 1, 0.1]],
        EntityType::Bat->value => [],
        EntityType::Ocelot->value => [],
        EntityType::Wolf->value => [],
        EntityType::IronGolem->value => [[ItemIds::IRON_INGOT, 3, 5, 1.0]],
        EntityType::SnowGolem->value => [[ItemIds::SNOWBALL, 0, 15, 1.0]],
    ];

    public function __construct(
        private readonly World $world,
        private readonly EventPort $eventPort,
        private readonly EntitySpawnService $spawnService,
    ) {}

    /** @var array<int, array{tick: int, amount: float}> per-entity last-damage state (old-src noDamageTicks). */
    private array $lastDamage = [];

    /**
     * old-src Living::attack() invulnerability: for NO_DAMAGE_TICKS after
     * taking damage, equal-or-weaker damage is cancelled entirely. Legacy
     * carried this not just as game feel but as robustness - retried or
     * duplicated attack packets within the window deal nothing. Only a
     * strictly stronger hit pierces it (and restarts the window).
     */
    private const NO_DAMAGE_TICKS = 10;

    private function isInvulnerable(EntityRef $targetRef, float $damage): bool {
        if ($damage <= 0.0) {
            return false;
        }
        $tick = \pocketmine\Kernel::getInstance()?->getResourceRegistry()?->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0;
        $last = $this->lastDamage[$targetRef->getId()] ?? null;
        if ($last !== null && $tick - $last['tick'] < self::NO_DAMAGE_TICKS && $damage <= $last['amount']) {
            return true;
        }
        return false;
    }

    private function recordDamage(EntityRef $targetRef, float $finalDamage): void {
        $tick = \pocketmine\Kernel::getInstance()?->getResourceRegistry()?->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0;
        $this->lastDamage[$targetRef->getId()] = ['tick' => $tick, 'amount' => $finalDamage];
    }

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

        // old-src invulnerability window: equal-or-weaker damage inside the
        // no-damage window is cancelled before anything else runs.
        if ($this->isInvulnerable($targetRef, $damage)) {
            return false;
        }

        // Creative players are immune to attacks (legacy: no damage taken,
        // mobs never target them either). /kill goes through kill() directly,
        // so command kills keep working in creative.
        if ($cause === EntityDamageEvent::CAUSE_ENTITY_ATTACK) {
            $meta = $target->get(\pocketmine\core\component\MetadataComponent::class);
            if ($meta !== null && GameMode::coerce($meta->get(MetadataKeys::GAMEMODE)) === GameMode::Creative) {
                return false;
            }
        }

        // Spectators take no damage from any source (legacy isSpectator
        // semantics: untargetable, unhittable, untouchable).
        $meta = $target->get(\pocketmine\core\component\MetadataComponent::class);
        if ($meta !== null && GameMode::coerce($meta->get(MetadataKeys::GAMEMODE)) === GameMode::Spectator) {
            return false;
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

        // Damage landed: open the no-damage window at this strength.
        $this->recordDamage($targetRef, $damage);

        // Apply damage reduction from armor
        $damage = $this->applyArmorReduction($targetRef, $damage, $cause);

        // Apply damage
        $health->current = max(0, $health->current - $damage);

        // Play hurt sound
        if ($damage > 0) {
            $this->playHurtEffect($targetRef);
        }

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

        if ($dist <= 0) {
            return;
        }

        // old-src Living::knockBack(): halve the existing motion before
        // adding the impulse (repeated hits cannot accumulate without
        // bound) and cap the vertical component at the base force.
        // $base is the FIXED 0.4 blocks/tick from the old-src
        // EntityDamageByEntityEvent default - it does not scale with damage.
        // VelocityComponent is in blocks/second; the legacy constants are
        // blocks/tick, so work per-tick and scale back by 20 on store.
        $base = 0.4;
        $vx = ($targetVel->x / 20.0) * 0.5 + ($dx / $dist) * $base;
        $vz = ($targetVel->z / 20.0) * 0.5 + ($dz / $dist) * $base;
        $vy = min(($targetVel->y / 20.0) * 0.5 + $base, $base);
        $targetVel->x = $vx * 20.0;
        $targetVel->y = $vy * 20.0;
        $targetVel->z = $vz * 20.0;

        // Tell the victim's client to simulate the knockback locally
        // (old-src Player::setMotion sends SetEntityMotionPacket to self).
        // The wire carries blocks/tick. Without this the client never feels
        // the hit: server-side velocity no longer moves players at all
        // (MovementSystem excludes them), so the packet is the whole effect.
        Kernel::getInstance()?->getNetworkSessionService()?->sendEntityMotionTo(
            $targetRef->getId(),
            $vx,
            $vy,
            $vz,
        );
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

        // 14.22: a creeper explodes on death (legacy Creeper::explode). The
        // blast is the same ray-based TNTExplosionSystem, sized to the creeper
        // and attributed to the killer; the creeper drops no loot afterwards.
        $meta = $target->get(MetadataComponent::class);
        $isCreeper = $meta?->get(MetadataKeys::ENTITY_TYPE) === EntityType::Creeper->value
            || $meta?->get(MetadataKeys::MOB_TYPE) === EntityType::Creeper->value;
        if ($isCreeper) {
            $this->triggerCreeperExplosion($targetRef, $killerRef);
            $this->world->despawn($target);
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
            // Death smoke particle at mob position (legacy: Entity::despawn
            // fires DestroyBlockParticle at the entity's position)
            $pos = $target->get(\pocketmine\core\component\PositionComponent::class);
            if ($pos !== null) {
                $kernel = \pocketmine\Kernel::getInstance();
                $wes = $kernel?->getWorldEventService();
                if ($wes !== null) {
                    $worldId = $target->get(\pocketmine\core\component\WorldComponent::class)?->id ?? 0;
                    $chunkX = (int)floor($pos->x / 16);
                    $chunkZ = (int)floor($pos->z / 16);
                    $wes->spawnSmokeParticle($worldId, $chunkX, $chunkZ, $pos->x, $pos->y + 0.5, $pos->z, 3);
                }
            }
            $this->world->despawn($target);
        }
    }

    private function triggerCreeperExplosion(EntityRef $creeperRef, ?EntityRef $killerRef): void {
        $entity = $creeperRef->getEntity();
        $pos = $entity?->get(PositionComponent::class);
        if ($pos === null) {
            return;
        }
        $chunks = $this->world->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
        $blocks = $this->world->getResourceRegistry()->get(\pocketmine\core\resource\BlockRegistry::class);
        if (!$chunks instanceof \pocketmine\core\resource\ChunkStore || !$blocks instanceof \pocketmine\core\resource\BlockRegistry) {
            return;
        }
        // A creeper's blast is slightly smaller than TNT and attributed to
        // whatever killed it (legacy EntityExplodeEvent source). explode() is
        // stateless (deps passed in), so a fresh instance is fine here.
        (new \pocketmine\core\system\TNTExplosionSystem())->explode(
            $this->world,
            $chunks,
            $blocks,
            $this->spawnService,
            $this,
            $pos->x,
            $pos->y,
            $pos->z,
            \pocketmine\core\system\TNTExplosionSystem::CREEPER_RADIUS,
            $killerRef,
        );
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
                ->withTag(\pocketmine\core\constants\EntityTags::XP_ORB)
        );
    }

    private function getXpDrop(EntityRef $entityRef): int {
        $entity = $entityRef->getEntity();
        if (!$entity) return 5;

        $meta = $entity->get(MetadataComponent::class);
        $entityType = EntityType::tryFrom((string)($meta?->get(MetadataKeys::ENTITY_TYPE) ?? ''));

        return match ($entityType) {
            EntityType::Zombie, EntityType::Skeleton, EntityType::Spider,
            EntityType::Slime, EntityType::Silverfish, EntityType::CaveSpider,
            EntityType::PigZombie, EntityType::LavaSlime, EntityType::Witch,
            EntityType::Stray, EntityType::Husk, EntityType::ZombieVillager => 5,
            EntityType::Creeper => 5,
            EntityType::Enderman => 5,
            EntityType::Blaze => 10,
            EntityType::Ghast => 10,
            EntityType::IronGolem => 15,
            EntityType::Cow, EntityType::Pig, EntityType::Sheep, EntityType::Chicken,
            EntityType::Villager, EntityType::Mooshroom, EntityType::Squid,
            EntityType::Rabbit, EntityType::Ocelot, EntityType::SnowGolem => 1 + mt_rand(0, 3),
            EntityType::Bat => 0,
            EntityType::Wolf => 2,
            // Projectiles drop no XP when they despawn.
            EntityType::Arrow, EntityType::Snowball, EntityType::Egg, EntityType::XPOrb => 0,
            // Vehicles and TNT are not mobs - no XP.
            EntityType::Boat, EntityType::Minecart, EntityType::PrimedTNT, EntityType::ThrownPotion => 0,
            null => $entity->has(PlayerTag::class) ? 7 : 5,
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
        $entityType = EntityType::tryFrom((string)($meta?->get(MetadataKeys::ENTITY_TYPE) ?? ''));
        $worldId = $this->worldIdOf($entityRef);
        foreach (self::MOB_LOOT[$entityType?->value ?? ''] ?? [] as [$itemId, $min, $max, $chance]) {
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
        $name = (string)($meta?->get(MetadataKeys::USERNAME) ?? $meta?->get(MetadataKeys::DISPLAY_NAME) ?? 'Entity');

        if ($killerRef !== null) {
            $killer = $killerRef->getEntity();
            $killerMeta = $killer?->get(MetadataComponent::class);
            $killerName = (string)($killerMeta?->get(MetadataKeys::USERNAME) ?? $killerMeta?->get(MetadataKeys::DISPLAY_NAME) ?? 'Entity');
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
            $attackerPvP = $attackerMeta->get(MetadataKeys::PVP_ENABLED) ?? true;
            $targetPvP = $targetMeta->get(MetadataKeys::PVP_ENABLED) ?? true;

            if (!$attackerPvP || !$targetPvP) {
                return false;
            }
        }

        return true;
    }

    /** Play the generic hurt "click" sound at the target's position. */
    private function playHurtEffect(EntityRef $targetRef): void {
        $pos = $targetRef->getPosition();
        if ($pos === null) return;
        $kernel = \pocketmine\Kernel::getInstance();
        $wes = $kernel?->getWorldEventService();
        if ($wes === null) return;
        $worldId = $targetRef->getEntity()?->get(\pocketmine\core\component\WorldComponent::class)?->id ?? 0;
        $chunkX = (int)floor($pos->x / 16);
        $chunkZ = (int)floor($pos->z / 16);
        $wes->playSound($worldId, $chunkX, $chunkZ, $pos->x, $pos->y, $pos->z, \pocketmine\core\service\WorldEventService::SOUND_CLICK);
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
