<?php

declare(strict_types=1);

namespace pocketmine\api\level;

use pocketmine\core\ecs\World;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\QueryBuilder;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\service\ChunkLoadService;
use pocketmine\core\service\ChunkUnloadService;
use pocketmine\core\service\ChunkSendService;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\WorldGenPort;

class Level {
    private World $world;
    private string $name;
    private string $folderName;
    
    private ChunkLoadService $chunkLoadService;
    private ChunkUnloadService $chunkUnloadService;
    private ChunkSendService $chunkSendService;

    public function __construct(World $world, string $name, string $folderName) {
        $this->world = $world;
        $this->name = $name;
        $this->folderName = $folderName;
        
        $kernel = \pocketmine\Kernel::getInstance();
        $this->chunkLoadService = $kernel->getChunkLoadService();
        $this->chunkUnloadService = $kernel->getChunkUnloadService();
        $this->chunkSendService = $kernel->getChunkSendService();
    }

    public function getName(): string {
        return $this->name;
    }

    public function getFolderName(): string {
        return $this->folderName;
    }

    public function getWorld(): World {
        return $this->world;
    }

    public function getEntities(): array {
        $entities = [];
        foreach ($this->world->getEntities() as $entity) {
            $entities[] = \pocketmine\api\entity\Entity::wrap(
                EntityRef::create($entity->id, $this->world),
                $this->world
            );
        }
        return $entities;
    }

    public function getPlayers(): array {
        $players = [];
        $query = $this->world->query()
            ->with(\pocketmine\core\component\MetadataComponent::class)
            ->withTag(\pocketmine\core\component\tags\PlayerTag::class)
            ->build();
        
        foreach ($query as $entity) {
            $players[] = new \pocketmine\api\entity\Player(
                EntityRef::create($entity->id, $this->world),
                $this->world
            );
        }
        return $players;
    }

    public function getEntity(int $entityId): ?\pocketmine\api\entity\Entity {
        $entity = $this->world->getEntity($entityId);
        if (!$entity) return null;
        return \pocketmine\api\entity\Entity::wrap(
            EntityRef::create($entity->id, $this->world),
            $this->world
        );
    }

    public function getEntitiesInRadius(float $x, float $y, float $z, float $radius): array {
        $query = $this->world->query()
            ->with(\pocketmine\core\component\PositionComponent::class)
            ->build();

        $entities = [];
        $radiusSq = $radius * $radius;

        foreach ($query as $entity) {
            $pos = $entity->get(\pocketmine\core\component\PositionComponent::class);
            if (!$pos) continue;

            $dx = $pos->x - $x;
            $dy = $pos->y - $y;
            $dz = $pos->z - $z;
            if ($dx * $dx + $dy * $dy + $dz * $dz <= $radiusSq) {
                $entities[] = \pocketmine\api\entity\Entity::wrap(
                    EntityRef::create($entity->id, $this->world),
                    $this->world
                );
            }
        }

        return $entities;
    }

    public function getEntitiesInChunk(int $chunkX, int $chunkZ): array {
        $query = $this->world->query()
            ->with(\pocketmine\core\component\PositionComponent::class)
            ->build();

        $entities = [];
        foreach ($query as $entity) {
            $pos = $entity->get(\pocketmine\core\component\PositionComponent::class);
            if (!$pos) continue;

            $entityChunkX = (int)floor($pos->x / 16);
            $entityChunkZ = (int)floor($pos->z / 16);
            if ($entityChunkX === $chunkX && $entityChunkZ === $chunkZ) {
                $entities[] = \pocketmine\api\entity\Entity::wrap(
                    EntityRef::create($entity->id, $this->world),
                    $this->world
                );
            }
        }

        return $entities;
    }

    public function loadChunk(int $chunkX, int $chunkZ): ChunkData {
        return $this->chunkLoadService->loadChunk($chunkX, $chunkZ);
    }

    public function unloadChunk(int $chunkX, int $chunkZ): void {
        $this->chunkUnloadService->unloadChunk($chunkX, $chunkZ);
    }

    public function isChunkLoaded(int $chunkX, int $chunkZ): bool {
        // Would check if chunk is loaded
        return true; // Simplified
    }

