<?php

declare(strict_types=1);

namespace pocketmine;

use pocketmine\adapter\driven\network\Protocol84NetworkAdapter;
use pocketmine\adapter\driven\storage\AnvilStorageAdapter;
use pocketmine\adapter\driven\storage\LevelProviderManager;
use pocketmine\adapter\driven\threading\PmmpThreadPool;
use pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter;

use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\ComponentRegistry;
use pocketmine\core\ecs\ResourceRegistry;
use pocketmine\core\ecs\SystemScheduler;
use pocketmine\core\ecs\World;
use pocketmine\core\thread\CoordinationThread;
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
use pocketmine\core\service\PotionService;
use pocketmine\core\service\DamageService;
use pocketmine\core\service\KnockbackService;
use pocketmine\core\service\InventoryService;
use pocketmine\core\service\CraftingService;
use pocketmine\core\service\ContainerService;
use pocketmine\core\service\NetworkSessionService;
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
    /**
     * Current API version. plugin.yml "api" declares the minimum API a plugin
     * requires; a plugin is rejected when its api exceeds this version.
     */
    public const API_VERSION = '2.0.0';
    private static ?self $instance = null;

    private bool $running = false;
    private bool $shutdownComplete = false;
    /**
     * When true, run() binds the network adapter socket and the session
     * service processes real client connections. Off by default so headless
     * tests that tick the kernel never open a UDP socket; the real server
     * entrypoint (bootstrap.php) enables it.
     */
    private bool $networkingEnabled = false;
    /**
     * When true (default) a bounded run() shuts the worker threads down on
     * exit. Disable to make run() resumable (threads stay alive so a later
     * run() call continues where it left off); call shutdown() explicitly to
     * clean up afterwards.
     */
    private bool $autoShutdownOnRun = true;
    /**
     * Interactive console: when enabled (default) and stdin is a TTY, run()
     * reads admin command lines each tick and dispatches them through the
     * command map with a ConsoleCommandSender. Tests and pipelines that run
     * with a non-TTY stdin are unaffected.
     */
    private bool $consoleEnabled = true;
    private array $tickDurations = [];
    /** Per-phase timing accumulation (mirror / world tick / drain / balance),
     *  enabled via setPhaseProfiling() for benchmarking the pipeline. */
    private bool $phaseProfiling = false;
    private array $phaseTimes = ['mirror' => [], 'tick' => [], 'drain' => [], 'balance' => [], 'rest' => []];

    private CoordinationThread $coordinationThread;
    private array $regionThreads = [];
    private bool $threadsStarted = false;
    /** Blocker 4: plugins/<dataPath> is scanned once, on the first run(). */
    private bool $pluginsLoaded = false;
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
    /** @var array<int, int> entityId => owning region id (last mirrored; used for migration) */
    private array $pipelineRegion = [];
    /**
     * Per-entity chunk + region-ownership cache so the mirror's steady-state
     * hot loop skips the ownsChunk() ThreadSafe reads entirely. Valid only
     * while pipelineChunkEpoch[id] === regionEpoch (epoch bumps on every
     * split/merge, which resizes region bounds and can change ownership for
     * entities that did not move).
     */
    private int $regionEpoch = 0;
    /** @var array<int, int> entityId => chunk X verified against the cached region */
    private array $pipelineChunkX = [];
    /** @var array<int, int> entityId => chunk Z verified against the cached region */
    private array $pipelineChunkZ = [];
    /** @var array<int, int> entityId => regionEpoch when the chunk cache was last verified */
    private array $pipelineChunkEpoch = [];
    /**
     * entityId => the snapshot the region worker currently has stored
     * (pre-integration). Advanced by one lockstep integration each tick, so
     * the kernel can detect when a re-mirror is actually needed. Kept as six
     * flat number-keyed arrays (not one array of arrays) so the per-entity
     * hot path allocates nothing and stays cache-friendly.
     */
    private array $pipelineStoredX = [];
    private array $pipelineStoredY = [];
    private array $pipelineStoredZ = [];
    private array $pipelineStoredVX = [];
    private array $pipelineStoredVY = [];
    private array $pipelineStoredVZ = [];
    private int $pipelineTickSeq = 0;
    /** Next region id for dynamically split regions (9.4b). */
    private int $nextRegionId = 0;
    /** @var array<int, int> regionId => entities owned after this tick's mirror */
    private array $regionEntityCounts = [];
    /** Last tick's counts - the chunk-X collector only runs for regions that
     *  were already over the split threshold, so post-convergence ticks (all
     *  regions under) collect nothing. */
    private array $regionEntityCountsPrev = [];
    /** @var array<int, list<int>> regionId => chunk X of each owned entity (for split medians) */
    private array $regionEntityChunkXs = [];
    /** Region ids created by a split during the current balance pass - never
     *  merged back in the same pass (they are empty until entities migrate). */
    private array $freshlySplitRegions = [];
    private array $pipelineStats = [
        'enabled' => false,
        'applyMode' => false,
        'mirrored' => 0,
        'skipped' => 0,
        'migrated' => 0,
        'despawned' => 0,
        'received' => 0,
        'applied' => 0,
        'compared' => 0,
        'mismatches' => 0,
        'lagged' => 0,
        'stale' => 0,
        'lastTickMismatches' => 0,
        'splits' => 0,
        'merges' => 0,
    ];

    private PlayerJoinService $playerJoinService;
    private PlayerLeaveService $playerLeaveService;
    private PlayerRespawnService $playerRespawnService;
    private ChunkLoadService $chunkLoadService;
    private ChunkUnloadService $chunkUnloadService;
    private ChunkSendService $chunkSendService;
    private NetworkSessionService $networkSessionService;
    private BlockBreakService $blockBreakService;
    private BlockPlaceService $blockPlaceService;
    private BlockUpdateService $blockUpdateService;
    private EntitySpawnService $entitySpawnService;
    private EntityDespawnService $entityDespawnService;
    private EntityInteractionService $entityInteractionService;
    private CombatService $combatService;
    private PotionService $potionService;
    private DamageService $damageService;
    private KnockbackService $knockbackService;
    private InventoryService $inventoryService;
    private CraftingService $craftingService;
    private ContainerService $containerService;
    private \pocketmine\api\scheduler\Scheduler $scheduler;
    private \pocketmine\api\permission\PermissionManager $permissionManager;

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
        private readonly int $regionCount = 1,
        private readonly ?int $maxEntitiesPerRegion = null,
    ) {
        // The load service enforces the loaded-chunk budget by evicting via
        // the unload service, so the unload service is constructed first. The
        // load service is built before the join service: a new player's spawn
        // point must be derived from the actual terrain (safe spawn), which
        // requires loading the spawn chunk.
        $this->chunkUnloadService = new ChunkUnloadService($world, $storagePort);
        $this->chunkLoadService = new ChunkLoadService($world, $storagePort, $worldGenPort, $this->chunkUnloadService);
        $this->playerJoinService = new PlayerJoinService($world, $networkPort, $storagePort, $worldGenPort, $this->chunkLoadService, $eventPort);
        $this->playerLeaveService = new PlayerLeaveService($world, $networkPort, $storagePort, $eventPort);
        $this->playerRespawnService = new PlayerRespawnService($world, $storagePort, $eventPort);
        $this->chunkSendService = new ChunkSendService($world, $networkPort);
        // Block services are built before the session service: the network
        // layer must be able to translate client block actions (break/place)
        // straight into the ECS services.
        $this->blockBreakService = new BlockBreakService($world, $storagePort, $eventPort);
        $this->blockPlaceService = new BlockPlaceService($world, $eventPort);
        // 14.3: combat + interaction services are built before the session
        // service so client attack packets (InteractPacket) route straight
        // into the combat pipeline.
        $this->entitySpawnService = new EntitySpawnService($world, $storagePort, $eventPort);
        $this->combatService = new CombatService($world, $eventPort, $this->entitySpawnService);
        $this->entityInteractionService = new EntityInteractionService($world, $this->combatService, $eventPort);
        $this->blockUpdateService = new BlockUpdateService($world, $storagePort);
        $this->entityDespawnService = new EntityDespawnService($world, $storagePort);
        $this->damageService = new DamageService($world, $this->combatService);
        $this->knockbackService = new KnockbackService($world);
        $this->inventoryService = new InventoryService($world);
        $this->craftingService = new CraftingService($world);
        $this->containerService = new ContainerService($world);
        // 14.23: potion application (drink + splash) - after combat so
        // harming potions can route through the damage pipeline.
        $this->potionService = new PotionService($world, $networkPort, $this->combatService);

        // CraftingService must exist before NetworkSessionService: the wire
        // craft handler (CraftingEventPacket) validates grids through it.
        $this->networkSessionService = new NetworkSessionService(
            $networkPort,
            $world,
            $this->playerJoinService,
            $this->playerLeaveService,
            $this->chunkLoadService,
            $this->blockBreakService,
            $this->blockPlaceService,
            $this->combatService,
            $this->playerRespawnService,
            $this->entityInteractionService,
            $this->entitySpawnService,
            $this->craftingService,
            $this->resourceRegistry,
            $this->commandPort,
            $eventPort,
        );

        // Single scheduler instance: it registers a tick system that runs tasks,
        // so it must not be recreated per access.
        $this->scheduler = new \pocketmine\api\scheduler\Scheduler($world, $systemScheduler, $threadingPort);

        $this->permissionManager = new \pocketmine\api\permission\PermissionManager();
        // Blocker 1: the builtin command permissions (khronos.command.*)
        // default to OP so only operators can use the admin commands.
        registerBuiltinPermissions($this->permissionManager);
        // Override the default instance the resource registry was seeded with so
        // plugins that look it up from the registry get the SAME instance the
        // plugin manager registers plugin.yml permissions into.
        $this->resourceRegistry->set($this->permissionManager);

        // 14.20 multi-world: the default world (id 0) is the kernel's own
        // single ChunkStore/WorldConfig/storage bundle. Extra worlds are
        // registered later via WorldRegistry::registerWorld() (Server
        // API /world command); their chunk/block/network routing reuses the
        // exact same service instances, resolved per world id.
        $worldRegistry = $this->resourceRegistry->get(\pocketmine\core\resource\WorldRegistry::class);
        if ($worldRegistry instanceof \pocketmine\core\resource\WorldRegistry) {
            $serverConfig = $this->resourceRegistry->get(\pocketmine\core\resource\ServerConfig::class);
            // Register the RAW seed value: getSeed() would randomize a 0 seed
            // and cache it, but the caller may still pin the seed AFTER
            // bootstrap (tests do). ChunkLoadService falls back to
            // ServerConfig::getSeed() when the registered seed is 0, so the
            // first actual generation resolves the pinned value.
            $seed = $serverConfig instanceof \pocketmine\core\resource\ServerConfig ? $serverConfig->seed : 0;
            $worldConfig = $this->resourceRegistry->get(\pocketmine\core\resource\WorldConfig::class);
            $chunkStore = $this->resourceRegistry->get(\pocketmine\core\resource\ChunkStore::class);
            // khronos.json default-world picks the default world's folder (and
            // display name); the storage adapter was already routed to that
            // folder at port creation.
            $khronos = $this->resourceRegistry->get(\pocketmine\core\resource\KhronosConfig::class);
            $defaultWorld = $khronos instanceof \pocketmine\core\resource\KhronosConfig && $khronos->defaultWorld !== ''
                ? $khronos->defaultWorld
                : 'world';
            // The default world keeps the global tile-store instances so
            // single-world behavior (and existing tests) is unchanged; extra
            // worlds get their own stores when registered.
            $chestStore = $this->resourceRegistry->get(\pocketmine\core\resource\ChestStore::class);
            $furnaceStore = $this->resourceRegistry->get(\pocketmine\core\resource\FurnaceStore::class);
            $containerStore = $this->resourceRegistry->get(\pocketmine\core\resource\ContainerStore::class);
            $brewingStore = $this->resourceRegistry->get(\pocketmine\core\resource\BrewingStore::class);
            $tileEntityStore = $this->resourceRegistry->get(\pocketmine\core\resource\TileEntityStore::class);
            $worldRegistry->registerDefaultWorld(
                $defaultWorld,
                $defaultWorld,
                $seed,
                $chunkStore instanceof \pocketmine\core\resource\ChunkStore ? $chunkStore : new \pocketmine\core\resource\ChunkStore(),
                $worldConfig instanceof \pocketmine\core\resource\WorldConfig ? $worldConfig : new \pocketmine\core\resource\WorldConfig(),
                $storagePort,
                $chestStore instanceof \pocketmine\core\resource\ChestStore ? $chestStore : new \pocketmine\core\resource\ChestStore(),
                $furnaceStore instanceof \pocketmine\core\resource\FurnaceStore ? $furnaceStore : new \pocketmine\core\resource\FurnaceStore(),
                $containerStore instanceof \pocketmine\core\resource\ContainerStore ? $containerStore : new \pocketmine\core\resource\ContainerStore(),
                $brewingStore instanceof \pocketmine\core\resource\BrewingStore ? $brewingStore : new \pocketmine\core\resource\BrewingStore(),
                $tileEntityStore instanceof \pocketmine\core\resource\TileEntityStore ? $tileEntityStore : new \pocketmine\core\resource\TileEntityStore(),
            );
        }

        $this->dataPath = getcwd() . DIRECTORY_SEPARATOR;
        $this->startTime = time();
        self::$instance = $this;

        // Plugins are created before the kernel exists; wire them now.
        if ($this->pluginPort instanceof \pocketmine\api\plugin\PluginManager) {
            $this->pluginPort->setKernel($this);
            $this->pluginPort->setPermissionManager($this->permissionManager);
        }

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

        // Create region threads: the world is split into regionCount columns
        // along the X axis so entities crossing chunk boundaries exercise the
        // migration path (9.4b). Each region processes serialized snapshots
        // via thread-safe queues; the ECS world itself stays on the main
        // thread. regionCount = 1 keeps the whole world in a single region
        // (the default, matching pre-migration behavior).
        $worldMinX = -1000;
        $worldMaxX = 1000;
        $chunksPerRegion = (int)ceil(($worldMaxX - $worldMinX + 1) / max(1, $this->regionCount));
        for ($i = 0; $i < $this->regionCount; $i++) {
            $minX = $worldMinX + $i * $chunksPerRegion;
            $maxX = min($minX + $chunksPerRegion - 1, $worldMaxX);
            $regionThread = new RegionThread($i, $minX, $maxX, -1000, 1000);
            $this->regionThreads[$i] = $regionThread;
            $this->coordinationThread->addRegion($i, $regionThread->getCommandQueue());
        }
        $this->nextRegionId = $this->regionCount;
    }

    public function setAutoShutdownOnRun(bool $autoShutdown): void {
        $this->autoShutdownOnRun = $autoShutdown;
    }

    public function setConsoleEnabled(bool $enabled): void {
        $this->consoleEnabled = $enabled;
    }

    public function isConsoleEnabled(): bool {
        return $this->consoleEnabled;
    }

    /**
     * Ask the run() loop to exit cleanly (the "stop" console command). The
     * loop breaks on the next tick; with auto-shutdown on (the default for
     * the real server) shutdown() then saves the world and stops threads.
     */
    public function requestShutdown(): void {
        $this->running = false;
    }

    /** Opt into real client serving: binds the UDP socket in run(). */
    public function setNetworkingEnabled(bool $enabled): void {
        $this->networkingEnabled = $enabled;
    }

    public function isNetworkingEnabled(): bool {
        return $this->networkingEnabled;
    }

    /**
     * Override the adapter's bind port (before run()). Only meaningful when
     * networking is enabled; used by tests to avoid port clashes.
     */
    public function setBindPort(int $port): void {
        if ($this->networkPort instanceof Protocol84NetworkAdapter) {
            $this->networkPort->setBindPort($port);
        }
    }

    public function getNetworkSessionService(): NetworkSessionService {
        return $this->networkSessionService;
    }

    public function run(int $maxTicks = -1): void {
        if ($this->running) {
            return;
        }
        // Footgun guard: with auto-shutdown on (the default) a bounded run()
        // joins the workers, so a second run() would mirror into queues with
        // no consumer and stall every drain. Require the caller to opt out of
        // auto-shutdown before resuming.
        if ($this->threadsStarted && $this->autoShutdownOnRun) {
            throw new \RuntimeException(
                'run() already shut down its worker threads; call setAutoShutdownOnRun(false) to make run() resumable'
            );
        }
        $this->running = true;
        $tick = 0;

        // Start threads only on the first invocation (a started/joined Thread
        // cannot be restarted, so subsequent run() calls reuse the main-thread
        // ECS loop without worker threads).
        $ownsThreads = !$this->threadsStarted;
        if ($ownsThreads) {
            $this->coordinationThread->start(Thread::INHERIT_ALL);
            foreach ($this->regionThreads as $regionThread) {
                $regionThread->start(Thread::INHERIT_ALL);
            }
            $this->threadsStarted = true;
        }

        // Interactive console: only a TTY stdin is consumed (interactive
        // start.sh runs). A pipe or /dev/null stdin (tests, CI, nohup) skips
        // the console entirely - polling it would busy-read an EOF forever.
        $consoleActive = $this->consoleEnabled
            && defined('STDIN') && is_resource(STDIN)
            && @stream_isatty(STDIN);
        if ($consoleActive) {
            stream_set_blocking(STDIN, false);
            echo '[Khronos] Console ready - type "help" or "stop".' . PHP_EOL;
        }

        // Bind the UDP socket and start serving clients (opt-in: off by
        // default so headless tests never touch the network). The adapter
        // starts its own RakLibServer thread, which owns the socket.
        if ($this->networkingEnabled && $this->networkPort instanceof Protocol84NetworkAdapter) {
            // The motd's max-player field should reflect the world config,
            // not the adapter's hardcoded default.
            $worldConfig = $this->resourceRegistry->get(\pocketmine\core\resource\WorldConfig::class);
            if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
                $this->networkPort->setMaxPlayers($worldConfig->maxPlayers);
            }
            $this->networkPort->start();
        }

        // Blocker 4: auto-load plugins from <dataPath>/plugins/ on the first
        // run() (the real server start). The folder is created when missing,
        // mirroring server.properties; an empty folder loads nothing. Runs
        // before the tick loop so plugin systems/commands are live from tick 0.
        if (!$this->pluginsLoaded) {
            $this->pluginsLoaded = true;
            $pluginsDir = $this->dataPath . 'plugins';
            if (!is_dir($pluginsDir)) {
                @mkdir($pluginsDir, 0755, true);
            }
            $n = $this->loadPluginsFromDirectory($pluginsDir);
            if ($n > 0) {
                echo "[Khronos] Loaded $n plugin(s)." . PHP_EOL;
            }
        }

        // Profiling closure is created once (not per tick) so the hot loop
        // does not pay closure allocation on every iteration.
        $phaseStart = 0;
        $markPhase = function (string $name) use (&$phaseStart): void {
            if ($this->phaseProfiling) {
                $now = hrtime(true);
                $this->phaseTimes[$name][] = ($now - $phaseStart) / 1_000_000;
                $phaseStart = $now;
            }
        };

        while ($this->running && ($maxTicks < 0 || $tick < $maxTicks)) {
            $start = hrtime(true);
            $phaseStart = $start;

            // 0a. Mirror entity snapshots to region workers (pipeline).
            if ($this->regionPipelineEnabled && $this->threadsStarted) {
                $this->mirrorEntitiesToRegions();
            }
            $markPhase('mirror');

            // 0. Tick the ECS world on the main thread (ownership model)
            $this->world->tick(0.05);
            // 14.8: the global tick counter drives gameplay timing (block
            // breaking hold-time, future cooldowns). One increment per tick.
            $tickCounter = $this->resourceRegistry->get(\pocketmine\core\resource\TickCounter::class);
            if ($tickCounter instanceof \pocketmine\core\resource\TickCounter) {
                $tickCounter->value++;
            }
            $markPhase('tick');

            // 0b. Drain worker results: compare (gate) or apply.
            if ($this->regionPipelineEnabled && $this->threadsStarted) {
                $this->drainRegionResults();
            }
            $markPhase('drain');

            // 0c. Dynamic region load balancing (splits/merges). Runs after
            // the drain so this tick's integration is coherent; its effects
            // take hold from the next mirror via the migration path.
            if ($this->regionPipelineEnabled && $this->threadsStarted) {
                $this->balanceRegions();
            }
            $markPhase('balance');

            // 1. Main thread acts as coordinator - process global events
            $this->processGlobalCoordination();

            // 2. Flush network sync from all regions
            $this->flushNetworkSync();

            // 2b. Interactive console: dispatch admin commands typed on a TTY
            // stdin (start.sh). Disabled under a non-TTY stdin so headless
            // tests and pipelines never consume the piped input stream.
            if ($consoleActive) {
                $this->pollConsole();
            }

            // 3. Storage autosave (periodic): flush resident chunks + world
            // meta (14.4) so a crash or restart loses at most the interval.
            $autosaveConfig = $this->resourceRegistry->get(\pocketmine\core\resource\ServerConfig::class);
            $autosaveTicks = $autosaveConfig instanceof \pocketmine\core\resource\ServerConfig
                ? max(1, $autosaveConfig->autosaveIntervalTicks)
                : 6000;
            if ($tick % $autosaveTicks === 0) {
                $this->saveWorld();
            }

            $end = hrtime(true);
            $elapsedMs = ($end - $start) / 1_000_000;
            if ($this->phaseProfiling) {
                $this->phaseTimes['rest'][] = ($end - $phaseStart) / 1_000_000;
            }
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
        if ($ownsThreads && $this->autoShutdownOnRun) {
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
     * Predict the worker's stored snapshot after one lockstep integration -
     * exactly the operations RegionThread::tickSnapshots performs on the
     * worker side (and bit-for-bit the main thread's MovementSystem +
     * PhysicsSystem order): integrate position with pre-gravity velocity,
     * then apply gravity to velocity. Inline scalar version of the old
     * array-returning integrateOnce() - the mirror's per-entity hot path
     * allocates nothing.
     */
    private function advanceStored(int $id, float $x, float $y, float $z, float $vx, float $vy, float $vz): void {
        // Shared constants with RegionThread so this prediction can never
        // silently drift from the worker's integration.
        $dt = RegionThread::TARGET_DELTA_TIME;
        $this->pipelineStoredX[$id] = $x + $vx * $dt;
        $this->pipelineStoredY[$id] = $y + $vy * $dt;
        $this->pipelineStoredZ[$id] = $z + $vz * $dt;
        $this->pipelineStoredVX[$id] = $vx;
        // Terminal-velocity clamp - bit-identical to PhysicsSystem (same
        // threshold and same gravity expression), which the gate's drift
        // check and the diff-only mirror both rely on.
        $vy -= RegionThread::GRAVITY_ACCELERATION * $dt;
        if ($vy < -78.4) {
            $vy = -78.4;
        }
        $this->pipelineStoredVY[$id] = $vy;
        $this->pipelineStoredVZ[$id] = $vz;
    }

    /** Forget all six stored-snapshot scalars for an entity. */
    private function forgetStored(int $id): void {
        unset(
            $this->pipelineStoredX[$id],
            $this->pipelineStoredY[$id],
            $this->pipelineStoredZ[$id],
            $this->pipelineStoredVX[$id],
            $this->pipelineStoredVY[$id],
            $this->pipelineStoredVZ[$id],
        );
    }

    /**
     * Serialize entities into the owning region's command queue - but only
     * when the worker's stored snapshot would diverge from the main-thread
     * state. Since both sides integrate identically, an unchanged entity needs
     * no re-mirror: the worker keeps integrating its stored snapshot and stays
     * bit-in-sync. The kernel tracks the worker's stored state (pipelineStored,
     * advanced by one lockstep integration per tick) and re-mirrors only on
     * real divergence (teleport, knockback, plugin mutation, new entity).
     */
    private function mirrorEntitiesToRegions(): void {
        $this->pipelineTickSeq++;
        // Pre-fill the per-region counters so the per-entity hot loop only
        // does ++ (no ?? read) when balance tracking is configured.
        $this->regionEntityCounts = $this->maxEntitiesPerRegion !== null
            ? array_fill_keys(array_keys($this->regionThreads), 0)
            : [];
        $this->regionEntityChunkXs = [];
        /** @var array<int, bool> id => seen this tick (for the despawn sweep) */
        $currentIdSet = [];
        /** @var array<int, list<array{entityId: int, position: array{x: float, y: float, z: float}, velocity: array{x: float, y: float, z: float}}>> $updatesByRegion */
        $updatesByRegion = [];
        /** @var array<int, list<int>> $despawnsByRegion */
        $despawnsByRegion = [];
        /** @var array<int, list<array{entityId: int, snapshot: array{position: array{x: float, y: float, z: float}, velocity: array{x: float, y: float, z: float}}}}> $migrationsByRegion */
        $migrationsByRegion = [];
        foreach ($this->world->getEntities() as $entity) {
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            if ($pos === null || $vel === null) {
                continue;
            }
            $id = $entity->id;
            $currentIdSet[$id] = true;
            // Chunk = 16 blocks; (int)floor($x / 16) floors toward -inf for
            // negative positions. (A raw right shift would implicitly cast the
            // float and truncate negatives toward zero - wrong chunk for
            // -0.5 <= x < 0.)
            $chunkX = (int)floor($pos->x / 16);
            $chunkZ = (int)floor($pos->z / 16);

            // Fast path: an entity's owning region only changes when it
            // crosses a chunk-column boundary or a split/merge resized the
            // regions. We cache the (chunk, region) pair verified on the last
            // mirror; while the epoch is unchanged the ownership cannot have
            // changed, so no ownsChunk() ThreadSafe read is needed. Only a
            // moved entity (new chunk) or a resized region (epoch bump) falls
            // back to the real ownership scan.
            $prevRegion = $this->pipelineRegion[$id] ?? null;
            $regionId = null;
            // isset() guard: today a merged region's entities always have
            // pipelineRegion cleared before the region is removed, but the
            // guard keeps the fast path immune to future code paths that
            // unset a region without clearing ownership.
            if ($prevRegion !== null
                && isset($this->regionThreads[$prevRegion])
                && ($this->pipelineChunkEpoch[$id] ?? -1) === $this->regionEpoch
                && $this->pipelineChunkX[$id] === $chunkX
                && $this->pipelineChunkZ[$id] === $chunkZ) {
                $regionId = $prevRegion;
            } else {
                if ($prevRegion !== null && isset($this->regionThreads[$prevRegion])
                    && $this->regionThreads[$prevRegion]->ownsChunk($chunkX, $chunkZ)) {
                    $regionId = $prevRegion;
                } else {
                    foreach ($this->regionThreads as $rid => $region) {
                        if ($region->ownsChunk($chunkX, $chunkZ)) {
                            $regionId = $rid;
                            break;
                        }
                    }
                }
                $this->pipelineChunkX[$id] = $chunkX;
                $this->pipelineChunkZ[$id] = $chunkZ;
                $this->pipelineChunkEpoch[$id] = $this->regionEpoch;
            }
            if ($regionId === null) {
                continue; // outside every region (should not happen)
            }
            // Split bookkeeping: count every owned entity (cheap int bump), but
            // only collect chunk Xs for regions that were already over the
            // split threshold last tick - the median only needs the over-threshold
            // region, and post-convergence ticks then collect nothing. When no
            // split threshold is configured (default) this stays off entirely.
            if ($this->maxEntitiesPerRegion !== null) {
                $this->regionEntityCounts[$regionId]++;
                if (($this->regionEntityCountsPrev[$regionId] ?? 0) > $this->maxEntitiesPerRegion) {
                    $this->regionEntityChunkXs[$regionId][] = $chunkX;
                }
            }

            $x = $pos->x;
            $y = $pos->y;
            $z = $pos->z;
            $vx = $vel->x;
            $vy = $vel->y;
            $vz = $vel->z;

            // Cross-region migration: the entity changed owners. Drop the
            // stale copy in the old region and hand the snapshot to the new
            // region's migration queue (RegionThread::processMigrations).
            if ($prevRegion !== null && $prevRegion !== $regionId) {
                $despawnsByRegion[$prevRegion][] = $id;
                $migrationsByRegion[$regionId][] = [
                    'entityId' => $id,
                    'snapshot' => [
                        'position' => ['x' => $x, 'y' => $y, 'z' => $z],
                        'velocity' => ['x' => $vx, 'y' => $vy, 'z' => $vz],
                    ],
                ];
                $this->pipelineStats['migrated']++;
                $this->advanceStored($id, $x, $y, $z, $vx, $vy, $vz);
                $this->pipelineRegion[$id] = $regionId;
                $this->pipelineMirroredIds[$id] = $regionId;
                continue;
            }
            $this->pipelineRegion[$id] = $regionId;

            $inSync = isset($this->pipelineStoredX[$id])
                && $this->pipelineStoredX[$id] === $x
                && $this->pipelineStoredY[$id] === $y
                && $this->pipelineStoredZ[$id] === $z
                && $this->pipelineStoredVX[$id] === $vx
                && $this->pipelineStoredVY[$id] === $vy
                && $this->pipelineStoredVZ[$id] === $vz;
            if ($inSync) {
                // Worker and main agree; no transport needed. The worker
                // will integrate its stored snapshot and produce results.
                $this->pipelineStats['skipped']++;
            } else {
                $updatesByRegion[$regionId][] = [
                    'entityId' => $id,
                    'position' => ['x' => $x, 'y' => $y, 'z' => $z],
                    'velocity' => ['x' => $vx, 'y' => $vy, 'z' => $vz],
                ];
                $this->pipelineStats['mirrored']++;
            }
            // The worker stores this state and integrates it once when the
            // tick command arrives - predict its stored snapshot for the
            // next mirror.
            $this->advanceStored($id, $x, $y, $z, $vx, $vy, $vz);
            $this->pipelineMirroredIds[$id] = $regionId;
        }

        // Snapshot this tick's counts for next tick's chunk-X collector gate.
        $this->regionEntityCountsPrev = $this->regionEntityCounts;

        // Entities that left the pipeline (despawned or lost a component).
        // Note: an entity removed this tick is still present in getEntities()
        // until world->tick flushes removals, so it is mirrored once more and
        // its result surfaces as a dropped 'stale' result before the 'despawn'
        // command fires on the following tick - intentional one-tick lag.
        foreach ($this->pipelineMirroredIds as $id => $regionId) {
            if (!isset($currentIdSet[$id])) {
                $despawnsByRegion[$regionId][] = $id;
                $this->pipelineStats['despawned']++;
                unset($this->pipelineMirroredIds[$id]);
                $this->forgetStored($id);
                unset($this->pipelineRegion[$id]);
                unset($this->pipelineChunkX[$id], $this->pipelineChunkZ[$id], $this->pipelineChunkEpoch[$id]);
            }
        }

        // Emit one batch per region: updates, despawns, migrations, then the
        // lockstep tick (the worker integrates stored snapshots exactly once
        // per tick command). Migrations ride the dedicated migration queue so
        // the worker stores the snapshot via processMigrations().
        foreach ($this->regionThreads as $regionId => $region) {
            $queue = $region->getCommandQueue();
            if (!empty($updatesByRegion[$regionId])) {
                $queue[] = RegionThread::encodeUpdate($this->pipelineTickSeq, $updatesByRegion[$regionId]);
            }
            if (!empty($despawnsByRegion[$regionId])) {
                $queue[] = RegionThread::encodeDespawns($despawnsByRegion[$regionId]);
            }
            if (!empty($migrationsByRegion[$regionId])) {
                $migrationQueue = $region->getMigrationQueue();
                foreach ($migrationsByRegion[$regionId] as $migration) {
                    $migrationQueue[] = json_encode($migration);
                }
            }
            $queue[] = RegionThread::encodeTick($this->pipelineTickSeq);
            // Wake the worker: it sleeps on a condvar between polls, so a
            // pushed batch must notify it or the work sits until the timeout.
            $region->wakeup();
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
        // Wait only when there are tracked entities: with diff-only mirroring
        // most ticks have zero re-mirrors, but the worker still integrates its
        // stored snapshots and must be drained for gate comparison / apply.
        // The cap only guards against a wedged worker during split/migration
        // bursts - it is not the steady-state path (workers run a full
        // world-tick ahead). Tightening below 8 ms dropped results during
        // splits (10k lagged at 4 ms), so keep it comfortably above the
        // encode+push worst case.
        $syncWaitMs = !empty($this->pipelineMirroredIds) ? 8.0 : 0.0;
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
                if (!is_string($msg) || $msg === '') {
                    continue;
                }
                $tag = $msg[0];
                if ($tag === RegionThread::MSG_ACK) {
                    if (RegionThread::decodeHeaderSeq($msg) === $this->pipelineTickSeq) {
                        $synced = true; // worker finished this tick's integration
                    }
                } elseif ($tag === RegionThread::MSG_RESULTS) {
                    if (RegionThread::decodeHeaderSeq($msg) === $this->pipelineTickSeq) {
                        $synced = true;
                    }
                    $this->processPipelineResults($msg);
                }
            } while (true);

            // Drain anything that arrived just after the sync point.
            while (($msg = $queue->shift()) !== null) {
                if (is_string($msg) && strlen($msg) >= 9 && $msg[0] === RegionThread::MSG_RESULTS) {
                    $this->processPipelineResults($msg);
                }
            }
        }
    }

    /**
     * Dynamic region load balancing (9.4b).
     *
     * Runs after the drain so this tick's integration is coherent, and its
     * effects (resized bounds, new regions) take hold from the NEXT mirror:
     * entities whose owning region changed are picked up by the existing
     * cross-region migration path (despawn old + snapshot to new), so no
     * entity is ever integrated twice or lost.
     */
    private function balanceRegions(): void {
        if ($this->maxEntitiesPerRegion === null) {
            return;
        }
        $this->freshlySplitRegions = [];

        // 1. Splits: regions over the threshold split at their entity-weighted
        //    median column so both halves are roughly equal. Counts come from
        //    this tick's mirror, so a freshly split region (empty until its
        //    entities migrate in) is only eligible from the next pass.
        foreach (array_keys($this->regionThreads) as $regionId) {
            $count = $this->regionEntityCounts[$regionId] ?? 0;
            if ($count <= $this->maxEntitiesPerRegion) {
                continue;
            }
            $chunkXs = $this->regionEntityChunkXs[$regionId] ?? [];
            if (count($chunkXs) >= 2) {
                $this->splitRegion($regionId, $chunkXs);
            }
        }

        // 2. Merges: regions far below the threshold are folded into an
        //    adjacent region. Only regions that existed before this pass may
        //    be merged, and a region that absorbed another this pass is not
        //    merged again (its post-merge count is not known until the next
        //    mirror).
        $minEntities = max(1, (int)($this->maxEntitiesPerRegion / 5));
        $eligible = array_diff(array_keys($this->regionThreads), $this->freshlySplitRegions);
        $absorbedInto = [];
        foreach ($eligible as $regionId) {
            if (count($this->regionThreads) <= 1) {
                break;
            }
            if (!isset($this->regionThreads[$regionId]) || in_array($regionId, $absorbedInto, true)) {
                continue;
            }
            $count = $this->regionEntityCounts[$regionId] ?? 0;
            if ($count >= $minEntities) {
                continue;
            }
            $absorber = $this->mergeRegion($regionId);
            if ($absorber !== null) {
                $absorbedInto[] = $absorber;
            }
        }
    }

    /**
     * Split a region into two columns at the entity-weighted median chunk X.
     * The western half keeps the region id (shrunk bounds); the eastern half
     * becomes a new region thread. Entities in the east still point at the old
     * region in pipelineRegion, so the next mirror migrates them through the
     * standard despawn+migrate path.
     *
     * The split column is constrained so BOTH resulting halves hold at least
     * the merge floor of entities (minEntities). Without this, a split can
     * isolate a tiny sliver (a chunk with a handful of entities) that falls
     * straight back under the merge floor next pass and merges - a
     * split/merge oscillation that churns workers without reducing work.
     *
     * @param list<int> $chunkXs
     */
    private function splitRegion(int $regionId, array $chunkXs): void {
        $region = $this->regionThreads[$regionId];
        $minX = $region->getMinChunkX();
        $maxX = $region->getMaxChunkX();
        if ($maxX - $minX < 1) {
            return; // a single-column region cannot split
        }
        $total = count($chunkXs);
        $minSplit = max(1, (int)($this->maxEntitiesPerRegion / 5)); // same floor as mergeRegion

        // Per-chunk entity counts, then scan for split columns where both
        // halves stay above the floor. Prefer the column closest to the
        // entity-weighted median so the halves stay balanced.
        $perChunk = array_count_values($chunkXs);
        ksort($perChunk);
        $cumulative = 0;
        $medianTarget = $total / 2;
        $bestColumn = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($perChunk as $chunk => $count) {
            $cumulative += $count;
            if ($chunk <= $minX || $chunk >= $maxX) {
                continue; // cannot use a boundary chunk as the split column
            }
            $west = $cumulative;
            $east = $total - $west;
            if ($west < $minSplit || $east < $minSplit) {
                continue; // would create a region under the merge floor
            }
            $distance = abs($west - $medianTarget);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestColumn = $chunk;
            }
        }
        if ($bestColumn === null) {
            return; // every viable split column would strand a sub-floor half
        }

        $newId = $this->nextRegionId++;
        $eastRegion = new RegionThread(
            $newId,
            $bestColumn + 1,
            $maxX,
            $region->getMinChunkZ(),
            $region->getMaxChunkZ(),
        );
        $region->shrinkMaxX($bestColumn);
        $this->regionThreads[$newId] = $eastRegion;
        $this->coordinationThread->addRegion($newId, $eastRegion->getCommandQueue());
        if ($this->threadsStarted) {
            $eastRegion->start(Thread::INHERIT_ALL);
        }
        $this->freshlySplitRegions[] = $newId;
        $this->regionEpoch++; // region bounds changed: cached ownership is stale
        $this->pipelineStats['splits']++;
    }

    /**
     * Fold a nearly-empty region into its adjacent neighbor: the neighbor's
     * bounds absorb this region's column, the diff-only bookkeeping for the
     * entities we own is reset (so the next mirror re-mirrors them to the
     * absorber instead of despawning from a dying region), and the worker
     * thread is shut down.
     *
     * @return int|null the absorbing region id, or null if none was found
     */
    private function mergeRegion(int $regionId): ?int {
        $region = $this->regionThreads[$regionId];
        $minX = $region->getMinChunkX();
        $maxX = $region->getMaxChunkX();

        // Prefer the directly-west neighbor; fall back to the directly-east one.
        $absorber = null;
        foreach ($this->regionThreads as $otherId => $other) {
            if ($otherId !== $regionId && $other->getMaxChunkX() + 1 === $minX) {
                $absorber = $otherId;
                break;
            }
        }
        if ($absorber === null) {
            foreach ($this->regionThreads as $otherId => $other) {
                if ($otherId !== $regionId && $other->getMinChunkX() - 1 === $maxX) {
                    $absorber = $otherId;
                    break;
                }
            }
        }
        if ($absorber === null) {
            return null;
        }

        $absorberRegion = $this->regionThreads[$absorber];
        if ($absorberRegion->getMaxChunkX() < $minX) {
            $absorberRegion->expandMaxX($maxX); // absorber is west: absorb our column to the east
        } else {
            $absorberRegion->expandMinX($minX); // absorber is east
        }

        // The entities we own must be re-mirrored to the absorber from a
        // clean slate: reset the diff-only prediction so the next mirror sends
        // a fresh snapshot, and forget the old owner so no despawn is sent to
        // this (dying) region.
        foreach ($this->pipelineRegion as $id => $owner) {
            if ($owner === $regionId) {
                $this->forgetStored($id);
                unset($this->pipelineRegion[$id]);
                unset($this->pipelineChunkX[$id], $this->pipelineChunkZ[$id], $this->pipelineChunkEpoch[$id]);
            }
        }

        unset($this->regionThreads[$regionId]);
        $region->shutdown();
        $region->join();
        $this->regionEpoch++; // region bounds changed: cached ownership is stale
        $this->pipelineStats['merges']++;
        return $absorber;
    }

    /**
     * Process one binary results batch from a region worker. Only batches
     * tagged with the current tick seq are compared/applied; older batches
     * are dropped as 'lagged'. The gate's drift check relies on bit-exact
     * float equality between worker and main-thread integration (identical
     * operations on the same values; binary doubles round-trip exactly), so
     * the epsilon only guards against future variable-timestep drift.
     */
    private function processPipelineResults(string $blob): void {
        $decoded = RegionThread::decodeResultsBlock($blob);
        if ($decoded['seq'] !== $this->pipelineTickSeq) {
            $this->pipelineStats['lagged'] += count($decoded['ids']);
            return;
        }
        $ids = $decoded['ids'];
        $doubles = $decoded['doubles'];
        $count = min(count($ids), intdiv(count($doubles), 6));
        for ($i = 0; $i < $count; $i++) {
            $entityId = $ids[$i];
            $j = $i * 6;
            $wx = $doubles[$j];
            $wy = $doubles[$j + 1];
            $wz = $doubles[$j + 2];
            $vx = $doubles[$j + 3];
            $vy = $doubles[$j + 4];
            $vz = $doubles[$j + 5];
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
            if ($this->regionPipelineApply) {
                $pos->x = $wx;
                $pos->y = $wy;
                $pos->z = $wz;
                $vel = $entity->get(VelocityComponent::class);
                if ($vel !== null) {
                    $vel->x = $vx;
                    $vel->y = $vy;
                    $vel->z = $vz;
                }
                // The worker's stored snapshot is now the applied (post-
                // integration) state - record it so the next mirror sees the
                // entity as in-sync and skips the re-mirror (no double
                // integration).
                $this->pipelineStoredX[$entityId] = $wx;
                $this->pipelineStoredY[$entityId] = $wy;
                $this->pipelineStoredZ[$entityId] = $wz;
                $this->pipelineStoredVX[$entityId] = $vx;
                $this->pipelineStoredVY[$entityId] = $vy;
                $this->pipelineStoredVZ[$entityId] = $vz;
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

    public function setPhaseProfiling(bool $enabled): void {
        $this->phaseProfiling = $enabled;
    }

    /** Per-phase mean timings (ms) across the run, for pipeline benchmarks. */
    public function getPhaseStats(): array {
        $out = [];
        foreach ($this->phaseTimes as $name => $samples) {
            $count = count($samples);
            if ($count === 0) {
                $out[$name . '_ms'] = 0.0;
                continue;
            }
            $out[$name . '_ms'] = round(array_sum($samples) / $count, 3);
        }
        return $out;
    }

    public function shutdown(): void {
        if (!$this->running && $this->shutdownComplete) {
            return;
        }
        $this->running = false;
        
        // Shutdown threads (only if they were actually started)
        if ($this->threadsStarted) {
            $this->coordinationThread->shutdown();
            foreach ($this->regionThreads as $regionThread) {
                $regionThread->shutdown();
            }
            
            // Wait for threads to finish
            $this->coordinationThread->join();
            foreach ($this->regionThreads as $regionThread) {
                $regionThread->join();
            }
        }
        
        // Disconnect all sessions before the socket closes.
        $this->networkSessionService->shutdown();

        // Stop the chunk-generation pool (real worker threads) and shut down
        // the adapter's RakLibServer thread (which owns the UDP socket).
        if ($this->worldGenPort instanceof ParallelGeneratorAdapter) {
            $this->worldGenPort->shutdown();
        }
        if ($this->networkPort instanceof Protocol84NetworkAdapter) {
            $this->networkPort->shutdown();
        }
        $this->threadingPort->shutdown();
        // Blocker 1: persist the player lists (ops/bans/whitelist) so the
        // text files on disk never lag the in-memory state.
        $lists = $this->resourceRegistry->get(\pocketmine\core\resource\PlayerListManager::class);
        if ($lists instanceof \pocketmine\core\resource\PlayerListManager) {
            $lists->saveAll();
        }
        // 14.4: persist everything before the process exits.
        $this->saveWorld();
        $this->storagePort->saveAll();
        $this->shutdownComplete = true;
    }

    /**
     * Read one or more complete console lines from the non-blocking TTY stdin
     * and dispatch each through the shared command map as the console sender.
     */
    private function pollConsole(): void {
        while (($raw = fgets(STDIN)) !== false) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            echo '[CONSOLE] > ' . $line . PHP_EOL;
            // Accept both 'stop' and '/stop' (the player-command prefix).
            $this->commandPort->execute(
                new \pocketmine\api\command\ConsoleCommandSender(),
                ltrim($line, '/')
            );
        }
    }

    /**
     * 14.4 persistence: flush every resident chunk to disk and write the
     * world meta (seed/spawn/difficulty) so a restart reproduces the same
     * terrain and spawn point. Called on the autosave interval and shutdown.
     */
    private function saveWorld(): void {
        // 14.20: every world bundle persists its own store to its own storage
        // folder. The default world (id 0) keeps the historical behavior; new
        // worlds save their region files under worlds/<folderName>/.
        $worldRegistry = $this->resourceRegistry->get(\pocketmine\core\resource\WorldRegistry::class);
        if ($worldRegistry instanceof \pocketmine\core\resource\WorldRegistry) {
            foreach ($worldRegistry->getWorlds() as $worldId => $worldInfo) {
                $this->saveWorldBundle($worldId, $worldRegistry->getStore($worldId), $worldRegistry->getStorage($worldId), $worldRegistry->getConfig($worldId));
            }
        } else {
            // Fallback: pre-registry path (tests constructing the kernel
            // without a registry) - save the single default store.
            $store = $this->resourceRegistry->get(\pocketmine\core\resource\ChunkStore::class);
            if ($store instanceof \pocketmine\core\resource\ChunkStore) {
                $this->saveWorldBundle(0, $store, $this->storagePort, $this->resourceRegistry->get(\pocketmine\core\resource\WorldConfig::class));
            }
        }
        // 14.4b: persist every online player (position/health/inventory/
        // metadata) on the same interval so a crash loses at most the
        // autosave window - clean disconnects save immediately on leave.
        foreach ($this->networkSessionService->getOnlinePlayers() as $player) {
            $entity = $this->world->getEntity($player['entityId']);
            if ($entity !== null) {
                $this->playerLeaveService->savePlayer(
                    \pocketmine\core\ecs\EntityRef::create($player['entityId'], $this->world)
                );
            }
        }
    }

    /**
     * Save one world bundle: every resident chunk through its own StoragePort
     * (with chest/furnace tile snapshots attached) plus its world meta.
     */
    private function saveWorldBundle(int $worldId, ?\pocketmine\core\resource\ChunkStore $store, ?StoragePort $storage, ?\pocketmine\core\resource\WorldConfig $worldConfig): void {
        if ($store === null || $storage === null) {
            return;
        }
        foreach ($store->getLoadedChunkCoordinates() as [$chunkX, $chunkZ]) {
            $chunkData = $store->toChunkData($chunkX, $chunkZ);
            if ($chunkData !== null) {
                $chunkData = \pocketmine\core\service\ChunkPersistence::attachTileSnapshots(
                    $this->resourceRegistry,
                    $chunkData,
                    $chunkX,
                    $chunkZ,
                    $worldId,
                );
                $storage->saveChunk($chunkX, $chunkZ, $chunkData);
            }
        }
        $config = $this->resourceRegistry->get(\pocketmine\core\resource\ServerConfig::class);
        if ($config instanceof \pocketmine\core\resource\ServerConfig) {
            // Prefer the WorldConfig seed when it was pinned; fall through to
            // the ServerConfig seed otherwise. WorldConfig defaults to 0 and is
            // only set when the meta was restored or a world was created with
            // an explicit seed - writing that 0 here would wipe the seed and
            // make the world regenerate on every restart.
            $seed = ($worldConfig !== null && $worldConfig->seed !== 0) ? $worldConfig->seed : $config->getSeed();
            $storage->saveWorldMeta([
                'seed' => (string)$seed,
                'spawnX' => (string)($worldConfig?->spawnX ?? $config->spawnX),
                'spawnY' => (string)($worldConfig?->spawnY ?? $config->spawnY),
                'spawnZ' => (string)($worldConfig?->spawnZ ?? $config->spawnZ),
                'difficulty' => (string)$config->difficulty->value,
                'time' => (string)($worldConfig?->time ?? 0),
                // 14.22: resume the weather spell and its remaining duration.
                'weather' => (string)($worldConfig?->weather ?? 0),
                'weatherDuration' => (string)($worldConfig?->weatherDuration ?? 0),
                // 14.20b: persist the world's generator (normal/flat/void) so
                // it survives restarts and is restored on load.
                'generator' => ($worldConfig?->generator ?? \pocketmine\core\enum\GeneratorType::Normal)->value,
            ]);
        }
    }

    /**
     * Persist every world bundle (chunks + meta) now. Public so the API
     * Server facade can save before unloading a world.
     */
    public function saveAllWorlds(): void {
        $this->saveWorld();
        $this->storagePort->saveAll();
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

    public function getPermissionManager(): \pocketmine\api\permission\PermissionManager {
        return $this->permissionManager;
    }

    public function getComponentRegistry(): ComponentRegistry {
        return $this->componentRegistry;
    }

    /**
     * Memory profile snapshot (11.3): resident entities and archetype array
     * sizing from the ECS, plus loaded-chunk count and payload bytes from the
     * ChunkStore. Rough per-archetype cost = sum of wasted slots * ~16 bytes
     * per slot (zval pointer); chunk bytes are the actual binary strings.
     */
    public function getMemoryProfile(): array {
        $archetypes = [];
        foreach ($this->componentRegistry->getArchetypes() as $key => $archetype) {
            $archetypes[$key] = $archetype->getStats();
        }
        $chunkStore = $this->resourceRegistry->get(\pocketmine\core\resource\ChunkStore::class);
        $chunkCount = $chunkStore instanceof \pocketmine\core\resource\ChunkStore ? $chunkStore->getCount() : 0;
        $chunkBytes = $chunkStore instanceof \pocketmine\core\resource\ChunkStore ? $chunkStore->getMemoryEstimate() : 0;
        return [
            'entities' => count($this->world->getEntities()),
            'archetypes' => count($archetypes),
            'archetypeStats' => $archetypes,
            'loadedChunks' => $chunkCount,
            'chunkBytes' => $chunkBytes,
            'maxLoadedChunks' => $this->chunkLoadService->getMaxLoadedChunks(),
            'phpPeakBytes' => memory_get_peak_usage(true),
            'phpCurrentBytes' => memory_get_usage(true),
        ];
    }

    public function getResourceRegistry(): ResourceRegistry {
        return $this->resourceRegistry;
    }

    /**
     * Multi-world registry (14.20): per-world bundles (ChunkStore, WorldConfig,
     * seed, storage folder). The default world is id 0; extra worlds are added
     * via Server::generateWorld()/loadWorld() or directly on the registry.
     */
    public function getWorldRegistry(): \pocketmine\core\resource\WorldRegistry {
        $registry = $this->resourceRegistry->get(\pocketmine\core\resource\WorldRegistry::class);
        if (!$registry instanceof \pocketmine\core\resource\WorldRegistry) {
            throw new \RuntimeException('WorldRegistry not registered');
        }
        return $registry;
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

    /**
     * The stable, server-wide plugin manager (the same instance exposed as
     * the plugin port).
     */
    public function getPluginManager(): \pocketmine\api\plugin\PluginManager {
        if (!$this->pluginPort instanceof \pocketmine\api\plugin\PluginManager) {
            throw new \LogicException('Plugin port is not backed by the api PluginManager');
        }
        return $this->pluginPort;
    }

    /**
     * Blocker 4: load every plugin found in a directory. Accepts .phar
     * archives and directories carrying a plugin.yml (the two formats
     * PluginManager::loadPlugin supports; .jar archives are rejected there).
     * Per-plugin failures are logged by the manager and never abort startup.
     *
     * @return int number of plugins successfully loaded and enabled
     */
    public function loadPluginsFromDirectory(string $dir): int {
        if (!is_dir($dir)) {
            return 0;
        }
        $manager = $this->getPluginManager();
        $base = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $loaded = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $base . $entry;
            if (is_file($path)) {
                // Only .phar archives are plugin candidates; anything else
                // (README, logs, etc.) in the folder is ignored.
                if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'phar') {
                    continue;
                }
            } elseif (is_dir($path)) {
                // A directory is only a plugin when it carries a plugin.yml.
                if (!is_file($path . DIRECTORY_SEPARATOR . 'plugin.yml')) {
                    continue;
                }
            } else {
                continue;
            }
            if ($manager->loadPlugin($path) !== null) {
                $loaded++;
            }
        }
        return $loaded;
    }

    public function getCoordinationThread(): CoordinationThread {
        return $this->coordinationThread;
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

    public function getPotionService(): PotionService {
        return $this->potionService;
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
                $this->regionThreads[$regionId]->wakeup();
            }
        }
    }

    private function flushNetworkSync(): void {
        // Network adapter I/O happens on the main thread. Receive and decode
        // inbound datagrams, let the session service respond to game packets,
        // then flush the compressed outbound frames to the socket.
        if ($this->networkPort instanceof \pocketmine\adapter\driven\network\Protocol84NetworkAdapter) {
            try {
                $this->networkPort->processPendingCommands();
                $this->networkSessionService->poll();
                $this->networkPort->flushOutboundPackets();
            } catch (\Throwable $e) {
                // A hostile/foreign game packet must not take down the whole
                // server: surface the error and keep ticking.
                fwrite(STDERR, '[net] poll error: ' . $e->getMessage()
                    . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
            }
            // Surface RakLib thread logs: critical lines always (a thread
            // crash would otherwise be silent), everything when tracing.
            $traceOn = getenv('KHRONOS_WIRE_TRACE') === '1';
            foreach ($this->networkPort->drainLogLines() as $line) {
                if ($traceOn || str_starts_with($line, 'critical')) {
                    fwrite(STDERR, '[raknet] ' . $line . PHP_EOL);
                }
            }
        }
    }
}

function createKernel(int $regionCount = 1, ?int $maxEntitiesPerRegion = null): Kernel {
    // khronos.json is loaded BEFORE any port is created: its default-world
    // folder routes the storage adapter (which folder the world lives in), so
    // the file is written/read first and the folder is passed down.
    $khronosConfig = loadKhronosConfig();

    $threadingPort = createThreadingPort();
    $networkPort = createNetworkPort();
    $storagePort = createStoragePort($khronosConfig->defaultWorld);
    $worldGenPort = createWorldGenPort($threadingPort);
    $eventPort = createEventPort();
    $commandPort = createCommandPort($eventPort);
    // Builtin commands (gamemode/tp/give/kill/time/help) share the same
    // server-wide map the console and plugins use.
    \pocketmine\api\command\builtin\BuiltinCommands::registerAll($commandPort);
    $pluginPort = createPluginPort($commandPort, $eventPort);

    $componentRegistry = new ComponentRegistry();
    $resourceRegistry = new ResourceRegistry();
    $systemScheduler = new SystemScheduler($threadingPort);
    $world = new World($componentRegistry, $resourceRegistry, $systemScheduler);

    // Register built-in components
    registerBuiltinComponents($componentRegistry);

    // Register built-in resources
    registerBuiltinResources($resourceRegistry);
    // Replace the default KhronosConfig with the file-loaded instance so every
    // reader (services, commands, plugins) sees the admin's values.
    $resourceRegistry->set($khronosConfig);

    // Register built-in crafting recipes
    registerBuiltinRecipes($resourceRegistry);

    // Register built-in projectiles
    registerBuiltinProjectiles($resourceRegistry);

    // Register built-in systems
    registerBuiltinSystems($systemScheduler);

    // 14.4: restore the persisted world (seed/spawn/difficulty) BEFORE the
    // kernel exists so the first chunk generation (player join) and the safe
    // spawn use the saved seed - never a fresh random one.
    applyPersistedWorldMeta($storagePort, $resourceRegistry);

    // Blocker 1: read server.properties (generating it with defaults when
    // missing) and apply it to the configs, the Server facade, and the
    // network adapter. Persisted world meta takes precedence over the file's
    // level-seed, so apply the file AFTER applyPersistedWorldMeta.
    applyServerProperties($networkPort, $resourceRegistry);

    // khronos.json wins for the keys it owns (server.properties and the
    // persisted world meta stay authoritative for theirs): explicit spawn
    // coordinates override the world spawn, the default-world folder is
    // mirrored into the WorldConfig display name.
    applyKhronosConfig($resourceRegistry, $khronosConfig);

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
        $regionCount,
        $maxEntitiesPerRegion,
    );
}

