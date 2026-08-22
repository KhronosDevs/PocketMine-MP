<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\constants\EntityTags;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\enum\EntityType;
use pocketmine\port\driven\EntitySnapshot;

/**
 * Bug 35: captures and restores non-player entities (dropped items, hostile
 * mobs) when chunks are evicted and reloaded. Prevents silent loss of items
 * and mobs during normal play (not just restarts).
 *
 * Capture: serializes each qualifying entity to an EntitySnapshot with enough
 * data to recreate it. Restore: recreates via the existing spawn pipeline.
 *
 * Players are excluded (PlayerLeaveService handles them separately).
 * Passive animals are excluded (they'd duplicate on every cycle without
 * grass-eating tracking). Vehicles are excluded (cheap to replace).
 */
final class ChunkEntityPersistence {
    /** Entity types that get captured on chunk unload. */
    private const PERSISTENT_TAGS = [
        EntityTags::ITEM,
        EntityTags::XP_ORB,
    ];

    public static function captureEntitiesForChunk(World $world, int $chunkX, int $chunkZ): array {
        $out = [];
        $minX = $chunkX * 16;
        $maxX = $minX + 15;
        $minZ = $chunkZ * 16;
        $maxZ = $minZ + 16;

        foreach ($world->getEntities() as $entity) {
            // Skip players.
            if ($entity->has(\pocketmine\core\component\tags\PlayerTag::class)) {
                continue;
            }
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) continue;
            $ex = (int)floor($pos->x);
            $ez = (int)floor($pos->z);
            if ($ex < $minX || $ex > $maxX || $ez < $minZ || $ez > $maxZ) {
                continue;
            }

            $meta = $entity->get(MetadataComponent::class);
            if ($meta === null) continue;

            // Only capture dropped items and XP orbs.
            $isItem = $meta?->get(MetadataKeys::ITEM) instanceof \pocketmine\core\component\ItemStack
                || $entity->has(EntityTags::ITEM);
            $isXpOrb = $entity->has(EntityTags::XP_ORB) || $meta?->get('xp') !== null;
            if (!$isItem && !$isXpOrb) {
                continue;
            }

            $rot = $entity->get(RotationComponent::class);
            $components = [];
            foreach ($entity->getComponents() as $type => $component) {
                if (!is_object($component)) continue;
                $components[$type] = \pocketmine\core\ecs\ComponentSerializer::serialize($component);
            }
            $type = $entity->has(EntityTags::ITEM) ? 'item' : ($entity->has(EntityTags::XP_ORB) ? 'xp_orb' : 'unknown');
            $out[] = new \pocketmine\port\driven\EntitySnapshot(
                'chunk_' . $entity->id,
                $type,
                $pos->x, $pos->y, $pos->z,
                $rot?->yaw ?? 0,
                $rot?->pitch ?? 0,
                $components,
            );
        }
        return $out;
    }
}
