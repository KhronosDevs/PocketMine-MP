<?php

declare(strict_types=1);

namespace pocketmine\api\server;

use pocketmine\core\ecs\EntityRef;
use pocketmine\api\world\World;
use pocketmine\api\entity\Player;

class Server {
    private static ?self $instance = null;
    
    private array $worlds = [];
    private ?World $defaultWorld = null;
    private string $name = 'Khronos';
    private string $version = '2.0.0';
    private int $maxPlayers = 20;
    private string $motd = 'Khronos Server';
    private string $ip = '0.0.0.0';
    private int $port = 19132;
    private bool $onlineMode = false;
    private bool $pvp = true;
    private \pocketmine\core\enum\Difficulty $difficulty = \pocketmine\core\enum\Difficulty::Easy;
    private bool $spawnAnimals = true;
    private bool $spawnMobs = true;
    private bool $forceGamemode = false;
    private \pocketmine\core\enum\GameMode $gamemode = \pocketmine\core\enum\GameMode::Survival;
    private int $viewDistance = 10;
    private bool $allowFlight = false;
    private string $language = 'eng';
    private bool $whiteList = false;

    private function __construct() {}

    /**
     * Apply parsed server.properties values (called at bootstrap before the
     * kernel starts). Unknown keys keep the current defaults.
     *
     * @param array<string, string> $props
     */
    public function configure(array $props): void {
        $this->name = (string)($props['server-name'] ?? $this->name);
        $this->motd = (string)($props['motd'] ?? $this->motd);
        $this->ip = (string)($props['server-ip'] ?? $this->ip);
        $this->maxPlayers = (int)($props['max-players'] ?? $this->maxPlayers);
        $this->port = (int)($props['server-port'] ?? $this->port);
        $this->difficulty = \pocketmine\core\enum\Difficulty::coerce($props['difficulty'] ?? $this->difficulty->value);
        $this->gamemode = \pocketmine\core\enum\GameMode::coerce($props['gamemode'] ?? $this->gamemode->value);
        $this->viewDistance = (int)($props['view-distance'] ?? $this->viewDistance);
        $this->pvp = self::toBool($props['pvp'] ?? null, $this->pvp);
        $this->spawnAnimals = self::toBool($props['spawn-animals'] ?? null, $this->spawnAnimals);
        $this->spawnMobs = self::toBool($props['spawn-mobs'] ?? null, $this->spawnMobs);
        $this->onlineMode = self::toBool($props['online-mode'] ?? null, $this->onlineMode);
        $this->forceGamemode = self::toBool($props['force-gamemode'] ?? null, $this->forceGamemode);
        $this->allowFlight = self::toBool($props['allow-flight'] ?? null, $this->allowFlight);
        $this->whiteList = self::toBool($props['white-list'] ?? null, $this->whiteList);
        $this->language = (string)($props['language'] ?? $this->language);
    }

    public function setWhitelist(bool $enabled): void {
        $this->whiteList = $enabled;
    }

    public function isWhitelistEnabled(): bool {
        return $this->whiteList;
    }