function createThreadingPort(): ThreadingPort {
    $workerCount = max(1, (int)shell_exec('nproc') - 2);
    return new PmmpThreadPool($workerCount);
}

function createNetworkPort(): NetworkPort {
    return new Protocol84NetworkAdapter("0.0.0.0", 19132);
}

function createStoragePort(string $levelName = 'world'): StoragePort {
    // Auto-detect the default world's format on disk (Anvil / McRegion /
    // LevelDB-with-error), falling back to a fresh Anvil world. The folder
    // comes from khronos.json default-world when configured.
    return LevelProviderManager::create(LevelProviderManager::DEFAULT_DATA_PATH, $levelName);
}

function createWorldGenPort(ThreadingPort $threadingPort): WorldGenPort {
    return new ParallelGeneratorAdapter($threadingPort);
}

function createCommandPort(EventPort $eventPort): CommandPort {
    return new \pocketmine\api\command\CommandMap($eventPort);
}

/**
 * Read khronos.json from the data path, generating the default file on first
 * boot (mirroring server.properties). The world-folder it selects must be
 * known before the storage port is created, so this runs first in createKernel.
 */
function loadKhronosConfig(): \pocketmine\core\resource\KhronosConfig {
    $dataPath = getcwd() . DIRECTORY_SEPARATOR;
    $path = $dataPath . 'khronos.json';
    if (!is_file($path)) {
        \pocketmine\core\resource\KhronosConfig::writeDefaults($path);
    }
    return \pocketmine\core\resource\KhronosConfig::load($path);
}

