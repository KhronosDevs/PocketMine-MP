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

    /** Breed foods per passive type (bug 20). */
    private const BREED_FOODS = [
        'Cow' => 337,      // wheat
        'Sheep' => 337,    // wheat
        'Pig' => 391,      // carrot
        'Chicken' => 295,  // seeds
    ];
    private ?CombatService $combatService = null;

    public function run(World $world, float $deltaTime): void {
        // The spatial index is only consumed here; rebuild it once per tick so
        // target acquisition sees current positions. O(entities) insert.
        $spatial = $world->getResourceRegistry()->get(SpatialIndex::class);
        if ($spatial instanceof SpatialIndex) {
            $spatial->rebuild($world);
        }

        $combat = $this->getCombatService();

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

            // Shorn sheep regrow their fleece after ~60s (vanilla-lite: the
            // original ate grass to regrow; a timer keeps it simple).
            if ($meta->get('shorn') && ($tick = \pocketmine\Kernel::getInstance()?->getResourceRegistry()?->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0) - (int)($meta->get('shornAt', 0)) >= 1200) {
                $meta->remove('shorn');
                $meta->remove('shornAt');
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

            // Hostile mobs acquire the nearest living player as a target.
            if ($hostile && $ai->targetEntity === null) {
                $this->acquireTarget($world, $spatial, $ai, $pos, $entity->id);
            }

            // Retreat: any mob below its health threshold flees the nearest
            // player (passive mobs panic when hurt; hostile mobs fall back).
            $flee = $ai->retreatHealthPercent > 0
                && $health->max > 0
                && $health->current <= $health->max * $ai->retreatHealthPercent;
            if ($flee) {
                $this->handleFleeing($world, $spatial, $ai, $pos, $rot, $vel, $entity->id);
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

        $this->processBreeding($world);
        $this->processCreepers($world, $combat);
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
        ?SpatialIndex $spatial,
        AIStateComponent $ai,
        PositionComponent $pos,
        RotationComponent $rot,
        VelocityComponent $vel,
        int $mobId,
    ): void {
        $playerId = $this->findNearestPlayer($world, $spatial, $pos, 16.0, $mobId);
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
        ?SpatialIndex $spatial,
        AIStateComponent $ai,
        PositionComponent $pos,
        int $mobId,
    ): void {
        $playerId = $this->findNearestPlayer($world, $spatial, $pos, $ai->followRange, $mobId);
        if ($playerId !== null) {
            $ai->setTargetEntity($playerId);
        }
    }

    private function findNearestPlayer(World $world, ?SpatialIndex $spatial, PositionComponent $pos, float $radius, int $selfId): ?int {
        if ($spatial === null) {
            return null;
        }
        $candidates = $spatial->getNearby($pos->x, $pos->z, $radius);
        $bestId = null;
        $bestDistSq = $radius * $radius;
        foreach ($candidates as $candidateId) {
            if ($candidateId === $selfId) {
                continue;
            }
            $candidate = $world->getEntity($candidateId);
            if ($candidate === null || !$candidate->has(PlayerTag::class)) {
                continue;
            }
            $candidateHealth = $candidate->get(HealthComponent::class);
            if ($candidateHealth === null || $candidateHealth->current <= 0) {
                continue;
            }
            // Creative players are not valid targets (legacy: mobs ignore
            // creative players entirely). Spectators likewise.
            $candidateMeta = $candidate->get(MetadataComponent::class);
            $candidateMode = $candidateMeta !== null ? \pocketmine\core\enum\GameMode::coerce($candidateMeta->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE)) : \pocketmine\core\enum\GameMode::Survival;
            if ($candidateMode === \pocketmine\core\enum\GameMode::Creative || $candidateMode === \pocketmine\core\enum\GameMode::Spectator) {
                continue;
            }
            $candidatePos = $candidate->get(PositionComponent::class);
            if ($candidatePos === null) {
                continue;
            }
            $d = $this->distSq($pos, $candidatePos);
            if ($d < $bestDistSq) {
                $bestDistSq = $d;
                $bestId = $candidateId;
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
            }
            $byType[$type][] = ['entity' => $entity, 'meta' => $meta, 'pos' => $pos];
        }
        static $dbgN = 0;
        if ($dbgN++ < 6) {
            error_log("[BR] types=" . json_encode(array_map(fn($a) => count($a), $byType)) . " tick=$tick");
        }

        $spawner = \pocketmine\Kernel::getInstance()?->getEntitySpawnService();
        $wes = \pocketmine\Kernel::getInstance()?->getWorldEventService();
        foreach ($byType as $type => $animals) {
            while (count($animals) >= 2) {
                [$a, $b] = [$array_a = array_shift($animals), array_shift($animals)];
                $dx = $a['pos']->x - $b['pos']->x;
                $dz = $a['pos']->z - $b['pos']->z;
                if ($dx * $dx + $dz * $dz > 100) {
                    // Too far apart to pair this tick; both go back in.
                    $animals[] = $a;
                    array_unshift($animals, $b);
                    sort($animals);
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