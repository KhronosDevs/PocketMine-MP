<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\tags\DeadTag;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\core\service\NetworkSessionService;
use pocketmine\core\service\WorldEventService;

/**
 * Pressure plates (70 stone / 72 wooden) - entity-over-block activation.
 *
 * Legacy PressurePlate::onEntityCollide parity: when a living entity stands
 * on a plate (foot block = plate block) the plate's meta bit 0x08 is set
 * (pressed) with a click sound; when nothing stands on it any more the bit
 * clears after a short grace period (stone 1s, wood 0.5s) so plates don't
 * flicker between footsteps. Plate state is plain block meta - no extra
 * store, and it round-trips through the normal chunk persistence.
 *
 * Redstone signal emission is deferred to the (future) redstone engine; this
 * system only maintains the visual/audible pressed state.
 *
 * Per tick the system scans each world's LOADED chunks for plate blocks
 * within a small band around entity Y positions. To keep that O(entities)
 * rather than O(blocks), the scan walks only the chunk columns entities
 * actually occupy: for each alive entity the system probes the block at its
 * feet and one block below (plates are 1/16 tall, positions can round
 * either way), so a handful of block reads per entity per tick.
 */
final class PressurePlateSystem implements System {
    public const STONE_PLATE = 70;
    public const WOODEN_PLATE = 72;
    /** Block meta bit marking a pressed plate (same bit buttons/levers use). */
    public const PRESSED_BIT = 0x08;
    /** Unpress grace in ticks: stone 20, wood 10 (legacy ~1s / ~0.5s). */
    public const STONE_GRACE_TICKS = 20;
    public const WOODEN_GRACE_TICKS = 10;

    public function run(World $world, float $deltaTime): void {
        $resources = $world->getResourceRegistry();
        $registry = $resources->get(WorldRegistry::class);
        $blocks = $resources->get(BlockRegistry::class);
        $network = \pocketmine\Kernel::getInstance()?->getNetworkSessionService();
        $wes = \pocketmine\Kernel::getInstance()?->getWorldEventService();
        if (!$network instanceof NetworkSessionService) {
            $network = null;
        }
        if (!$wes instanceof WorldEventService) {
            $wes = null;
        }

        // Collect feet positions of alive entities grouped by world id.
        // Players and mobs both press plates; dead entities do not.
        $feetByWorld = [];
        foreach ($world->query()
            ->with(PositionComponent::class, HealthComponent::class)
            ->build() as $entity) {
            $health = $entity->get(HealthComponent::class);
            if ($health === null || $health->current <= 0 || $entity->has(DeadTag::class)) {
                continue;
            }
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            $wid = $entity->get(\pocketmine\core\component\WorldComponent::class)?->id ?? 0;
            $feetByWorld[$wid][] = [(int)floor($pos->x), (int)floor($pos->y), (int)floor($pos->z)];
        }

        // Tick every registered world's chunk store (plates live in block
        // space, so there is no per-plate state store to iterate).
        $worlds = [];
        if ($registry instanceof WorldRegistry) {
            foreach ($registry->getWorlds() as $worldId => $_) {
                $store = $registry->getStore((int)$worldId);
                if ($store instanceof ChunkStore) {
                    $worlds[(int)$worldId] = $store;
                }
            }
        } else {
            $store = $resources->get(ChunkStore::class);
            if ($store instanceof ChunkStore) {
                $worlds[0] = $store;
            }
        }

        foreach ($worlds as $worldId => $store) {
            $feet = $feetByWorld[$worldId] ?? [];
            // 1. Press plates under any living entity (dedup by coordinate -
            // several entities may stand on one plate).
            $pressedThisTick = [];
            foreach ($feet as [$fx, $fy, $fz]) {
                foreach ([$fy, $fy - 1] as $py) {
                    $id = $store->getBlock($fx, $py, $fz);
                    if ($id !== self::STONE_PLATE && $id !== self::WOODEN_PLATE) {
                        continue;
                    }
                    $key = $fx . ':' . $py . ':' . $fz;
                    $pressedThisTick[$key] = [$fx, $py, $fz, $id];
                    break; // one plate per column
                }
            }
            foreach ($pressedThisTick as [$px, $py, $pz, $id]) {
                $this->press($store, $network, $wes, $worldId, $px, $py, $pz, $id);
            }

            // 2. Unpress every loaded plate that nothing stands on and whose
            // grace period has expired. Scanning loaded chunks for plates
            // every tick is O(loaded blocks) - too hot; instead only plates
            // that are currently PRESSED carry meta 0x08, and the system
            // tracks them in a per-world transient registry below.
            $this->unpressExpired($store, $network, $wes, $worldId);
        }
    }

    /**
     * Per-world pressed-plate registry: "worldId|x:y:z" => plate info + the
     * tick after which the plate may unpress. Static so the tracker survives
     * across ticks regardless of system instance lifetime (the scheduler
     * registers one instance, but tests may re-create the system per run).
     * @var array<string, array{id: int, worldId: int, x: int, y: int, z: int, until: int}>
     */
    private static array $pressed = [];

    private function press(
        ChunkStore $store,
        ?NetworkSessionService $network,
        ?WorldEventService $wes,
        int $worldId,
        int $x,
        int $y,
        int $z,
        int $id,
    ): void {
        $meta = $store->getBlockMeta($x, $y, $z);
        $key = $worldId . '|' . $x . ':' . $y . ':' . $z;
        $tick = $this->nowTicks();
        $grace = $id === self::STONE_PLATE ? self::STONE_GRACE_TICKS : self::WOODEN_GRACE_TICKS;
        if (($meta & self::PRESSED_BIT) === 0) {
            $store->setBlock($x, $y, $z, $id, $meta | self::PRESSED_BIT);
            $network?->broadcastBlockState($x, $y, $z, $worldId);
            $wes?->playSound($worldId, (int)floor($x / 16), (int)floor($z / 16), $x + 0.5, $y + 0.5, $z + 0.5, WorldEventService::SOUND_BUTTON_CLICK);
        }
        self::$pressed[$key] = ['id' => $id, 'worldId' => $worldId, 'x' => $x, 'y' => $y, 'z' => $z, 'until' => $tick + $grace];
    }

    private function unpressExpired(ChunkStore $store, ?NetworkSessionService $network, ?WorldEventService $wes, int $worldId): void {
        if (self::$pressed === []) {
            return;
        }
        $tick = $this->nowTicks();
        foreach (self::$pressed as $key => $info) {
            if ($info['worldId'] !== $worldId || $info['until'] > $tick) {
                continue;
            }
            $x = $info['x'];
            $y = $info['y'];
            $z = $info['z'];
            $id = $info['id'];
            // The plate may have been broken/replaced while pressed.
            if ($store->getBlock($x, $y, $z) !== $id) {
                unset(self::$pressed[$key]);
                continue;
            }
            $meta = $store->getBlockMeta($x, $y, $z);
            if (($meta & self::PRESSED_BIT) !== 0) {
                $store->setBlock($x, $y, $z, $id, $meta & ~self::PRESSED_BIT);
                $network?->broadcastBlockState($x, $y, $z, $worldId);
            }
            unset(self::$pressed[$key]);
        }
    }

    private function nowTicks(): int {
        return \pocketmine\Kernel::getInstance()?->getResourceRegistry()
            ?->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0;
    }
}