/**
 * Apply the khronos.json values that override other config surfaces: an
 * explicit default-spawn wins over the persisted world spawn, and the
 * default-world folder is mirrored into the WorldConfig display name.
 */
function applyKhronosConfig(ResourceRegistry $resourceRegistry, \pocketmine\core\resource\KhronosConfig $config): void {
    if ($config->spawnX !== null && $config->spawnY !== null && $config->spawnZ !== null) {
        $serverConfig = $resourceRegistry->get(\pocketmine\core\resource\ServerConfig::class);
        if ($serverConfig instanceof \pocketmine\core\resource\ServerConfig) {
            $serverConfig->spawnX = $config->spawnX;
            $serverConfig->spawnY = $config->spawnY;
            $serverConfig->spawnZ = $config->spawnZ;
        }
        $worldConfig = $resourceRegistry->get(\pocketmine\core\resource\WorldConfig::class);
        if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
            $worldConfig->spawnX = $config->spawnX;
            $worldConfig->spawnY = $config->spawnY;
            $worldConfig->spawnZ = $config->spawnZ;
        }
    }
    $worldConfig = $resourceRegistry->get(\pocketmine\core\resource\WorldConfig::class);
    if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
        $worldConfig->name = $config->defaultWorld;
        $worldConfig->folderName = $config->defaultWorld;
    }
}

