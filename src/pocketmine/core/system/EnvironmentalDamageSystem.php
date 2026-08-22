<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\FireComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\tags\DeadTag;
use pocketmine\core\constants\BlockIds;
use pocketmine\core\ecs\Entity;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\enum\GameMode;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldConfig;
use pocketmine\core\system\TimeSystem;

/**
 * Environmental damage (old-src Entity::entityBaseTick / Living::
 * entityBaseTick / block onEntityCollide parity). One sequential system
 * covers all ambient sources:
 *
 *  - void: y <= -16 -> 10 damage every application (Entity.php:1013)
 *  - lava contact: 4 damage (Lava::onEntityCollide) + set on fire for 15s
 *  - fire contact: 1 damage (Fire::onEntityCollide) + set on fire for 8s
 *  - burning: 1 damage per second while fireTicks last (entityBaseTick)
 *  - suffocation: eye-height inside a solid opaque block -> 1 damage
 *    (Living::entityBaseTick / isInsideOfSolid)
 *  - drowning: head in water drains air from 300 at 4/tick; at air <= -80,
 *    reset to 0 and take 2 damage (Living::entityBaseTick DATA_AIR logic)
 *
 * All applications route through CombatService::applyDamage so the damage
 * event fires and death handling runs like any other source. The combat
 * no-damage window naturally throttles repeated ambient hits to legacy's
 * attackTime cadence.
 */
final class EnvironmentalDamageSystem implements System {
    /** Legacy Entity::fireTicks cadence: 1 damage per 20 ticks while burning. */
    private const FIRE_TICK_INTERVAL = 20;
    /** Legacy Fire::onEntityCollide combust duration. */
    private const FIRE_IGNITE_SECONDS = 8;
    /** Legacy Lava::onEntityCollide combust duration. */
    private const LAVA_IGNITE_SECONDS = 15;
    /** Legacy Living DATA_AIR default (Entity.php:148). */
    private const AIR_MAX_TICKS = 300;
    /** Legacy drain rate: $air -= tickDiff * 4. */
    private const AIR_DRAIN_PER_TICK = 4;

    /** @var array<int, int> entityId => remaining air ticks */
    private array $airTicks = [];

