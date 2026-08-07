<?php

declare(strict_types=1);

namespace pocketmine\api\plugin;

use pocketmine\api\command\Command;
use pocketmine\api\command\CommandMap;
use pocketmine\api\command\CommandExecutor;
use pocketmine\api\command\ConsoleCommandSender;
use pocketmine\api\command\PlayerCommandSender;
use pocketmine\api\event\EventBus;
use pocketmine\api\event\Event;
use pocketmine\api\permission\PermissionManager;
use pocketmine\api\scheduler\Scheduler;
use pocketmine\api\world\WorldAccessor;
use pocketmine\core\ecs\World;
use pocketmine\core\ecs\EntityRef;
use pocketmine\Kernel;

abstract class Plugin {
    private ?string $name = null;
    private ?string $version = null;
    private ?string $author = null;
    private array $depend = [];
    private array $softDepend = [];
    private bool $enabled = false;
    private ?PluginDescription $description = null;
    private \pocketmine\api\command\CommandMap $commandMap;

    final public function __construct() {
        $this->commandMap = new \pocketmine\api\command\CommandMap();
    }
    
    // Injected by PluginManager
    private ?KernelAccessor $kernelAccessor = null;

    // Kernel access (injected by PluginManager)
    final public function setKernelAccessor(KernelAccessor $accessor): void {
        $this->kernelAccessor = $accessor;
    }

    final protected function getKernel(): KernelAccessor {
        if (!$this->kernelAccessor) {
            throw new \RuntimeException("Plugin not initialized - kernel accessor not set");
        }
        return $this->kernelAccessor;
    }

    // World access (ECS World for queries)
    final protected function getWorld(): World {
        return $this->getKernel()->getWorld();
    }

    // ECS Query API
    final protected function query(): \pocketmine\core\ecs\QueryBuilder {
        return $this->getWorld()->query();
    }

    // System registration
    final protected function registerSystem(\pocketmine\core\ecs\System $system, \pocketmine\core\ecs\SystemPhase $phase = \pocketmine\core\ecs\SystemPhase::SEQUENTIAL): void {
        $this->getKernel()->getSystemScheduler()->register($system, $phase);
    }

    // Service access
    final protected function getPlayerJoinService(): \pocketmine\core\service\PlayerJoinService {
        return $this->getKernel()->getPlayerJoinService();
    }

    final protected function getPlayerLeaveService(): \pocketmine\core\service\PlayerLeaveService {
        return $this->getKernel()->getPlayerLeaveService();
    }

    final protected function getPlayerRespawnService(): \pocketmine\core\service\PlayerRespawnService {
        return $this->getKernel()->getPlayerRespawnService();
    }

    final protected function getChunkLoadService(): \pocketmine\core\service\ChunkLoadService {
        return $this->getKernel()->getChunkLoadService();
    }

    final protected function getChunkUnloadService(): \pocketmine\core\service\ChunkUnloadService {
        return $this->getKernel()->getChunkUnloadService();
    }

    final protected function getChunkSendService(): \pocketmine\core\service\ChunkSendService {
        return $this->getKernel()->getChunkSendService();
    }

    final protected function getBlockBreakService(): \pocketmine\core\service\BlockBreakService {
        return $this->getKernel()->getBlockBreakService();
    }

    final protected function getBlockPlaceService(): \pocketmine\core\service\BlockPlaceService {
        return $this->getKernel()->getBlockPlaceService();
    }

    final protected function getBlockUpdateService(): \pocketmine\core\service\BlockUpdateService {
        return $this->getKernel()->getBlockUpdateService();
    }

    final protected function getEntitySpawnService(): \pocketmine\core\service\EntitySpawnService {
        return $this->getKernel()->getEntitySpawnService();
    }

    final protected function getEntityDespawnService(): \pocketmine\core\service\EntityDespawnService {
        return $this->getKernel()->getEntityDespawnService();
    }

    final protected function getEntityInteractionService(): \pocketmine\core\service\EntityInteractionService {
        return $this->getKernel()->getEntityInteractionService();
    }

    final protected function getCombatService(): \pocketmine\core\service\CombatService {
        return $this->getKernel()->getCombatService();
    }

    final protected function getDamageService(): \pocketmine\core\service\DamageService {
        return $this->getKernel()->getDamageService();
    }

    final protected function getKnockbackService(): \pocketmine\core\service\KnockbackService {
        return $this->getKernel()->getKnockbackService();
    }

    final protected function getInventoryService(): \pocketmine\core\service\InventoryService {
        return $this->getKernel()->getInventoryService();
    }

    final protected function getCraftingService(): \pocketmine\core\service\CraftingService {
        return $this->getKernel()->getCraftingService();
    }