    private static function toBool(mixed $v, bool $current): bool {
        if (!is_string($v)) {
            return $current;
        }
        return match (strtolower($v)) {
            'true', 'on', '1', 'yes' => true,
            'false', 'off', '0', 'no' => false,
            default => $current,
        };
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function getVersion(): string {
        return $this->version;
    }

    public function getApiVersion(): string {
        return '2.0.0';
    }

    public function getMaxPlayers(): int {
        return $this->maxPlayers;
    }

    public function setMaxPlayers(int $max): void {
        $this->maxPlayers = $max;
    }

    public function getMotd(): string {
        return $this->motd;
    }

    public function setMotd(string $motd): void {
        $this->motd = $motd;
    }

    public function getIp(): string {
        return $this->ip;
    }

    public function setIp(string $ip): void {
        $this->ip = $ip;
    }

    public function getPort(): int {
        return $this->port;
    }

    public function setPort(int $port): void {
        $this->port = $port;
    }

    public function isOnlineMode(): bool {
        return $this->onlineMode;
    }

    public function setOnlineMode(bool $online): void {
        $this->onlineMode = $online;
    }

    public function isPvp(): bool {
        return $this->pvp;
    }

    public function setPvp(bool $pvp): void {
        $this->pvp = $pvp;
    }

    public function getDifficulty(): \pocketmine\core\enum\Difficulty {
        return $this->difficulty;
    }

    public function setDifficulty(\pocketmine\core\enum\Difficulty $difficulty): void {
        $this->difficulty = $difficulty;
    }

    public function getSpawnAnimals(): bool {
        return $this->spawnAnimals;
    }

    public function setSpawnAnimals(bool $spawn): void {
        $this->spawnAnimals = $spawn;
    }

    public function getSpawnMobs(): bool {
        return $this->spawnMobs;
    }

    public function setSpawnMobs(bool $spawn): void {
        $this->spawnMobs = $spawn;
    }

    public function isForceGamemode(): bool {
        return $this->forceGamemode;
    }

    public function setForceGamemode(bool $force): void {
        $this->forceGamemode = $force;
    }

    public function getDefaultGamemode(): \pocketmine\core\enum\GameMode {
        return $this->gamemode;
    }

    public function setDefaultGamemode(\pocketmine\core\enum\GameMode $gamemode): void {
        $this->gamemode = $gamemode;
    }

    public function isAllowFlight(): bool {
        return $this->allowFlight;
    }

    public function setAllowFlight(bool $allow): void {
        $this->allowFlight = $allow;
    }

    public function getLanguage(): string {
        return $this->language;
    }

    public function setLanguage(string $lang): void {
        $this->language = $lang;
    }

    public function getWorld(): \pocketmine\core\ecs\World {
        return \pocketmine\Kernel::getInstance()->getWorld();
    }

    public function getWorlds(): array {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            return array_values($this->worlds);
        }
        $out = [];
        foreach ($kernel->getWorldRegistry()->getWorlds() as $worldId => $info) {
            $out[] = $this->facadeFor($worldId, $info);
        }
        return $out;
    }