    public function run(World $world, float $deltaTime): void {
        $registry = $world->getResourceRegistry()->get(BlockRegistry::class);
        $store = $world->getResourceRegistry()->get(ChunkStore::class);
        if (!$registry instanceof BlockRegistry || !$store instanceof ChunkStore) {
            return;
        }
        $combat = \pocketmine\Kernel::getInstance()?->getCombatService();
        $worldConfig = $world->getResourceRegistry()->get(WorldConfig::class);
        if ($combat === null) {
            return;
        }

        foreach ($world->query()
            ->with(PositionComponent::class, HealthComponent::class)
            ->build() as $entity) {
            $health = $entity->get(HealthComponent::class);
            if ($health === null || $health->current <= 0 || $entity->has(DeadTag::class)) {
                continue;
            }
            // Creative players are immune to environmental damage (old-src
            // Player::attack gate covered every cause except magic/suicide).
            if ($this->isCreative($entity)) {
                continue;
            }

            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            $ref = \pocketmine\core\ecs\EntityRef::create($entity->id, $world);

            // --- Void: y <= -16, fixed 10 damage ------------------------
            if ($pos->y <= -16) {
                $combat->applyDamage($ref, 10.0, null, \pocketmine\api\event\EntityDamageEvent::CAUSE_VOID);
                continue; // nothing else matters while falling through the void
            }

            $fx = (int)floor($pos->x);
            $fz = (int)floor($pos->z);
            $feet = (int)floor($pos->y);
            $feetBlock = $store->getBlock($fx, $feet, $fz);
            $bodyBlock = $store->getBlock($fx, $feet + 1, $fz);

            // --- Sunlight burning: undead mobs in direct sky light during
            // daytime catch fire (legacy EntityEffects + vanilla behavior).
            // Without this, night-spawned zombies/skeletons accumulate on
            // the surface indefinitely.
            $mobType = $meta?->get(MetadataKeys::MOB_TYPE) ?? '';
            if (($mobType === 'Zombie' || $mobType === 'Skeleton' || $mobType === 'ZombieVillager')
                && !TimeSystem::isNight($worldConfig?->time ?? 0)) {
                $skyLight = $store->getSkyLightLevel((int)floor($pos->x), (int)floor($pos->y + 1), (int)floor($pos->z));
                if ($skyLight >= 14) {
                    $combat->applyDamage($ref, 1.0, null,
                        \pocketmine\api\event\EntityDamageEvent::CAUSE_FIRE_TICK);
                }
            }

            // --- Lava: body or feet inside lava --------------------------
            // Bug 30: Fire Resistance effect skips lava/fire/burning damage.
            $fireRes = $effects?->get(12) !== null; // EFFECT_FIRE_RESISTANCE
            $fire = $entity->get(FireComponent::class);
            if (!$fireRes && ($feetBlock === BlockIds::LAVA || $feetBlock === BlockIds::STILL_LAVA
                || $bodyBlock === BlockIds::LAVA || $bodyBlock === BlockIds::STILL_LAVA)) {
                $combat->applyDamage($ref, 4.0, null, \pocketmine\api\event\EntityDamageEvent::CAUSE_LAVA);
                $this->ignite($entity, (int)($fire?->ticks ?? 0), self::LAVA_IGNITE_SECONDS * 20);
            } elseif (!$fireRes && $feetBlock === BlockIds::FIRE) {
                // --- Fire: standing in a fire block ----------------------
                $combat->applyDamage($ref, 1.0, null, \pocketmine\api\event\EntityDamageEvent::CAUSE_FIRE);
                $this->ignite($entity, (int)($fire?->ticks ?? 0), self::FIRE_IGNITE_SECONDS * 20);
            } elseif (!$fireRes && ($fire?->ticks ?? 0) > 0) {
                // --- Burning after leaving the flame ---------------------
                $fire->ticks -= 1;
                if ($fire->ticks % self::FIRE_TICK_INTERVAL === 0) {
                    $combat->applyDamage($ref, 1.0, null, \pocketmine\api\event\EntityDamageEvent::CAUSE_FIRE_TICK);
                }
            } else {
                $entity->remove(FireComponent::class); // extinguished
            }

            $headY = (int)floor($pos->y + 1.62); // eye height (legacy getEyeHeight)
            $headBlock = $store->getBlock($fx, $headY, $fz);

            // --- Suffocation: solid opaque block at eye height ----------
            if ($this->isSolidOpaque($registry, $headBlock)) {
                $combat->applyDamage($ref, 1.0, null, \pocketmine\api\event\EntityDamageEvent::CAUSE_SUFFOCATION);
            }

            // --- Drowning: head in water drains air ---------------------
            // Bug 30: Water Breathing effect prevents the air drain entirely.
            $waterBreathing = $effects?->get(13) !== null; // EFFECT_WATER_BREATHING
            if (!$waterBreathing && ($headBlock === BlockIds::WATER || $headBlock === BlockIds::STILL_WATER
                || $bodyBlock === BlockIds::WATER || $bodyBlock === BlockIds::STILL_WATER)) {
                $air = ($this->airTicks[$entity->id] ?? self::AIR_MAX_TICKS) - self::AIR_DRAIN_PER_TICK;
                if ($air <= -80) {
                    $air = 0;
                    $combat->applyDamage($ref, 2.0, null, \pocketmine\api\event\EntityDamageEvent::CAUSE_DROWNING);
                }
                $this->airTicks[$entity->id] = $air;
            } else {
                $this->airTicks[$entity->id] = self::AIR_MAX_TICKS;
            }
        }
    }

    private function isCreative(Entity $entity): bool {
        $meta = $entity->get(MetadataComponent::class);
        $mode = $meta !== null ? GameMode::coerce($meta->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE)) : GameMode::Survival;
        // Spectators take no environmental damage either (legacy
        // isSpectator semantics: fully detached from the world).
        return $mode === GameMode::Creative || $mode === GameMode::Spectator;
    }

    /** Legacy setOnFire(): only ever extends the burn, never shortens it. */
    private function ignite(Entity $entity, int $currentTicks, int $newTicks): void {
        if ($currentTicks >= $newTicks) {
            return;
        }
        $fire = $entity->get(FireComponent::class) ?? new FireComponent();
        $fire->ticks = $newTicks;
        $entity->set(FireComponent::class, $fire);
    }

    /**
     * Legacy isInsideOfSolid(): solid AND not transparent (glass does not
     * suffocate, stone does).
     */
    private function isSolidOpaque(BlockRegistry $registry, int $blockId): bool {
        $props = $registry->get($blockId);
        return ($props['solid'] ?? false) === true && ($props['transparent'] ?? true) === false;
    }
}
