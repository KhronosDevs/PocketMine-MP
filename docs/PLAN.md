# PocketMine-MP 2.0.0 Optimization Plan
## ECS Architecture + Clean Architecture + Multithreading Strategy

**Target:** Minecraft Pocket Edition 0.15.10 (protocol 84) — **PROTOCOL LAYER IS FROZEN**
**PHP Version:** 8.2
**Threading Extension:** pmmp/ext-pmmpthread (pthreads fork for PHP 8.1/8.2)
**Base Branch:** `master` (not `main`)
**PHP Binary:** `bin/php7/bin/php` (project-specific, see README.md)

---

## 1. CURRENT ARCHITECTURE ANALYSIS

### 1.1 Core Structure
```
src/pocketmine/
├── Server.php          # Monolithic god class (~2940 lines) - main tick loop, lifecycle, everything
├── entity/             # 60+ entity classes, Entity.php is 2000+ lines, heavy inheritance hierarchy
├── level/              # Level.php 3000+ lines - chunk mgmt, entity/tile ticking, world gen, physics
├── network/            # Protocol 84 implementation (FROZEN), Network.php, RakLibInterface
├── plugin/             # PluginManager, PluginBase, loaders - event-driven API
├── scheduler/          # ServerScheduler, AsyncPool, AsyncWorker - basic thread pool
├── block/              # Block classes, legacy static registry
├── item/               # Item classes, legacy static registry
├── tile/               # Tile entities (chest, hopper, etc.)
├── event/              # Event system, HandlerList, Timings
├── command/            # Command system
├── inventory/          # Inventory system
├── metadata/           # Metadata stores (entity, player, level, block)
├── nbt/                # NBT serialization
└── utils/              # Utilities
```

### 1.2 Key Pain Points Identified

| Area | Problem |
|------|---------|
| **Server.php** | God class: tick loop, player mgmt, level mgmt, plugin mgmt, network, RCON, query, autosave, console |
| **Entity.php** | 2000+ lines, deep inheritance (Entity → Living → Human → Player), mixed concerns (physics, networking, metadata, effects, AI) |
| **Level.php** | 3000+ lines: chunk loading, entity ticking, tile ticking, block updates, random ticks, weather, chunk sending, generation |
| **Threading** | AsyncPool only used for world generation; entity ticking, chunk loading, physics all on main thread |
| **Plugin API** | Event-based only, no query-based access, plugins couple to concrete implementations |
| **Data Flow** | Tight coupling: Entity ↔ Level ↔ Chunk ↔ Network ↔ Player all directly reference each other |
| **Protocol** | Network/protocol classes directly instantiated in entity/level logic (violates frozen boundary) |

### 1.3 Threading Status Quo
- **Main thread:** Network I/O, packet handling, entity ticking, tile ticking, block updates, chunk loading/sending, plugin events, scheduler
- **AsyncPool (2-8 workers):** World generation only (GeneratorRegisterTask, GenerationTask, PopulationTask, LightPopulationTask)
- **pmmpthread capabilities:** Thread, Worker, ThreadSafe, Synchronized, Condition, Threaded — full pthreads API available

---

## 2. ECS ARCHITECTURE DESIGN

### 2.1 Design Principles
- **Query-based access as primary interface** — no simplified facade; advanced developers get direct archetype/query access
- **Data-oriented design** — components are plain data (readonly where possible), systems contain logic
- **Archetype-based storage** — entities with same component set grouped for cache-friendly iteration
- **Plugin API exposes ECS primitives** — World, EntityBuilder, Query, System, Component, Resource
- **Protocol boundary respected** — Network serialization stays in adapter layer, never in components/systems

### 2.2 Core ECS Concepts

#### Component
```php
// Plain data objects, no behavior
#[Component]
final class PositionComponent {
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $z = 0.0,
        public float $yaw = 0.0,
        public float $pitch = 0.0,
    ) {}
}

#[Component]
final class VelocityComponent {
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $z = 0.0,
    ) {}
}

#[Component]
final class HealthComponent {
    public function __construct(
        public float $current = 20.0,
        public float $max = 20.0,
    ) {}
}

// Tag components (zero-size, presence = true)
#[Component]
final class PlayerTag {}

#[Component]
final class OnGroundTag {}

#[Component]
final class InvisibleTag {}
```

#### Resource (Singleton Global State)
```php
#[Resource]
final class TickCounter {
    public function __construct(public int $value = 0) {}
}

#[Resource]
final class ServerConfig {
    public function __construct(
        public int $viewDistance = 10,
        public int $tickRate = 20,
        public bool $pvpEnabled = true,
    ) {}
}
```

#### System (Logic)
```php
interface System {
    public function run(World $world, float $deltaTime): void;
}

final class MovementSystem implements System {
    public function run(World $world, float $deltaTime): void {
        $query = $world->query()
            ->with(PositionComponent::class, VelocityComponent::class)
            ->without(InvisibleTag::class) // optional filter
            ->build();
        
        foreach ($query as $entity) {
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            
            $pos->x += $vel->x * $deltaTime;
            $pos->y += $vel->y * $deltaTime;
            $pos->z += $vel->z * $deltaTime;
        }
    }
}

final class PhysicsSystem implements System {
    public function run(World $world, float $deltaTime): void {
        // Collision detection, gravity, etc.
        // Uses SpatialIndex resource for broad-phase
    }
}
```