    public function getBlock(int $x, int $y, int $z): int {
        // Would query chunk data
        return 0; // Simplified
    }

    public function setBlock(int $x, int $y, int $z, int $blockId, int $meta = 0): bool {
        // Would set block in chunk data
        return true; // Simplified
    }

    public function getBlockMeta(int $x, int $y, int $z): int {
        return 0; // Simplified
    }

    public function getHighestBlockAt(int $x, int $z): int {
        // Would query heightmap
        return 0; // Simplified
    }

    public function getBiome(int $x, int $z): int {
        // Would query biome data
        return 0; // Simplified
    }

    public function setBiome(int $x, int $z, int $biome): void {
        // Would set biome
    }

    public function getTime(): int {
        $metadata = new \pocketmine\core\component\MetadataComponent();
        // Would get from level metadata
        return 0;
    }

    public function setTime(int $time): void {
        // Would set level time
    }

    public function getSeed(): int {
        return 0; // Simplified
    }

    public function getSpawnLocation(): array {
        return ['x' => 0, 'y' => 64, 'z' => 0]; // Simplified
    }

    public function setSpawnLocation(float $x, float $y, float $z): void {
        // Would set spawn
    }

    public function addEntity(\pocketmine\api\entity\Entity $entity): void {
        $core = $entity->getInternalRef()->getEntity();
        if ($core) {
            $this->world->addEntity($core);
        }
    }

    public function removeEntity(\pocketmine\api\entity\Entity $entity): void {
        $core = $entity->getInternalRef()->getEntity();
        if ($core) {
            $this->world->despawn($core);
        }
    }

    public function dropItem(float $x, float $y, float $z, \pocketmine\core\component\ItemStack $item): void {
        \pocketmine\api\entity\ItemEntity::create($x, $y, $z, $item);
    }

    public function dropExp(float $x, float $y, float $z, int $amount): void {
        // Would spawn XP orbs
    }

    public function getChunkData(int $chunkX, int $chunkZ): ?ChunkData {
        return $this->chunkLoadService->loadChunk($chunkX, $chunkZ);
    }

    public function setChunkData(ChunkData $data): void {
        // Would save chunk data
    }

    public function isChunkGenerated(int $chunkX, int $chunkZ): bool {
        return true; // Simplified
    }

    public function isChunkPopulated(int $chunkX, int $chunkZ): bool {
        return true; // Simplified
    }

    public function getGenerator(): string {
        return 'default'; // Simplified
    }

    public function getDifficulty(): int {
        return 1; // Simplified
    }

    public function setDifficulty(int $difficulty): void {
        // Would set difficulty
    }

    public function getGameMode(): int {
        return 0; // Simplified
    }

    public function setGameMode(int $gamemode): void {
        // Would set gamemode
    }

    public function getMaxPlayers(): int {
        return 20; // Simplified
    }

    public function getOnlinePlayers(): array {
        return $this->getPlayers();
    }

    public function getPlayerByName(string $name): ?\pocketmine\api\entity\Player {
        foreach ($this->getPlayers() as $player) {
            if (strtolower($player->getName()) === strtolower($name)) {
                return $player;
            }
        }
        return null;
    }

    public function getPlayerByUniqueId(string $uniqueId): ?\pocketmine\api\entity\Player {
        foreach ($this->getPlayers() as $player) {
            if ($player->getUniqueId() === $uniqueId) {
                return $player;
            }
        }
        return null;
    }

    public function getPlayerById(int $id): ?\pocketmine\api\entity\Player {
        foreach ($this->getPlayers() as $player) {
            if ($player->getId() === $id) {
                return $player;
            }
        }
        return null;
    }

    public function getEntitiesByClass(string $className): array {
        $entities = [];
        foreach ($this->getEntities() as $entity) {
            if ($entity instanceof $className) {
                $entities[] = $entity;
            }
        }
        return $entities;
    }

    public function getEntitiesByTag(string $tag): array {
        $entities = [];
        $query = $this->world->query()
            ->withTag($tag)
            ->build();
        
        foreach ($query as $entity) {
            $entities[] = \pocketmine\api\entity\Entity::wrap(
                EntityRef::create($entity->id, $this->world),
                $this->world
            );
        }
        return $entities;
    }
}