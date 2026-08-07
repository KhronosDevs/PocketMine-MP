<?php

declare(strict_types=1);

namespace pocketmine;

use pocketmine\adapter\driven\network\Protocol84NetworkAdapter;
use pocketmine\adapter\driven\storage\AnvilStorageAdapter;
use pocketmine\adapter\driven\threading\PmmpThreadPool;
use pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter;
use pocketmine\adapter\driving\console\ConsoleCommandAdapter;
use pocketmine\adapter\driving\plugin\PluginManagerAdapter;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\ComponentRegistry;
use pocketmine\core\ecs\ResourceRegistry;
use pocketmine\core\ecs\SystemScheduler;
use pocketmine\core\ecs\World;
use pocketmine\core\thread\CoordinationThread;
use pocketmine\core\thread\NetworkThread;
use pocketmine\core\thread\RegionThread;
use pocketmine\core\service\PlayerJoinService;
use pocketmine\core\service\PlayerLeaveService;
use pocketmine\core\service\PlayerRespawnService;
use pocketmine\core\service\ChunkLoadService;
use pocketmine\core\service\ChunkUnloadService;
use pocketmine\core\service\ChunkSendService;
use pocketmine\core\service\BlockBreakService;
use pocketmine\core\service\BlockPlaceService;
use pocketmine\core\service\BlockUpdateService;
use pocketmine\core\service\EntitySpawnService;
use pocketmine\core\service\EntityDespawnService;
use pocketmine\core\service\EntityInteractionService;
use pocketmine\core\service\CombatService;
use pocketmine\core\service\DamageService;
use pocketmine\core\service\KnockbackService;
use pocketmine\core\service\InventoryService;
use pocketmine\core\service\CraftingService;
use pocketmine\core\service\ContainerService;
use pocketmine\core\system\ChunkUpdateSystem;
use pocketmine\core\system\MovementSystem;
use pocketmine\core\system\PhysicsSystem;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\port\driven\WorldGenPort;
use pocketmine\port\driving\CommandPort;
use pocketmine\port\driving\EventPort;
use pocketmine\port\driving\PluginPort;
use pmmp\thread\Thread;

final class Kernel {
    private static ?self $instance = null;

    private bool $running = false;
    private bool $shutdownComplete = false;
    private array $tickDurations = [];

    private CoordinationThread $coordinationThread;
    private NetworkThread $networkThread;
    private array $regionThreads = [];
    private bool $threadsStarted = false;
    private string $dataPath;
    private int $startTime;

    // --- Region pipeline (Phase 9) ---------------------------------------
    // Mirror entities to region workers each tick; workers integrate position
    // from velocity (plus gravity) in lockstep; results are compared (gate
    // mode) or applied (apply mode). Off by default: zero behavior change.
    private bool $regionPipelineEnabled = false;
    private bool $regionPipelineApply = false;
    /** @var array<int, int> entityId => owning region id */
    private array $pipelineMirroredIds = [];
    private int $pipelineTickSeq = 0;
    private int $pipelineMirroredThisTick = 0;
    private array $pipelineStats = [
        'enabled' => false,
        'applyMode' => false,
        'mirrored' => 0,
        'despawned' => 0,
        'received' => 0,
        'applied' => 0,
        'compared' => 0,
        'mismatches' => 0,
        'lagged' => 0,
        'stale' => 0,
        'lastTickMismatches' => 0,
    ];

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
    private \pocketmine\api\scheduler\Scheduler $scheduler;

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

        // Single scheduler instance: it registers a tick system that runs tasks,
        // so it must not be recreated per access.
        $this->scheduler = new \pocketmine\api\scheduler\Scheduler($world, $systemScheduler, $threadingPort);

        $this->dataPath = getcwd() . DIRECTORY_SEPARATOR;
        $this->startTime = time();
        self::$instance = $this;

