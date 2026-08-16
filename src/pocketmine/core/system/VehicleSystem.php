<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\WorldComponent;
use pocketmine\core\constants\EntityTags;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\Entity;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldRegistry;
use function abs;
use function cos;
use function floor;
use function in_array;
use function sin;

/**
 * 14.25: the vehicle driver. Entities tagged VEHICLE (boats, minecarts)
 * are ticked here:
 *
 *  - rider input: the riding player's latest PlayerInputPacket (stored by
 *    NetworkSessionService in the vehicle's metadata) becomes horizontal
 *    velocity - boats steer like legacy (forward = yaw direction), minecarts
 *    roll along their facing. No rider = the vehicle drifts to a stop.
 *  - terrain: boats float on water (y bobs toward the surface) and rest on
 *    the surface instead of sinking; minecarts ride 0.5 above a rail block
 *    when one is directly below, otherwise they fall with gravity like any
 *    other entity (PhysicsSystem handles the fall).
 *  - passenger sync: the rider entity's PositionComponent follows the
 *    vehicle (offset by its seat height) so the per-tick network pass moves
 *    the rider with the vehicle.
 *
 * Runs sequentially after movement/physics so the vehicle's own integration
 * and the rider override happen after the generic systems.
 */
final class VehicleSystem implements System {

    private const BOAT_SPEED = 0.4;
    private const MINECART_SPEED = 0.4;
    private const RAIL_IDS = [27, 28, 66, 157]; // powered/detector/rail/activator

    public function run(World $world, float $deltaTime): void {
        $query = $world->query()
            ->withTag(EntityTags::VEHICLE)
            ->with(PositionComponent::class, VelocityComponent::class, MetadataComponent::class)
            ->build();

        foreach ($query as $entity) {
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            $meta = $entity->get(MetadataComponent::class);
            if ($pos === null || $vel === null || $meta === null) {
                continue;
            }
            $type = (string)($meta->get(MetadataKeys::VEHICLE_TYPE) ?? '');
            $worldId = $entity->get(WorldComponent::class)?->id ?? 0;
            $chunks = $this->chunkStore($world, $worldId);
            $riderId = (int)($meta->get(MetadataKeys::VEHICLE_RIDER_ID) ?? 0);

            if ($type === 'Boat') {
                $this->tickBoat($world, $chunks, $entity, $pos, $vel, $meta, $worldId, $deltaTime);
            } elseif ($type === 'Minecart') {
                $this->tickMinecart($world, $chunks, $entity, $pos, $vel, $meta, $worldId, $deltaTime);
            }

            if ($riderId > 0) {
                $this->syncRider($world, $riderId, $pos);
            }
        }
    }

    private function tickBoat(World $world, ?ChunkStore $chunks, Entity $entity, PositionComponent $pos, VelocityComponent $vel, MetadataComponent $meta, int $worldId, float $deltaTime): void {
        $inputX = (float)($meta->get(MetadataKeys::VEHICLE_INPUT_X) ?? 0.0);
        $inputZ = (float)($meta->get(MetadataKeys::VEHICLE_INPUT_Z) ?? 0.0);
        $yaw = $entity->get(RotationComponent::class)?->yaw ?? 0.0;

        $waterBelow = $chunks !== null && $this->isWater($chunks, (int)floor($pos->x), (int)floor($pos->y - 0.1), (int)floor($pos->z));

        if ($waterBelow) {
            // Float: resist gravity and settle at the water surface (~0.3
            // above the water block). Input steers relative to the yaw.
            $rad = $yaw * M_PI / 180.0;
            $speed = self::BOAT_SPEED * $deltaTime * 20.0;
            $vel->x = -sin($rad) * $speed * $inputZ + cos($rad) * $speed * $inputX * 0.5;
            $vel->z = cos($rad) * $speed * $inputZ + sin($rad) * $speed * $inputX * 0.5;
            $vel->y = max(-0.02, min(0.02, (0.3 + (float)floor($pos->y) - $pos->y) * 0.1));
        } else {
            // On land: barely move, let gravity take over (PhysicsSystem).
            $vel->x *= 0.9;
            $vel->z *= 0.9;
        }
        $this->dampen($vel);
    }

    private function tickMinecart(World $world, ?ChunkStore $chunks, Entity $entity, PositionComponent $pos, VelocityComponent $vel, MetadataComponent $meta, int $worldId, float $deltaTime): void {
        $inputZ = (float)($meta->get(MetadataKeys::VEHICLE_INPUT_Z) ?? 0.0);
        $yaw = $entity->get(RotationComponent::class)?->yaw ?? 0.0;
        $rad = $yaw * M_PI / 180.0;

        $railY = $chunks !== null ? $this->railBelow($chunks, (int)floor($pos->x), (int)floor($pos->y - 0.5), (int)floor($pos->z)) : null;
        if ($railY !== null) {
            // On rails: keep the cart on the rail and roll with input.
            $speed = self::MINECART_SPEED * $deltaTime * 20.0;
            $vel->x = -sin($rad) * $speed * $inputZ;
            $vel->z = cos($rad) * $speed * $inputZ;
            $vel->y = 0.0;
            $pos->y = $railY + 0.5; // centre of the rail block
        } else {
            // Off rails: coast and fall.
            $vel->x *= 0.95;
            $vel->z *= 0.95;
        }
        $this->dampen($vel);
    }

    private function dampen(VelocityComponent $vel): void {
        // No rider: bleed off horizontal motion.
        $vel->x *= 0.9;
        $vel->z *= 0.9;
        if (abs($vel->x) < 0.0001) {
            $vel->x = 0.0;
        }
        if (abs($vel->z) < 0.0001) {
            $vel->z = 0.0;
        }
    }

    private function syncRider(World $world, int $riderId, PositionComponent $pos): void {
        $rider = $world->getEntity($riderId);
        $riderPos = $rider?->get(PositionComponent::class);
        if ($riderPos === null) {
            return;
        }
        // Seat height: ~0.5 above the vehicle centre.
        $riderPos->x = $pos->x;
        $riderPos->y = $pos->y + 0.5;
        $riderPos->z = $pos->z;

        // The vehicle owns the rider's motion while mounted: zero the
        // rider's own velocity so gravity/knockback never drag it off the
        // seat (the per-tick position sync overrides any integration).
        $riderVel = $rider?->get(VelocityComponent::class);
        if ($riderVel !== null) {
            $riderVel->x = 0.0;
            $riderVel->y = 0.0;
            $riderVel->z = 0.0;
            $riderVel->pending = null;
        }
    }

    private function isWater(ChunkStore $chunks, int $x, int $y, int $z): bool {
        if ($y < 0) {
            return false;
        }
        $id = $chunks->getBlock($x, $y, $z);
        return $id === 8 || $id === 9;
    }

    private function railBelow(ChunkStore $chunks, int $x, int $y, int $z): ?float {
        if ($y < 0) {
            return null;
        }
        $id = $chunks->getBlock($x, $y, $z);
        return in_array($id, self::RAIL_IDS, true) ? (float)$y : null;
    }

    private function chunkStore(World $world, int $worldId): ?ChunkStore {
        if ($worldId !== 0) {
            $registry = $world->getResourceRegistry()->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getStore($worldId) : null;
        }
        $store = $world->getResourceRegistry()->get(ChunkStore::class);
        return $store instanceof ChunkStore ? $store : null;
    }
}
