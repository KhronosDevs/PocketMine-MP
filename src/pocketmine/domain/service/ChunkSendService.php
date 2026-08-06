<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\ecs\World;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;

final class ChunkSendService {
    public const CHUNKS_PER_TICK = 4;
    public const VIEW_DISTANCE = 10;

    public function __construct(
        private readonly World $world,
        private readonly NetworkPort $networkPort,
    ) {}

    public function sendChunksToPlayer(PlayerRef $playerRef): void {
        $playerEntityRef = $this->findPlayerEntity($playerRef);
        if (!$playerEntityRef) return;
        
        $playerEntity = $playerEntityRef->getEntity();
        if (!$playerEntity) return;
        
        $position = $playerEntity->get(PositionComponent::class);
        if (!$position) return;
        
        $centerChunkX = (int)floor($position->x / 16);
        $centerChunkZ = (int)floor($position->z / 16);
        
        // Send chunks in view distance
        $chunksSent = 0;
        for ($dx = -self::VIEW_DISTANCE; $dx <= self::VIEW_DISTANCE && $chunksSent < self::CHUNKS_PER_TICK; $dx++) {
            for ($dz = -self::VIEW_DISTANCE; $dz <= self::VIEW_DISTANCE && $chunksSent < self::CHUNKS_PER_TICK; $dz++) {
                $chunkX = $centerChunkX + $dx;
                $chunkZ = $centerChunkZ + $dz;
                
                $distanceSq = $dx * $dx + $dz * $dz;
                if ($distanceSq > self::VIEW_DISTANCE * self::VIEW_DISTANCE) {
                    continue;
                }
                
                $this->sendChunkToPlayer($playerRef, $chunkX, $chunkZ);
                $chunksSent++;
            }
        }
    }

    public function sendChunkUpdates(PlayerRef $playerRef): void {
        // Send incremental chunk updates for chunks that changed
        // This would track which chunks the player has and what changed
    }

    private function findPlayerEntity(PlayerRef $playerRef): ?\pocketmine\domain\ecs\EntityRef {
        $query = $this->world->query()
            ->with(\pocketmine\domain\component\MetadataComponent::class)
            ->withTag('player')
            ->build();
        
        foreach ($query as $entity) {
            $metadata = $entity->get(\pocketmine\domain\component\MetadataComponent::class);
            if ($metadata && $metadata->get('uniqueId') === $playerRef->uniqueId) {
                return \pocketmine\domain\ecs\EntityRef::create($entity->id, $this->world);
            }
        }
        
        return null;
    }

    private function sendChunkToPlayer(PlayerRef $playerRef, int $chunkX, int $chunkZ): void {
        // NetworkSyncSystem will create FullChunkDataPacket and send via NetworkPort
        // This service coordinates the sending
        
        // For now, we just mark that this chunk should be sent
        // The actual packet creation happens in NetworkSyncSystem
    }

    public function getChunksInView(int $centerChunkX, int $centerChunkZ, int $viewDistance): array {
        $chunks = [];
        
        for ($dx = -$viewDistance; $dx <= $viewDistance; $dx++) {
            for ($dz = -$viewDistance; $dz <= $viewDistance; $dz++) {
                $distanceSq = $dx * $dx + $dz * $dz;
                if ($distanceSq <= $viewDistance * $viewDistance) {
                    $chunks[] = [$centerChunkX + $dx, $centerChunkZ + $dz];
                }
            }
        }
        
        return $chunks;
    }
}