/**
 * Blocker 1: load server.properties from the data path (writing a default
 * file on first boot), then apply every understood key to the shared configs
 * and the Server facade. Unknown or malformed values keep the defaults.
 */
function applyServerProperties(NetworkPort $networkPort, ResourceRegistry $resourceRegistry): void {
    $dataPath = getcwd() . DIRECTORY_SEPARATOR;
    $path = $dataPath . 'server.properties';
    if (!is_file($path)) {
        \pocketmine\core\resource\ServerProperties::writeDefaults($path);
    }
    $props = \pocketmine\core\resource\ServerProperties::load($path);

    $serverConfig = $resourceRegistry->get(\pocketmine\core\resource\ServerConfig::class);
    if ($serverConfig instanceof \pocketmine\core\resource\ServerConfig) {
        $serverConfig->viewDistance = \pocketmine\core\resource\ServerProperties::int($props, 'view-distance', $serverConfig->viewDistance);
        $serverConfig->pvpEnabled = \pocketmine\core\resource\ServerProperties::bool($props, 'pvp', $serverConfig->pvpEnabled);
        $serverConfig->spawnAnimals = \pocketmine\core\resource\ServerProperties::bool($props, 'spawn-animals', $serverConfig->spawnAnimals);
        $serverConfig->spawnMobs = \pocketmine\core\resource\ServerProperties::bool($props, 'spawn-mobs', $serverConfig->spawnMobs);
        $serverConfig->difficulty = \pocketmine\core\enum\Difficulty::coerce(\pocketmine\core\resource\ServerProperties::int($props, 'difficulty', $serverConfig->difficulty->value));
        $serverConfig->whiteList = \pocketmine\core\resource\ServerProperties::bool($props, 'white-list', $serverConfig->whiteList);
        $serverConfig->defaultGameMode = \pocketmine\core\enum\GameMode::coerce(\pocketmine\core\resource\ServerProperties::int($props, 'gamemode', $serverConfig->defaultGameMode->value));
        $serverConfig->autosaveIntervalTicks = max(1,
            \pocketmine\core\resource\ServerProperties::int($props, 'autosave-interval', 60) * 20
        );

        // level-seed only pins when no world meta restored a seed: an
        // existing world's saved seed is authoritative over the file.
        $seedProp = (string)($props['level-seed'] ?? '');
        if ($seedProp !== '' && $serverConfig->seed === 0) {
            $seed = (int)$seedProp;
            $serverConfig->seed = $seed;
            $worldConfig = $resourceRegistry->get(\pocketmine\core\resource\WorldConfig::class);
            if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig && $worldConfig->seed === 0) {
                $worldConfig->seed = $seed;
            }
        }
    }

    // The WorldConfig mirrors max-players / view-distance / gamemode for the
    // world-bundle readers (StartGame, chunk streaming budget, login burst).
    $worldConfig = $resourceRegistry->get(\pocketmine\core\resource\WorldConfig::class);
    if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
        $worldConfig->maxPlayers = \pocketmine\core\resource\ServerProperties::int($props, 'max-players', $worldConfig->maxPlayers);
        $worldConfig->viewDistance = $serverConfig?->viewDistance ?? $worldConfig->viewDistance;
        $worldConfig->gameMode = $serverConfig?->defaultGameMode ?? $worldConfig->gameMode;
    }

    // The Server facade is what plugins read for server-level config.
    \pocketmine\api\server\Server::getInstance()->configure($props);

    // Adapter: bind port + structured motd name from the file.
    if ($networkPort instanceof Protocol84NetworkAdapter) {
        $networkPort->setBindPort(\pocketmine\core\resource\ServerProperties::int($props, 'server-port', 19132));
        $networkPort->setServerName((string)($props['server-name'] ?? 'Khronos Server'));
    }

    // Blocker 1: the player lists (ops/whitelist/bans) live in the data path;
    // register so the login path and admin commands share one instance.
    $resourceRegistry->set(new \pocketmine\core\resource\PlayerListManager($dataPath));
}

