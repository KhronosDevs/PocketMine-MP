<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\service\CombatService;
use pocketmine\core\service\EntityDespawnService;
use pocketmine\Kernel;
use function abs;
use function ceil;
use function floor;
use function max;
use function mt_rand;
use function sqrt;

/**
 * 14.17: arrow projectiles. Bows fire arrows (spawnProjectile with the
 * 'Arrow' type, metadata projectileType='Arrow'); this sequential system ticks
 * them exactly like the legacy 0.15 Arrow::onUpdate:
 *
 *  - drag: velocity *= 0.99 each tick (legacy drag 0.01)
 *  - block collision: a solid block at the arrow's next position sticks it
 *    (velocity zeroed; it idles until age 1200 then despawns, legacy parity)
 *  - entity collision: the nearest living entity on the flight path takes
 *    ceil(speed_blocksPerTick * 2) damage (critical arrows add a random bonus
 *    up to half that), attributed to the shooter through CombatService so the
 *    full damage pipeline (events, armor, knockback, death drops) applies
 *  - age: arrows despawn after 1200 ticks, matching legacy
 *
 * Gravity is NOT applied here: arrows ride the generic PhysicsSystem +
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

        foreach ($query as $entity) {
            $meta = $entity->get(MetadataComponent::class);
            if ($meta?->get('projectileType') !== 'Arrow') {
                continue;
            }
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            if ($pos === null || $vel === null) {
                continue;
            }

            // Age: legacy arrows live 1200 ticks then despawn.
            $age = (int)($meta->get('arrowAge', 0)) + 1;
            $meta->set('arrowAge', $age);
            if ($age > 1200) {
                $despawn->despawn(EntityRef::create($entity->id, $world), false);
                continue;
            }

            // Resting (stuck) arrows: the generic PhysicsSystem would keep
            // re-applying gravity to the velocity every tick and pull the
            // arrow through the floor. Re-zero each tick (before the parallel
            // integration reads it) so a stuck arrow stays pinned in place
            // until its age despawn (legacy: onGround arrow, motion zeroed).
            if ($meta->get('stuck') === true) {
                $vel->x = 0;
                $vel->y = 0;
                $vel->z = 0;
                continue;
            }

            // Drag (legacy 0.01/tick).
            $vel->x *= 0.99;
            $vel->y *= 0.99;
            $vel->z *= 0.99;
            if (abs($vel->x) < 0.0001 && abs($vel->y) < 0.0001 && abs($vel->z) < 0.0001) {
                continue;
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
                    $vel->x = 0;
                    $vel->y = 0;
                    $vel->z = 0;
                    $meta->set('stuck', true);
                    // The arrow keeps its current (pre-move) position so it
                    // renders just in front of the wall face; the movement
                    // systems will not move it again while velocity is zero.
                    $hit = true;
                    break;
                }

                // Entity collision: nearest living target near this sample.
                $target = $this->findHitTarget($world, $sx, $sy, $sz, $age, (int)($meta->get('shooterId', -1)));
                if ($target !== null) {
                    // Legacy: damage = ceil(motion_blocksPerTick * 2); our
                    // velocity is blocks/second, so divide by 20 for per tick.
                    $damage = (int)ceil(($speed / 20.0) * 2);
                    $critical = (bool)$meta->get('critical', false);
                    if ($critical) {
                        $damage += mt_rand(0, (int)($damage / 2) + 1);
                    }
                    $shooter = EntityRef::create((int)$meta->get('shooterId', -1), $world);
                    $combat->applyDamage(
                        EntityRef::create($target, $world),
                        (float)max(0, $damage),
                        $shooter,
                        \pocketmine\api\event\EntityDamageEvent::CAUSE_PROJECTILE,
                    );
                    $despawn->despawn(EntityRef::create($entity->id, $world), false);
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
            if ($cMeta?->get('projectileType') !== null) {
                continue; // arrows do not hit other projectiles
            }
            // Creative players are invulnerable to projectiles (legacy).
            if (($cMeta?->get('gamemode') ?? 0) === 1) {
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
}
