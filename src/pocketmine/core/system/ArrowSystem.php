<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\ProjectileRegistry;
use pocketmine\core\service\CombatService;
use pocketmine\core\service\EntityDespawnService;
use pocketmine\Kernel;
use function abs;
use function atan2;
use function ceil;
use function floor;
use function is_string;
use function max;
use function mt_rand;
use function sqrt;

/**
 * 14.17/14.18: the projectile driver. Every entity tagged with a REGISTERED
 * projectileType (metadata set by EntitySpawnService::spawnProjectile) is
 * ticked here, with per-type stats read from the ProjectileRegistry:
 *
 *  - drag: velocity *= (1 - drag) each tick (registry drag, legacy 0.01)
 *  - rotation: the projectile points along its motion vector every tick
 *    (legacy Projectile::onUpdate), so in-flight rendering shows it aimed
 *    correctly - the client follows it with MoveEntityPacket while it flies
 *  - block collision: a solid block on the flight path sticks the projectile
 *    (velocity zeroed; it idles until age 1200 then despawns, legacy parity)
 *  - entity collision: the nearest living entity on the flight path takes
 *    ceil(speed_blocksPerTick * damage) damage (critical arrows add a random
 *    bonus up to half that), attributed to the shooter through CombatService
 *    so the full damage pipeline (events, armor, knockback, death drops)
 *    applies. Sticky projectiles (arrows) then embed in the victim, ride it
 *    while it lives, and fall to the ground when it dies; non-sticky ones
 *    (future snowballs/eggs) despawn on hit like legacy.
 *  - age: projectiles despawn after 1200 ticks, matching legacy
 *
 * Gravity is NOT applied here: projectiles ride the generic PhysicsSystem +
 * MovementSystem pipeline (1.6 blocks/s^2) exactly like item drops and mobs,
 * and they deliberately have no CollisionComponent so they fly through blocks
 * until this system stops them (legacy arrows had a 0.5 box but no sliding).
 */
final class ArrowSystem implements System {

    private ?CombatService $combat = null;
    private ?EntityDespawnService $despawn = null;

