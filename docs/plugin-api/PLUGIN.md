# Khronos Plugin Development Guide

**API version: 2.0.0** · Protocol 84 (MCPE 0.15.10) · PHP 8.2+

This guide covers the complete Khronos plugin API: the `plugin.yml` descriptor, the `Plugin` base class, commands, permissions, events, scheduling, the world/entity facades, and direct access to the ECS underneath.

> ⚠️ **This is a brand-new API.** Khronos is a from-scratch rewrite — existing PocketMine plugins will **not** run here. Everything below is the Khronos way.

---

## 1. Quick start

A plugin is a **directory** (or `.phar` archive) containing a `plugin.yml` descriptor and PHP source under `src/`. Drop it into the server's `plugins/` folder and it loads at the next server start.

Minimal plugin:

```
plugins/MyPlugin/
├── plugin.yml
└── src/
    └── MyPlugin/
        └── Main.php
```

`plugin.yml`:

```yaml
name: MyPlugin
version: 1.0.0
author: You
main: MyPlugin\Main
api: 2.0.0
```

`src/MyPlugin/Main.php`:

```php
<?php

declare(strict_types=1);

namespace MyPlugin;

use pocketmine\api\plugin\Plugin;

class Main extends Plugin {
    public function onEnable(): void {
        $this->getLogger()->info('MyPlugin enabled!');
    }
}
```

That's it. The server's `PluginManager` parses `plugin.yml`, autoloads `MyPlugin\Main` from `src/MyPlugin/Main.php` (the class path mirrors the file path), instantiates it, and calls the lifecycle hooks.

---

## 2. `plugin.yml` reference

| Key | Required | Type | Meaning |
|---|---|---|---|
| `name` | ✅ | string | Plugin name. Must be unique; duplicates are rejected. |
| `main` | ✅ | string | Fully-qualified class name of your plugin class (must extend `Plugin`). |
| `version` | – | string | Display version, e.g. `1.0.0`. Default `1.0.0`. |
| `author` | – | string | Author name. Default `Unknown`. |
| `description` | – | string | Short description. |
| `api` | – | string | Minimum API version required. Plugin loads only if `api <= 2.0.0`. Default `0.0.0`. |
| `depend` | – | list | Plugin names that must be **loaded and enabled** first. Missing deps block loading. |
| `softdepend` | – | list | Plugin names that should be loaded first if present (not required). |
| `commands` | – | map | Commands declared here route to your `onCommand()` hook (see §4). |
| `permissions` | – | map | Permissions registered into the server-wide manager (see §5). |

### Commands section

```yaml
commands:
  hello:
    description: Says hello
    usage: /hello [name]
    aliases: [hi]
    permission: myplugin.hello
```

### Permissions section

```yaml
permissions:
  myplugin.hello:
    description: Allows the hello command
    default: true            # op | notop | true | false
  myplugin.admin:
    description: Admin powers
    default: op
    children:
      myplugin.hello: true   # holding the parent grants the child
```

---

## 3. The `Plugin` base class

Extend `pocketmine\api\plugin\Plugin`. The constructor is `final` — do **not** define your own; use the lifecycle hooks.

### Lifecycle hooks

| Hook | When |
|---|---|
| `onLoad(): void` | Right after the plugin is instantiated, before enable. Register things that must exist before other plugins enable. |
| `onEnable(): void` | Plugin is fully loaded, permissions/commands registered, kernel accessor injected. Do your setup here. |
| `onDisable(): void` | Server shutdown or plugin disabled. Clean up. |
| `onCommand(CommandSender $sender, string $label, array $args): bool` | Dispatch target for commands declared in `plugin.yml`. Return `true` if handled. |

### Convenience accessors

| Method | Returns |
|---|---|
| `getName()` / `getVersion()` / `getAuthor()` | Metadata from `plugin.yml` |
| `getDescription()` | The parsed `PluginDescription` |
| `getLogger()` | `Logger` with your plugin's prefix (`info`, `warning`, `error`, `debug`, …) |
| `getDataFolder()` | `plugins/<Name>/` on disk — create files here |
| `getConfig()` | `Config` over `plugins/<Name>/config.yml` (auto-created with empty defaults) |
| `saveConfig()` / `reloadConfig()` | Persist / re-read the config |
| `getKernel()` | The `KernelAccessor` (see §8) |
| `getWorld()` | The ECS `World` |
| `getScheduler()` | The API `Scheduler` (see §6) |