        // Initialize region-based architecture
        $this->initializeRegions();
    }

    public static function getInstance(): ?self {
        return self::$instance;
    }

    public function isRunning(): bool {
        return $this->running;
    }

    public function getDataPath(): string {
        return $this->dataPath;
    }

    public function getUptime(): int {
        return time() - $this->startTime;
    }

    private function initializeRegions(): void {
        // Create coordination thread (thread-safe values only)
        $this->coordinationThread = new CoordinationThread();

        // Create network pipeline worker (socket I/O stays on the main thread)
        $this->networkThread = new NetworkThread();

        // Create region threads (for now, a single region covering the whole world)
        // In the future, this would be split based on world size.
        // The ECS world itself remains on the main thread; region threads
        // process serialized snapshots via thread-safe queues.
        $regionThread = new RegionThread(
            0, // regionId
            -1000, 1000, // minChunkX, maxChunkX
            -1000, 1000, // minChunkZ, maxChunkZ
        );

        $this->regionThreads[0] = $regionThread;
        $this->coordinationThread->addRegion(0, $regionThread->getCommandQueue());
    }

    public function run(int $maxTicks = -1): void {
        if ($this->running) {
            return;
        }
        $this->running = true;
        $tick = 0;

        // Start threads only on the first invocation (a started/joined Thread
        // cannot be restarted, so subsequent run() calls reuse the main-thread
        // ECS loop without worker threads).
        $ownsThreads = !$this->threadsStarted;
        if ($ownsThreads) {
            $this->coordinationThread->start(Thread::INHERIT_ALL);
            $this->networkThread->start(Thread::INHERIT_ALL);
            foreach ($this->regionThreads as $regionThread) {
                $regionThread->start(Thread::INHERIT_ALL);
            }
            $this->threadsStarted = true;
        }

        while ($this->running && ($maxTicks < 0 || $tick < $maxTicks)) {
            $start = hrtime(true);

            // 0a. Mirror entity snapshots to region workers (pipeline).
            if ($this->regionPipelineEnabled && $this->threadsStarted) {
                $this->mirrorEntitiesToRegions();
            }

            // 0. Tick the ECS world on the main thread (ownership model)
            $this->world->tick(0.05);

            // 0b. Drain worker results: compare (gate) or apply.
            if ($this->regionPipelineEnabled && $this->threadsStarted) {
                $this->drainRegionResults();
            }

            // 1. Main thread acts as coordinator - process global events
            $this->processGlobalCoordination();

            // 2. Flush network sync from all regions
            $this->flushNetworkSync();

            // 3. Storage autosave (periodic)
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

        $this->running = false;
        if ($ownsThreads) {
            $this->shutdown();
        }
    }

    // --- Region pipeline (Phase 9) ---------------------------------------

    /**
     * Enable the region worker pipeline. In gate mode (apply off) the main
     * thread continues to simulate movement and the worker's results are
     * compared for determinism. Results are reported via getRegionPipelineStats().
     */
    public function setRegionPipelineEnabled(bool $enabled): void {
        $this->regionPipelineEnabled = $enabled;
        $this->pipelineStats['enabled'] = $enabled;
    }

    public function isRegionPipelineEnabled(): bool {
        return $this->regionPipelineEnabled;
    }

    /**
     * Experimental: hand movement+gravity integration over to the worker.
     * The worker is authoritative for position/velocity; the main-thread
     * MovementSystem and PhysicsSystem are disabled while enabled.
     * Only valid once the determinism gate reports zero mismatches.
     */
    public function setRegionPipelineApplyMode(bool $apply): void {
        $this->regionPipelineApply = $apply;
        $this->pipelineStats['applyMode'] = $apply;
        $this->systemScheduler->setEnabled(MovementSystem::class, !$apply);
        $this->systemScheduler->setEnabled(PhysicsSystem::class, !$apply);
    }

    public function isRegionPipelineApplyMode(): bool {
        return $this->regionPipelineApply;
    }

    public function getRegionPipelineStats(): array {
        return $this->pipelineStats;
    }

    /**
     * Serialize every entity with Position+Velocity into the command queue of
     * the region that owns its chunk, and send 'despawn' for entities that no
     * longer qualify so the worker store stays in sync.
     */
    private function mirrorEntitiesToRegions(): void {
        $this->pipelineTickSeq++;
        $this->pipelineMirroredThisTick = 0;
        $currentIds = [];
        foreach ($this->world->getEntities() as $entity) {
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            if ($pos === null || $vel === null) {
                continue;
            }
            $currentIds[] = $entity->id;
            $chunkX = (int)floor($pos->x / 16);
            $chunkZ = (int)floor($pos->z / 16);
            $snapshot = [
                'type' => 'update',
                'entityId' => $entity->id,
                'snapshot' => [
                    'position' => ['x' => $pos->x, 'y' => $pos->y, 'z' => $pos->z],
                    'velocity' => ['x' => $vel->x, 'y' => $vel->y, 'z' => $vel->z],
                ],
            ];
            foreach ($this->regionThreads as $regionId => $region) {
                if ($region->ownsChunk($chunkX, $chunkZ)) {
                    $region->getCommandQueue()[] = json_encode($snapshot);
                    $this->pipelineMirroredIds[$entity->id] = $regionId;
                    $this->pipelineStats['mirrored']++;
                    $this->pipelineMirroredThisTick++;
                    break;
                }
            }
        }

        // Entities that left the pipeline (despawned or lost a component).
        // Note: an entity removed this tick is still present in getEntities()
        // until world->tick flushes removals, so it is mirrored once more and
        // its result surfaces as a dropped 'stale' result before the 'despawn'
        // command fires on the following tick - intentional one-tick lag.
        $currentIdsSet = array_flip($currentIds); // O(1) lookups instead of in_array
        foreach ($this->pipelineMirroredIds as $id => $regionId) {
            if (!isset($currentIdsSet[$id])) {
                $region = $this->regionThreads[$regionId] ?? null;
                if ($region !== null) {
                    $region->getCommandQueue()[] = json_encode(['type' => 'despawn', 'entityId' => $id]);
                    $this->pipelineStats['despawned']++;
                }
                unset($this->pipelineMirroredIds[$id]);
            }
        }

        // Lockstep tick: after all updates/despawns are queued, tell each
        // region to integrate its stored snapshots exactly once.
        foreach ($this->regionThreads as $region) {
            $region->getCommandQueue()[] = json_encode(['type' => 'tick', 'seq' => $this->pipelineTickSeq]);
        }
    }

    /**
     * Drain worker integration results. Gate mode compares them to the
     * main-thread result; apply mode writes them onto the entities.
     *
     * The sync point waits for a message tagged with the CURRENT tick seq
     * (the worker pushes results then its ack in order), so the gate always
     * compares this tick's results - leftovers from earlier ticks are drained
     * and dropped as lagged instead of being compared against the wrong tick.
     * When nothing was mirrored this tick there is nothing to sync, so the
     * wait is skipped entirely (no idle stall).
     */
    private function drainRegionResults(): void {
        $this->pipelineStats['lastTickMismatches'] = 0;
        $syncWaitMs = $this->pipelineMirroredThisTick > 0 ? 10.0 : 0.0;
        foreach ($this->regionThreads as $region) {
            $queue = $region->getSyncQueue();
            $deadline = microtime(true) + $syncWaitMs / 1000.0;
            $synced = false;
            do {
                $msg = $queue->shift();
                if ($msg === null) {
                    if ($synced || microtime(true) >= $deadline) {
                        break;
                    }
                    usleep(200);
                    continue;
                }
                $decoded = json_decode($msg, true);
                if (!is_array($decoded)) {
                    continue;
                }
                if ((int)($decoded['seq'] ?? -1) === $this->pipelineTickSeq) {
                    $synced = true; // worker finished this tick's integration
                }
                $this->processPipelineMessage($decoded);
            } while (true);

            // Drain anything that arrived just after the sync point.
            while (($msg = $queue->shift()) !== null) {
                $decoded = json_decode($msg, true);
                if (is_array($decoded)) {
                    $this->processPipelineMessage($decoded);
                }
            }
        }
    }

    /**
     * Process a single worker message. Only 'snapshots' batches tagged with
     * the current tick seq are compared/applied; older batches are dropped as
     * 'lagged'. The gate's drift check relies on bit-exact float equality
     * between worker and main-thread integration (identical operations on the
     * same values; JSON round-trips are exact with serialize_precision=-1),
     * so the epsilon only guards against future variable-timestep drift.
     */
    private function processPipelineMessage(array $decoded): void {
        if (($decoded['type'] ?? '') !== 'snapshots') {
            return;
        }
        if ((int)($decoded['seq'] ?? -1) !== $this->pipelineTickSeq) {
            $this->pipelineStats['lagged'] += count($decoded['entities'] ?? []);
            return;
        }
        foreach ($decoded['entities'] ?? [] as $entry) {
            $entityId = (int)$entry['entityId'];
            $snapshot = $entry['snapshot'];
            $entity = $this->world->getEntity($entityId);
            if ($entity === null) {
                $this->pipelineStats['stale']++;
                continue;
            }
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            $this->pipelineStats['received']++;
            $wx = (float)$snapshot['position']['x'];
            $wy = (float)$snapshot['position']['y'];
            $wz = (float)$snapshot['position']['z'];
            if ($this->regionPipelineApply) {
                $pos->x = $wx;
                $pos->y = $wy;
                $pos->z = $wz;
                $vel = $entity->get(VelocityComponent::class);
                if ($vel !== null) {
                    $vel->x = (float)$snapshot['velocity']['x'];
                    $vel->y = (float)$snapshot['velocity']['y'];
                    $vel->z = (float)$snapshot['velocity']['z'];
                }
                $this->pipelineStats['applied']++;
            } else {
                $this->pipelineStats['compared']++;
                $drift = abs($pos->x - $wx) + abs($pos->y - $wy) + abs($pos->z - $wz);
                if ($drift > 1e-6) {
                    $this->pipelineStats['mismatches']++;
                    $this->pipelineStats['lastTickMismatches']++;
                }
            }
        }
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
        if (!$this->running && $this->shutdownComplete) {
            return;
        }
        $this->running = false;
        
        // Shutdown threads (only if they were actually started)
        if ($this->threadsStarted) {
            $this->coordinationThread->shutdown();
            $this->networkThread->shutdown();
            foreach ($this->regionThreads as $regionThread) {
                $regionThread->shutdown();
            }
            
            // Wait for threads to finish
            $this->coordinationThread->join();
            $this->networkThread->join();
            foreach ($this->regionThreads as $regionThread) {
                $regionThread->join();
            }
        }
        
        $this->threadingPort->shutdown();
        $this->storagePort->saveAll();
        $this->shutdownComplete = true;
    }

    public function getWorld(): World {
        return $this->world;
    }

    public function getSystemScheduler(): SystemScheduler {
        return $this->systemScheduler;
    }

    public function getScheduler(): \pocketmine\api\scheduler\Scheduler {
        return $this->scheduler;
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

    public function getCoordinationThread(): CoordinationThread {
        return $this->coordinationThread;
    }

    public function getNetworkThread(): NetworkThread {
        return $this->networkThread;
    }

    public function getRegionThreads(): array {
        return $this->regionThreads;
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

    private function processGlobalCoordination(): void {
        // Drain global command/event queues from the coordination thread
        // and route them to the appropriate region command queues.
        while (($cmd = $this->coordinationThread->getGlobalCommandQueue()->shift()) !== null) {
            $decoded = json_decode($cmd, true);
            if (!is_array($decoded)) {
                continue;
            }
            $regionId = (int)($decoded['regionId'] ?? 0);
            if (isset($this->regionThreads[$regionId])) {
                $this->regionThreads[$regionId]->getCommandQueue()[] = $cmd;
            }
        }
    }

    private function flushNetworkSync(): void {
        // Network adapter I/O happens on the main thread.
        if ($this->networkPort instanceof \pocketmine\adapter\driven\network\Protocol84NetworkAdapter) {
            $this->networkPort->processPendingCommands();
            $this->networkPort->flushOutboundPackets();
        }
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
    $registry->register(\pocketmine\core\component\PositionComponent::class);
    $registry->register(\pocketmine\core\component\RotationComponent::class);
    $registry->register(\pocketmine\core\component\VelocityComponent::class);
    $registry->register(\pocketmine\core\component\CollisionComponent::class);
    $registry->register(\pocketmine\core\component\HealthComponent::class);
    $registry->register(\pocketmine\core\component\MetadataComponent::class);
    $registry->register(\pocketmine\core\component\EffectComponent::class);
    $registry->register(\pocketmine\core\component\AttributeComponent::class);
    $registry->register(\pocketmine\core\component\InventoryComponent::class);
    $registry->register(\pocketmine\core\component\AIStateComponent::class);
    $registry->register(\pocketmine\core\component\PathComponent::class);
    $registry->register(\pocketmine\core\component\tags\PlayerTag::class);
    $registry->register(\pocketmine\core\component\tags\MonsterTag::class);
    $registry->register(\pocketmine\core\component\tags\OnGroundTag::class);
    $registry->register(\pocketmine\core\component\tags\InvisibleTag::class);
    $registry->register(\pocketmine\core\component\tags\DeadTag::class);
    $registry->register(\pocketmine\core\component\tags\SpectatorTag::class);
}

function registerBuiltinResources(ResourceRegistry $registry): void {
    $registry->set(new \pocketmine\core\resource\TickCounter());
    $registry->set(new \pocketmine\core\resource\ServerConfig());
    $registry->set(new \pocketmine\core\resource\SpatialIndex());
    $registry->set(new \pocketmine\core\resource\WorldConfig());
    $registry->set(new \pocketmine\core\resource\BlockRegistry());
    $registry->set(new \pocketmine\core\resource\ItemRegistry());
    $registry->set(new \pocketmine\core\resource\ChunkStore());
}

function registerBuiltinSystems(SystemScheduler $scheduler): void {
    $scheduler->register(new \pocketmine\core\system\PhysicsSystem(), \pocketmine\core\ecs\SystemPhase::PARALLEL);
    $scheduler->register(new \pocketmine\core\system\MovementSystem(), \pocketmine\core\ecs\SystemPhase::PARALLEL);
    $scheduler->register(new \pocketmine\core\system\EffectSystem(), \pocketmine\core\ecs\SystemPhase::PARALLEL);
    $scheduler->register(new \pocketmine\core\system\AISystem(), \pocketmine\core\ecs\SystemPhase::PARALLEL);
    $scheduler->register(new \pocketmine\core\system\ChunkUpdateSystem(), \pocketmine\core\ecs\SystemPhase::CHUNK_PARALLEL);
}

function bootstrap(): Kernel {
    return createKernel();
}