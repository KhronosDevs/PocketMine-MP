<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\api\event\EntityDamageEvent;
use pocketmine\core\component\AIStateComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\component\WorldComponent;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\SpatialIndex;
use pocketmine\core\service\CombatService;

/**
 * Real AI behaviors (12.1).
 *
 * Sequential (main-thread) system: target acquisition, chasing/attacking with
 * cooldowns, and fleeing at low health. Runs before the parallel movement
 * systems so the velocities it writes are integrated the same tick. Attacks go
 * through CombatService so every hit fires the damage event, applies armor,
 * and (on death) drops loot - no bypasses.
 *
 * Entities need an AIStateComponent (attached to mobs at spawn by
 * EntitySpawnService) plus Position/Velocity/Health/Metadata components.
 */
final class AISystem implements System {
    /** Ticks two paired animals wait before they can breed again. */
    public const BREED_COOLDOWN_TICKS = 1200;
    /** Creeper fuse: ticks to detonation once player is in range. */
    public const CREEPER_FUSE_TICKS = 30;
    /** Distance at which a creeper starts its fuse. */
    public const CREEPER_FUSE_START_RANGE = 3.0;
    /** Distance at which the fuse cancels (player escaped). */
    public const CREEPER_CANCEL_RANGE = 7.0;

    /**
     * Targetless hostiles retry player acquisition at most once every N ticks
     * (staggered by entity id). A mob within the activation gate but beyond
     * its follow range CANNOT acquire and would otherwise re-scan the player
     * list every tick forever - with hundreds of far mobs that is the single
     * biggest per-tick AI cost. 10 ticks = 0.5s worst case before a freshly
     * arrived player is targeted, matching vanilla re-evaluation cadence.
     */
    public const ACQUIRE_RETRY_INTERVAL = 10;

    /**
     * Mob AI activation range (blocks from the nearest alive player).
     *
     * Vanilla MCPE only runs full entity AI within its simulation distance of
     * a player; mobs beyond it stand idle. Khronos mirrors that: a targetless
     * mob whose nearest player (same world) is farther than this parks its
     * velocity and skips decision work (acquisition/chase/flee/wander). Mobs
     * with a target or a manual path always tick, so chases that leave the
     * range complete normally, and worlds with no players keep full AI.
     *
     * This is also what makes per-mob cost LINEAR in mob count: target
     * acquisition scans the small player list (below), not the whole mob
     * pack, so 400 idle mobs no longer pay 400x400 candidate checks per tick.
     */
    public const PLAYER_ACTIVATION_RANGE = 64.0;

    /** Breed foods per passive type (bug 20). */
    private const BREED_FOODS = [
        'Cow' => 337,      // wheat
        'Sheep' => 337,    // wheat
        'Pig' => 391,      // carrot
        'Chicken' => 295,  // seeds
    ];
    private ?CombatService $combatService = null;