#### Query API (Primary Interface)
```php
// Fluent query builder
$query = $world->query()
    ->with(PositionComponent::class, VelocityComponent::class, HealthComponent::class)
    ->withAny(PlayerTag::class, MonsterTag::class)  // OR logic
    ->without(DeadTag::class, SpectatorTag::class)  // Exclusion
    ->where(fn(Entity $e) => $e->get(HealthComponent::class)->current > 0)  // Runtime filter
    ->orderBy(fn(Entity $e) => $e->get(PositionComponent::class)->y)  // Sorting
    ->chunked(64)  // Batch processing for large queries
    ->build();

// Iteration
foreach ($query as $entity) {
    $pos = $entity->get(PositionComponent::class);
    $vel = $entity->get(VelocityComponent::class);
    $health = $entity->get(HealthComponent::class);
    // ...
}

// Or functional
$query->each(function(Entity $entity) {
    // ...
});

// Archetype iteration (cache-optimal)
foreach ($query->archetypes() as $archetype) {
    $positions = $archetype->getComponentArray(PositionComponent::class);
    $velocities = $archetype->getComponentArray(VelocityComponent::class);
    for ($i = 0; $i < $archetype->count(); $i++) {
        // Direct array access, no indirection
    }
}
```

### 2.3 Component Registry
```php
final class ComponentRegistry {
    private array $components = [];
    private array $archetypes = [];
    
    public function register(ComponentDefinition $def): void { ... }
    public function getArchetype(array $componentTypes): Archetype { ... }
    public function createEntity(array $components): Entity { ... }
    public function addComponent(Entity $entity, Component $component): void { ... }
    public function removeComponent(Entity $entity, string $componentType): void { ... }
}
```

### 2.4 World & EntityBuilder
```php
final class World {
    private ComponentRegistry $registry;
    private SystemScheduler $systems;
    private ResourceRegistry $resources;
    
    public function query(): QueryBuilder { ... }
    public function spawn(EntityBuilder $builder): Entity { ... }
    public function despawn(Entity $entity): void { ... }
    public function getResource(string $type): mixed { ... }
    public function setResource(Resource $resource): void { ... }
}

final class EntityBuilder {
    private array $components = [];
    
    public function with(Component $component): self { ... }
    public function withTag(string $tag): self { ... }
    public function at(float $x, float $y, float $z): self { ... }
    public function build(World $world): Entity { ... }
}

// Usage
$world->spawn(
    (new EntityBuilder())
        ->with(new PositionComponent(100, 64, 100))
        ->with(new VelocityComponent(0, 0, 0))
        ->with(new HealthComponent(20, 20))
        ->withTag('zombie')
        ->with(new AITargetComponent())
);
```

### 2.5 Migration Strategy: Strangler Fig Pattern
- **Phase 1:** ECS runs alongside legacy; new entities use ECS, old entities use Entity.php
- **Phase 2:** Legacy Entity components migrated one-by-one (Position, Health, Velocity, Effects, Metadata)
- **Phase 3:** Legacy Entity.php becomes thin wrapper over ECS entity
- **Phase 4:** Full cutover; Entity.php removed

---

## 3. CLEAN ARCHITECTURE PATTERN SELECTION

### 3.1 Evaluation of Alternatives

| Pattern | Fit for ECS + Minecraft Server | PHP 8.2 Suitability | Threading Integration |
|---------|--------------------------------|---------------------|----------------------|
| **Layered (Traditional)** | ❌ Tight coupling between layers; ECS cuts across layers | ✅ Familiar | ⚠️ Hard to isolate threaded subsystems |
| **DDD (Domain-Driven Design)** | ✅ Rich domain models fit entities/blocks; aggregates = chunks | ✅ PHP 8.2 attributes, readonly, enums | ⚠️ Aggregates conflict with ECS data-oriented design |
| **Hexagonal / Ports & Adapters** | ✅ Protocol = driven adapter; plugins = driving adapters; ECS = core domain | ✅ Interfaces + attributes work well | ✅ Thread boundaries = port boundaries |
| **Hybrid: Hexagonal Core + ECS Domain** | ✅ **WINNER** — Protocol frozen boundary = driven port; ECS = domain kernel; plugins = driving ports | ✅ Best of both | ✅ Thread pools per port adapter |

### 3.2 Chosen Architecture: **Hexagonal Core with ECS Domain Kernel**

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           DRIVING ADAPTERS (Plugins)                        │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │  Command    │  │   Event     │  │   Task      │  │   Query     │        │
│  │  Adapter    │  │  Adapter    │  │  Adapter    │  │  Adapter    │        │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘        │
└─────────┼────────────────┼────────────────┼────────────────┼───────────────┘
          │                │                │                │
          ▼                ▼                ▼                ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        APPLICATION SERVICES (Use Cases)                     │
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐          │
│  │ PlayerJoinService│  │ ChunkLoadService │  │ EntitySpawnService│         │
│  │ BlockBreakService│  │ CombatService    │  │ InventoryService │          │
│  └────────┬─────────┘  └────────┬─────────┘  └────────┬─────────┘          │
└───────────┼─────────────────────┼─────────────────────┼────────────────────┘
            │                     │                     │
            ▼                     ▼                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         DOMAIN KERNEL (ECS)                                 │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │   World     │  │  Systems    │  │ Components  │  │ Resources   │        │
│  │ (ECS Core)  │  │ (Logic)     │  │ (Data)      │  │ (Singletons)│        │
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘        │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    SYSTEM SCHEDULER (Dependency Graph)              │   │
│  │  MovementSys → PhysicsSys → AISys → CombatSys → NetworkSyncSys     │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
└───────────┬─────────────────────┬─────────────────────┬────────────────────┘
            │                     │                     │
            ▼                     ▼                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        DRIVEN ADAPTERS (Infrastructure)                     │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │  Network    │  │  Storage    │  │  World Gen  │  │  Threading  │        │
│  │  Port       │  │  Port       │  │  Port       │  │  Port       │        │
│  │  (FROZEN)   │  │  (LevelDB)  │  │  (Parallel) │  │  (pmmpthread)│       │
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘        │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 3.3 Port Definitions (Interfaces)

