<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\constants\EntityTags;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\service\CombatService;
use pocketmine\core\service\EntityDespawnService;
use pocketmine\core\service\EntitySpawnService;
use pocketmine\Kernel;
use pocketmine\port\driven\NetworkPort;
use pocketmine\protocol\ExplodePacket;
use pocketmine\utils\TextFormat;
use pocketmine\api\entity\Entity as ApiEntity;
use function ceil;
use function cos;
use function floor;
use function mt_rand;
use function sin;
use function sqrt;

/**
 * 14.22: the explosion driver for PrimedTNT entities (and chain reactions).
 *
 * Every entity tagged PRIMED_TNT is ticked here:
 *   - fuse countdown (legacy 80 ticks = 4s); when it hits 0 the TNT explodes
 *   - explosion = legacy ray-based blast: N rays out of the source, each
 *     walking until its blast force is spent (block resistance reduces it);
 *     every block a ray reaches with force left is destroyed (set to air)
 *   - entities within the blast take damage with vanilla falloff, attributed
 *     to the igniter through CombatService (full damage pipeline)
 *   - TNT blocks caught in the blast re-prime (chain reaction, legacy)
 *   - the destroyed-block list is broadcast as ExplodePacket so the client
 *     removes those blocks from its world
 *
 * Creative players and other TNT are immune to blast damage (legacy: TNT
 * does not chain through entities - the chain is through blocks only).
 */
final class TNTExplosionSystem implements System {

    /** Legacy PrimedTNT::FUSE (80 ticks). */
    public const DEFAULT_FUSE = 80;
    /** TNT blast size (blocks). */
    public const TNT_RADIUS = 4.0;
    /** Creeper blast size (blocks). */
    public const CREEPER_RADIUS = 3.5;
    /** Ray count for the blast walk (legacy 16^3 shell sample). */
    private const RAYS = 16;

    private ?CombatService $combat = null;
    private ?EntityDespawnService $despawn = null;
    private ?EntitySpawnService $spawn = null;
    private ?NetworkPort $network = null;
    private ?\pocketmine\core\service\NetworkSessionService $sessions = null;

    public function run(World $world, float $deltaTime): void {
        $combat = $this->getCombat();
        $despawn = $this->getDespawn();
        $spawn = $this->getSpawn();
        if ($combat === null || $despawn === null || $spawn === null) {
            return;
        }
        $chunks = $world->getResourceRegistry()->get(ChunkStore::class);
        $blocks = $world->getResourceRegistry()->get(BlockRegistry::class);
        if (!$chunks instanceof ChunkStore || !$blocks instanceof BlockRegistry) {
            return;
        }

        // Snapshot the primed TNT so explosions (which despawn entities and
        // spawn new TNT) cannot mutate the iteration set mid-loop.
        $primed = [];
        foreach ($world->getEntities() as $id => $entity) {
            if ($entity->has(EntityTags::PRIMED_TNT)) {
                $primed[$id] = $entity;
            }
        }

        foreach ($primed as $id => $entity) {
            $meta = $entity->get(MetadataComponent::class);
            $pos = $entity->get(PositionComponent::class);
            if ($meta === null || $pos === null) {
                continue;
            }
            $fuse = (int)$meta->get(MetadataKeys::FUSE_TICKS, self::DEFAULT_FUSE) - 1;
            if ($fuse > 0) {
                $meta->set(MetadataKeys::FUSE_TICKS, $fuse);
                continue;
            }

            // Fuse spent: explode at the TNT's position.
            $radius = (float)$meta->get(MetadataKeys::EXPLOSION_RADIUS, self::TNT_RADIUS);
            $sourceId = (int)$meta->get(MetadataKeys::EXPLOSION_SOURCE_ID, -1);
            $source = $sourceId >= 0 ? EntityRef::create($sourceId, $world) : null;
            $despawn->despawn(EntityRef::create($id, $world), false);
            $this->explode($world, $chunks, $blocks, $spawn, $combat, $pos->x, $pos->y, $pos->z, $radius, $source);
        }
    }