/**
 * Blocker 1: register the builtin command permissions so the PermissionManager
 * defaults apply them to ops (and /help + /list to everyone) without any
 * plugin.yml.
 */
function registerBuiltinPermissions(\pocketmine\api\permission\PermissionManager $manager): void {
    $op = \pocketmine\api\permission\Permission::DEFAULT_OP;
    $everyone = \pocketmine\api\permission\Permission::DEFAULT_TRUE;
    foreach ([
        'khronos.command.gamemode' => 'Change player gamemodes',
        'khronos.command.tp' => 'Teleport players',
        'khronos.command.give' => 'Give items',
        'khronos.command.kill' => 'Kill players',
        'khronos.command.time' => 'Set the world clock',
        'khronos.command.weather' => 'Set the weather',
        'khronos.command.world' => 'List and switch worlds',
        'khronos.command.stop' => 'Stop the server',
        'khronos.command.save-all' => 'Save the world now',
        'khronos.command.op' => 'Grant operator',
        'khronos.command.deop' => 'Revoke operator',
        'khronos.command.ban' => 'Ban a player',
        'khronos.command.kick' => 'Kick a player',
        'khronos.command.pardon' => 'Unban a player',
        'khronos.command.ban-ip' => 'Ban an IP address',
        'khronos.command.pardon-ip' => 'Unban an IP address',
        'khronos.command.whitelist' => 'Manage the whitelist',
        'khronos.command.plugins' => 'List loaded plugins',
    ] as $name => $description) {
        $manager->addPermission(new \pocketmine\api\permission\Permission($name, $description, $op));
    }
    $manager->addPermission(new \pocketmine\api\permission\Permission('khronos.command.help', 'Show command help', $everyone));
    $manager->addPermission(new \pocketmine\api\permission\Permission('khronos.command.list', 'List online players', $everyone));
}