```php
// Driven Ports (implemented by infrastructure)
interface NetworkPort {
    public function sendPacket(PlayerRef $player, DataPacket $packet): void;
    public function broadcastPacket(Iterable<PlayerRef> $players, DataPacket $packet): void;
    public function disconnect(PlayerRef $player, string $reason): void;
}

interface StoragePort {
    public function loadChunk(int $chunkX, int $chunkZ): ChunkData;
    public function saveChunk(int $chunkX, int $chunkZ, ChunkData $data): void;
    public function loadEntity(EntityId $id): EntitySnapshot;
    public function saveEntity(EntitySnapshot $snapshot): void;
}

interface WorldGenPort {
    public function generateChunk(int $chunkX, int $chunkZ, GeneratorConfig $config): ChunkData;
    public function populateChunk(int $chunkX, int $chunkZ, ChunkData $data): void;
}

interface ThreadingPort {
    public function submit(Closure $task): Future;
    public function submitToWorker(int $workerId, Closure $task): Future;
    public function parallelFor(iterable $items, Closure $body): void;
    public function awaitAll(iterable<Future> $futures): void;
}

// Driving Ports (implemented by plugins/application)
interface CommandPort {
    public function register(Command $command): void;
}

interface EventPort {
    public function subscribe(string $event, callable $handler, int $priority): void;
    public function emit(Event $event): void;
}
```

### 3.4 Module/Folder Structure (Post-Migration)

```
src/pocketmine/
├── bootstrap.php                 # Composition root, DI container setup
├── Kernel.php                    # Application kernel, lifecycle
├── domain/                       # DOMAIN KERNEL (ECS) - NO external deps
│   ├── ecs/
│   │   ├── Component.php         # Attribute marker
│   │   ├── Resource.php          # Attribute marker
│   │   ├── System.php            # Interface
│   │   ├── World.php             # Core ECS world
│   │   ├── Entity.php            # Entity reference (opaque handle)
│   │   ├── Query.php             # Query builder + iterator
│   │   ├── Archetype.php         # Archetype storage
│   │   ├── ComponentRegistry.php # Component type registry
│   │   ├── ResourceRegistry.php  # Resource management
│   │   └── SystemScheduler.php   # Dependency-ordered system execution
│   ├── component/                # Built-in components
│   │   ├── PositionComponent.php
│   │   ├── VelocityComponent.php
│   │   ├── HealthComponent.php
│   │   ├── MetadataComponent.php
│   │   ├── tags/                 # Tag components
│   │   │   ├── PlayerTag.php
│   │   │   ├── MonsterTag.php
│   │   │   ├── OnGroundTag.php
│   │   │   └── ...
│   │   └── ...
│   ├── system/                   # Built-in systems
│   │   ├── MovementSystem.php
│   │   ├── PhysicsSystem.php
│   │   ├── AISystem.php
│   │   ├── CombatSystem.php
│   │   ├── EffectSystem.php
│   │   ├── TileEntitySystem.php
│   │   ├── ChunkSystem.php
│   │   └── NetworkSyncSystem.php # Protocol adapter boundary
│   ├── resource/                 # Built-in resources
│   │   ├── TickCounter.php
│   │   ├── ServerConfig.php
│   │   ├── SpatialIndex.php      # Broad-phase collision
│   │   ├── ChunkManager.php      # Chunk loading state
│   │   └── PlayerManager.php     # Player entity refs
│   └── service/                  # Application services (use cases)
│       ├── PlayerJoinService.php
│       ├── ChunkLoadService.php
│       ├── EntitySpawnService.php
│       ├── BlockBreakService.php
│       ├── CombatService.php
│       └── InventoryService.php
├── port/                         # PORT INTERFACES (no implementations)
│   ├── driven/
│   │   ├── NetworkPort.php
│   │   ├── StoragePort.php
│   │   ├── WorldGenPort.php
│   │   └── ThreadingPort.php
│   └── driving/
│       ├── CommandPort.php
│       ├── EventPort.php
│       └── PluginPort.php
├── adapter/                      # ADAPTER IMPLEMENTATIONS
│   ├── driven/
│   │   ├── network/
│   │   │   ├── Protocol84NetworkAdapter.php  # FROZEN - wraps existing Network.php
│   │   │   ├── PacketSerializer.php          # Protocol 84 serialization
│   │   │   └── PlayerNetworkRef.php
│   │   ├── storage/
│   │   │   ├── AnvilStorageAdapter.php
│   │   │   ├── LevelDBStorageAdapter.php
│   │   │   └── ChunkData.php
│   │   ├── worldgen/
│   │   │   ├── ParallelGeneratorAdapter.php
│   │   │   └── GeneratorAdapter.php
│   │   └── threading/
│   │       ├── PmmpThreadPool.php
│   │       ├── AsyncTaskAdapter.php
│   │       └── ParallelExecutor.php
│   └── driving/
│       ├── plugin/
│       │   ├── PluginManagerAdapter.php
│       │   ├── PluginEventAdapter.php
│       │   └── PluginCommandAdapter.php
│       └── console/
│           └── ConsoleCommandAdapter.php
├── plugin/                       # PLUGIN API (facade over ports)
│   ├── Plugin.php
│   ├── PluginBase.php
│   ├── PluginManager.php         # Thin wrapper over PluginManagerAdapter
│   ├── event/
│   │   ├── Event.php
│   │   └── Listener.php
│   └── command/
│       └── Command.php
├── protocol/                     # PROTOCOL 84 (FROZEN - DO NOT TOUCH)
│   ├── DataPacket.php
│   ├── Info.php
│   └── ... (all existing packet classes)
└── legacy/                       # STRANGLER FIG - legacy code being migrated
    ├── Entity.php                # Legacy entity, delegates to ECS
    ├── Level.php                 # Legacy level, delegates to ECS
    └── Server.php                # Legacy server, delegates to Kernel
```