    final protected function getContainerService(): \pocketmine\core\service\ContainerService {
        return $this->getKernel()->getContainerService();
    }

    // Port access
    final protected function getNetworkPort(): \pocketmine\port\driven\NetworkPort {
        return $this->getKernel()->getNetworkPort();
    }

    final protected function getStoragePort(): \pocketmine\port\driven\StoragePort {
        return $this->getKernel()->getStoragePort();
    }

    final protected function getWorldGenPort(): \pocketmine\port\driven\WorldGenPort {
        return $this->getKernel()->getWorldGenPort();
    }

    final protected function getThreadingPort(): \pocketmine\port\driven\ThreadingPort {
        return $this->getKernel()->getThreadingPort();
    }

    final protected function getCommandPort(): \pocketmine\port\driving\CommandPort {
        return $this->getKernel()->getCommandPort();
    }

    final protected function getEventPort(): \pocketmine\port\driving\EventPort {
        return $this->getKernel()->getEventPort();
    }

    final protected function getPluginPort(): \pocketmine\port\driving\PluginPort {
        return $this->getKernel()->getPluginPort();
    }

    // Lifecycle
    public function onEnable(): void {}
    public function onDisable(): void {}

    // Metadata
    final public function getName(): string {
        return $this->name ?? get_class($this);
    }

    final public function getVersion(): string {
        return $this->version ?? "1.0.0";
    }

    final public function getAuthor(): string {
        return $this->author ?? "Unknown";
    }

    final public function getDepend(): array {
        return $this->depend;
    }

    final public function getSoftDepend(): array {
        return $this->softDepend;
    }

    final public function isEnabled(): bool {
        return $this->enabled;
    }

    final public function getDescription(): PluginDescription {
        if (!$this->description) {
            $this->description = new PluginDescription(
                $this->getName(),
                $this->getVersion(),
                $this->getAuthor(),
                $this->depend,
                $this->softDepend,
                [], // commands
                []  // permissions
            );
        }
        return $this->description;
    }

    // Internal setters (called by PluginManager)
    final public function setName(string $name): void {
        $this->name = $name;
    }

    final public function setVersion(string $version): void {
        $this->version = $version;
    }

    final public function setAuthor(string $author): void {
        $this->author = $author;
    }

    final public function setDepend(array $depend): void {
        $this->depend = $depend;
    }

    final public function setSoftDepend(array $softDepend): void {
        $this->softDepend = $softDepend;
    }

    final public function setEnabled(bool $enabled): void {
        $this->enabled = $enabled;
    }

    // Convenience methods
    final protected function getServer(): \pocketmine\api\server\Server {
        return \pocketmine\api\server\Server::getInstance();
    }

    final protected function getLogger(): \pocketmine\api\plugin\Logger {
        return new \pocketmine\api\plugin\Logger($this->getName());
    }

    final protected function getDataFolder(): string {
        return \pocketmine\Kernel::getInstance()->getDataPath() . "plugins/" . $this->getName() . "/";
    }

    final protected function getFile(): string {
        return $this->getDataFolder() . "plugin.yml";
    }

    final protected function saveResource(string $resource, bool $replace = false): void {
        // Would save resource from plugin jar
    }

    final protected function getResource(string $resource): ?string {
        // Would get resource from plugin jar
        return null;
    }

    final protected function saveConfig(): void {
        // Would save config.yml
    }

    final protected function reloadConfig(): void {
        // Would reload config.yml
    }

    final protected function getConfig(): \pocketmine\api\plugin\Config {
        return new \pocketmine\api\plugin\Config($this->getDataFolder() . "config.yml");
    }

    // Event registration
    final protected function registerEvent(string $eventClass, callable $handler, int $priority = \pocketmine\api\event\EventPriority::NORMAL): void {
        $this->getKernel()->getEventPort()->subscribe($eventClass, $handler, $priority);
    }

    final protected function unregisterEvent(string $eventClass, callable $handler): void {
        $this->getKernel()->getEventPort()->unsubscribe($eventClass, $handler);
    }

    // Command registration
    final protected function registerCommand(\pocketmine\api\command\Command $command): void {
        $this->commandMap->register($command);
    }

    final protected function getCommandMap(): \pocketmine\api\command\CommandMap {
        return $this->commandMap;
    }

    // Scheduler access
    final protected function getScheduler(): Scheduler {
        return $this->getKernel()->getScheduler();
    }

    final protected function scheduleRepeatingTask(callable $callback, int $period): \pocketmine\api\scheduler\TaskHandler {
        return $this->getScheduler()->scheduleRepeatingTask($callback, $period);
    }

