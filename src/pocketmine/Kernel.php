<?php

declare(strict_types=1);

namespace pocketmine;

use pocketmine\adapter\driven\network\Protocol84NetworkAdapter;
use pocketmine\adapter\driven\storage\AnvilStorageAdapter;
use pocketmine\adapter\driven\threading\PmmpThreadPool;
use pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter;
use pocketmine\adapter\driving\console\ConsoleCommandAdapter;
use pocketmine\domain\ecs\ComponentRegistry;
use pocketmine\domain\ecs\ResourceRegistry;
use pocketmine\domain\ecs\SystemScheduler;
use pocketmine\domain\ecs\World;
use pocketmine\domain\service\PlayerJoinService;
use pocketmine\domain\service\PlayerLeaveService;
use pocketmine\domain\service\PlayerRespawnService;
use pocketmine\domain\service\ChunkLoadService;
use pocketmine\domain\service\ChunkUnloadService;
use pocketmine\domain\service\ChunkSendService;
use pocketmine\domain\service\BlockBreakService;
use pocketmine\domain\service\BlockPlaceService;
use pocketmine\domain\service\BlockUpdateService;
use pocketmine\domain\service\EntitySpawnService;
use pocketmine\domain\service\EntityDespawnService;
use pocketmine\domain\service\EntityInteractionService;
use pocketmine\domain\service\CombatService;
use pocketmine\domain\service\DamageService;
use pocketmine\domain\service\KnockbackService;
use pocketmine\domain\service\InventoryService;
use pocketmine\domain\service\CraftingService;
use pocketmine\domain\service\ContainerService;
use pocketmine\domain\system\ChunkUpdateSystem;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\port\driven\WorldGenPort;
use pocketmine\port\driving\CommandPort;
use pocketmine\port\driving\EventPort;
use pocketmine\port\driving\PluginPort;

final class Kernel {
    private bool $running = false;
    private array $tickDurations = [];

    private PlayerJoinService $playerJoinService;
    private PlayerLeaveService $playerLeaveService;
    private PlayerRespawnService $playerRespawnService;
    private ChunkLoadService $chunkLoadService;
    private ChunkUnloadService $chunkUnloadService;
    private ChunkSendService $chunkSendService;
    private BlockBreakService $blockBreakService;
    private BlockPlaceService $blockPlaceService;
    private BlockUpdateService $blockUpdateService;
    private EntitySpawnService $entitySpawnService;
    private EntityDespawnService $entityDespawnService;
    private EntityInteractionService $entityInteractionService;
    private CombatService $combatService;
    private DamageService $damageService;
    private KnockbackService $knockbackService;
    private InventoryService $inventoryService;
    private CraftingService $craftingService;
    private ContainerService $containerService;

    public function __construct(
        private readonly NetworkPort $networkPort,
        private readonly StoragePort $storagePort,
        private readonly WorldGenPort $worldGenPort,
        private readonly ThreadingPort $threadingPort,
        private readonly CommandPort $commandPort,
        private readonly EventPort $eventPort,
        private readonly PluginPort $pluginPort,
        private readonly World $world,
        private readonly SystemScheduler $systemScheduler,
        private readonly ComponentRegistry $componentRegistry,
        private readonly ResourceRegistry $resourceRegistry,
    ) {
        $this->playerJoinService = new PlayerJoinService($world, $networkPort, $storagePort, $worldGenPort);
        $this->playerLeaveService = new PlayerLeaveService($world, $networkPort, $storagePort);
        $this->playerRespawnService = new PlayerRespawnService($world, $storagePort);
        $this->chunkLoadService = new ChunkLoadService($world, $storagePort, $worldGenPort);
        $this->chunkUnloadService = new ChunkUnloadService($world, $storagePort);
        $this->chunkSendService = new ChunkSendService($world, $networkPort);
        $this->blockBreakService = new BlockBreakService($world, $storagePort);
        $this->blockPlaceService = new BlockPlaceService($world);
        $this->blockUpdateService = new BlockUpdateService($world, $storagePort);
        $this->entitySpawnService = new EntitySpawnService($world, $storagePort);
        $this->entityDespawnService = new EntityDespawnService($world, $storagePort);
        $this->entityInteractionService = new EntityInteractionService($world);
        $this->combatService = new CombatService($world);
        $this->damageService = new DamageService($world);
        $this->knockbackService = new KnockbackService($world);
        $this->inventoryService = new InventoryService($world);
        $this->craftingService = new CraftingService($world);
        $this->containerService = new ContainerService($world);
    }