---

## 4. MULTITHREADING STRATEGY

### 4.1 Threading Model: **Region-Based Simulation + Pipeline Parallelism (Target Architecture)**

The end goal is a **region-partitioned simulation** where each spatial region runs on its own thread, with a lightweight coordination thread and dedicated network thread. This scales linearly with CPU cores for large worlds.

```
┌─────────────────────────────────────────────────────────────────────────────────────┐
│ NETWORK THREAD (RakLib)                                                              │
│  • UDP I/O, packet framing, encryption                                               │
│  • Decode → PlayerCommand/Event DTOs → Push to Command Queue (ThreadSafe)           │
└─────────────────────────────────┬───────────────────────────────────────────────────┘
                                  │ ThreadSafe Queue
                                  ▼
┌─────────────────────────────────────────────────────────────────────────────────────┐
│ COORDINATION THREAD (Main)                                                           │
│  • Global state: player list, level list, plugin manager, global resources          │
│  • Cross-region entity migration                                                     │
│  • Plugin event dispatch (driving adapters)                                          │
│  • Chunk load/unload coordination                                                    │
│  • Tick rate management, autosave trigger                                            │
└─────────────────────────────────┬───────────────────────────────────────────────────┘
                                  │
          ┌───────────────────────┼───────────────────────┐
          ▼                       ▼                       ▼
┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐
│  REGION THREAD 1 │    │  REGION THREAD 2 │    │  REGION THREAD N │
│  (Worker)        │    │  (Worker)        │    │  (Worker)        │
├──────────────────┤    ├──────────────────┤    ├──────────────────┤
│ Owns:            │    │ Owns:            │    │ Owns:            │
│ • Chunks [0-63,  │    │ • Chunks [64-127,│    │ • Chunks [N...]  │
│   0-63]          │    │   0-63]          │    │                  │
│ • Entities in    │    │ • Entities in    │    │ • Entities in    │
│   region         │    │   region         │    │   region         │
│ • TileEntities   │    │ • TileEntities   │    │ • TileEntities   │
│ • Local ECS      │    │ • Local ECS      │    │ • Local ECS      │
│   World          │    │   World          │    │   World          │
│ Runs per-tick:   │    │ Runs per-tick:   │    │ Runs per-tick:   │
│ 1. Player cmds   │    │ 1. Player cmds   │    │ 1. Player cmds   │
│ 2. MovementSys   │    │ 2. MovementSys   │    │ 2. MovementSys   │
│ 3. PhysicsSys    │    │ 3. PhysicsSys    │    │ 3. PhysicsSys    │
│ 4. AISys         │    │ 4. AISys         │    │ 4. AISys         │
│ 5. CombatSys     │    │ 5. CombatSys     │    │ 5. CombatSys     │
│ 6. TileEntitySys │    │ 6. TileEntitySys │    │ 6. TileEntitySys │
│ 7. BlockUpdates  │    │ 7. BlockUpdates  │    │ 7. BlockUpdates  │
│ 8. NetworkSync   │    │ 8. NetworkSync   │    │ 8. NetworkSync   │
└────────┬─────────┘    └────────┬─────────┘    └────────┬─────────┘
         │                       │                       │
         └───────────────────────┼───────────────────────┘
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────────────┐
│ NETWORK THREAD (RakLib)                                                              │
│  • Collect NetworkSyncComponent from all regions                                     │
│  • Encode packets (Protocol 84)                                                      │
│  • Batch & send UDP                                                                  │
└─────────────────────────────────────────────────────────────────────────────────────┘
```

#### Region Partitioning

```php
// Configuration
const REGION_SIZE_CHUNKS = 16; // 16x16 chunks = 256x256 blocks
const REGION_THREADS = max(1, Utils::getCoreCount() - 2); // Leave 2 for network + coord

// Region ownership
final class Region {
    public int $minChunkX, $maxChunkX;
    public int $minChunkZ, $maxChunkZ;
    public Thread $thread;
    public RegionWorld $world; // ECS World slice
    public SpatialIndex $spatialIndex;
    public ThreadSafe $commandQueue; // Input from coordination thread
    public ThreadSafe $syncQueue;    // Output to network thread
}

// Entity migration (coordination thread)
function migrateEntity(EntityRef $entity, Region $from, Region $to): void {
    // 1. Remove from $from.world, capture component snapshot
    $snapshot = $from.world->extractEntity($entity);
    
    // 2. Send to $to via thread-safe queue
    $to.commandQueue[] = new MigrateEntityCommand($entity, $snapshot);
    
    // 3. $to thread applies on next tick start
}
```

### 4.2 Phased Approach: Incremental Adoption

The region-based model is the **target (Phase 6+)**. Phases 1-5 build the foundation incrementally:

| Phase | Threading Model | Description |
|-------|-----------------|-------------|
| **0-3** | Single-threaded ECS | ECS core, systems, adapters on main thread |
| **4** | Legacy + ECS hybrid | Strangler Fig; main thread runs both |
| **5** | **Work-stealing pool + archetype parallelism** | Parallel systems per archetype (Section 4.5) |
| **6** | **Region-based (full)** | Spatial partitioning, region threads, pipeline |