    public function run(World $world, float $deltaTime): void {
        // The spatial index is rebuilt here once per tick so ArrowSystem (and
        // legacy consumers) see current positions. Mob target acquisition no
        // longer scans it - that was O(nearby entities) per mob and went
        // quadratic in dense packs - so its rebuild is purely for arrows.
        $spatial = $world->getResourceRegistry()->get(SpatialIndex::class);
        if ($spatial instanceof SpatialIndex) {
            $spatial->rebuild($world);
        }

        $combat = $this->getCombatService();

        // Per-world alive player snapshots (players are FEW - a mob pack can
        // be hundreds - so acquisition scans this list, never the entity
        // index). Also scopes AI per game-world: a mob never targets or even
        // activates for a player in another world.
        $playersByWorld = $this->collectPlayersByWorld($world);

        // Per-world bounding box of players inflated by the activation range.
        // Lets the gate reject a far mob in O(1) (four comparisons) instead
        // of scanning every player - loaded chunks can hold hundreds of mobs
        // in the annulus between the gate and the follow range, and they all
        // re-check distance every tick.
        $activeBounds = [];
        foreach ($playersByWorld as $wid => $ps) {
            $minX = $minZ = INF;
            $maxX = $maxZ = -INF;
            foreach ($ps as $p) {
                $minX = min($minX, $p['x']);
                $maxX = max($maxX, $p['x']);
                $minZ = min($minZ, $p['z']);
                $maxZ = max($maxZ, $p['z']);
            }
            $r = self::PLAYER_ACTIVATION_RANGE;
            $activeBounds[$wid] = [$minX - $r, $maxX + $r, $minZ - $r, $maxZ + $r];
        }

        $query = $world->query()
            ->with(
                AIStateComponent::class,
                PositionComponent::class,
                RotationComponent::class,
                VelocityComponent::class,
                HealthComponent::class,
                MetadataComponent::class,
            )
            ->build();

        $tick = \pocketmine\Kernel::getInstance()?->getResourceRegistry()?->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0;
        $hasCreepers = false;
        $hasBreeding = false;

        foreach ($query as $entity) {
            $ai = $entity->get(AIStateComponent::class);
            $pos = $entity->get(PositionComponent::class);
            $rot = $entity->get(RotationComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            $health = $entity->get(HealthComponent::class);
            $meta = $entity->get(MetadataComponent::class);
            if (!$ai || !$pos || !$rot || !$vel || !$health || !$meta) {
                continue;
            }

            if ($ai->attackCooldown > 0) {
                $ai->attackCooldown--;
            }

            $hostile = (bool)$meta->get(\pocketmine\core\constants\MetadataKeys::HOSTILE, false);
            $mobType = (string)$meta->get(\pocketmine\core\constants\MetadataKeys::MOB_TYPE, '');

            // Shorn sheep regrow their fleece after ~60s (vanilla-lite: the
            // original ate grass to regrow; a timer keeps it simple).
            if ($meta->get('shorn') && $tick - (int)($meta->get('shornAt', 0)) >= 1200) {
                $meta->remove('shorn');
                $meta->remove('shornAt');
            }

            // --- Activation gate (vanilla simulation distance) ---
            // A targetless mob only runs its AI while a player of its own
            // world is within range; far mobs park (velocity zeroed) instead
            // of paying chase/acquire/wander cost no one observes. Mobs with
            // a target or a manual path always tick, and worlds without any
            // player keep full AI (creative/test worlds, offline spawns).
            $worldId = (int)($entity->get(WorldComponent::class)?->id ?? 0);
            $players = $playersByWorld[$worldId] ?? [];
            $bounds = $activeBounds[$worldId] ?? null;
            // O(1) reject: outside the inflated player bounding box means no
            // player can be within activation range - skip the exact scan.
            // Mirrors the exact gate below (only targetless mobs without a
            // manual path park), so plugin-set paths and active chases keep
            // ticking exactly as before.
            if ($players !== [] && $bounds !== null
                && $ai->targetEntity === null
                && $ai->state !== 2 // manual path set by plugins/trainers
                && ($pos->x < $bounds[0] || $pos->x > $bounds[1] || $pos->z < $bounds[2] || $pos->z > $bounds[3])
            ) {
                $vel->x = 0.0;
                $vel->z = 0.0;
                continue;
            }
            if ($players !== []
                && $ai->targetEntity === null
                && $ai->state !== 2 // manual path set by plugins/trainers
                && !$this->anyPlayerWithin($players, $pos, self::PLAYER_ACTIVATION_RANGE)
            ) {
                $vel->x = 0.0;
                $vel->z = 0.0;
                continue;
            }

            // Creeper/breeding bookkeeping rides the same query pass instead
            // of a second full-entity scan: only AI mobs can be creepers or
            // in-love animals, and a creeper parked by the gate cannot fuse.
            // Counted before the chase/flee branches so a chasing creeper (or
            // an in-love animal mid-flee) still triggers its subsystem.
            if (!$hasCreepers && $mobType === 'Creeper') {
                $hasCreepers = true;
            }
            if (!$hasBreeding && (int)($meta->get('inLoveUntil', 0)) > $tick
                && isset(self::BREED_FOODS[$mobType])
            ) {
                $hasBreeding = true;
            }

            // Validate the current target: gone, dead, or out of follow range.
            if ($ai->targetEntity !== null) {
                $target = $world->getEntity($ai->targetEntity);
                $targetHealth = $target?->get(HealthComponent::class);
                $targetPos = $target?->get(PositionComponent::class);
                $tooFar = $targetPos === null
                    || $this->distSq($pos, $targetPos) > $ai->followRange * $ai->followRange;
                if ($target === null || $targetHealth === null || $targetHealth->current <= 0 || $tooFar) {
                    $ai->clearTarget();
                }
            }

            // Hostile mobs acquire the nearest living player as a target -
            // throttled and staggered: a mob beyond its follow range cannot
            // acquire, and retrying for every one of those every tick was the
            // dominant AI cost at mob scale (each attempt re-scans every
            // player). Retries are spread by entity id so a mob reacts to a
            // freshly arrived player within at most ACQUIRE_RETRY_INTERVAL
            // ticks.
            if ($hostile && $ai->targetEntity === null
                && $tick % self::ACQUIRE_RETRY_INTERVAL === $entity->id % self::ACQUIRE_RETRY_INTERVAL
            ) {
                $this->acquireTarget($world, $players, $ai, $pos, $entity->id);
            }

            // Retreat: any mob below its health threshold flees the nearest
            // player (passive mobs panic when hurt; hostile mobs fall back).
            $flee = $ai->retreatHealthPercent > 0
                && $health->max > 0
                && $health->current <= $health->max * $ai->retreatHealthPercent;
            if ($flee) {
                $this->handleFleeing($world, $players, $ai, $pos, $rot, $vel, $entity->id);
                continue;
            }

            if ($ai->targetEntity !== null) {
                $this->handleChaseAndAttack($world, $combat, $ai, $pos, $rot, $vel, $entity->id);
                continue;
            }

            // No target: idle / wander / follow a manually set path.
            switch ($ai->state) {
                case 1:
                    $this->handleWandering($ai, $pos, $rot, $vel);
                    break;
                case 2:
                    $this->handlePathfinding($ai, $pos, $rot, $vel);
                    break;
                default:
                    // Idle: stop moving. Without this a mob that lost its target
                    // keeps the last chase/flee velocity and slides forever.
                    $vel->x = 0;
                    $vel->z = 0;
                    $this->handleIdle($ai, $pos);
                    break;
            }
        }
        if ($hasBreeding) {
            $this->processBreeding($world);
        }
        if ($hasCreepers) {
            $this->processCreepers($world, $combat);
        }
    }

    /**
     * One pass over all entities collecting alive players, grouped by their
     * WorldComponent id (players without one default to world 0). Each entry
     * carries the position and whether the player is a valid combat target
     * (creative/spectator players activate mob AI but are never targeted,
     * matching the legacy gamemode exclusion in findNearestPlayer).
     *
     * @return array<int, array<int, array{id: int, x: float, y: float, z: float, targetable: bool}>>
     */
    private function collectPlayersByWorld(World $world): array {
        $out = [];
        foreach ($world->getEntities() as $entity) {
            if (!$entity->has(PlayerTag::class)) {
                continue;
            }
            $pos = $entity->get(PositionComponent::class);
            $hp = $entity->get(HealthComponent::class);
            if ($pos === null || ($hp !== null && $hp->current <= 0)) {
                continue;
            }
            $meta = $entity->get(MetadataComponent::class);
            $mode = $meta !== null
                ? \pocketmine\core\enum\GameMode::coerce($meta->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE))
                : \pocketmine\core\enum\GameMode::Survival;
            $out[(int)($entity->get(WorldComponent::class)?->id ?? 0)][] = [
                'id' => $entity->id,
                'x' => $pos->x,
                'y' => $pos->y,
                'z' => $pos->z,
                'targetable' => $mode !== \pocketmine\core\enum\GameMode::Creative
                    && $mode !== \pocketmine\core\enum\GameMode::Spectator,
            ];
        }
        return $out;
    }

