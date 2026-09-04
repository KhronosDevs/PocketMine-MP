<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\EffectComponent;
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

    /**
     * Activation range (blocks from the nearest alive player of the same
     * world). Mirrors AISystem::PLAYER_ACTIVATION_RANGE so environmental
     * damage (burning, suffocation, drowning) only runs for entities inside
     * the same simulation bubble as mob AI: an entity beyond it parks (its
     * fire/air state freezes) and resumes when a player approaches - the
     * vanilla "entities outside simulation distance stand idle" semantic.
     * The void check runs BEFORE the gate, so void deaths always resolve.
     */
    private const PLAYER_ACTIVATION_RANGE = 64.0;

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

        // Per-tick memoized lookups for the whole pass (players are few;
        // mob packs can be hundreds - scan the player list once).
        $solidOpaque = $registry->getSolidOpaqueFlags();
        $playersByWorld = $this->collectPlayersByWorld($world);

        foreach ($world->query()
            ->with(PositionComponent::class, HealthComponent::class)
            ->build() as $entity) {
            $health = $entity->get(HealthComponent::class);
            if ($health === null || $health->current <= 0 || $entity->has(DeadTag::class)) {
                continue;
            }
            $meta = $entity->get(MetadataComponent::class);
            $mode = $meta !== null
                ? GameMode::coerce($meta->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE))
                : GameMode::Survival;
            // Creative/spectator players are immune to environmental damage
            // (old-src Player::attack gate covered every cause except
            // magic/suicide; spectators are fully detached).
            if ($mode === GameMode::Creative || $mode === GameMode::Spectator) {
                continue;
            }

            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            $ref = \pocketmine\core\ecs\EntityRef::create($entity->id, $world);

            // --- Void: y <= -16, fixed 10 damage ------------------------
            // Runs before the activation gate: falling into the void must
            // resolve even when no player is nearby to watch.
            if ($pos->y <= -16) {
                $combat->applyDamage($ref, 10.0, null, \pocketmine\api\event\EntityDamageEvent::CAUSE_VOID);
                continue; // nothing else matters while falling through the void
            }

            // --- Activation gate (same simulation bubble as mob AI) -----
            // Entities beyond PLAYER_ACTIVATION_RANGE of every same-world
            // player park: no lava/fire/suffocation/drowning bookkeeping, no
            // per-entity block probes. Their state resumes on approach.
            $players = $playersByWorld[(int)($entity->get(WorldComponent::class)?->id ?? 0)] ?? [];
            if ($players !== [] && !$this->anyPlayerWithin($players, $pos->x, $pos->z, self::PLAYER_ACTIVATION_RANGE)) {
                continue;
            }

            $effects = $entity->get(EffectComponent::class);
            $fx = (int)floor($pos->x);
            $fz = (int)floor($pos->z);
            $feet = (int)floor($pos->y);
            $headY = (int)floor($pos->y + 1.62); // eye height (legacy getEyeHeight)

            // Feet/body/head blocks come from ONE column resolve - the three
            // levels always share a chunk column, and getBlock() re-resolves
            // the chunk (floor-div + key concat + hash) per level, which was
            // the dominant per-entity cost of this system.
            $colIds = $store->readColumnBlockRange($fx, min($feet, $headY), max($feet, $headY), $fz);
            $feetBlock = $colIds[$feet] ?? 0;
            $bodyBlock = $colIds[$feet + 1] ?? 0;
            $headBlock = $colIds[$headY] ?? 0;

            // --- Sunlight burning: undead mobs in direct sky light during
            // daytime catch fire (legacy EntityEffects + vanilla behavior).
            // Without this, night-spawned zombies/skeletons accumulate on
            // the surface indefinitely.
            $mobType = $meta?->get(\pocketmine\core\constants\MetadataKeys::MOB_TYPE) ?? '';
            if (($mobType === 'Zombie' || $mobType === 'Skeleton' || $mobType === 'ZombieVillager')
                && !TimeSystem::isNight($worldConfig?->time ?? 0)) {
                $skyLight = $store->getSkyLightLevel($fx, (int)floor($pos->y + 1), $fz);
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

            // --- Suffocation: solid opaque block at eye height ----------
            if (($solidOpaque[$headBlock] ?? 0) === 1) {
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

    /**
     * One pass over all entities collecting alive players grouped by their
     * WorldComponent id (players without one default to world 0), as XZ
     * activation anchors. Any gamemode anchors - a creative player watching
     * a burning field keeps its simulation alive even though they take no
     * damage themselves.
     *
     * @return array<int, list<array{x: float, z: float}>>
     */
    private function collectPlayersByWorld(World $world): array {
        $out = [];
        foreach ($world->query()
            ->with(PositionComponent::class)
            ->withTag(\pocketmine\core\component\tags\PlayerTag::class)
            ->build() as $entity) {
            $hp = $entity->get(HealthComponent::class);
            if ($hp !== null && $hp->current <= 0) {
                continue;
            }
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            $out[(int)($entity->get(WorldComponent::class)?->id ?? 0)][] = ['x' => $pos->x, 'z' => $pos->z];
        }
        return $out;
    }

    /** Cheap XZ check used by the activation gate before any per-entity work. */
    private function anyPlayerWithin(array $players, float $x, float $z, float $range): bool {
        $rangeSq = $range * $range;
        foreach ($players as $p) {
            $dx = $p['x'] - $x;
            $dz = $p['z'] - $z;
            if ($dx * $dx + $dz * $dz <= $rangeSq) {
                return true;
            }
        }
        return false;
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
}