### 4.3 Phase 5: Work-Stealing Pool + Archetype Parallelism (Immediate Target)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            MAIN THREAD (Game Loop)                          │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  1. Network I/O (RakLib)                                            │   │
│  │  2. Packet decode → Command/Event emission                          │   │
│  │  3. Application Services (PlayerJoin, BlockBreak, etc.)             │   │
│  │  4. ECS System Scheduler (sequential + parallel systems)            │   │
│  │     ├── Sequential: PhysicsSys, CombatSys (cross-entity writes)     │   │
│  │     └── Parallel (archetype-isolated):                              │   │
│  │         ├── MovementSys  → worker threads                           │   │
│  │         ├── AISys        → worker threads                           │   │
│  │         ├── EffectSys    → worker threads                           │   │
│  │         ├── TileEntitySys→ worker threads                           │   │
│  │         └── ChunkSys     → worker threads (per-chunk)               │   │
│  │  5. NetworkSyncSystem flush → NetworkPort.sendPacket()              │   │
│  │  6. Chunk unload/autosave (deferred to storage port)                │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    ▼               ▼               ▼
           ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
           │  WORKER 1    │ │  WORKER 2    │ │  WORKER N    │
           │ (Thread)     │ │ (Thread)     │ │ (Thread)     │
           ├──────────────┤ ├──────────────┤ ├──────────────┤
           │ Archetype    │ │ Archetype    │ │ Archetype    │
           │ batches      │ │ batches      │ │ batches      │
           │ (Movement,   │ │ (Movement,   │ │ (Movement,   │
           │  AI, Effect, │ │  AI, Effect, │ │  AI, Effect, │
           │  Tile, Chunk)│ │  Tile, Chunk)│ │  Tile, Chunk)│
           └──────────────┘ └──────────────┘ └──────────────┘
```

#### Parallelizable Subsystems (Safe for ext-pmmpthread)

| Subsystem | Parallelization Strategy | Data Sharing | Sync Mechanism |
|-----------|-------------------------|--------------|----------------|
| **Chunk Generation** | Per-chunk tasks, work-stealing | Read-only: generator config, biome data. Write: chunk data (isolated per chunk) | Future<ChunkData> → main thread applies |
| **Chunk Population** | Per-chunk tasks after generation | Read-only: generated chunk. Write: populated chunk (isolated) | Future<ChunkData> → main thread applies |
| **Light Calculation** | Per-chunk or per-section tasks | Read-only: block data. Write: light data (isolated per chunk) | Future<LightData> → main thread applies |
| **Block Updates (Random/Scheduled)** | Batched per-chunk, parallel for independent chunks | Read: block state. Write: block state, schedule updates | Chunk-level locks; main thread collects results |
| **Pathfinding** | Per-entity requests, async | Read-only: chunk data, block collision. Write: path result | Future<Path> → entity AI system consumes |
| **Entity AI (Heavy)** | Per-archetype batch processing | Read: Position, Target. Write: Velocity, AIState | Double-buffered components; swap at sync point |
| **Chunk Compression/Serialization** | Per-chunk tasks | Read: chunk data. Write: compressed bytes | Future<Bytes> → network thread sends |
| **Movement/Physics (Phase 5+)** | Per-archetype batches | Read: Position, Velocity. Write: Position | Double-buffered PositionComponent; swap at sync |
| **Tile Entities (Phase 5+)** | Per-chunk batches | Read/Write: tile entity state (chunk-local) | Chunk-local; no cross-chunk writes |

### 4.4 Thread-Safety & Data Sharing Strategy

#### 4.4.1 Ownership Model
- **Main thread owns:** Global ECS World (Phase 5), Region Worlds (Phase 6), all Components, Resources, SystemScheduler
- **Worker threads own:** Nothing persistent; receive immutable input, produce immutable output
- **No shared mutable state** between threads — message passing via `Future` / `ThreadSafe` queues

#### 4.4.2 Component Access Patterns for Threading

```php
// Double-buffered components for async writes (Phase 5)
#[Component]
final class VelocityComponent {
    // Main thread reads this
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;
    
    // Worker writes to this (swapped at sync point)
    public ?VelocityComponent $pending = null;
    
    public function applyPending(): void {
        if ($this->pending !== null) {
            $this->x = $this->pending->x;
            $this->y = $this->pending->y;
            $this->z = $this->pending->z;
            $this->pending = null;
        }
    }
}

// Immutable data transfer objects for cross-thread
final class ChunkGenerationInput {
    public function __construct(
        public readonly int $chunkX,
        public readonly int $chunkZ,
        public readonly GeneratorConfig $config,
        public readonly BiomeMap $biomeMap,  // Shared immutable
    ) {}
}

final class ChunkGenerationOutput {
    public function __construct(
        public readonly int $chunkX,
        public readonly int $chunkZ,
        public readonly ChunkData $data,
        public readonly LightData $lightData,
    ) {}
}
```

#### 4.4.3 ThreadingPort Implementation

```php
final class PmmpThreadPool implements ThreadingPort {
    private array $workers = [];
    private ThreadSafe $taskQueue;
    private ThreadSafe $resultQueue;
    private int $workerCount;
    
    public function __construct(int $workerCount = null) {
        $this->workerCount = $workerCount ?? (Utils::getCoreCount() - 1);
        $this->taskQueue = new ThreadSafe();
        $this->resultQueue = new ThreadSafe();
        
        for ($i = 0; $i < $this->workerCount; $i++) {
            $worker = new WorkerThread($this->taskQueue, $this->resultQueue);
            $worker->start(Worker::INHERIT_ALL);
            $this->workers[] = $worker;
        }
    }
    
    public function submit(Closure $task): Future {
        $future = new Future();
        $this->taskQueue[] = new ThreadedTask($task, $future);
        return $future;
    }
    
    public function parallelFor(iterable $items, Closure $body): void {
        $futures = [];
        foreach ($items as $item) {
            $futures[] = $this->submit(fn() => $body($item));
        }
        $this->awaitAll($futures);
    }
    
    public function awaitAll(iterable $futures): void {
        foreach ($futures as $future) {
            $future->await();
        }
    }
    
