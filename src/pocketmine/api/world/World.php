<?php

declare(strict_types=1);

namespace pocketmine\api\world;

use pocketmine\core\ecs\World as ECSWorld;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\QueryBuilder;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldConfig;
use pocketmine\core\service\ChunkLoadService;
use pocketmine\core\service\ChunkUnloadService;
use pocketmine\core\service\ChunkSendService;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\WorldGenPort;

class World {
    private ECSWorld $world;
    private string $name;
    private string $folderName;
    
    private ChunkLoadService $chunkLoadService;
    private ChunkUnloadService $chunkUnloadService;
    private ChunkSendService $chunkSendService;

    public function __construct(ECSWorld $world, string $name, string $folderName) {
        $this->world = $world;
        $this->name = $name;
        $this->folderName = $folderName;
        
        $kernel = \pocketmine\Kernel::getInstance();
        $this->chunkLoadService = $kernel->getChunkLoadService();
        $this->chunkUnloadService = $kernel->getChunkUnloadService();
        $this->chunkSendService = $kernel->getChunkSendService();

        // Keep the world-level config resource in sync with this facade.
        $config = $this->getWorldConfig();
        if ($config !== null) {
            $config->name = $name;
            $config->folderName = $folderName;
        }
    }

    public function getName(): string {
        return $this->name;
    }

    public function getFolderName(): string {
        return $this->folderName;
    }

    public function getEcsWorld(): ECSWorld {
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
            ->with(MetadataComponent::class)
            ->withTag(PlayerTag::class)
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
            ->with(PositionComponent::class)
            ->build();

        $entities = [];
        $radiusSq = $radius * $radius;

        foreach ($query as $entity) {
            $pos = $entity->get(PositionComponent::class);
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
            ->with(PositionComponent::class)
            ->build();

        $entities = [];
        foreach ($query as $entity) {
            $pos = $entity->get(PositionComponent::class);
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
        $store = $this->getChunkStore();
        return $store !== null && $store->isLoaded($chunkX, $chunkZ);
    }

    public function isChunkGenerated(int $chunkX, int $chunkZ): bool {
        $store = $this->getChunkStore();
        return $store !== null && $store->isGenerated($chunkX, $chunkZ);
    }

    public function isChunkPopulated(int $chunkX, int $chunkZ): bool {
        $store = $this->getChunkStore();
        return $store !== null && $store->isPopulated($chunkX, $chunkZ);
    }

    public function getBlock(int $x, int $y, int $z): int {
        $store = $this->getChunkStore();
        return $store?->getBlock($x, $y, $z) ?? 0;
    }

    public function setBlock(int $x, int $y, int $z, int $blockId, int $meta = 0): bool {
        $store = $this->getChunkStore();
        return $store?->setBlock($x, $y, $z, $blockId, $meta) ?? false;
    }

    public function getBlockMeta(int $x, int $y, int $z): int {
        $store = $this->getChunkStore();
        return $store?->getBlockMeta($x, $y, $z) ?? 0;
    }

    public function getHighestBlockAt(int $x, int $z): int {
        $store = $this->getChunkStore();
        return $store?->getHighestBlockAt($x, $z) ?? 0;
    }

    public function getBiome(int $x, int $z): int {
        $store = $this->getChunkStore();
        return $store?->getBiome($x, $z) ?? 0;
    }

    public function setBiome(int $x, int $z, int $biome): void {
        $store = $this->getChunkStore();
        $store?->setBiome($x, $z, $biome);
    }

    public function getTime(): int {
        return $this->getWorldConfig()?->time ?? 0;
    }

    public function setTime(int $time): void {
        $config = $this->getWorldConfig();
        if ($config !== null) {
            $config->time = $time;
        }
    }

    public function getSeed(): int {
        return $this->getWorldConfig()?->seed ?? 0;
    }

    public function setSeed(int $seed): void {
        $config = $this->getWorldConfig();
        if ($config !== null) {
            $config->seed = $seed;
        }
    }

    public function getSpawnLocation(): array {
        $config = $this->getWorldConfig();
        return $config !== null
            ? ['x' => $config->spawnX, 'y' => $config->spawnY, 'z' => $config->spawnZ]
            : ['x' => 0, 'y' => 64, 'z' => 0];
    }

    public function setSpawnLocation(float $x, float $y, float $z): void {
        $config = $this->getWorldConfig();
        if ($config !== null) {
            $config->spawnX = (int)$x;
            $config->spawnY = (int)$y;
            $config->spawnZ = (int)$z;
        }
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
        $this->world->spawn(
            (new \pocketmine\core\ecs\EntityBuilder())
                ->with(new PositionComponent($x, $y + 0.5, $z))
                ->with(new \pocketmine\core\component\VelocityComponent())
                ->with(new \pocketmine\core\component\HealthComponent(1, 1))
                ->with(new MetadataComponent(['xp' => $amount]))
                ->withTag('xp_orb')
        );
    }

    public function getChunkData(int $chunkX, int $chunkZ): ?ChunkData {
        $store = $this->getChunkStore();
        if ($store !== null && $store->isLoaded($chunkX, $chunkZ)) {
            return $store->toChunkData($chunkX, $chunkZ);
        }
        return $this->chunkLoadService->loadChunk($chunkX, $chunkZ);
    }

    public function setChunkData(ChunkData $data): void {
        $this->getChunkStore()?->load($data);
    }

    public function getGenerator(): string {
        return $this->getWorldConfig()?->generator ?? 'normal';
    }

    public function getDifficulty(): int {
        return $this->getWorldConfig()?->difficulty ?? 1;
    }

    public function setDifficulty(int $difficulty): void {
        $config = $this->getWorldConfig();
        if ($config !== null) {
            $config->difficulty = $difficulty;
        }
    }

    public function getGameMode(): int {
        return $this->getWorldConfig()?->gameMode ?? 0;
    }

    public function setGameMode(int $gamemode): void {
        $config = $this->getWorldConfig();
        if ($config !== null) {
            $config->gameMode = $gamemode;
        }
    }

    public function getMaxPlayers(): int {
        return $this->getWorldConfig()?->maxPlayers ?? 20;
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

    private function getChunkStore(): ?ChunkStore {
        $store = $this->world->getResourceRegistry()->get(ChunkStore::class);
        return $store instanceof ChunkStore ? $store : null;
    }

    private function getWorldConfig(): ?WorldConfig {
        $config = $this->world->getResourceRegistry()->get(WorldConfig::class);
        return $config instanceof WorldConfig ? $config : null;
    }
}
