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
        $bucket = self::bucketizeEntities($world);
        $key = $chunkX . ',' . $chunkZ;
        return $bucket[$key] ?? [];
    }

    /**
     * Single O(M) pass that groups persistent entities (items, XP orbs)
     * by chunk key. Call this once before processing multiple chunks, then
     * use the returned map for O(1) lookups per chunk — instead of
     * re-scanning all entities for each chunk (O(N×M) → O(N+M)).
     *
     * @return array<string, list<\pocketmine\port\driven\EntitySnapshot>> chunkKey => snapshots
     */
    public static function bucketizeEntities(World $world): array {
        $buckets = [];
        foreach ($world->getEntities() as $entity) {
            if ($entity->has(\pocketmine\core\component\tags\PlayerTag::class)) {
                continue;
            }
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) continue;
            $meta = $entity->get(MetadataComponent::class);
            if ($meta === null) continue;
            $isItem = $meta?->get(MetadataKeys::ITEM) instanceof \pocketmine\core\component\ItemStack
                || $entity->has(EntityTags::ITEM);
            $isXpOrb = $entity->has(EntityTags::XP_ORB) || $meta?->get('xp') !== null;
            if (!$isItem && !$isXpOrb) continue;

            $chunkKey = ((int)floor($pos->x) >> 4) . ',' . ((int)floor($pos->z) >> 4);
            $rot = $entity->get(RotationComponent::class);
            $components = [];
            foreach ($entity->getComponents() as $type => $component) {
                if (!is_object($component)) continue;
                $components[$type] = \pocketmine\core\ecs\ComponentSerializer::serialize($component);
            }
            $type = $entity->has(EntityTags::ITEM) ? 'item' : ($entity->has(EntityTags::XP_ORB) ? 'xp_orb' : 'unknown');
            $buckets[$chunkKey][] = new \pocketmine\port\driven\EntitySnapshot(
                'chunk_' . $entity->id,
                $type,
                $pos->x, $pos->y, $pos->z,
                $rot?->yaw ?? 0,
                $rot?->pitch ?? 0,
                $components,
            );
        }
        return $buckets;
    }
}