function createEventPort(): EventPort {
    return new \pocketmine\api\event\EventBus();
}

function createPluginPort(CommandPort $commandPort, EventPort $eventPort): PluginPort {
    return new \pocketmine\api\plugin\PluginManager($commandPort, $eventPort);
}

function registerBuiltinComponents(ComponentRegistry $registry): void {
    $registry->register(\pocketmine\core\component\PositionComponent::class);
    $registry->register(\pocketmine\core\component\WorldComponent::class);
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
    $registry->set(new \pocketmine\api\permission\PermissionManager());
    $registry->set(new \pocketmine\core\resource\TickCounter());
    // khronos.json defaults are registered so registry lookups never fail;
    // createKernel replaces this with the file-loaded instance when present.
    $registry->set(new \pocketmine\core\resource\KhronosConfig());
    $registry->set(new \pocketmine\core\resource\ServerConfig());
    $registry->set(new \pocketmine\core\resource\SpatialIndex());
    $registry->set(new \pocketmine\core\resource\WorldConfig());
    $registry->set(new \pocketmine\core\resource\BlockRegistry());
    $registry->set(new \pocketmine\core\resource\ItemRegistry());
    $registry->set(new \pocketmine\core\resource\ChunkStore());
    $registry->set(new \pocketmine\core\resource\WorldRegistry());
    $registry->set(new \pocketmine\core\resource\ChestStore());
    $registry->set(new \pocketmine\core\resource\RecipeRegistry());
    $registry->set(new \pocketmine\core\resource\SmeltingRegistry());
    $registry->set(new \pocketmine\core\resource\FurnaceStore());
    $registry->set(new \pocketmine\core\resource\ContainerStore());
    $registry->set(new \pocketmine\core\resource\BrewingStore());
    $registry->set(new \pocketmine\core\resource\BrewingRegistry());
    $registry->set(new \pocketmine\core\resource\ProjectileRegistry());
    $registry->set(new \pocketmine\core\resource\PotionRegistry());
    $registry->set(new \pocketmine\core\resource\TileEntityStore());
}