    public function getWorldByName(string $name): ?World {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            return $this->worlds[$name] ?? null;
        }
        $registry = $kernel->getWorldRegistry();
        $id = $registry->getWorldIdByName($name);
        if ($id === null) {
            return null;
        }
        $info = $registry->getWorld($id);
        return $info !== null ? $this->facadeFor($id, $info) : null;
    }

    public function getWorldById(int $worldId): ?World {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            return null;
        }
        $info = $kernel->getWorldRegistry()->getWorld($worldId);
        return $info !== null ? $this->facadeFor($worldId, $info) : null;
    }

    /**
     * Folders under worlds/ that look like saved worlds (a region/ subfolder
     * or a level.dat) but are NOT currently loaded. Lets admins see what is
     * on disk and /world load it. External-plugin leftovers (protector.yml,
     * wpcfg.yml, ...) are ignored - only region data or a level.dat counts.
     *
     * @return list<string> folder names, sorted
     */
    public function getUnloadedWorlds(): array {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            return [];
        }
        $loaded = [];
        foreach ($kernel->getWorldRegistry()->getWorlds() as $info) {
            $loaded[(string)$info['folderName']] = true;
        }
        $base = \pocketmine\Kernel::getInstance()?->getDataPath() . 'worlds';
        $out = [];
        if (!is_dir($base)) {
            return [];
        }
        foreach (glob($base . '/*', GLOB_ONLYDIR) as $dir) {
            $folder = basename($dir);
            if (isset($loaded[$folder])) {
                continue;
            }
            $looksLikeWorld = is_dir($dir . '/region')
                || file_exists($dir . '/level.dat')
                || is_dir($dir . '/db');
            if ($looksLikeWorld) {
                $out[] = $folder;
            }
        }
        sort($out);
        return $out;
    }

    /** The current world a player is in, or null for a non-player / unknown. */
    public function getWorldOfPlayer(\pocketmine\api\entity\Player $player): ?World {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            return null;
        }
        $registry = $kernel->getWorldRegistry();
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['entityId'] !== $player->getId()) {
                continue;
            }
            // The session's world id is authoritative (the entity's
            // WorldComponent tracks it too - read it the same way).
            $entity = $kernel->getWorld()->getEntity($p['entityId']);
            $wc = $entity?->get(\pocketmine\core\component\WorldComponent::class);
            $worldId = $wc instanceof \pocketmine\core\component\WorldComponent ? $wc->id : 0;
            $info = $registry->getWorld($worldId);
            if ($info !== null) {
                return $this->facadeFor($worldId, $info);
            }
        }
        return null;
    }

    public function getDefaultWorld(): World {
        // The default world is always id 0 and registered at kernel boot.
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            throw new \RuntimeException('Kernel not initialized');
        }
        $info = $kernel->getWorldRegistry()->getWorld(0);
        if ($info === null) {
            throw new \RuntimeException('Default world not registered');
        }
        $facade = $this->facadeFor(0, $info);
        if ($this->defaultWorld === null) {
            $this->defaultWorld = $facade;
        }
        return $this->defaultWorld;
    }

    public function setDefaultWorld(World $world): void {
        $this->defaultWorld = $world;
        $this->worlds[$world->getName()] = $world;
    }

    /**
     * Load a world that already exists on disk (worlds/<folderName>/). The
     * persisted seed/spawn meta is restored from its level.dat so its terrain
     * regenerates identically; if no meta exists a fresh random seed is used.
     */
    public function loadWorld(string $name): World {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            throw new \RuntimeException('Kernel not initialized');
        }
        $registry = $kernel->getWorldRegistry();
        $existingId = $registry->getWorldIdByName($name);
        if ($existingId !== null) {
            $info = $registry->getWorld($existingId);
            return $this->facadeFor($existingId, $info);
        }
        // A loaded world must already have a folder (generated once before).
        $folder = 'worlds/' . $name . '/';
        if (!is_dir($folder)) {
            throw new \RuntimeException("World '$name' has no saved data");
        }
        $storage = \pocketmine\adapter\driven\storage\LevelProviderManager::create('worlds/', $name);
        $meta = $storage->loadWorldMeta();
        $seed = isset($meta['seed']) && $meta['seed'] !== '' ? (int)$meta['seed'] : random_int(1, PHP_INT_MAX);
        $store = new \pocketmine\core\resource\ChunkStore();
        $config = new \pocketmine\core\resource\WorldConfig();
        $config->name = $name;
        $config->folderName = $name;
        $config->seed = $seed;
        if ($meta !== null) {
            $config->spawnX = (int)($meta['spawnX'] ?? 0);
            $config->spawnY = (int)($meta['spawnY'] ?? 64);
            $config->spawnZ = (int)($meta['spawnZ'] ?? 0);
            $config->time = (int)($meta['time'] ?? 0);
            // A world whose level.dat carries a generatorName is restored with
            // it (normal/flat/void). Foreign or legacy level.dat files (no
            // generator info) default to VOID: the world is not regenerated
            // around the player - a dropped-in lobby stays as-is.
            $config->generator = \pocketmine\core\enum\GeneratorType::coerce($meta['generator'] ?? 'void');
        } else {
            // No level.dat at all (a hand-dropped folder): treat as void so
            // nothing generates around whatever the user placed.
            $config->generator = \pocketmine\core\enum\GeneratorType::Void;
        }
        $worldId = $registry->registerWorld($name, $name, $seed, $store, $config, $storage);
        $info = $registry->getWorld($worldId);
        return $this->facadeFor($worldId, $info);
    }

    /**
     * Create a brand-new world: its own chunk store, config, seed and storage
     * folder (worlds/<name>/). The world becomes immediately playable - a
     * player switched to it (via /world) streams freshly generated terrain.
     */
    public function generateWorld(string $name, int $seed = 0, \pocketmine\core\enum\GeneratorType $generator = \pocketmine\core\enum\GeneratorType::Normal, array $options = []): World {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            throw new \RuntimeException('Kernel not initialized');
        }
        $registry = $kernel->getWorldRegistry();
        $existingId = $registry->getWorldIdByName($name);
        if ($existingId !== null) {
            $info = $registry->getWorld($existingId);
            return $this->facadeFor($existingId, $info);
        }
        if ($seed === 0) {
            $seed = random_int(1, PHP_INT_MAX);
        }
        $storage = \pocketmine\adapter\driven\storage\LevelProviderManager::create('worlds/', $name);
        $store = new \pocketmine\core\resource\ChunkStore();
        $config = new \pocketmine\core\resource\WorldConfig();
        $config->name = $name;
        $config->folderName = $name;
        $config->seed = $seed;
        $config->generator = $generator;
        $worldId = $registry->registerWorld($name, $name, $seed, $store, $config, $storage);
        $info = $registry->getWorld($worldId);
        return $this->facadeFor($worldId, $info);
    }

    /**
     * Unload a world: optionally persist its resident chunks + meta, then
     * drop its bundle from the registry. The default world (id 0) cannot be
     * unloaded.
     */
    public function unloadWorld(string $name, bool $save = true): bool {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            return isset($this->worlds[$name]) ? (bool)array_splice($this->worlds, array_search($name, array_keys($this->worlds), true), 1) : false;
        }
        $registry = $kernel->getWorldRegistry();
        $worldId = $registry->getWorldIdByName($name);
        if ($worldId === null || $worldId === 0) {
            return false;
        }
        // Never leave players in a world that is about to disappear: they
        // would silently read the default world's store through the fallback
        // while still carrying the old world id. Evacuate them first.
        $network = $kernel->getNetworkSessionService();
        foreach ($network->getOnlinePlayers() as $p) {
            $entity = $kernel->getWorld()->getEntity($p['entityId']);
            $wc = $entity?->get(\pocketmine\core\component\WorldComponent::class);
            if ($wc instanceof \pocketmine\core\component\WorldComponent && $wc->id === $worldId) {
                $network->switchWorld($p['entityId'], 0);
            }
        }
        if ($save) {
            $kernel->saveAllWorlds();
        }
        $removed = $registry->removeWorld($worldId);
        if ($removed && isset($this->worlds[$name])) {
            unset($this->worlds[$name]);
        }
        return $removed;
    }

    /**
     * Build (and cache) the API World facade for a registry bundle.
     */
    private function facadeFor(int $worldId, array $info): World {
        $name = (string)$info['name'];
        if (isset($this->worlds[$name]) && $this->worlds[$name]->getWorldId() === $worldId) {
            return $this->worlds[$name];
        }
        $facade = new World(
            \pocketmine\Kernel::getInstance()->getWorld(),
            $name,
            (string)$info['folderName'],
            $worldId,
        );
        $this->worlds[$name] = $facade;
        return $facade;
    }

    public function getOnlinePlayers(): array {
        $players = [];
        $world = \pocketmine\Kernel::getInstance()->getWorld();
        
        $query = $world->query()
            ->with(\pocketmine\core\component\MetadataComponent::class)
            ->withTag(\pocketmine\core\component\tags\PlayerTag::class)
            ->build();
        
        $players = [];
        foreach ($query as $entity) {
            $players[] = new \pocketmine\api\entity\Player(
                EntityRef::create($entity->id, $world),
                $world
            );
        }
        return $players;
    }

    public function getOnlinePlayersCount(): int {
        return count($this->getOnlinePlayers());
    }

    public function getPlayer(string $name): ?Player {
        $world = \pocketmine\Kernel::getInstance()->getWorld();
        
        $query = $world->query()
            ->with(\pocketmine\core\component\MetadataComponent::class)
            ->withTag(\pocketmine\core\component\tags\PlayerTag::class)
            ->build();
        
        foreach ($query as $entity) {
            $metadata = $entity->get(\pocketmine\core\component\MetadataComponent::class);
            if ($metadata && strtolower($metadata->get('username', '')) === strtolower($name)) {
                return new \pocketmine\api\entity\Player(
                    EntityRef::create($entity->id, $world),
                    $world
                );
            }
        }
        return null;
    }

    public function getPlayerByUniqueId(string $uniqueId): ?Player {
        $world = \pocketmine\Kernel::getInstance()->getWorld();
        
        $query = $world->query()
            ->with(\pocketmine\core\component\MetadataComponent::class)
            ->withTag(\pocketmine\core\component\tags\PlayerTag::class)
            ->build();
        
        foreach ($query as $entity) {
            $metadata = $entity->get(\pocketmine\core\component\MetadataComponent::class);
            if ($metadata && $metadata->get(\pocketmine\core\constants\MetadataKeys::UNIQUE_ID) === $uniqueId) {
                return new \pocketmine\api\entity\Player(
                    EntityRef::create($entity->id, $world),
                    $world
                );
            }
        }
        return null;
    }

    public function getPlayerById(int $id): ?Player {
        $world = \pocketmine\Kernel::getInstance()->getWorld();
        $entity = $world->getEntity($id);
        
        if ($entity && $entity->hasComponent(\pocketmine\core\component\tags\PlayerTag::class)) {
            return new \pocketmine\api\entity\Player(
                EntityRef::create($entity->id, $world),
                $world
            );
        }
        return null;
    }

    public function getUptime(): string {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            return '0s';
        }
        $seconds = $kernel->getUptime();
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        $parts = [];
        if ($days > 0) $parts[] = $days . 'd';
        if ($hours > 0) $parts[] = $hours . 'h';
        if ($minutes > 0) $parts[] = $minutes . 'm';
        $parts[] = $secs . 's';
        return implode(' ', $parts);
    }

    public function getTicksPerSecond(): float {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            return 20.0;
        }
        $stats = $kernel->getTickStats();
        $mean = $stats['mean_ms'] ?? null;
        if (!is_numeric($mean) || $mean <= 0) {
            return 20.0;
        }
        return round(1000.0 / (float)$mean, 1);
    }

    public function getTicksPerSecondAverage(): float {
        return $this->getTicksPerSecond();
    }

    public function isRunning(): bool {
        $kernel = \pocketmine\Kernel::getInstance();
        return $kernel->isRunning();
    }

    public function shutdown(): void {
        $kernel = \pocketmine\Kernel::getInstance();
        $kernel->shutdown();
    }

    public function broadcastMessage(string $message): void {
        foreach ($this->getOnlinePlayers() as $player) {
            $player->sendMessage($message);
        }
    }

    public function broadcastPopup(string $message): void {
        foreach ($this->getOnlinePlayers() as $player) {
            $player->sendPopup($message);
        }
    }

    public function broadcastTip(string $message): void {
        foreach ($this->getOnlinePlayers() as $player) {
            $player->sendTip($message);
        }
    }

    /**
     * Dispatch a command line through the single server-wide command map.
     * The sender may be a CommandSender, a player name (resolved to that
     * player), or null/'CONSOLE' for the console.
     */
    public function dispatchCommand(string $command, \pocketmine\api\command\CommandSender|string|null $sender = null): bool {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            throw new \RuntimeException("Kernel not initialized");
        }
        return $kernel->getCommandPort()->execute($this->resolveSender($sender), $command);
    }

    private function resolveSender(\pocketmine\api\command\CommandSender|string|null $sender): \pocketmine\api\command\CommandSender {
        if ($sender instanceof \pocketmine\api\command\CommandSender) {
            return $sender;
        }
        if ($sender === null || $sender === 'CONSOLE') {
            return new \pocketmine\api\command\ConsoleCommandSender();
        }
        $player = $this->getPlayer($sender);
        return $player !== null
            ? new \pocketmine\api\command\PlayerCommandSender($player)
            : new \pocketmine\api\command\ConsoleCommandSender();
    }

    /**
     * Register a command into the single server-wide command map.
     */
    public function registerCommand(\pocketmine\api\command\Command $command): void {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel !== null) {
            $kernel->getCommandPort()->register($command);
        }
    }

    public function getCommand(string $name): ?\pocketmine\api\command\Command {
        return \pocketmine\Kernel::getInstance()?->getCommandPort()->getCommand($name);
    }

    public function getPluginManager(): \pocketmine\api\plugin\PluginManager {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            throw new \RuntimeException("Kernel not initialized");
        }
        return $kernel->getPluginManager();
    }

    public function getScheduler(): \pocketmine\api\scheduler\Scheduler {
        return \pocketmine\Kernel::getInstance()->getScheduler();
    }

    public function getServices(): array {
        return [
            'playerJoin' => \pocketmine\Kernel::getInstance()->getPlayerJoinService(),
            'playerLeave' => \pocketmine\Kernel::getInstance()->getPlayerLeaveService(),
            'playerRespawn' => \pocketmine\Kernel::getInstance()->getPlayerRespawnService(),
            'chunkLoad' => \pocketmine\Kernel::getInstance()->getChunkLoadService(),
            'chunkUnload' => \pocketmine\Kernel::getInstance()->getChunkUnloadService(),
            'chunkSend' => \pocketmine\Kernel::getInstance()->getChunkSendService(),
            'blockBreak' => \pocketmine\Kernel::getInstance()->getBlockBreakService(),
            'blockPlace' => \pocketmine\Kernel::getInstance()->getBlockPlaceService(),
            'blockUpdate' => \pocketmine\Kernel::getInstance()->getBlockUpdateService(),
            'entitySpawn' => \pocketmine\Kernel::getInstance()->getEntitySpawnService(),
            'entityDespawn' => \pocketmine\Kernel::getInstance()->getEntityDespawnService(),
            'entityInteraction' => \pocketmine\Kernel::getInstance()->getEntityInteractionService(),
            'combat' => \pocketmine\Kernel::getInstance()->getCombatService(),
            'damage' => \pocketmine\Kernel::getInstance()->getDamageService(),
            'knockback' => \pocketmine\Kernel::getInstance()->getKnockbackService(),
            'inventory' => \pocketmine\Kernel::getInstance()->getInventoryService(),
            'crafting' => \pocketmine\Kernel::getInstance()->getCraftingService(),
            'container' => \pocketmine\Kernel::getInstance()->getContainerService(),
        ];
    }
}