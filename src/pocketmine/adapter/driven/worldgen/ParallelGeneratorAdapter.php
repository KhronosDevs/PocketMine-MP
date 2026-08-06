<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\worldgen;

use pocketmine\level\generator\Generator;
use pocketmine\level\Level;
use pocketmine\math\Vector3;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\GeneratorConfig;
use pocketmine\port\driven\LightData;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\port\driven\WorldGenPort;
use pocketmine\Server;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;

final class ParallelGeneratorAdapter implements WorldGenPort {
    public function __construct(
        private readonly ThreadingPort $threadingPort,
    ) {}

    public function generateChunk(int $chunkX, int $chunkZ, GeneratorConfig $config): ChunkData {
        // For now, delegate to synchronous generator
        // In the future, this can submit to threadingPort
        return $this->generateChunkSync($chunkX, $chunkZ, $config);
    }

    public function populateChunk(int $chunkX, int $chunkZ, ChunkData $data): void {
        // Populate chunk with structures, ores, trees, etc.
        $this->populateChunkSync($chunkX, $chunkZ, $data);
    }

    public function calculateLight(int $chunkX, int $chunkZ, ChunkData $data): LightData {
        // Calculate sky light and block light for the chunk
        return $this->calculateLightSync($chunkX, $chunkZ, $data);
    }

    private function generateChunkSync(int $chunkX, int $chunkZ, GeneratorConfig $config): ChunkData {
        $server = Server::getInstance();
        $level = $server?->getDefaultLevel();
        
        if (!$level) {
            return new ChunkData($chunkX, $chunkZ, [], [], [], [], []);
        }

        $generator = $level->getGenerator();
        if (!$generator) {
            return new ChunkData($chunkX, $chunkZ, [], [], [], [], []);
        }

        // Generate using the level's generator
        $fullChunk = $generator->generateChunk($chunkX, $chunkZ);
        
        return $this->fullChunkToChunkData($fullChunk, $chunkX, $chunkZ);
    }

    private function populateChunkSync(int $chunkX, int $chunkZ, ChunkData $data): void {
        $server = Server::getInstance();
        $level = $server?->getDefaultLevel();
        
        if (!$level || !$level->getGenerator()) {
            return;
        }

        // Create a temporary FullChunk from ChunkData for the generator to populate
        // This is a simplified approach - in practice, the generator would work directly with ChunkData
    }

    private function calculateLightSync(int $chunkX, int $chunkZ, ChunkData $data): LightData {
        // Calculate sky light (from top down)
        $skyLight = [];
        $blockLight = [];
        
        foreach ($data->sections as $section) {
            $y = $section['y'];
            $sectionSkyLight = $this->calculateSkyLightForSection($section, $y);
            $sectionBlockLight = $this->calculateBlockLightForSection($section);
            
            $skyLight[] = $sectionSkyLight;
            $blockLight[] = $sectionBlockLight;
        }
        
        return new LightData($skyLight, $blockLight);
    }

    private function calculateSkyLightForSection(array $section, int $sectionY): string {
        // Sky light calculation - from top of world down
        // This is a simplified version
        return str_repeat("\xff", 2048); // Full sky light (4-bit per block = 2048 bytes for 4096 blocks)
    }

    private function calculateBlockLightForSection(array $section): string {
        // Block light from light sources
        return str_repeat("\x00", 2048); // No block light by default
    }

    private function fullChunkToChunkData(\pocketmine\level\format\FullChunk $chunk, int $chunkX, int $chunkZ): ChunkData {
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

        $biomes = $chunk->getBiomeColorArray();
        if (count($biomes) !== 256) {
            $biomes = array_pad($biomes, 256, 0);
        }

        $heightmap = $chunk->getHeightMapArray();
        if (count($heightmap) !== 256) {
            $heightmap = array_pad($heightmap, 256, 0);
        }

        $entities = [];
        foreach ($chunk->getEntities() as $entity) {
            $entities[] = $this->serializeEntity($entity);
        }

        $tileEntities = [];
        foreach ($chunk->getTiles() as $tile) {
            $tileEntities[] = $this->serializeTileEntity($tile);
        }

        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, $entities, $tileEntities);
    }

    private function serializeEntity(\pocketmine\entity\Entity $entity): EntitySnapshot {
        $components = [];
        
        $components['pocketmine\domain\component\PositionComponent'] = [
            'x' => $entity->x,
            'y' => $entity->y,
            'z' => $entity->z,
            'yaw' => $entity->yaw,
            'pitch' => $entity->pitch,
        ];

        $components['pocketmine\domain\component\VelocityComponent'] = [
            'x' => $entity->motionX,
            'y' => $entity->motionY,
            'z' => $entity->motionZ,
        ];

        $components['pocketmine\domain\component\HealthComponent'] = [
            'current' => $entity->getHealth(),
            'max' => $entity->getMaxHealth(),
        ];

        $components['pocketmine\domain\component\MetadataComponent'] = [
            'data' => $entity->getAllMetadata(),
        ];

        if ($entity instanceof \pocketmine\Player) {
            $components['pocketmine\domain\component\tags\PlayerTag'] = true;
        } elseif ($entity->getNetworkId() > 0) {
            $components['pocketmine\domain\component\tags\MonsterTag'] = true;
        }

        return new EntitySnapshot(
            (string)$entity->getId(),
            get_class($entity),
            $entity->x, $entity->y, $entity->z,
            $entity->yaw, $entity->pitch,
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
            $tile->x, $tile->y, $tile->z,
            ['nbt' => base64_encode($data)]
        );
    }
}