function registerBuiltinProjectiles(ResourceRegistry $registry): void {
    $projectiles = $registry->get(\pocketmine\core\resource\ProjectileRegistry::class);
    if (!$projectiles instanceof \pocketmine\core\resource\ProjectileRegistry) {
        return;
    }

    // Arrow: legacy Arrow::NETWORK_ID 80, damage 2, gravity 0.05, drag 0.01,
    // sticky (embeds in entities it hits).
    $projectiles->register(\pocketmine\core\enum\EntityType::Arrow->value, 80, 2.0, 0.05, 0.01, true);
    // 14.23 throwables: snowball (81), egg (82) and thrown potion (86) are
    // non-sticky - they despawn on impact. Snowball/egg deal no damage (0.15
    // parity); the potion's splash effect is applied by PotionService through
    // the POTION_ID metadata set at throw time.
    $projectiles->register(\pocketmine\core\enum\EntityType::Snowball->value, 81, 0.0, 0.03, 0.01, false);
    $projectiles->register(\pocketmine\core\enum\EntityType::Egg->value, 82, 0.0, 0.03, 0.01, false);
    $projectiles->register(\pocketmine\core\enum\EntityType::ThrownPotion->value, 86, 0.0, 0.05, 0.01, false);
}

function registerBuiltinRecipes(ResourceRegistry $registry): void {
    $recipes = $registry->get(\pocketmine\core\resource\RecipeRegistry::class);
    if (!$recipes instanceof \pocketmine\core\resource\RecipeRegistry) {
        return;
    }

    $recipes->registerShaped(
        'planks_from_log',
        ['L'],
        ['L' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::LOG, -1)], // any log (meta wildcard)
        new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::PLANKS, 0, 4),
    );
    $recipes->registerShaped(
        'sticks',
        ['P', 'P'],
        ['P' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::PLANKS)],
        new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::STICK, 0, 4),
    );
    $recipes->registerShaped(
        'crafting_table',
        ['PP', 'PP'],
        ['P' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::PLANKS)],
        new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::CRAFTING_TABLE, 0, 1),
    );
    $recipes->registerShaped(
        'furnace',
        ['CCC', 'C C', 'CCC'],
        ['C' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::COBBLESTONE)],
        new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::FURNACE, 0, 1),
    );
    $recipes->registerShaped(
        'chest',
        ['PPP', 'P P', 'PPP'],
        ['P' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::PLANKS)],
        new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::CHEST, 0, 1),
    );
    $recipes->registerShaped(
        'wooden_pickaxe',
        ['PPP', ' S ', ' S '],
        ['P' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::PLANKS), 'S' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::STICK)],
        new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::WOODEN_PICKAXE, 0, 1),
    );
    $recipes->registerShaped(
        'wooden_axe',
        ['PP ', 'PS ', ' S '],
        ['P' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::PLANKS), 'S' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::STICK)],
        new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::WOODEN_AXE, 0, 1),
    );
    $recipes->registerShaped(
        'wooden_sword',
        ['P', 'P', 'S'],
        ['P' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::PLANKS), 'S' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::STICK)],
        new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::WOODEN_SWORD, 0, 1),
    );
    $recipes->registerShaped(
        'torches',
        ['C', 'S'],
        ['C' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::COAL), 'S' => new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::STICK)],
        new \pocketmine\core\component\ItemStack(\pocketmine\core\constants\ItemIds::TORCH, 0, 4),
    );

    // 14.16 furnace smelting recipes + fuel (legacy recipes.json type 2/3
    // and Fuel::$duration - authoritative for protocol 84).
    registerBuiltinSmelting($registry);
}

function registerBuiltinSmelting(ResourceRegistry $registry): void {
    $smelting = $registry->get(\pocketmine\core\resource\SmeltingRegistry::class);
    if (!$smelting instanceof \pocketmine\core\resource\SmeltingRegistry) {
        return;
    }
    $item = static fn(int $id, int $meta = 0, int $count = 1) => new \pocketmine\core\component\ItemStack($id, $meta, $count);

    // Ores / blocks -> refined products.
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::COBBLESTONE), $item(\pocketmine\core\constants\ItemIds::STONE));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::SAND), $item(\pocketmine\core\constants\ItemIds::GLASS));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::GOLD_ORE), $item(\pocketmine\core\constants\ItemIds::GOLD_INGOT));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::IRON_ORE), $item(\pocketmine\core\constants\ItemIds::IRON_INGOT));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::COAL_ORE), $item(\pocketmine\core\constants\ItemIds::COAL));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::LOG, -1), $item(\pocketmine\core\constants\ItemIds::COAL, 1)); // logs -> charcoal
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::LOG_ACACIA, -1), $item(\pocketmine\core\constants\ItemIds::COAL, 1)); // acacia/dark oak logs -> charcoal
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::LAPIS_ORE), $item(\pocketmine\core\constants\ItemIds::DYE, 4)); // lapis ore -> lapis lazuli
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::DIAMOND_ORE), $item(\pocketmine\core\constants\ItemIds::DIAMOND));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::REDSTONE_ORE), $item(\pocketmine\core\constants\ItemIds::REDSTONE));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::CACTUS), $item(\pocketmine\core\constants\ItemIds::DYE, 2)); // cactus -> cactus green
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::CLAY_BLOCK), $item(\pocketmine\core\constants\ItemIds::HARDENED_CLAY));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::NETHERRACK), $item(\pocketmine\core\constants\ItemIds::NETHER_BRICK));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::EMERALD_ORE), $item(\pocketmine\core\constants\ItemIds::EMERALD));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::NETHER_QUARTZ_ORE), $item(\pocketmine\core\constants\ItemIds::QUARTZ));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::STONE_BRICKS, 0), $item(\pocketmine\core\constants\ItemIds::STONE_BRICKS, 2)); // stone bricks -> cracked stone bricks

    // Food.
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::RAW_PORKCHOP), $item(\pocketmine\core\constants\ItemIds::COOKED_PORKCHOP));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::CLAY_BALL), $item(\pocketmine\core\constants\ItemIds::BRICK)); // clay ball -> brick
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::RAW_FISH), $item(\pocketmine\core\constants\ItemIds::COOKED_FISH));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::RAW_BEEF), $item(\pocketmine\core\constants\ItemIds::STEAK));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::RAW_CHICKEN), $item(\pocketmine\core\constants\ItemIds::COOKED_CHICKEN));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::POTATO), $item(\pocketmine\core\constants\ItemIds::BAKED_POTATO));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::RAW_RABBIT), $item(\pocketmine\core\constants\ItemIds::COOKED_RABBIT));
    $smelting->registerSmelting($item(\pocketmine\core\constants\ItemIds::RAW_SALMON), $item(\pocketmine\core\constants\ItemIds::COOKED_SALMON));

    // Fuel (legacy Fuel::$duration; -1 = any meta).
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::COAL, -1, 1600);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::COAL_BLOCK, -1, 16000);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::LOG, -1, 300);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::LOG_ACACIA, -1, 300);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::PLANKS, -1, 300);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::SAPLING, -1, 100);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::STICK, -1, 100);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::WOODEN_SWORD, -1, 200);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::WOODEN_SHOVEL, -1, 200);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::WOODEN_PICKAXE, -1, 200);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::WOODEN_AXE, -1, 200);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::WOODEN_HOE, -1, 200);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::CRAFTING_TABLE, -1, 300);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::CHEST, -1, 300);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::BLAZE_ROD, -1, 2400);
    $smelting->registerFuel(\pocketmine\core\constants\ItemIds::BUCKET, 10, 20000);
}