Example config usage:

```php
public function onEnable(): void {
    $this->getConfig()->set('welcome-message', 'Welcome!');
    $this->saveConfig();
    $message = $this->getConfig()->get('welcome-message', 'Welcome!');
}
```

---

## 4. Commands

There are **two** ways to make a command.

### A. Declared in `plugin.yml` (routes to `onCommand`)

```yaml
commands:
  hello:
    description: Says hello
    aliases: [hi]
```

```php
public function onCommand(CommandSender $sender, string $label, array $args): bool {
    $sender->sendMessage('Hello, ' . ($args[0] ?? 'world') . '!');
    return true;
}
```

### B. Class-based (registered from `onEnable`)

```php
use pocketmine\api\command\Command;
use pocketmine\api\command\CommandSender;

class HelloCommand extends Command {
    public function __construct() {
        parent::__construct('hello', 'Says hello', '/hello [name]', ['hi']);
    }

    public function execute(CommandSender $sender, array $args): bool {
        $sender->sendMessage('Hello, ' . ($args[0] ?? 'world') . '!');
        return true;
    }
}
```

```php
public function onEnable(): void {
    $this->registerCommand(new HelloCommand());
}
```

### CommandSender

Both console and players implement `CommandSender`:

| Method | Meaning |
|---|---|
| `sendMessage(string $message)` | Send a chat message to the sender |
| `hasPermission(string $permission)` | Permission check (console always `true`) |
| `getName()` | `CONSOLE` or the player's name |
| `isPlayer()` | Whether the sender is a player |
| `getPlayer()` | The `Player` facade, or `null` for console |

To restrict a command to operators, set `permission` on it and register the permission with `default: op`:

```yaml
commands:
  kick:
    permission: khronos.command.kick
permissions:
  khronos.command.kick:
    description: Kick a player
    default: op
```

---

## 5. Permissions

Permissions are declared in `plugin.yml` and registered into the server-wide `PermissionManager` when your plugin enables (and removed when it disables).

Defaults: `op` (operators only), `notop` (non-ops only), `true` (everyone), `false` (nobody).

A player's effective permissions are the union of:

1. Explicit grants stored on the player (set by ops, or via `$player->setOp()`),
2. Defaults whose scope matches the player's op status,
3. Children — holding a parent permission grants its `true`-valued children.

Check anywhere with `$sender->hasPermission('myplugin.thing')` or `$player->hasPermission('myplugin.thing')`.

---

## 6. Scheduler

Tasks run on the server tick (20 TPS). All scheduling is callback-based — no task classes needed.

| Method | Runs |
|---|---|
| `scheduleDelayedTask(callable, int $delay)` | Once, after `$delay` ticks |
| `scheduleRepeatingTask(callable, int $period)` | Every `$period` ticks |
| `scheduleDelayedRepeatingTask(callable, int $delay, int $period)` | First run after `$delay`, then every `$period` |
| `scheduleAsyncTask(callable)` | On a worker thread (do not touch shared state without care) |

Each returns a `TaskHandler` — call `->cancel()` to stop it.

```php
use pocketmine\api\scheduler\TaskHandler;

public function onEnable(): void {
    $this->getLogger()->info('Ticking every 20 ticks');
    $handler = $this->scheduleRepeatingTask(function (): void {
        $this->getLogger()->info('tick!');
    }, 20);

    // stop it later
    $handler->cancel();
}
```

### Async plugin tasks (real worker threads)

The `scheduleAsyncTask(callable)` method runs a closure **on the main thread** — it exists for backward compat only. For real off-thread work, use the async plugin task system:

| Method | Returns |
|---|---|
| `scheduleAsyncPluginTask(Runnable $task)` | `PluginFuture` with `then()` callbacks |
| `getThreadingPort()->submitPluginTask(Runnable $task)` | Same, but skips the Scheduler |

**How it works:**