    final protected function scheduleDelayedTask(callable $callback, int $delay): \pocketmine\api\scheduler\TaskHandler {
        return $this->getScheduler()->scheduleDelayedTask($callback, $delay);
    }

    final protected function scheduleDelayedRepeatingTask(callable $callback, int $delay, int $period): \pocketmine\api\scheduler\TaskHandler {
        return $this->getScheduler()->scheduleDelayedRepeatingTask($callback, $delay, $period);
    }

    final protected function scheduleAsyncTask(callable $callback): void {
        $this->getScheduler()->scheduleAsyncTask($callback);
    }

    // Permission checks
    final protected function hasPermission(string $permission): bool {
        // Would check server console permissions
        return true;
    }

    // World access (typed WorldAccessor facade)
    final protected function getWorldAccessor(): \pocketmine\api\world\WorldAccessor {
        return new \pocketmine\api\world\WorldAccessor($this->getWorld());
    }
}

class KernelAccessor {
    public function __construct(
        private readonly Kernel $kernel,
    ) {}

    public function getWorld(): World {
        return $this->kernel->getWorld();
    }

    public function getScheduler(): Scheduler {
        return $this->kernel->getScheduler();
    }

    public function getSystemScheduler(): \pocketmine\core\ecs\SystemScheduler {
        return $this->kernel->getSystemScheduler();
    }

    public function getPlayerJoinService(): \pocketmine\core\service\PlayerJoinService {
        return $this->kernel->getPlayerJoinService();
    }

    public function getPlayerLeaveService(): \pocketmine\core\service\PlayerLeaveService {
        return $this->kernel->getPlayerLeaveService();
    }

    public function getPlayerRespawnService(): \pocketmine\core\service\PlayerRespawnService {
        return $this->kernel->getPlayerRespawnService();
    }

    public function getChunkLoadService(): \pocketmine\core\service\ChunkLoadService {
        return $this->kernel->getChunkLoadService();
    }

    public function getChunkUnloadService(): \pocketmine\core\service\ChunkUnloadService {
        return $this->kernel->getChunkUnloadService();
    }

    public function getChunkSendService(): \pocketmine\core\service\ChunkSendService {
        return $this->kernel->getChunkSendService();
    }

    public function getBlockBreakService(): \pocketmine\core\service\BlockBreakService {
        return $this->kernel->getBlockBreakService();
    }

    public function getBlockPlaceService(): \pocketmine\core\service\BlockPlaceService {
        return $this->kernel->getBlockPlaceService();
    }

    public function getBlockUpdateService(): \pocketmine\core\service\BlockUpdateService {
        return $this->kernel->getBlockUpdateService();
    }

    public function getEntitySpawnService(): \pocketmine\core\service\EntitySpawnService {
        return $this->kernel->getEntitySpawnService();
    }

    public function getEntityDespawnService(): \pocketmine\core\service\EntityDespawnService {
        return $this->kernel->getEntityDespawnService();
    }

    public function getEntityInteractionService(): \pocketmine\core\service\EntityInteractionService {
        return $this->kernel->getEntityInteractionService();
    }

    public function getCombatService(): \pocketmine\core\service\CombatService {
        return $this->kernel->getCombatService();
    }

    public function getDamageService(): \pocketmine\core\service\DamageService {
        return $this->kernel->getDamageService();
    }

    public function getKnockbackService(): \pocketmine\core\service\KnockbackService {
        return $this->kernel->getKnockbackService();
    }

    public function getInventoryService(): \pocketmine\core\service\InventoryService {
        return $this->kernel->getInventoryService();
    }

    public function getCraftingService(): \pocketmine\core\service\CraftingService {
        return $this->kernel->getCraftingService();
    }

    public function getContainerService(): \pocketmine\core\service\ContainerService {
        return $this->kernel->getContainerService();
    }

    public function getNetworkPort(): \pocketmine\port\driven\NetworkPort {
        return $this->kernel->getNetworkPort();
    }

    public function getStoragePort(): \pocketmine\port\driven\StoragePort {
        return $this->kernel->getStoragePort();
    }

    public function getWorldGenPort(): \pocketmine\port\driven\WorldGenPort {
        return $this->kernel->getWorldGenPort();
    }

    public function getThreadingPort(): \pocketmine\port\driven\ThreadingPort {
        return $this->kernel->getThreadingPort();
    }

    public function getCommandPort(): \pocketmine\port\driving\CommandPort {
        return $this->kernel->getCommandPort();
    }

    public function getEventPort(): \pocketmine\port\driving\EventPort {
        return $this->kernel->getEventPort();
    }

    public function getPluginPort(): \pocketmine\port\driving\PluginPort {
        return $this->kernel->getPluginPort();
    }
}