/**
 * 14.4: apply a previously saved world's meta (seed, spawn, difficulty) onto
 * the freshly created ServerConfig. Missing or empty values are left alone.
 */
function applyPersistedWorldMeta(StoragePort $storagePort, ResourceRegistry $resourceRegistry): void {
    $meta = $storagePort->loadWorldMeta();
    if ($meta === null) {
        // No level.dat at all: a genuinely fresh server (no world folder yet)
        // keeps the normal generator, but a dropped-in world folder (foreign
        // data without a Khronos level.dat) defaults to VOID so the server
        // never regenerates terrain around whatever the user placed there.
        $worldConfig = $resourceRegistry->get(\pocketmine\core\resource\WorldConfig::class);
        if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig && $storagePort->worldFolderExists()) {
            $worldConfig->generator = \pocketmine\core\enum\GeneratorType::Void;
        }
        return;
    }
    $config = $resourceRegistry->get(\pocketmine\core\resource\ServerConfig::class);
    if (!$config instanceof \pocketmine\core\resource\ServerConfig) {
        return;
    }
    if (isset($meta['seed']) && $meta['seed'] !== '' && (int)$meta['seed'] !== 0) {
        $config->seed = (int)$meta['seed'];
        // Keep the WorldConfig in sync so every reader (StartGame seed, the
        // save path, the World facade, per-world WorldRegistry bundles) sees
        // the same restored seed - not a stale 0 default.
        $restoredSeed = $resourceRegistry->get(\pocketmine\core\resource\WorldConfig::class);
        if ($restoredSeed instanceof \pocketmine\core\resource\WorldConfig) {
            $restoredSeed->seed = (int)$meta['seed'];
        }
    }
    if (isset($meta['spawnX']) && $meta['spawnX'] !== '') {
        $config->spawnX = (int)$meta['spawnX'];
    }
    // The default world's generator follows the persisted level.dat when it
    // has one; a foreign/legacy level.dat (no generator info) defaults to
    // VOID so the world is never regenerated around the player's build.
    $worldConfigGen = $resourceRegistry->get(\pocketmine\core\resource\WorldConfig::class);
    if ($worldConfigGen instanceof \pocketmine\core\resource\WorldConfig) {
        $worldConfigGen->generator = \pocketmine\core\enum\GeneratorType::coerce($meta['generator'] ?? 'void');
    }
    if (isset($meta['spawnY']) && $meta['spawnY'] !== '') {
        $config->spawnY = (int)$meta['spawnY'];
    }
    if (isset($meta['spawnZ']) && $meta['spawnZ'] !== '') {
        $config->spawnZ = (int)$meta['spawnZ'];
    }
    if (isset($meta['difficulty']) && $meta['difficulty'] !== '') {
        $config->difficulty = \pocketmine\core\enum\Difficulty::coerce((int)$meta['difficulty']);
    }
    // 14.6: resume the persisted time of day (wrapped to a valid day range).
    $worldConfig = $resourceRegistry->get(\pocketmine\core\resource\WorldConfig::class);
    if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig
        && isset($meta['time']) && $meta['time'] !== '') {
        $worldConfig->time = ((int)$meta['time'] % 24000 + 24000) % 24000;
    }
    // 14.22: resume the weather spell and its remaining duration (missing or
    // zero means the next tick rolls a fresh spell - same as a new world).
    if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig
        && isset($meta['weather']) && $meta['weather'] !== '') {
        $worldConfig->weather = (int)$meta['weather'];
    }
    if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig
        && isset($meta['weatherDuration']) && $meta['weatherDuration'] !== '') {
        $worldConfig->weatherDuration = (int)$meta['weatherDuration'];
    }
}

function registerBuiltinSystems(SystemScheduler $scheduler): void {
    $scheduler->register(new \pocketmine\core\system\PhysicsSystem(), \pocketmine\core\ecs\SystemPhase::PARALLEL);
    $scheduler->register(new \pocketmine\core\system\MovementSystem(), \pocketmine\core\ecs\SystemPhase::PARALLEL);
    $scheduler->register(new \pocketmine\core\system\EffectSystem(), \pocketmine\core\ecs\SystemPhase::PARALLEL);
    // Sequential: AI needs main-thread access to the combat pipeline, spatial
    // index, and cross-entity reads. Runs before parallel movement/physics so
    // the velocities it writes are integrated the same tick.
    $scheduler->register(new \pocketmine\core\system\AISystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.6: day/night cycle - advances WorldConfig::$time every tick so the
    // network layer can broadcast it and the mob spawner can gate on it.
    // Runs before MobSpawnerSystem so the spawner sees the advanced time.
    $scheduler->register(new \pocketmine\core\system\TimeSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.22: weather cycle - transitions between clear/rain/storm spells with
    // random durations and a lightning timer during storms. After time so the
    // world clock and weather stay independent; before the network poll the
    // broadcast picks up transitions the same tick they occur.
    $scheduler->register(new \pocketmine\core\system\WeatherSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.3: periodic hostile-mob spawning near players. Registered after AI so
    // fresh spawns do not act (target, move) the same tick they appear.
    $scheduler->register(new \pocketmine\core\system\MobSpawnerSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.5: dropped items are collected by nearby players (walk-over here,
    // plus the right-click route inside EntityInteractionService). Runs after
    // movement/AI so entity positions are current.
    $scheduler->register(new \pocketmine\core\system\ItemPickupSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.9: natural health regen - 1 HP / 4s after 5s without damage. Runs
    // after combat (health reads are current) and before chunk work.
    $scheduler->register(new \pocketmine\core\system\RegenSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.11: hunger - passive exhaustion, drain to saturation/hunger,
    // starvation damage, HUD sync. After regen so the hunger gate (regen
    // needs food) sees current values.
    $scheduler->register(new \pocketmine\core\system\HungerSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.16: furnaces - burn fuel, cook input, produce results on the main
    // thread every world tick (furnaces are blocks, not entities, so they
    // are not part of the region pipeline). Runs after crafting so the
    // smelting registry is available; no ordering constraint on gameplay.
    $scheduler->register(new \pocketmine\core\system\FurnaceSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.27: hoppers - pull one item from the container above and push one
    // into the container below every 8 ticks. After furnaces so a hopper
    // feeding a furnace sees the previous tick's burn state.
    $scheduler->register(new \pocketmine\core\system\HopperSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.27: brewing stands - count down the brew timer while an ingredient
    // and matching potion bottles are present, then convert them.
    $scheduler->register(new \pocketmine\core\system\BrewingSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.17: arrow projectiles - drag, block stick, entity hits, age despawn.
    // After AI so targets' positions are current; before chunk work.
    $scheduler->register(new \pocketmine\core\system\ArrowSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.22: primed TNT fuses and explodes (block destruction, entity
    // damage, chain reactions, ExplodePacket broadcast). Runs after combat
    // so creeper blasts triggered by a kill see consistent state.
    $scheduler->register(new \pocketmine\core\system\TNTExplosionSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.24: water/lava flow - liquids spread down and sideways every few
    // ticks, hardening lava to stone on contact with water. Sequential so it
    // sees the authoritative block state after place/break/explosion.
    $scheduler->register(new \pocketmine\core\system\FluidSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    // 14.25: vehicles - boats float on water, minecarts roll on rails, and
    // the rider follows the vehicle. After movement/physics so the vehicle
    // sees the integrated position; before chunk work.
    $scheduler->register(new \pocketmine\core\system\VehicleSystem(), \pocketmine\core\ecs\SystemPhase::SEQUENTIAL);
    $scheduler->register(new \pocketmine\core\system\ChunkUpdateSystem(), \pocketmine\core\ecs\SystemPhase::CHUNK_PARALLEL);
    // Post-movement block collision: clamps the pending positions written by
    // the parallel systems against solid blocks before they are committed
    // (mobs stop at walls, drops land on the ground).
    $scheduler->setCollisionSystem(new \pocketmine\core\system\BlockCollisionSystem());
}

function bootstrap(int $regionCount = 1, ?int $maxEntitiesPerRegion = null): Kernel {
    // Raise the PHP memory floor: a real 0.15.10 client joins at view
    // distance 8-12, which generates ~625 chunks (each ~200KB resident in
    // the ChunkStore) - far past the 128M CLI default. Legacy PocketMine
    // launched with -d memory_limit=512M for exactly this reason; the
    // server enforces the same floor itself (start scripts also pass it),
    // so direct `php bootstrap.php` and the test suite are covered too.
    $current = (string) ini_get('memory_limit');
    if ($current !== '-1' && parseIniBytes($current) < 512 * 1024 * 1024) {
        ini_set('memory_limit', '512M');
    }
    return createKernel($regionCount, $maxEntitiesPerRegion);
}

/** Parse a PHP ini byte-size value ("128M", "1G", "512") into bytes. */
function parseIniBytes(string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $unit = strtoupper(substr($value, -1));
    $number = (int) $value;
    return match ($unit) {
        'G' => $number * 1024 * 1024 * 1024,
        'M' => $number * 1024 * 1024,
        'K' => $number * 1024,
        default => (int) $value,
    };
}