1. You extend `PluginTask` (which extends pmmpthread's `Runnable`).
2. Set input properties on the task object.
3. Submit it — it runs on a **real worker thread**, not the main thread.
4. Register a `then()` callback — it fires on the main thread the next tick after the worker finishes.
5. The server continues without blocking.

```php
use pocketmine\adapter\driven\threading\PluginTask;

class HashTask extends PluginTask {
    public string $input = '';
    public string $hash = '';

    public function run(): void {
        // This runs on a WORKER THREAD — do not touch game state.
        $this->hash = hash('sha256', $this->input);
        $this->complete($this->hash); // resolve the future
    }
}
```

```php
public function onEnable(): void {
    $task = new HashTask();
    $task->input = 'some large data';

    $future = $this->getScheduler()->scheduleAsyncPluginTask($task);
    $future->then(
        fn(string $hash) => $this->getLogger()->info("Hash: $hash"),
        fn(\Throwable $e) => $this->getLogger()->error("Failed: {$e->getMessage()}")
    );
    // Returns immediately — the server does not freeze.
}
```

#### `PluginTask` base class

| Method | Meaning |
|---|---|
| `complete(mixed $result = null)` | Resolve the future with a value (call from `run()`) |
| `fail(\Throwable $e)` | Reject the future with an error (call from `run()`) |

If `run()` completes without calling `complete()` or `fail()`, the future is auto-resolved with `null`. If `run()` throws, the future is auto-rejected.

#### `PluginFuture` callbacks

| Method | Meaning |
|---|---|
| `then(callable $onSuccess, ?callable $onError = null)` | Register a callback. `$onSuccess` receives the result; `$onError` receives the `\Throwable`. Both run on the main thread. |
| `isDone()` | Whether the worker has finished (check without blocking) |
| `cancel()` | Prevent callbacks from firing |

Multiple `then()` calls stack — all registered callbacks fire.

#### Important constraints

- **Task properties must be scalars or thread-safe.** The task object crosses thread boundaries. Strings, integers, floats, and booleans are safe. Closures, resources, and main-thread objects (entities, players, world) are **not** — they will crash or corrupt.
- **Task classes must be pre-loaded.** pmmpthread workers cannot autoload. Call `class_exists(MyTask::class)` in your `onEnable()` before the first submission:

```php
public function onEnable(): void {
    // Pre-load so workers can instantiate it.
    class_exists(HashTask::class);

    // Now safe to submit.
    $future = $this->getScheduler()->scheduleAsyncPluginTask(new HashTask());
    // ...
}
```

- **Do not touch game state from `run()`.** The worker thread has no access to the ECS, entities, players, or the world. Compute pure data only. Use `complete($result)` to send the result back; handle it in the `then()` callback on the main thread.

#### When to use async tasks

| Use case | Approach |
|---|---|
| Hashing, encoding, compression, heavy math | `PluginTask` → worker thread |
| HTTP requests, file I/O (large) | `PluginTask` → worker thread |
| Modifying entities/blocks/inventory | Main-thread scheduled task |
| Sending packets to players | Main-thread scheduled task |
| Anything touching ECS state | Main-thread scheduled task |

---

## 7. Events

Events fire from the gameplay services and the network layer at the points listed below. Subscribe with `registerEvent()`:

```php
use pocketmine\api\event\PlayerChatEvent;
use pocketmine\api\event\EventPriority;

public function onEnable(): void {
    $this->registerEvent(PlayerChatEvent::class, function (PlayerChatEvent $event): void {
        $event->setMessage(strtoupper($event->getMessage()));
    }, EventPriority::HIGH);
}
```

Signature: `registerEvent(string $eventClass, callable $handler, int $priority = EventPriority::NORMAL)`.

Priorities (highest runs first): `LOWEST`, `LOW`, `NORMAL`, `HIGH`, `HIGHEST`, `MONITOR`.

**Cancellable events** extend `CancellableEvent`. Call `$event->setCancelled(true)` and the underlying action is blocked:

```php
$this->registerEvent(BlockBreakEvent::class, function (BlockBreakEvent $event): void {
    if ($event->getBlock()->getId() === 1) { // stone
        $event->setCancelled(true); // bedrock-like protection
    }
});
```

### Event reference

| Event | Cancellable | Carries |
|---|---|---|
| `PlayerJoinEvent` | – | `Player` |
| `PlayerLeaveEvent` | – | `Player`, `reason` |
| `PlayerRespawnEvent` | – | `Player` |
| `PlayerChatEvent` | ✅ | `Player`, mutable `message` |
| `PlayerCommandPreprocessEvent` | ✅ | `Player`, mutable `command` |
| `PlayerMoveEvent` | ✅ | `Player`, `from`, `to` (position arrays) — cancelling reverts the player to `from` and sends a position reset to the client |
| `PlayerInteractEvent` | ✅ | `Player`, `action` (const `LEFT_CLICK_AIR`/`LEFT_CLICK_BLOCK`/`RIGHT_CLICK_AIR`/`RIGHT_CLICK_BLOCK`), `target` entity, `face` |
| `BlockBreakEvent` | ✅ | `Player`, `Block` |
| `BlockPlaceEvent` | ✅ | `Player`, `Block`, `face` |
| `EntitySpawnEvent` | – | `Entity` |
| `EntityDamageEvent` | ✅ | `Entity`, `cause` (const `CAUSE_*`), mutable `damage` |
| `EntityDeathEvent` | – | `Entity`, `killer` |
| `PlayerDeathEvent` | – | `Player`, `killer`, mutable `deathMessage` |
| `InventoryOpenEvent` | ✅ | `Player`, `containerType`, `position` — cancelling prevents the ContainerOpenPacket from being sent |
| `InventoryCloseEvent` | – | `Player`, `containerType`, `position` |

### Entity visibility filter

Plugins that need per-viewer entity hiding (auth plugins, spectator modes, stealth) can set a visibility filter:

```php
$this->setEntityVisibilityFilter(function ($viewer, $target): bool {
    // $viewer = PlayerRef, $target = EntityRef
    // Return true to allow, false to suppress entity state packets
    return $this->isPlayerAuthenticated($viewer);
});
```

The filter is called for every viewer→entity pair during `broadcastEntityStates`. Return `false` to hide the entity from that player. Pass `null` to remove the filter.

**Important:** When the filter suppresses an entity, the client receives a `RemoveEntityPacket`. When the filter later allows it, the entity is re-added with a fresh `AddEntityPacket`. This means toggling visibility causes a brief despawn/respawn cycle — design your filter to be stable to avoid flickering.

---

## 8. KernelAccessor — services and ports

`getKernel()` hands you the full engine surface. Everything is also available directly on `Plugin` via the convenience getters below.

### Gameplay services (18)

| Getter | Service |
|---|---|
| `getPlayerJoinService()` | `handleJoin()` — spawn a player |
| `getPlayerLeaveService()` | `handleLeave()` — despawn a player |
| `getPlayerRespawnService()` | `respawn()` — revive a dead player |
| `getChunkLoadService()` / `getChunkUnloadService()` / `getChunkSendService()` | Chunk lifecycle |
| `getBlockBreakService()` / `getBlockPlaceService()` / `getBlockUpdateService()` | Block operations (respect events) |
| `getEntitySpawnService()` | `spawnEntity()`, `spawnMob()`, `spawnItem()`, `spawnProjectile()` |
| `getEntityDespawnService()` | `despawn()` |
| `getEntityInteractionService()` | `attack()`, `interact()` |
| `getCombatService()` | `applyDamage()`, `heal()`, `kill()`, `applyKnockback()` |
| `getDamageService()` / `getKnockbackService()` | Damage & knockback pipelines |
| `getInventoryService()` / `getCraftingService()` / `getContainerService()` | Inventory, crafting, containers |

### Ports (6)

| Getter | Port |
|---|---|
| `getNetworkPort()` | `NetworkPort` — send packets, disconnect sessions |
| `getStoragePort()` | `StoragePort` — chunk persistence |
| `getWorldGenPort()` | `WorldGenPort` — chunk generation |
| `getThreadingPort()` | `ThreadingPort` — worker threads / futures |
| `getCommandPort()` | `CommandPort` — register/execute commands |
| `getEventPort()` | `EventPort` — subscribe/emit events |
| `getPluginPort()` | `PluginPort` — the `PluginManager` itself |

Spawning a mob and killing it, for example:

```php
use pocketmine\core\component\ItemStack;

use pocketmine\core\enum\EntityType;

$ref = $this->getEntitySpawnService()->spawnMob(EntityType::Zombie, 100, 64, 100);
$item = $this->getEntitySpawnService()->spawnItem(101, 65, 101, new ItemStack(1, 0, 5)); // 5 stone
```

---

## 9. The ECS underneath

Khronos is ECS-driven. Plugins can read the world directly and even register their own systems.

### Queries

```php
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\PlayerTag;

// All players with health
$players = $this->query()
    ->with(HealthComponent::class)
    ->withTag(PlayerTag::class)
    ->build();

foreach ($players as $entity) {
    $pos = $entity->get(PositionComponent::class);
    // ...
}
```

`QueryBuilder` supports `with()`, `withTag()`, `withAny()`, `without()`, `where()`, `orderBy()`, and `chunked()`.

### Registering a system

```php
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\ecs\SystemPhase;

$this->registerSystem(new class implements System {
    public function run(World $world, float $deltaTime): void {
        // runs every tick
    }
}, SystemPhase::SEQUENTIAL);
```

Phases: `SEQUENTIAL`, `PARALLEL`, `CHUNK_PARALLEL`.

### Spawning custom entities

```php
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\PositionComponent;

$this->getWorld()->spawn(
    (new EntityBuilder())
        ->at(10, 64, 10)
        ->with(new HealthComponent(20, 20))
        ->with(new \pocketmine\core\component\MetadataComponent(['custom' => 'value']))
        ->withTag('monster')
);
```

---

## 10. Facades — entities, players, worlds, blocks, items

### Entity / Player

`pocketmine\api\entity\Entity` wraps an `EntityRef`:

```php
use pocketmine\api\entity\Entity;

$entity = $world->getEntity($entityId);   // via World facade
// or
$entity = Entity::wrap($entityRef, $ecsWorld);
```

| Area | Methods |
|---|---|
| Identity | `getId()`, `getUniqueId()`, `isValid()` |
| Position | `getPosition()`, `getRotation()`, `getVelocity()`, `teleport(x, y, z, yaw, pitch)`, `getDistanceTo()` |
| Health | `getHealth()`, `getMaxHealth()`/`setMaxHealth()`, `damage()`, `heal()`, `kill()`, `isAlive()` |
| State | `isPlayer()`, `isMonster()`, `isOnGround()`, `isInvisible()`, `isDead()`, `isSpectator()` |
| Data | `getMetadata()`, `getInventory()`, `getAttributes()`, `getEffects()`, `getCollision()` |
| Lifecycle | `remove()` (silent despawn) |

`pocketmine\api\entity\Player` adds:

| Area | Methods |
|---|---|
| Identity | `getName()`/`setName()`, `getDisplayName()`, `getSkin()`, `getAddress()`, `getClientId()`, `getPing()`, `isOnline()` |
| Game | `getGamemode()`/`setGamemode()`, `getFoodLevel()`/`setFoodLevel()`, `getSaturation()`, `getExhaustion()`, `getAbsorption()` |
| XP | `getExperience()`/`setExperience()`, `getLevel()`/`setLevel()`, `giveExperience()`, `addExperienceLevel()`, `takeExperienceLevel()` |
| Ops | `isOp()`/`setOp()`, `hasPermission()` |
| Messaging | `sendMessage()`, `sendTip()`, `sendPopup()` |
| Inventory | `getInventory()` (36 slots), `getEnderChestInventory()` |
| Admin | `kick(string $reason)` |

### EntityFactory

```php
use pocketmine\api\entity\EntityFactory;
use pocketmine\api\inventory\ItemStack;

$zombie = EntityFactory::create('zombie', 10, 64, 10);
$drop = EntityFactory::create('item', 11, 65, 11, ['item' => new ItemStack(1, 0, 5)]);

EntityFactory::register('my_mob', MyMob::class); // your class needs a static create(float $x, float $y, float $z)
$custom = EntityFactory::create('my_mob', 12, 64, 12);
```

Built-in spawnable types: `zombie`, `skeleton`, `creeper`, `pig`, `item` (with the `['item' => ItemStack]` option). `player` is registered but has no `create()` — players come from `PlayerJoinService::handleJoin()`, not the factory.

### World facade

`pocketmine\api\world\World` — get one from `Server::getInstance()->getWorldByName('name')`, `getDefaultWorld()`, `getWorldById($id)`, or iterate `getWorlds()`.

| Area | Methods |
|---|---|
| Identity | `getName()`, `getFolderName()`, `getWorldId()`, `getGenerator()` |
| Entities | `getEntities()`, `getPlayers()`, `getEntity(id)`, `getEntitiesInRadius(x, y, z, r)`, `getEntitiesInChunk(cx, cz)`, `getPlayerByName()`, `getPlayerByUniqueId()`, `getPlayerById()` |
| Blocks | `getBlock(x, y, z)`, `setBlock(x, y, z, id, meta)`, `getBlockMeta()`, `getHighestBlockAt()`, `getBiome()`/`setBiome()` |
| Chunks | `loadChunk(cx, cz)`, `unloadChunk()`, `isChunkLoaded()`, `isChunkGenerated()`, `isChunkPopulated()`, `getChunkData()`, `setChunkData()` |
| World state | `getTime()`/`setTime()`, `getSeed()`/`setSeed()`, `getSpawnLocation()`/`setSpawnLocation()`, `getDifficulty()`/`setDifficulty()`, `getGameMode()`/`setGameMode()`, `getMaxPlayers()` |
| Items | `dropItem(x, y, z, ItemStack)`, `dropExp(x, y, z, amount)` |

### Block facade

`pocketmine\api\block\Block` — construct with a world + coordinates (events hand you these):

```php
$block = new Block($world, $x, $y, $z);
$block->getId();          // 1 = stone
$block->getMeta();
$block->isSolid();
$block->getHardness();    // 1.5 for stone
$block->getDrops($heldItem); // array<ItemStack>
$block->getRelative(0, 1, 0); // block above
```

### ItemStack & Inventory

`pocketmine\api\inventory\ItemStack` is the plugin-facing item value:

```php
use pocketmine\api\inventory\ItemStack;

$stack = new ItemStack(1, 0, 64);          // 64 stone
$stack->setCustomName('Sacred Stone');
$stack->setLore(['First line', 'Second line']);
$stack->addEnchantment(5, 1);              // sharpness I
$stack->getMaxStackSize();                 // 64
```

`pocketmine\api\inventory\Inventory` wraps an entity's inventory with the API item type:

```php
use pocketmine\api\inventory\Inventory;

// From inside a Plugin: wrap the player's EntityRef with the ECS world.
$inv = new Inventory($player->getInternalRef(), $this->getWorld());

$inv->getItem(0);          // held slot item
$inv->setItem(0, $stack);
$inv->addItem($stack);     // stacks into existing slots; $stack->getCount() reflects what was consumed
$inv->getHeldSlot();       // 0-8 hotbar index
$inv->setHeldSlot(2);
$inv->canAddItem($stack);
$inv->countItem(1);        // total stone in inventory
$inv->removeItemById(1, 10);
```

> Note: `Player::getInventory()` returns the raw core `InventoryComponent` (storage type). When you want the plugin-facing facade with `api\inventory\ItemStack` conversion, wrap it as above.

### Server facade

`pocketmine\api\server\Server::getInstance()` — global server state and dispatch:

| Area | Methods |
|---|---|
| Config | `getName()`, `getMotd()`, `getMaxPlayers()`, `getPort()`, `getDifficulty()`, `getDefaultGamemode()`, `getViewDistance()`, `isPvp()`, `isWhiteListEnabled()` |
| Worlds | `getWorlds()`, `getWorldByName()`, `getWorldById()`, `getDefaultWorld()`, `loadWorld()`, `generateWorld(name, seed, generator)`, `unloadWorld()` |
| Players | `getOnlinePlayers()`, `getPlayer(name)`, `getPlayerByUniqueId()`, `getPlayerById()`, `broadcastMessage()`, `broadcastTip()`, `broadcastPopup()` |
| Commands | `dispatchCommand('say hi', $sender)`, `registerCommand()`, `getCommand('name')` |
| Engine | `getUptime()`, `getTicksPerSecond()`, `isRunning()`, `shutdown()`, `getPluginManager()`, `getScheduler()`, `getServices()` |

---

## 11. Packaging & deployment

### Directory plugins (development)

```
plugins/MyPlugin/
├── plugin.yml
└── src/MyPlugin/Main.php
```

### `.phar` archives (distribution)

Build a phar with `plugin.yml` at the root and your source under `src/` (the same layout as the directory form). The manager reads `plugin.yml` from the archive and autoloads classes from `phar://.../src/`.

> `.jar` files are **never** treated as plugins — they are Java binaries, not PHP.

### Load order

1. Every `plugin.yml` in `plugins/` is parsed.
2. API compatibility is checked (`api` must be ≤ `2.0.0`).
3. `depend` plugins must already be loaded **and enabled**; `softdepend` plugins are loaded first if present.
4. Classes are autoloaded from `src/` following the namespace → path layout.
5. `onLoad()` → commands/permissions registered → `onEnable()`.

### Troubleshooting

| Symptom | Cause |
|---|---|
| Plugin not loaded | `plugin.yml` missing/invalid, `name`/`main` missing, main class missing or not extending `Plugin`, API too new, dependency missing |
| Duplicate name rejected | Only one plugin per name can be loaded at a time |
| Command missing | Declared in `plugin.yml` but `onCommand()` returns `false`, or not declared and never `registerCommand()`-ed |
| Permission denied for ops | Permission not registered (unregistered perms default to `notop`) — declare it in `plugin.yml` |

---

## 12. Example: a complete plugin

A plugin that welcomes players, protects a region from breaking, and adds a `/ping` command:

```yaml
name: HelloServer
version: 1.0.0
author: You
main: HelloServer\Main
api: 2.0.0
commands:
  ping:
    description: Pong!
    permission: helloserver.ping
permissions:
  helloserver.ping:
    description: Use /ping
    default: true
```

```php
<?php

declare(strict_types=1);

namespace HelloServer;

use pocketmine\api\event\BlockBreakEvent;
use pocketmine\api\event\PlayerChatEvent;
use pocketmine\api\event\PlayerJoinEvent;
use pocketmine\api\plugin\Plugin;
use pocketmine\api\command\CommandSender;

class Main extends Plugin {
    private const PROTECTED = [[10, 64, 10], [20, 70, 20]]; // region corners

    public function onEnable(): void {
        // Welcome message on join
        $this->registerEvent(PlayerJoinEvent::class, function (PlayerJoinEvent $event): void {
            $event->getPlayer()->sendMessage('§aWelcome to the server!');
        });

        // Uppercase everything said in chat
        $this->registerEvent(PlayerChatEvent::class, function (PlayerChatEvent $event): void {
            $event->setMessage(strtoupper($event->getMessage()));
        });

        // Protect the spawn region
        $this->registerEvent(BlockBreakEvent::class, function (BlockBreakEvent $event): void {
            $b = $event->getBlock();
            $x = $b->getX(); $y = $b->getY(); $z = $b->getZ();
            if ($x >= 10 && $x <= 20 && $y >= 64 && $y <= 70 && $z >= 10 && $z <= 20) {
                $event->setCancelled(true);
                $event->getPlayer()->sendMessage('§cThis area is protected!');
            }
        });

        $this->getLogger()->info('HelloServer enabled.');
    }

    public function onCommand(CommandSender $sender, string $label, array $args): bool {
        if ($label === 'ping') {
            $sender->sendMessage('§aPong!');
            return true;
        }
        return false;
    }
}
```

---

## 13. API versioning

`api: 2.0.0` in `plugin.yml` means "I need at least API 2.0.0". Plugins declaring a newer API than the server are rejected at load. As the API evolves, breaking changes bump the minor version (`2.x`); the current server API is **2.0.0** (`Kernel::API_VERSION`).