    /**
     * Whether any collected player (any gamemode) is within $range of $pos.
     * Cheap XZ check used by the activation gate before any per-mob work.
     *
     * @param array<int, array{id: int, x: float, y: float, z: float, targetable: bool}> $players
     */
    private function anyPlayerWithin(array $players, PositionComponent $pos, float $range): bool {
        $rangeSq = $range * $range;
        foreach ($players as $p) {
            $dx = $p['x'] - $pos->x;
            $dz = $p['z'] - $pos->z;
            if ($dx * $dx + $dz * $dz <= $rangeSq) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bug 32: creeper self-detonation. When a creeper is within
     * FUSE_START_RANGE blocks of an alive player it starts a fuse countdown;
     * at zero it explodes using the same TNTExplosionSystem blast as the
     * death-triggered explosion. If the player moves away beyond
     * CANCEL_RANGE the fuse resets (legacy Creeper::onUpdate behavior).
     */
    private function processCreepers(World $world, ?CombatService $combat): void {
        if ($combat === null) {
            return;
        }
        $chunks = $world->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
        $blocks = $world->getResourceRegistry()->get(\pocketmine\core\resource\BlockRegistry::class);
        $spawn = \pocketmine\Kernel::getInstance()?->getEntitySpawnService();
        $tnt = new \pocketmine\core\system\TNTExplosionSystem();
        $tick = \pocketmine\Kernel::getInstance()?->getResourceRegistry()?->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0;

        // Collect alive player positions for proximity checks.
        $playerPositions = [];
        foreach ($world->getEntities() as $entity) {
            if (!$entity->has(PlayerTag::class)) continue;
            $pos = $entity->get(PositionComponent::class);
            $hp = $entity->get(HealthComponent::class);
            if ($pos === null || ($hp !== null && $hp->current <= 0)) continue;
            $wc = $entity->get(\pocketmine\core\component\WorldComponent::class);
            $wid = $wc?->id ?? 0;
            if (!isset($playerPositions[$wid])) { $playerPositions[$wid] = []; }
            $playerPositions[$wid][] = $pos;
        }

        foreach ($world->getEntities() as $entity) {
            $meta = $entity->get(MetadataComponent::class);
            if ($meta === null || $meta->get(\pocketmine\core\constants\MetadataKeys::MOB_TYPE) !== 'Creeper') {
                continue;
            }
            $health = $entity->get(HealthComponent::class);
            if ($health === null || $health->current <= 0) continue;
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) continue;
            $wid = $entity->get(\pocketmine\core\component\WorldComponent::class)?->id ?? 0;
            $positions = $playerPositions[$wid] ?? [];
            if ($positions === []) continue;

            // Find nearest player distance.
            $nearestSq = PHP_FLOAT_MAX;
            foreach ($positions as $ppos) {
                $dx = $pos->x - $ppos->x;
                $dy = $pos->y - $ppos->y;
                $dz = $pos->z - $ppos->z;
                $dSq = $dx * $dx + $dy * $dy + $dz * $dz;
                if ($dSq < $nearestSq) { $nearestSq = $dSq; }
            }
            $dist = sqrt($nearestSq);

            $fuse = (int)$meta->get('creeperFuse', 0);

            if ($dist <= self::CREEPER_FUSE_START_RANGE && $fuse === 0) {
                // Start fuse: 30 ticks (~1.5s of hissing/swelling).
                $meta->set('creeperFuse', self::CREEPER_FUSE_TICKS);
            } elseif ($fuse > 0) {
                $fuse--;
                $meta->set('creeperFuse', $fuse);

                if ($dist > self::CREEPER_CANCEL_RANGE) {
                    // Player escaped: cancel fuse.
                    $meta->set('creeperFuse', 0);
                    continue;
                }
                if ($fuse <= 0) {
                    // Detonate: remove creeper and explode at its position.
                    $ref = EntityRef::create($entity->id, $world);
                    $world->despawn($entity);
                    $tnt->explode(
                        $world,
                        $chunks instanceof \pocketmine\core\resource\ChunkStore ? $chunks : null,
                        $blocks instanceof BlockRegistry ? $blocks : null,
                        $spawn instanceof \pocketmine\core\service\EntitySpawnService ? $spawn : null,
                        $combat,
                        $pos->x, $pos->y, $pos->z,
                        \pocketmine\core\system\TNTExplosionSystem::CREEPER_RADIUS,
                        null,
                    );
                    continue;
                }
            }
        }
    }

    private function handleIdle(AIStateComponent $ai, PositionComponent $position): void {
        $ai->updateCounter++;
        if ($ai->updateCounter >= 200) { // ~10 seconds
            $ai->updateCounter = 0;
            // Random chance to start wandering
            if (mt_rand(1, 10) <= 3) {
                $ai->state = 1;
                $angle = mt_rand(0, 360);
                $dist = mt_rand(5, 15);
                $ai->targetX = $position->x + sin(deg2rad($angle)) * $dist;
                $ai->targetZ = $position->z + cos(deg2rad($angle)) * $dist;
                $ai->targetY = $position->y;
            }
        }
    }

    private function handleWandering(AIStateComponent $ai, PositionComponent $position, RotationComponent $rotation, VelocityComponent $velocity): void {
        $dx = $ai->targetX - $position->x;
        $dz = $ai->targetZ - $position->z;
        $distSq = $dx * $dx + $dz * $dz;

        if ($distSq < 4.0) { // Close enough
            $ai->state = 0;
            $velocity->x = 0;
            $velocity->z = 0;
            return;
        }

        $dist = sqrt($distSq);
        // Velocity is in blocks/second (MovementSystem integrates v*dt).
        $speed = 1.5 * $ai->speedModifier;
        $velocity->x = ($dx / $dist) * $speed;
        $velocity->z = ($dz / $dist) * $speed;
        $rotation->yaw = rad2deg(atan2(-$dx, $dz));
    }

    private function handlePathfinding(AIStateComponent $ai, PositionComponent $position, RotationComponent $rotation, VelocityComponent $velocity): void {
        if ($ai->hasPath()) {
            $next = $ai->getNextPathPoint();
            if ($next) {
                $dx = $next['x'] - $position->x;
                $dy = $next['y'] - $position->y;
                $dz = $next['z'] - $position->z;
                $dist = sqrt($dx * $dx + $dy * $dy + $dz * $dz);

                if ($dist < 0.5) {
                    return; // Close enough, advance to next node
                }

                $speed = 0.3 * $ai->speedModifier;
                $velocity->x = ($dx / $dist) * $speed;
                $velocity->z = ($dz / $dist) * $speed;
                $rotation->yaw = rad2deg(atan2(-$dx, $dz));
            }
        } else {
            $ai->state = 0; // Path complete or no path
        }
    }

    /**
     * Move toward the target; within attack range, stop and attack on a
     * cooldown. Damage goes through CombatService (events, armor, death/loot).
     */
    private function handleChaseAndAttack(
        World $world,
        ?CombatService $combat,
        AIStateComponent $ai,
        PositionComponent $pos,
        RotationComponent $rot,
        VelocityComponent $vel,
        int $mobId,
    ): void {
        $target = $world->getEntity($ai->targetEntity);
        $targetPos = $target?->get(PositionComponent::class);
        if ($targetPos === null) {
            $ai->clearTarget();
            return;
        }

        $dx = $targetPos->x - $pos->x;
        $dz = $targetPos->z - $pos->z;
        $dist = sqrt($dx * $dx + $dz * $dz);

        if ($dist <= 0.0001) {
            $vel->x = 0;
            $vel->z = 0;
            return;
        }

        // Attack range is 3D: a mob must not hit a player standing 10 blocks
        // above it in the same XZ column. Chase steering stays 2D.
        $dy = $targetPos->y - $pos->y;
        $attackRangeSq = $ai->attackRange * $ai->attackRange;
        if ($dx * $dx + $dz * $dz + $dy * $dy <= $attackRangeSq) {
            // In attack range: stop and attack on cooldown.
            $vel->x = 0;
            $vel->z = 0;
            if ($ai->attackCooldown <= 0 && $combat !== null && $ai->attackDamage > 0) {
                $combat->applyDamage(
                    EntityRef::create($ai->targetEntity, $world),
                    $ai->attackDamage,
                    EntityRef::create($mobId, $world),
                    EntityDamageEvent::CAUSE_ENTITY_ATTACK,
                );
                $ai->attackCooldown = $ai->attackCooldownMax;
            }
            return;
        }

        // Chase: full speed toward the target (blocks/second).
        $speed = 4.0 * $ai->speedModifier;
        $vel->x = ($dx / $dist) * $speed;
        $vel->z = ($dz / $dist) * $speed;
        $rot->yaw = rad2deg(atan2(-$dx, $dz));
    }

    /** Move away from the nearest player (panic / retreat). */
    private function handleFleeing(
        World $world,
        array $players,
        AIStateComponent $ai,
        PositionComponent $pos,
        RotationComponent $rot,
        VelocityComponent $vel,
        int $mobId,
    ): void {
        $playerId = $this->findNearestPlayer($players, $pos, 16.0, $mobId);
        if ($playerId === null) {
            $vel->x = 0;
            $vel->z = 0;
            return;
        }
        $playerPos = $world->getEntity($playerId)?->get(PositionComponent::class);
        if ($playerPos === null) {
            $vel->x = 0;
            $vel->z = 0;
            return;
        }

        $dx = $pos->x - $playerPos->x;
        $dz = $pos->z - $playerPos->z;
        $dist = sqrt($dx * $dx + $dz * $dz);
        if ($dist > 0.0001) {
            $speed = 5.0 * $ai->speedModifier;
            $vel->x = ($dx / $dist) * $speed;
            $vel->z = ($dz / $dist) * $speed;
            $rot->yaw = rad2deg(atan2(-$dx, $dz));
        }
    }

    /** Pick the nearest living player inside the follow range. */
    private function acquireTarget(
        World $world,
        array $players,
        AIStateComponent $ai,
        PositionComponent $pos,
        int $mobId,
    ): void {
        $playerId = $this->findNearestPlayer($players, $pos, $ai->followRange, $mobId);
        if ($playerId !== null) {
            $ai->setTargetEntity($playerId);
        }
    }

    /**
     * Nearest targetable player from the per-world snapshot, within $radius.
     * Scans the (few) collected players directly - never the entity index,
     * which in a dense mob pack holds hundreds of nearby mobs and made
     * acquisition quadratic in pack size.
     *
     * @param array<int, array{id: int, x: float, y: float, z: float, targetable: bool}> $players
     */
    private function findNearestPlayer(array $players, PositionComponent $pos, float $radius, int $selfId): ?int {
        $bestId = null;
        $bestDistSq = $radius * $radius;
        foreach ($players as $candidate) {
            if ($candidate['id'] === $selfId || !$candidate['targetable']) {
                continue;
            }
            $dx = $candidate['x'] - $pos->x;
            $dz = $candidate['z'] - $pos->z;
            $d = $dx * $dx + $dz * $dz;
            if ($d < $bestDistSq) {
                $bestDistSq = $d;
                $bestId = $candidate['id'];
            }
        }
        return $bestId;
    }

    private function distSq(PositionComponent $a, PositionComponent $b): float {
        $dx = $a->x - $b->x;
        $dz = $a->z - $b->z;
        return $dx * $dx + $dz * $dz;
    }

    /**
     * CombatService is created by the Kernel constructor, after systems are
     * registered - resolve lazily on first run (Kernel::getInstance() is set
     * before any tick). Matches the pattern used by the api facades.
     */
    private function getCombatService(): ?CombatService {
        if ($this->combatService === null) {
            $kernel = \pocketmine\Kernel::getInstance();
            $this->combatService = $kernel?->getCombatService();
        }
        return $this->combatService;
    }

    /**
     * Bug 20: pairs two same-type animals that are both in love mode and
     * within range, spawning a baby between them and starting each parent's
     * breed cooldown. Runs once per tick after the main AI loop.
     */
    private function processBreeding(World $world): void {
        $tick = \pocketmine\Kernel::getInstance()?->getResourceRegistry()?->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0;

        // Collect love-mode passive animals grouped by type.
        $byType = [];
        foreach ($world->getEntities() as $entity) {
            $meta = $entity->get(MetadataComponent::class);
            if ($meta === null) {
                continue;
            }
            if ((int)($meta->get('inLoveUntil', 0)) <= $tick) {
                continue;
            }
            $type = (string)$meta->get(\pocketmine\core\constants\MetadataKeys::MOB_TYPE, '');
            if (!isset(self::BREED_FOODS[$type])) {
                continue; // only feedable passives breed
            }
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }                $byType[$type][] = ['entity' => $entity, 'meta' => $meta, 'pos' => $pos];
            }

            $spawner = \pocketmine\Kernel::getInstance()?->getEntitySpawnService();
        $wes = \pocketmine\Kernel::getInstance()?->getWorldEventService();
        foreach ($byType as $type => $animals) {
            while (count($animals) >= 2) {
                [$a, $b] = [array_shift($animals), array_shift($animals)];
                $dx = $a['pos']->x - $b['pos']->x;
                $dz = $a['pos']->z - $b['pos']->z;
                if ($dx * $dx + $dz * $dz > 100) {
                    // Too far apart to pair this tick; both go back in.
                    $animals[] = $a;
                    array_unshift($animals, $b);
                    break;
                }
                // Baby spawns midway; parents start their cooldown.
                $mx = ($a['pos']->x + $b['pos']->x) / 2;
                $mz = ($a['pos']->z + $b['pos']->z) / 2;
                $my = max($a['pos']->y, $b['pos']->y);
                \pocketmine\Kernel::getInstance()?->getEntitySpawnService()?->spawnMob(\pocketmine\core\enum\EntityType::from($type), $mx, $my + 0.2, $mz);
                if ($wes !== null) {
                    $wes->spawnHeartParticle(
                        $a['entity']->get(\pocketmine\core\component\WorldComponent::class)?->id ?? 0,
                        (int)floor($mx / 16), (int)floor($mz / 16),
                        $mx, $my + 1.2, $mz,
                    );
                }
                foreach ([$a, $b] as $parent) {
                    $parent['meta']->remove('inLoveUntil');
                    $parent['meta']->set('breedCooldownUntil', $tick + self::BREED_COOLDOWN_TICKS);
                }
            }
        }
    }
}