    public function run(int $maxTicks = -1): void {
        $this->running = true;
        $tick = 0;

        while ($this->running && ($maxTicks < 0 || $tick < $maxTicks)) {
            $start = hrtime(true);

            // 1. Process network commands (from network thread in future)
            $this->networkPort->processPendingCommands();

            // 2. Run application services (use cases)
            // TODO: Implement service execution

            // 3. Run ECS systems
            $this->systemScheduler->run($this->world, 0.05); // 20 TPS = 50ms

            // 4. Flush network sync
            $this->networkPort->flushOutboundPackets();

            // 5. Storage autosave (periodic)
            if ($tick % 6000 === 0) {
                $this->storagePort->saveAll();
            }

            $end = hrtime(true);
            $elapsedMs = ($end - $start) / 1_000_000;
            $this->recordTickDuration($elapsedMs);
            $targetMs = 50.0;

            if ($elapsedMs < $targetMs) {
                $sleepUs = (int)(($targetMs - $elapsedMs) * 1000);
                if ($sleepUs > 0) {
                    usleep($sleepUs);
                }
            }

            $tick++;
        }

        $this->shutdown();
    }

    private function recordTickDuration(float $ms): void {
        $this->tickDurations[] = $ms;
        if (count($this->tickDurations) > 10000) {
            array_shift($this->tickDurations);
        }
    }

    public function getTickStats(): array {
        if (empty($this->tickDurations)) {
            return ['count' => 0];
        }
        $count = count($this->tickDurations);
        $sum = array_sum($this->tickDurations);
        $mean = $sum / $count;
        sort($this->tickDurations);
        $p50 = $this->tickDurations[(int)($count * 0.5)];
        $p95 = $this->tickDurations[(int)($count * 0.95)];
        $p99 = $this->tickDurations[(int)($count * 0.99)];
        $max = max($this->tickDurations);
        $min = min($this->tickDurations);
        $variance = array_sum(array_map(fn($x) => ($x - $mean) ** 2, $this->tickDurations)) / $count;
        $stddev = sqrt($variance);

        return [
            'count' => $count,
            'mean_ms' => round($mean, 3),
            'median_ms' => round($p50, 3),
            'p95_ms' => round($p95, 3),
            'p99_ms' => round($p99, 3),
            'min_ms' => round($min, 3),
            'max_ms' => round($max, 3),
            'stddev_ms' => round($stddev, 3),
        ];
    }

    public function shutdown(): void {
        $this->running = false;
        $this->threadingPort->shutdown();
        $this->storagePort->saveAll();
    }

    public function getWorld(): World {
        return $this->world;
    }

    public function getSystemScheduler(): SystemScheduler {
        return $this->systemScheduler;
    }

    public function getComponentRegistry(): ComponentRegistry {
        return $this->componentRegistry;
    }

    public function getResourceRegistry(): ResourceRegistry {
        return $this->resourceRegistry;
    }

    public function getNetworkPort(): NetworkPort {
        return $this->networkPort;
    }

    public function getStoragePort(): StoragePort {
        return $this->storagePort;
    }

    public function getWorldGenPort(): WorldGenPort {
        return $this->worldGenPort;
    }

    public function getThreadingPort(): ThreadingPort {
        return $this->threadingPort;
    }

    public function getCommandPort(): CommandPort {
        return $this->commandPort;
    }

    public function getEventPort(): EventPort {
        return $this->eventPort;
    }

    public function getPluginPort(): PluginPort {
        return $this->pluginPort;
    }

    public function getPlayerJoinService(): PlayerJoinService {
        return $this->playerJoinService;
    }

    public function getPlayerLeaveService(): PlayerLeaveService {
        return $this->playerLeaveService;
    }

    public function getPlayerRespawnService(): PlayerRespawnService {
        return $this->playerRespawnService;
    }

    public function getChunkLoadService(): ChunkLoadService {
        return $this->chunkLoadService;
    }

    public function getChunkUnloadService(): ChunkUnloadService {
        return $this->chunkUnloadService;
    }

    public function getChunkSendService(): ChunkSendService {
        return $this->chunkSendService;
    }

    public function getBlockBreakService(): BlockBreakService {
        return $this->blockBreakService;
    }

    public function getBlockPlaceService(): BlockPlaceService {
        return $this->blockPlaceService;
    }

    public function getBlockUpdateService(): BlockUpdateService {
        return $this->blockUpdateService;
    }

    public function getEntitySpawnService(): EntitySpawnService {
        return $this->entitySpawnService;
    }

    public function getEntityDespawnService(): EntityDespawnService {
        return $this->entityDespawnService;
    }

    public function getEntityInteractionService(): EntityInteractionService {
        return $this->entityInteractionService;
    }

    public function getCombatService(): CombatService {
        return $this->combatService;
    }

    public function getDamageService(): DamageService {
        return $this->damageService;
    }

    public function getKnockbackService(): KnockbackService {
        return $this->knockbackService;
    }

    public function getInventoryService(): InventoryService {
        return $this->inventoryService;
    }