    public function shutdown(): void {
        foreach ($this->workers as $worker) {
            $worker->quit();
        }
    }
}

final class WorkerThread extends Thread {
    public function __construct(
        private ThreadSafe $taskQueue,
        private ThreadSafe $resultQueue
    ) {}
    
    public function run(): void {
        $this->registerClassLoader();
        while (true) {
            $task = $this->taskQueue->shift(); // Blocking pop
            if ($task === null) continue; // Shutdown signal
            if ($task instanceof ShutdownTask) break;
            
            try {
                $result = $task->execute();
                $task->complete($result);
            } catch (Throwable $e) {
                $task->fail($e);
            }
        }
    }
}
```

### 4.5 Integration with ECS Query Model (Phase 5)

#### 4.5.1 System Parallelism Annotations
```php
#[System(phase: SystemPhase::PARALLEL, archetypeFilter: [PositionComponent::class, VelocityComponent::class])]
final class MovementSystem implements ParallelSystem {
    public function runParallel(Archetype $archetype, float $deltaTime): void {
        $positions = $archetype->getComponentArray(PositionComponent::class);
        $velocities = $archetype->getComponentArray(VelocityComponent::class);
        
        for ($i = 0; $i < $archetype->count(); $i++) {
            $positions[$i]->x += $velocities[$i]->x * $deltaTime;
            $positions[$i]->y += $velocities[$i]->y * $deltaTime;
            $positions[$i]->z += $velocities[$i]->z * $deltaTime;
        }
    }
}

// SystemScheduler executes PARALLEL systems via ThreadingPort
final class SystemScheduler {
    public function run(World $world, float $deltaTime): void {
        // Sequential systems (dependencies, writes shared state)
        foreach ($this->sequentialSystems as $system) {
            $system->run($world, $deltaTime);
        }
        
        // Parallel systems (archetype-isolated, no cross-archetype writes)
        $parallelFutures = [];
        foreach ($this->parallelSystems as $system) {
            foreach ($system->getTargetArchetypes($world) as $archetype) {
                $parallelFutures[] = $this->threading->submit(
                    fn() => $system->runParallel($archetype, $deltaTime)
                );
            }
        }
        $this->threading->awaitAll($parallelFutures);
        
        // Apply pending component changes (double-buffer swap)
        $world->applyPendingComponents();
    }
}
```

#### 4.5.2 Chunk-System Parallelism
```php
#[System(phase: SystemPhase::CHUNK_PARALLEL)]
final class ChunkSystem implements ChunkParallelSystem {
    public function runChunkParallel(int $chunkX, int $chunkZ, ChunkData $chunk, float $deltaTime): void {
        // Random ticks, block updates, tile entity updates for this chunk
        // No cross-chunk writes allowed
    }
}

// ChunkManager submits per-chunk work to thread pool
final class ChunkManager {
    public function tickChunks(World $world, float $deltaTime): void {
        $chunks = $this->getTickingChunks($world);
        
        $futures = [];
        foreach ($chunks as $chunk) {
            $futures[] = $this->threading->submit(
                fn() => $this->processChunk($chunk, $deltaTime)
            );
        }
        $this->threading->awaitAll($futures);
        
        // Merge results back to main thread chunk storage
        foreach ($futures as $future) {
            $result = $future->getResult();
            $this->applyChunkResult($result);
        }
    }
}
```

### 4.6 Phase 6: Region-Based Architecture (Full Target)

#### 4.6.1 RegionThread Implementation
```php
// RegionThread extends Thread
final class RegionThread extends Thread {
    public function __construct(
        private Region $region,
        private ThreadSafe $globalCommandQueue, // From coordination
        private ThreadSafe $globalSyncQueue,    // To network
        private ThreadingPort $threading,       // For sub-tasks (pathfinding, etc.)
    ) {}
    
