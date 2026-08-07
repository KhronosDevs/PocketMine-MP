<?php

declare(strict_types=1);

namespace pocketmine\api\server;

use pocketmine\core\ecs\EntityRef;
use pocketmine\api\world\World;
use pocketmine\api\entity\Player;

class Server {
    private static ?self $instance = null;
    
    private array $worlds = [];
    private World $defaultWorld;
    private string $name = 'Khronos';
    private string $version = '2.0.0';
    private int $maxPlayers = 20;
    private string $motd = 'Khronos Server';
    private string $ip = '0.0.0.0';
    private int $port = 19132;
    private bool $onlineMode = false;
    private bool $pvp = true;
    private int $difficulty = 1;
    private bool $spawnAnimals = true;
    private bool $spawnMobs = true;
    private bool $forceGamemode = false;
    private int $gamemode = 0;
    private bool $allowFlight = false;
    private string $language = 'eng';

    private function __construct() {
        // Singleton constructor
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

    public function getDifficulty(): int {
        return $this->difficulty;
    }

    public function setDifficulty(int $difficulty): void {
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

    public function getDefaultGamemode(): int {
        return $this->gamemode;
    }

    public function setDefaultGamemode(int $gamemode): void {
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
        return array_values($this->worlds);
    }

    public function getWorldByName(string $name): ?World {
        foreach ($this->worlds as $world) {
            if ($world->getName() === $name) {
                return $world;
            }
        }
        return null;
    }

    public function getDefaultWorld(): World {
        return $this->defaultWorld;
    }

    public function setDefaultWorld(World $world): void {
        $this->defaultWorld = $world;
        $this->worlds[$world->getName()] = $world;
    }

    public function loadWorld(string $name): World {
        // Would load world from storage
        return $this->getDefaultWorld(); // Simplified
    }

    public function generateWorld(string $name, int $seed = 0, string $generator = 'default', array $options = []): World {
        // Would generate world
        return $this->getDefaultWorld(); // Simplified
    }

    public function unloadWorld(string $name, bool $save = true): bool {
        if (isset($this->worlds[$name])) {
            unset($this->worlds[$name]);
            return true;
        }
        return false;
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
            if ($metadata && $metadata->get('uniqueId') === $uniqueId) {
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
        // Would return server uptime
        return '0s'; // Simplified
    }

    public function getTicksPerSecond(): float {
        $kernel = \pocketmine\Kernel::getInstance();
        return 20.0; // Simplified
    }

    public function getTicksPerSecondAverage(): float {
        return 20.0; // Simplified
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

    public function dispatchCommand(string $command, string $sender = 'CONSOLE'): bool {
        // Would dispatch command
        return true;
    }

    public function getPluginManager(): \pocketmine\api\plugin\PluginManager {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            throw new \RuntimeException("Kernel not initialized");
        }
        return new \pocketmine\api\plugin\PluginManager($kernel);
    }

    public function getScheduler(): \pocketmine\api\scheduler\Scheduler {
        return new \pocketmine\api\scheduler\Scheduler();
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