    public function run(World $world, float $deltaTime): void {
        $combat = $this->getCombat();
        $despawn = $this->getDespawn();
        if ($combat === null || $despawn === null) {
            return;
        }
        $chunks = $world->getResourceRegistry()->get(ChunkStore::class);
        $blocks = $world->getResourceRegistry()->get(BlockRegistry::class);
        if (!$chunks instanceof ChunkStore || !$blocks instanceof BlockRegistry) {
            return;
        }

        $query = $world->query()
            ->with(PositionComponent::class, VelocityComponent::class, MetadataComponent::class)
            ->build();

        // Per-projectile stats come from the registry (single source of truth).
        $registry = $world->getResourceRegistry()->get(ProjectileRegistry::class);
        $registry = $registry instanceof ProjectileRegistry ? $registry : null;

        foreach ($query as $entity) {
            $meta = $entity->get(MetadataComponent::class);
            $projectileType = $meta?->get(\pocketmine\core\constants\MetadataKeys::PROJECTILE_TYPE);
            if (!is_string($projectileType)) {
                continue;
            }
            // The fishing bobber is session-managed (cast/reel in
            // NetworkSessionService) - it must float, not fly or despawn.
            if ($projectileType === \pocketmine\core\enum\EntityType::FishingHook->value) {
                continue;
            }
            $projectile = $registry?->get($projectileType);
            if ($projectile === null) {
                continue; // not a registered projectile - not ours to tick
            }
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            if ($pos === null || $vel === null) {
                continue;
            }

            // Age: legacy arrows live 1200 ticks then despawn.
            $age = (int)($meta->get(\pocketmine\core\constants\MetadataKeys::ARROW_AGE, 0)) + 1;
            $meta->set(\pocketmine\core\constants\MetadataKeys::ARROW_AGE, $age);
            if ($age > 1200) {
                $despawn->despawn(EntityRef::create($entity->id, $world), false);
                continue;
            }

            // Resting (stuck) arrows: the generic PhysicsSystem would keep
            // re-applying gravity to the velocity every tick and pull the
            // arrow through the floor. Re-zero each tick (before the parallel
            // integration reads it) so a stuck arrow stays pinned in place
            // until its age despawn (legacy: onGround arrow, motion zeroed).
            // An arrow stuck in a living victim instead RIDES it: its position
            // is re-synced to the victim every tick, so the client sees it
            // embedded and following. When the victim dies/despawns, the arrow
            // unsticks and falls to the ground like a normal arrow.
            if ($meta->get(\pocketmine\core\constants\MetadataKeys::STUCK) === true) {
                $vel->x = 0;
                $vel->y = 0;
                $vel->z = 0;
                $stuckTargetId = (int)$meta->get(\pocketmine\core\constants\MetadataKeys::STUCK_TARGET_ID, -1);
                if ($stuckTargetId !== -1) {
                    $victim = $world->getEntity($stuckTargetId);
                    $victimHealth = $victim?->get(HealthComponent::class);
                    if ($victim !== null && $victimHealth !== null && $victimHealth->current > 0) {
                        $victimPos = $victim->get(PositionComponent::class);
                        if ($victimPos !== null) {
                            // Re-sync to the victim each tick so the client
                            // renders the arrow embedded and following. If the
                            // victim died inside solid terrain, the arrow
                            // re-sticks there (invisible) - an accepted edge.
                            $pos->x = $victimPos->x;
                            $pos->y = $victimPos->y;
                            $pos->z = $victimPos->z;
                        }
                        continue;
                    }
                    // Victim is gone: the projectile drops out and falls.
                    $meta->set(\pocketmine\core\constants\MetadataKeys::STUCK, false);
                    $meta->set(\pocketmine\core\constants\MetadataKeys::STUCK_TARGET_ID, -1);
                }
                continue;
            }

            // Drag from the registry (legacy 0.01/tick -> x0.99).
            $drag = 1.0 - $projectile['drag'];
            $vel->x *= $drag;
            $vel->y *= $drag;
            $vel->z *= $drag;
            if (abs($vel->x) < 0.0001 && abs($vel->y) < 0.0001 && abs($vel->z) < 0.0001) {
                continue;
            }

            // In-flight rendering: the arrow always points along its motion
            // (legacy Projectile::onUpdate), so the client's MoveEntity packets
            // carry a rotation that matches where the arrow is heading.
            $rot = $entity->get(RotationComponent::class);
            if ($rot !== null) {
                $f = sqrt($vel->x * $vel->x + $vel->z * $vel->z);
                $rot->yaw = atan2($vel->x, $vel->z) * 180 / M_PI;
                $rot->pitch = atan2($vel->y, $f) * 180 / M_PI;
            }

            // Flight path: the movement systems integrate pos += vel*dt after
            // this sequential system runs, so sweep the segment the arrow will
            // cross this tick and collide with the FIRST solid cell / entity on
            // it. A single end-point check would tunnel through thin walls and
            // skip fast-moving targets between ticks.
            $nx = $pos->x + $vel->x * $deltaTime;
            $ny = $pos->y + $vel->y * $deltaTime;
            $nz = $pos->z + $vel->z * $deltaTime;
            $speed = sqrt($vel->x * $vel->x + $vel->y * $vel->y + $vel->z * $vel->z);

            // Sample at most every 0.25 blocks (minimum 4 samples per tick),
            // so even the fastest arrows cannot skip a target cell.
            $distance = sqrt(($nx - $pos->x) ** 2 + ($ny - $pos->y) ** 2 + ($nz - $pos->z) ** 2);
            $samples = max(4, (int)ceil($distance / 0.25));
            $hit = false;
            for ($i = 1; $i <= $samples; $i++) {
                $t = $i / $samples;
                $sx = $pos->x + ($nx - $pos->x) * $t;
                $sy = $pos->y + ($ny - $pos->y) * $t;
                $sz = $pos->z + ($nz - $pos->z) * $t;

                // Block collision: a solid block at the sample cell sticks the
                // arrow (legacy Entity::move -> isCollided -> motion zeroed).
                $blockId = $chunks->getBlock((int)floor($sx), (int)floor($sy), (int)floor($sz));
                if ($blocks->isSolid($blockId)) {
                    $this->emitProjectileHit($world, EntityRef::create($entity->id, $world), null, $sx, $sy, $sz);
                    if ($projectile['sticky'] !== true) {
                        // Non-sticky throwables (snowball/egg/potion) shatter
                        // on the first solid block: potions splash, the rest
                        // just vanish (legacy Projectile::onCollideWithBlock).
                        $this->onThrowableImpact($world, $pos, $meta, $projectileType);
                        $despawn->despawn(EntityRef::create($entity->id, $world), false);
                    } else {
                        $vel->x = 0;
                        $vel->y = 0;
                        $vel->z = 0;
                        $meta->set(\pocketmine\core\constants\MetadataKeys::STUCK, true);
                    }
                    // The projectile keeps its current (pre-move) position so
                    // it renders just in front of the wall face.
                    $hit = true;
                    break;
                }

                // Entity collision: nearest living target near this sample.
                $target = $this->findHitTarget($world, $sx, $sy, $sz, $age, (int)($meta->get(\pocketmine\core\constants\MetadataKeys::SHOOTER_ID, -1)));
                if ($target !== null) {
                    // Legacy: damage = ceil(motion_blocksPerTick * damage); our
                    // velocity is blocks/second, so divide by 20 for per tick.
                    $damage = (int)ceil(($speed / 20.0) * $projectile['damage']);
                    $critical = (bool)$meta->get(\pocketmine\core\constants\MetadataKeys::CRITICAL, false);
                    if ($critical) {
                        $damage += mt_rand(0, (int)($damage / 2) + 1);
                        // Critical hit particles at impact point
                        $kernel = \pocketmine\Kernel::getInstance();
                        $wes = $kernel?->getWorldEventService();
                        if ($wes !== null) {
                            $chunkX = (int)floor($sx / 16);
                            $chunkZ = (int)floor($sz / 16);
                            $wes->spawnCriticalParticle(0, $chunkX, $chunkZ, $sx, $sy, $sz);
                        }
                    }
                    // 14.30: Power adds 0.5 * level bonus damage (legacy
                    // Enchantment::getDamageBonus for bows).
                    $power = (int)$meta->get(\pocketmine\core\constants\MetadataKeys::POWER_ENCHANT, 0);
                    if ($power > 0) {
                        $damage += (int)ceil(0.5 * $power);
                    }
                    $this->emitProjectileHit($world, EntityRef::create($entity->id, $world), $target, $sx, $sy, $sz);
                    $shooter = EntityRef::create((int)$meta->get(\pocketmine\core\constants\MetadataKeys::SHOOTER_ID, -1), $world);
                    $combat->applyDamage(
                        EntityRef::create($target, $world),
                        (float)max(0, $damage),
                        $shooter,
                        \pocketmine\api\event\EntityDamageEvent::CAUSE_PROJECTILE,
                    );
                    if ($projectile['sticky'] === true) {
                        // Sticky projectiles (arrows) embed into the victim:
                        // they stay in the world with zero velocity and ride
                        // it until the victim dies, then fall to the ground.
                        $vel->x = 0;
                        $vel->y = 0;
                        $vel->z = 0;
                        $meta->set(\pocketmine\core\constants\MetadataKeys::STUCK, true);
                        $meta->set(\pocketmine\core\constants\MetadataKeys::STUCK_TARGET_ID, $target);
                    } else {
                        // Non-sticky projectiles (future snowballs/eggs)
                        // despawn on impact, like legacy Projectile::kill().
                        $despawn->despawn(EntityRef::create($entity->id, $world), false);
                    }
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                continue;
            }
        }
    }

    /**
     * A non-sticky throwable (snowball/egg/potion) hit something solid: a
     * thrown potion splashes its PotionRegistry effect over the 6-block
     * radius (legacy ThrownPotion::kill), snowballs/eggs do nothing.
     */
    private function onThrowableImpact(World $world, PositionComponent $pos, MetadataComponent $meta, string $projectileType): void {
        if ($projectileType !== \pocketmine\core\enum\EntityType::ThrownPotion->value) {
            return;
        }
        $potionId = (int)$meta->get(\pocketmine\core\constants\MetadataKeys::POTION_ID, -1);
        $kernel = \pocketmine\Kernel::getInstance();
        $potionService = $kernel?->getPotionService();
        if ($potionService === null) {
            return;
        }
        $registry = $world->getResourceRegistry()->get(\pocketmine\core\resource\PotionRegistry::class);
        $effect = $registry instanceof \pocketmine\core\resource\PotionRegistry ? $registry->get($potionId) : null;
        $shooter = EntityRef::create((int)$meta->get(\pocketmine\core\constants\MetadataKeys::SHOOTER_ID, -1), $world);
        $potionService->applySplash($pos->x, $pos->y, $pos->z, $effect, $shooter);
    }

    /**
     * The nearest living entity to the arrow's next position, or null. Excludes
     * the shooter for the first 5 ticks (legacy ticksLived < 5) so a point-blank
     * shot cannot instantly re-hit its firer, and ignores other projectiles.
     */
    private function findHitTarget(World $world, float $x, float $y, float $z, int $age, int $shooterId): ?int {
        $bestId = null;
        $bestDist = 1.0; // hit radius in blocks
        foreach ($world->getEntities() as $id => $candidate) {
            if ($id === $shooterId && $age < 5) {
                continue;
            }
            $cMeta = $candidate->get(MetadataComponent::class);
            if ($cMeta?->get(\pocketmine\core\constants\MetadataKeys::PROJECTILE_TYPE) !== null) {
                continue; // arrows do not hit other projectiles
            }
            // Creative players are invulnerable to projectiles (legacy).
            if (\pocketmine\core\enum\GameMode::coerce($cMeta?->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE)) === \pocketmine\core\enum\GameMode::Creative) {
                continue;
            }
            $health = $candidate->get(HealthComponent::class);
            if ($health === null || $health->current <= 0) {
                continue;
            }
            $cPos = $candidate->get(PositionComponent::class);
            if ($cPos === null) {
                continue;
            }
            $dx = $cPos->x - $x;
            $dy = $cPos->y - $y;
            $dz = $cPos->z - $z;
            $distSq = $dx * $dx + $dy * $dy + $dz * $dz;
            if ($distSq < $bestDist * $bestDist) {
                $bestDist = sqrt($distSq);
                $bestId = $id;
            }
        }
        return $bestId;
    }

    private function getCombat(): ?CombatService {
        $this->combat ??= Kernel::getInstance()?->getCombatService();
        return $this->combat;
    }

    private function getDespawn(): ?EntityDespawnService {
        $this->despawn ??= Kernel::getInstance()?->getEntityDespawnService();
        return $this->despawn;
    }

    /**
     * Events breadth audit: ProjectileHitEvent (informational) for both block
     * and entity impacts - plugins hook it for hit effects / stats.
     */
    private function emitProjectileHit(World $world, EntityRef $projectileRef, ?int $hitEntityId, float $x, float $y, float $z): void {
        $kernel = Kernel::getInstance();
        if ($kernel === null) {
            return;
        }
        $hitEntity = $hitEntityId !== null ? EntityRef::create($hitEntityId, $world) : null;
        $kernel->getEventPort()->emit(new \pocketmine\api\event\ProjectileHitEvent(
            \pocketmine\api\entity\Entity::wrap($projectileRef, $world),
            $hitEntity !== null ? \pocketmine\api\entity\Entity::wrap($hitEntity, $world) : null,
            $x,
            $y,
            $z,
        ));
    }
}