    public function run(): void {
        $this->registerClassLoader();
        
        while (!$this->isKilled) {
            // 1. Drain commands from coordination thread
            while ($cmd = $this->globalCommandQueue->shift()) {
                $this->handleCommand($cmd);
            }
            
            // 2. Run local tick (all systems)
            $this->region->world->tick(0.05); // 20 TPS fixed timestep
            
            // 3. Push network sync to network thread
            $syncData = $this->region->world->collectNetworkSync();
            if (!empty($syncData)) {
                $this->globalSyncQueue[] = new RegionSyncPacket($this->region->id, $syncData);
            }
            
            // 4. Sleep to maintain 20 TPS
            $this->synchronized(function() { $this->wait(50_000_000); }); // 50ms = 20 TPS
        }
    }
}
```

#### 4.6.2 Thread-Safety Guarantees

| Data | Owner | Access Pattern |
|------|-------|----------------|
| Region chunks/entities/tiles | Region thread | Exclusive read/write |
| Global player registry | Coordination thread | Read-only for regions (snapshot per tick) |
| Plugin event bus | Coordination thread | Regions → push events; Coordination → dispatch |
| Network packets | Network thread | Regions → push NetworkSyncComponent; Network → encode/send |
| Chunk storage | Storage port (async) | Regions → request load/save via ThreadingPort |

#### 4.6.3 Migration Path: Phase 5 → Phase 6

1. **Phase 5 complete:** Parallel systems work on global World via archetype batches
2. **Introduce RegionWorld:** Subclass of World with spatial bounds
3. **Partition World:** Split global World into RegionWorlds by chunk coordinates
4. **Spawn RegionThreads:** Each RegionWorld runs on dedicated thread
5. **Add Coordination Thread:** Handles migration, global events, plugin dispatch
6. **Extract Network Thread:** Move RakLib I/O + packet encoding to dedicated thread

---

## 5. MIGRATION STEPS (ORDERED)

### Phase 0: Foundation (Week 1-2)
| Step | Branch | Description |
|------|--------|-------------|
| 0.1 | `foundation-bootstrap` | Create `bootstrap.php`, DI container (PHP-DI or manual), Kernel.php |
| 0.2 | `foundation-port-interfaces` | Define all Port interfaces in `src/pocketmine/port/` |
| 0.3 | `foundation-ecs-core` | Implement ECS core: Component, Resource, System, World, Query, Archetype, ComponentRegistry, SystemScheduler |
| 0.4 | `foundation-threading-port` | Implement ThreadingPort + PmmpThreadPool adapter |

### Phase 1: ECS Component Migration (Week 2-4)
| Step | Branch | Description |
|------|--------|-------------|
| 1.1 | `ecs-components-basic` | PositionComponent, VelocityComponent, HealthComponent, MetadataComponent, Tag components |
| 1.2 | `ecs-components-entity` | EntityRef, EntityBuilder, World::spawn/despawn, Entity ID allocation |
| 1.3 | `ecs-systems-movement` | MovementSystem, PhysicsSystem (gravity, collision broad-phase) |
| 1.4 | `ecs-systems-combat` | CombatSystem, EffectSystem, AttributeSystem |
| 1.5 | `ecs-systems-ai` | AISystem, PathfindingSystem (async via ThreadingPort) |

### Phase 2: Adapter Implementation (Week 3-5)
| Step | Branch | Description |
|------|--------|-------------|
| 2.1 | `adapter-network-protocol84` | Protocol84NetworkAdapter (wraps existing Network.php, FROZEN boundary) |
| 2.2 | `adapter-storage-anvil` | AnvilStorageAdapter, LevelDBStorageAdapter |
| 2.3 | `adapter-worldgen-parallel` | ParallelGeneratorAdapter (uses ThreadingPort for gen/pop/light) |
| 2.4 | `adapter-plugin` | PluginManagerAdapter, PluginEventAdapter, PluginCommandAdapter |

### Phase 3: Application Services (Week 4-6)
| Step | Branch | Description |
|------|--------|-------------|
| 3.1 | `service-player-lifecycle` | PlayerJoinService, PlayerLeaveService, PlayerRespawnService |
| 3.2 | `service-chunk-management` | ChunkLoadService, ChunkUnloadService, ChunkSendService |
| 3.3 | `service-block-interaction` | BlockBreakService, BlockPlaceService, BlockUpdateService |
| 3.4 | `service-entity-management` | EntitySpawnService, EntityDespawnService, EntityInteractionService |
| 3.5 | `service-inventory` | InventoryService, CraftingService, ContainerService |

### Phase 4: Legacy Strangler Fig (Week 5-8)
| Step | Branch | Description |
|------|--------|-------------|
| 4.1 | `legacy-entity-wrapper` | Legacy Entity.php delegates to ECS EntityRef |
| 4.2 | `legacy-level-wrapper` | Legacy Level.php delegates to ECS World + ChunkManager |
| 4.3 | `legacy-server-wrapper` | Legacy Server.php delegates to Kernel |
| 4.4 | `legacy-plugin-compat` | Plugin API compatibility layer over new ports |

### Phase 5: Threading Integration — Archetype Parallelism (Week 6-9)
| Step | Branch | Description |
|------|--------|-------------|
| 5.1 | `multithread-chunk-gen` | Move chunk generation/population/light to worker threads |
| 5.2 | `multithread-block-updates` | Parallel block random ticks + scheduled updates per chunk |
| 5.3 | `multithread-pathfinding` | Async pathfinding requests via ThreadingPort |
| 5.4 | `multithread-entity-ai` | Parallel AI system execution per archetype |
| 5.5 | `multithread-chunk-compression` | Async chunk serialization for network send |
| 5.6 | `multithread-system-scheduler` | Parallel system execution for independent archetypes (Movement, Effect, TileEntity) |

### Phase 6: Region-Based Architecture (Week 9-12)
| Step | Branch | Description |
|------|--------|-------------|
| 6.1 | `region-world-partition` | Partition global World into RegionWorlds by spatial bounds (16x16 chunks) |
| 6.2 | `region-thread-spawn` | Spawn RegionThread per region; each runs independent ECS tick loop |
| 6.3 | `region-coordination-thread` | Coordination thread: entity migration, global events, plugin dispatch, chunk coordination |
| 6.4 | `region-network-thread` | Extract RakLib I/O + packet encoding to dedicated NetworkThread |
| 6.5 | `region-cross-boundary` | Cross-region entity migration, chunk border physics, global chunk loading |
| 6.6 | `region-load-balancing` | Dynamic region splitting/merging based on entity density |

### Phase 7: Polish & Optimization (Week 11-14)
| Step | Branch | Description |
|------|--------|-------------|
| 7.1 | `optimize-archetype-storage` | Compact component arrays, reduce indirection |
| 7.2 | `optimize-query-performance` | Query caching, archetype indexing |
| 7.3 | `optimize-memory-layout` | Struct-of-arrays, reduce object allocation |
| 7.4 | `optimize-network-batching` | Batch NetworkSyncComponent flushes across regions |
| 7.5 | `benchmark-profile` | Full profiling, tick rate analysis, scalability testing |

---

## 6. RISK AREAS & PROTOCOL BOUNDARY PROTECTION

### 6.1 Explicit Protocol Boundary Risks

| Risk Area | Why It's Risky | Mitigation |
|-----------|----------------|------------|
| **Entity → Network packet creation** | Entity.php directly creates AddEntityPacket, MoveEntityPacket, etc. | NetworkSyncSystem is ONLY place that creates protocol packets; Entity components are data-only |
| **Level → FullChunkDataPacket** | Level.php builds FullChunkDataPacket directly | ChunkSendService uses StoragePort to get chunk data, NetworkPort to send; no protocol in domain |
| **Player → Direct packet sends** | Player.php has dataPacket(), sendPacket() methods | PlayerNetworkRef adapter implements NetworkPort; Player entity has only NetworkSyncComponent |
| **Block → UpdateBlockPacket** | Block::onUpdate() can send packets | BlockUpdateService emits domain events; NetworkSyncSystem handles packet creation |
| **TileEntity → BlockEntityDataPacket** | Tile entities send packets directly | TileEntitySystem collects dirty tiles; NetworkSyncSystem serializes |

### 6.2 Protocol Boundary Enforcement Rules
1. **NO `use pocketmine\network\protocol\*` in `domain/`** — enforced by PHPStan/phpcs ruleset
2. **NO `DataPacket` instantiation in systems** — only in NetworkSyncSystem and Protocol84NetworkAdapter
3. **All network serialization lives in `adapter/driven/network/`** — single location
4. **Protocol version constants only in `protocol/Info.php`** — frozen, never imported by domain
5. **Integration test:** `grep -r "network\\protocol" src/pocketmine/domain/` must return empty

### 6.3 Other Risk Areas

| Risk | Impact | Mitigation |
|------|--------|------------|
| **ECS migration breaks plugins** | High — plugins expect Entity/Level APIs | Strangler Fig: legacy wrappers maintain 100% API compatibility during transition |
| **Threading introduces race conditions** | Critical — world corruption | Ownership model + immutable DTOs + double-buffering; no shared mutable state |
| **Performance regression from abstraction** | Medium — overhead of ECS queries | Archetype iteration = raw array access; benchmark each phase; PHP 8.2 JIT helps |
| **pmmpthread stability** | Medium — extension bugs | Isolate threading behind ThreadingPort; can swap to sync impl for debugging |
| **Memory usage increase** | Medium — component arrays | Struct-of-arrays, object pooling, weak refs for entity handles |
| **Region partitioning imbalance** | Medium — some regions overloaded | Dynamic region splitting/merging (Phase 6.6); workload-aware partitioning |
| **Cross-region interaction latency** | Low-Medium — migration overhead | Batch migrations per tick; predictive migration for moving entities |
| **Plugin event ordering** | Medium — events from multiple regions | Coordination thread sequences events; per-region event buffers |

---

## 7. SUCCESS CRITERIA

| Metric | Current (Est.) | Phase 5 Target | Phase 6 Target | Measurement |
|--------|----------------|----------------|----------------|-------------|
| **Tick Time (20 players, flat world)** | ~35-50ms | <15ms | <5ms | TimingsHandler, microtime in tick loop |
| **Tick Time (100 players, large world)** | ~100-200ms | ~50ms | <15ms | TimingsHandler |
| **Chunk Generation (16 chunks)** | ~200ms sequential | <50ms parallel (4 workers) | <30ms (region-local) | GeneratorAdapter benchmark |
| **Entity Ticking (500 entities)** | ~15ms | <5ms (parallel archetypes) | <2ms (region-partitioned) | MovementSystem + PhysicsSystem benchmark |
| **Entity Ticking (5000 entities)** | ~150ms | ~40ms | <15ms | Stress test |
| **Memory (100 players, 10 worlds)** | ~800MB | <500MB | <400MB | memory_get_usage() |
| **Plugin API Compatibility** | 100% legacy | 100% legacy + new ECS API | 100% legacy + new ECS API | Plugin test suite |
| **Protocol Compliance** | 100% (protocol 84) | 100% (protocol 84) | 100% (protocol 84) | Protocol test suite, no protocol changes |
| **Scalability (cores)** | 1 core | 4-8 cores (archetype parallelism) | 16+ cores (region partitioning) | Speedup vs core count |

---

## 8. DOCUMENTATION STRUCTURE

```
docs/
├── PLAN.md              # This file - full plan
├── PROGRESS.md          # Tracker: phase, branch, commit, PR, status
├── DECISIONS.md         # Architectural decisions log with rationale
├── ARCHITECTURE.md      # High-level architecture diagram + description
├── ECS_API.md           # ECS API reference for plugin developers
├── THREADING_MODEL.md   # Threading design, safety guarantees, usage (Phases 5-6)
├── PORT_CONTRACTS.md    # Port interface specifications
└── MIGRATION_GUIDE.md   # Step-by-step for plugin developers
```

---

## 9. NEXT STEPS

**Awaiting your explicit approval** on this plan before proceeding. Once approved:

1. Create branch `foundation-bootstrap` with rollback anchor commit
2. Implement `bootstrap.php`, `Kernel.php`, DI container setup
3. Commit incrementally, update `docs/PROGRESS.md`
4. When ready, rebase on `master` and open PR for review

**Environment Setup:**
- **Base branch:** `master` (not `main`)
- **PHP binary:** `bin/php7/bin/php` (project-specific, committed in repo; see README.md)
- **Run baseline:** `bin/php7/bin/php measure_baseline.php`
- **Run PHPStan:** `bin/php7/bin/php vendor/bin/phpstan analyse --configuration=phpstan.neon`

**Clarifying Questions Before Proceeding:**

1. **DI Container:** Manual factory-based composition root, no external DI library
2. **PHPStan/Psalm:** No baseline exists — start PHPStan level 5+ fresh for new/refactored code only
3. **Testing:** No test suite yet
4. **pmmpthread Version:** v6.3.0
5. **Protocol 84 Test Vectors:** No conformance tests needed — protocol must simply remain untouched during refactor. Treat as frozen boundary; don't add test infrastructure for it.
6. **Benchmark Baseline:** No baseline yet — include establishing a tick-profiling baseline (via Blackfire, Xdebug profiler, or manual timing) as an early step before any threading work.

Please confirm the plan or request revisions.