    /**
     * Detonate at (x, y, z): destroy blocks by rays, damage entities, chain
     * TNT blocks, and broadcast the destroyed set as ExplodePacket. Public so
     * CombatService can call it for creeper deaths.
     */
    public function explode(
        World $world,
        ChunkStore $chunks,
        BlockRegistry $blocks,
        EntitySpawnService $spawn,
        CombatService $combat,
        float $x,
        float $y,
        float $z,
        float $radius,
        ?EntityRef $source
    ): void {
        if ($radius < 0.1) {
            return;
        }

        // --- Ray-based block destruction (legacy Explosion::explodeA) ---
        $destroyed = []; // "x:y:z" => true
        $stepLen = 0.3;
        $mRays = self::RAYS - 1;
        for ($i = 0; $i < self::RAYS; $i++) {
            for ($j = 0; $j < self::RAYS; $j++) {
                for ($k = 0; $k < self::RAYS; $k++) {
                    if ($i !== 0 && $i !== $mRays && $j !== 0 && $j !== $mRays && $k !== 0 && $k !== $mRays) {
                        continue; // legacy: only shell rays
                    }
                    $dx = $i / $mRays * 2 - 1;
                    $dy = $j / $mRays * 2 - 1;
                    $dz = $k / $mRays * 2 - 1;
                    $len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
                    if ($len < 1e-6) {
                        continue;
                    }
                    $vx = $dx / $len * $stepLen;
                    $vy = $dy / $len * $stepLen;
                    $vz = $dz / $len * $stepLen;

                    $px = $x;
                    $py = $y;
                    $pz = $z;
                    for ($force = $radius * (mt_rand(700, 1300) / 1000); $force > 0; $force -= $stepLen * 0.75) {
                        $bx = (int)floor($px);
                        $by = (int)floor($py);
                        $bz = (int)floor($pz);
                        if ($by < 0 || $by > 255) {
                            break;
                        }
                        $blockId = $chunks->getBlock($bx, $by, $bz);
                        if ($blockId !== 0) {
                            $force -= ($blocks->getResistance($blockId) / 5 + 0.3) * $stepLen;
                            if ($force > 0) {
                                $destroyed["$bx:$by:$bz"] = true;
                            }
                        }
                        $px += $vx;
                        $py += $vy;
                        $pz += $vz;
                    }
                }
            }
        }

        // --- Entity damage (legacy Explosion::explodeB) ---
        $blastSize = $radius * 2;
        foreach ($world->getEntities() as $targetId => $target) {
            $targetPos = $target->get(PositionComponent::class);
            if ($targetPos === null) {
                continue;
            }
            $dx = $targetPos->x - $x;
            $dy = $targetPos->y - $y;
            $dz = $targetPos->z - $z;
            $distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            if ($distance > $blastSize) {
                continue;
            }
            // Creative players are immune to explosions (legacy), and TNT
            // entities never damage each other (chain is block-based).
            $tMeta = $target->get(MetadataComponent::class);
            if ($target->has(EntityTags::PRIMED_TNT)) {
                continue;
            }
            if ($tMeta !== null && \pocketmine\core\enum\GameMode::coerce($tMeta->get(MetadataKeys::GAMEMODE)) === \pocketmine\core\enum\GameMode::Creative) {
                continue;
            }
            $health = $target->get(HealthComponent::class);
            if ($health === null || $health->current <= 0) {
                continue;
            }
            $impact = 1 - ($distance / $blastSize);
            $damage = (int)((($impact * $impact + $impact) / 2) * 8 * $blastSize + 1);
            if ($damage <= 0) {
                continue;
            }
            $combat->applyDamage(
                EntityRef::create($targetId, $world),
                (float)$damage,
                $source,
                \pocketmine\api\event\EntityDamageEvent::CAUSE_EXPLOSION,
            );
        }

        // --- Apply block destruction + chain reaction ---
        foreach ($destroyed as $key => $_) {
            [$bx, $by, $bz] = array_map('intval', explode(':', $key));
            $blockId = $chunks->getBlock($bx, $by, $bz);
            if ($blockId === 0) {
                continue;
            }
            if ($blockId === ItemIds::TNT) {
                // Chain reaction: re-prime TNT blocks caught in the blast
                // (legacy: every affected TNT becomes PrimedTNT).
                $chunks->setBlock($bx, $by, $bz, 0, 0);
                $spawn->spawnPrimedTNT((float)$bx, (float)$by, (float)$bz);
                continue;
            }
            $chunks->setBlock($bx, $by, $bz, 0, 0);
        }
        if (!empty($destroyed)) {
            $chunks->recalculateLight((int)floor($x / 16), (int)floor($z / 16), $blocks);
        }

        // --- Broadcast the blast to every player in the world ---
        $this->broadcastExplosion($world, $x, $y, $z, $radius, $destroyed);
    }

    private function broadcastExplosion(World $world, float $x, float $y, float $z, float $radius, array $destroyed): void {
        $network = $this->getNetwork();
        if ($network === null) {
            return;
        }
        $pk = new ExplodePacket();
        $pk->x = $x;
        $pk->y = $y;
        $pk->z = $z;
        $pk->radius = $radius;
        $pk->records = [];
        foreach (array_keys($destroyed) as $key) {
            [$bx, $by, $bz] = array_map('intval', explode(':', $key));
            // Legacy sends destroyed blocks as BYTE offsets from the source.
            $pk->records[] = (object)['x' => $bx - (int)floor($x), 'y' => $by - (int)floor($y), 'z' => $bz - (int)floor($z)];
        }

        // The port requires PlayerRefs - resolve each player entity through
        // the session service so the wire target is the real connected client.
        $players = [];
        foreach ($world->getEntities() as $entity) {
            if (!$entity->has(\pocketmine\core\component\tags\PlayerTag::class)) {
                continue;
            }
            $ref = $this->getSessions()?->getPlayerRefByEntity($entity->id);
            if ($ref !== null) {
                $players[] = $ref;
            }
        }
        if (!empty($players)) {
            $network->broadcastPacket($players, $pk);
        }
    }

    private function getCombat(): ?CombatService {
        $this->combat ??= Kernel::getInstance()?->getCombatService();
        return $this->combat;
    }

    private function getDespawn(): ?EntityDespawnService {
        $this->despawn ??= Kernel::getInstance()?->getEntityDespawnService();
        return $this->despawn;
    }

    private function getSpawn(): ?EntitySpawnService {
        $this->spawn ??= Kernel::getInstance()?->getEntitySpawnService();
        return $this->spawn;
    }

    private function getNetwork(): ?NetworkPort {
        $this->network ??= Kernel::getInstance()?->getNetworkPort();
        return $this->network;
    }

    private function getSessions(): ?\pocketmine\core\service\NetworkSessionService {
        $this->sessions ??= Kernel::getInstance()?->getNetworkSessionService();
        return $this->sessions;
    }
}