    public function getCraftingService(): CraftingService {
        return $this->craftingService;
    }

    public function getContainerService(): ContainerService {
        return $this->containerService;
    }
}

function createKernel(): Kernel {
    $threadingPort = createThreadingPort();
    $networkPort = createNetworkPort();
    $storagePort = createStoragePort();
    $worldGenPort = createWorldGenPort($threadingPort);
    $commandPort = createCommandPort();
    $eventPort = createEventPort();
    $pluginPort = createPluginPort($commandPort, $eventPort);

    $componentRegistry = new ComponentRegistry();
    $resourceRegistry = new ResourceRegistry();
    $systemScheduler = new SystemScheduler($threadingPort);
    $world = new World($componentRegistry, $resourceRegistry, $systemScheduler);

    // Register built-in components
    registerBuiltinComponents($componentRegistry);

    // Register built-in resources
    registerBuiltinResources($resourceRegistry);

    // Register built-in systems
    registerBuiltinSystems($systemScheduler);

    return new Kernel(
        $networkPort,
        $storagePort,
        $worldGenPort,
        $threadingPort,
        $commandPort,
        $eventPort,
        $pluginPort,
        $world,
        $systemScheduler,
        $componentRegistry,
        $resourceRegistry,
    );
}

function createThreadingPort(): ThreadingPort {
    $workerCount = max(1, (int)shell_exec('nproc') - 2);
    return new PmmpThreadPool($workerCount);
}

function createNetworkPort(): NetworkPort {
    return new Protocol84NetworkAdapter("0.0.0.0", 19132);
}

function createStoragePort(): StoragePort {
    return new AnvilStorageAdapter();
}

function createWorldGenPort(ThreadingPort $threadingPort): WorldGenPort {
    return new ParallelGeneratorAdapter($threadingPort);
}

function createCommandPort(): CommandPort {
    return new ConsoleCommandAdapter();
}

function createEventPort(): EventPort {
    return new \pocketmine\adapter\driving\plugin\PluginEventAdapter();
}

function createPluginPort(CommandPort $commandPort, EventPort $eventPort): PluginPort {
    return new PluginManagerAdapter($commandPort, $eventPort);
}

function registerBuiltinComponents(ComponentRegistry $registry): void {
    $registry->register(\pocketmine\domain\component\PositionComponent::class);
    $registry->register(\pocketmine\domain\component\RotationComponent::class);
    $registry->register(\pocketmine\domain\component\VelocityComponent::class);
    $registry->register(\pocketmine\domain\component\CollisionComponent::class);
    $registry->register(\pocketmine\domain\component\HealthComponent::class);
    $registry->register(\pocketmine\domain\component\MetadataComponent::class);
    $registry->register(\pocketmine\domain\component\EffectComponent::class);
    $registry->register(\pocketmine\domain\component\AttributeComponent::class);
    $registry->register(\pocketmine\domain\component\InventoryComponent::class);
    $registry->register(\pocketmine\domain\component\AIStateComponent::class);
    $registry->register(\pocketmine\domain\component\PathComponent::class);
    $registry->register(\pocketmine\domain\component\tags\PlayerTag::class);
    $registry->register(\pocketmine\domain\component\tags\MonsterTag::class);
    $registry->register(\pocketmine\domain\component\tags\OnGroundTag::class);
    $registry->register(\pocketmine\domain\component\tags\InvisibleTag::class);
    $registry->register(\pocketmine\domain\component\tags\DeadTag::class);
    $registry->register(\pocketmine\domain\component\tags\SpectatorTag::class);
}

function registerBuiltinResources(ResourceRegistry $registry): void {
    $registry->set(new \pocketmine\domain\resource\TickCounter());
    $registry->set(new \pocketmine\domain\resource\ServerConfig());
    $registry->set(new \pocketmine\domain\resource\SpatialIndex());
}

function registerBuiltinSystems(SystemScheduler $scheduler): void {
    $scheduler->register(new \pocketmine\domain\system\PhysicsSystem(), \pocketmine\domain\ecs\SystemPhase::PARALLEL);
    $scheduler->register(new \pocketmine\domain\system\MovementSystem(), \pocketmine\domain\ecs\SystemPhase::PARALLEL);
    $scheduler->register(new \pocketmine\domain\system\EffectSystem(), \pocketmine\domain\ecs\SystemPhase::PARALLEL);
    $scheduler->register(new \pocketmine\domain\system\AISystem(), \pocketmine\domain\ecs\SystemPhase::PARALLEL);
    $scheduler->register(new \pocketmine\domain\system\ChunkUpdateSystem(), \pocketmine\domain\ecs\SystemPhase::CHUNK_PARALLEL);
}

function bootstrap(): Kernel {
    return createKernel();
}