<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\storage;

use pocketmine\level\format\anvil\Anvil;
use pocketmine\level\format\leveldb\LevelDB;
use pocketmine\level\format\FullChunk;
use pocketmine\level\Level;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\EntitySnapshot;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\TileEntitySnapshot;
use pocketmine\Server;
use pocketmine\utils\Binary;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ByteArrayTag;

final class AnvilStorageAdapter implements StoragePort {
    private array $providers = [];

    public function __construct() {}

    private function getProvider(Level $level): Anvil|LevelDB {
        $folderName = $level->getFolderName();
        if (!isset($this->providers[$folderName])) {
            $provider = $level->getProvider();
            if ($provider instanceof Anvil || $provider instanceof LevelDB) {
                $this->providers[$folderName] = $provider;
            } else {
                throw new \RuntimeException("Unsupported level provider: " . get_class($provider));
            }
        }
        return $this->providers[$folderName];
    }

    public function loadChunk(int $chunkX, int $chunkZ): ChunkData {
        // This needs a Level context - for now return empty chunk
        // In practice, this would be called with a Level context
        return new ChunkData($chunkX, $chunkZ, [], [], [], [], []);
    }

    public function saveChunk(int $chunkX, int $chunkZ, ChunkData $data): void {
        // This needs a Level context
    }

    public function loadEntity(string $entityId): EntitySnapshot {
        return new EntitySnapshot($entityId, '', 0, 0, 0, 0, 0, []);
    }

    public function saveEntity(EntitySnapshot $snapshot): void {
        // Implementation would save entity to level.dat or chunk
    }

    public function saveAll(): void {
        $server = Server::getInstance();
        foreach ($server->getLevels() as $level) {
            if ($level->getAutoSave()) {
                $level->save();
            }
        }
    }

    /**
     * Load a chunk with full context (called from Level)
     */
    public function loadChunkWithContext(Level $level, int $chunkX, int $chunkZ): ChunkData {
        $provider = $this->getProvider($level);
        $chunk = $provider->getChunk($chunkX, $chunkZ, false);
        
        if (!$chunk instanceof FullChunk) {
            return new ChunkData($chunkX, $chunkZ, [], [], [], [], []);
        }

        // Extract sections
        $sections = [];
        foreach ($chunk->getSections() as $section) {
            if (!($section instanceof \pocketmine\level\format\generic\EmptyChunkSection)) {
                $sections[] = [
                    'y' => $section->getY(),
                    'blocks' => $section->getBlockIdArray(),
                    'data' => $section->getBlockDataArray(),
                    'skyLight' => $section->getBlockSkyLightArray(),
                    'blockLight' => $section->getBlockLightArray(),
                ];
            }
        }

        // Extract biomes
        $biomes = $chunk->getBiomeColorArray();
        if (count($biomes) !== 256) {
            $biomes = array_pad($biomes, 256, 0);
        }

        // Extract heightmap
        $heightmap = $chunk->getHeightMapArray();
        if (count($heightmap) !== 256) {
            $heightmap = array_pad($heightmap, 256, 0);
        }

        // Extract entities
        $entities = [];
        foreach ($chunk->getEntities() as $entity) {
            $entities[] = $this->serializeEntity($entity);
        }

        // Extract tile entities
        $tileEntities = [];
        foreach ($chunk->getTiles() as $tile) {
            $tileEntities[] = $this->serializeTileEntity($tile);
        }

        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, $entities, $tileEntities);
    }

    /**
     * Save a chunk with full context (called from Level)
     */
    public function saveChunkWithContext(Level $level, ChunkData $data): void {
        $provider = $this->getProvider($level);
        $chunk = $provider->getChunk($data->chunkX, $data->chunkZ, true);
        
        if (!$chunk instanceof FullChunk) {
            return;
        }

        // Apply sections
        foreach ($data->sections as $sectionData) {
            $y = $sectionData['y'];
            $section = $chunk->getSection($y);
            if ($section instanceof \pocketmine\level\format\generic\EmptyChunkSection) {
                $section = $provider->createChunkSection($y);
                $chunk->setSection($y, $section);
            }
            
            if (isset($sectionData['blocks'])) {
                $section->setBlockIdArray($sectionData['blocks']);
            }
            if (isset($sectionData['data'])) {
                $section->setBlockDataArray($sectionData['data']);
            }
            if (isset($sectionData['skyLight'])) {
                $section->setBlockSkyLightArray($sectionData['skyLight']);
            }
            if (isset($sectionData['blockLight'])) {
                $section->setBlockLightArray($sectionData['blockLight']);
            }
        }

        // Apply biomes
        if (!empty($data->biomes)) {
            $chunk->setBiomeColorArray($data->biomes);
        }

        // Apply heightmap
        if (!empty($data->heightmap)) {
            $chunk->setHeightMapArray($data->heightmap);
        }

        // Note: Entities and tile entities are handled separately by the Level
        // This method only saves block data

        $chunk->setChanged(true);
    }

    private function serializeEntity(\pocketmine\entity\Entity $entity): EntitySnapshot {
        $components = [];
        
        // Position
        $components['pocketmine\domain\component\PositionComponent'] = [
            'x' => $entity->x,
            'y' => $entity->y,
            'z' => $entity->z,
            'yaw' => $entity->yaw,
            'pitch' => $entity->pitch,
        ];

        // Velocity
        $components['pocketmine\domain\component\VelocityComponent'] = [
            'x' => $entity->motionX,
            'y' => $entity->motionY,
            'z' => $entity->motionZ,
        ];

        // Health
        $components['pocketmine\domain\component\HealthComponent'] = [
            'current' => $entity->getHealth(),
            'max' => $entity->getMaxHealth(),
        ];

        // Metadata
        $components['pocketmine\domain\component\MetadataComponent'] = [
            'data' => $entity->getAllMetadata(),
        ];

        // Tags
        if ($entity instanceof \pocketmine\Player) {
            $components['pocketmine\domain\component\tags\PlayerTag'] = true;
        } elseif ($entity->getNetworkId() > 0) {
            $components['pocketmine\domain\component\tags\MonsterTag'] = true;
        }

        return new EntitySnapshot(
            (string)$entity->getId(),
            get_class($entity),
            $entity->x,
            $entity->y,
            $entity->z,
            $entity->yaw,
            $entity->pitch,
            $components
        );
    }

    private function serializeTileEntity(\pocketmine\tile\Tile $tile): TileEntitySnapshot {
        $nbt = new NBT(NBT::LITTLE_ENDIAN);
        $compound = new CompoundTag("");
        $tile->writeSaveData($compound);
        $nbt->setData($compound);
        $data = $nbt->writeCompressed();

        return new TileEntitySnapshot(
            (string)$tile->getId(),
            get_class($tile),
            $tile->x,
            $tile->y,
            $tile->z,
            ['nbt' => base64_encode($data)]
        );
    }
}