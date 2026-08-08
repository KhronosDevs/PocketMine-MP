<?php

declare(strict_types=1);

namespace pocketmine\api\plugin;

use pocketmine\api\command\Command;
use pocketmine\api\command\CommandSender;
use pocketmine\api\scheduler\Scheduler;
use pocketmine\api\world\WorldAccessor;
use pocketmine\core\ecs\World;

/**
 * Base class for plugins. Plugin authors subclass this and implement the
 * lifecycle hooks (onLoad/onEnable/onDisable); the PluginManager instantiates
 * it from the plugin.yml "main" field and injects the kernel accessor,
 * descriptor and file path. All ECS-facing access (world, query, systems,
 * services, ports) is available through getKernel().
 */
abstract class Plugin {
    private ?PluginDescription $description = null;
    private ?string $file = null;
    private bool $enabled = false;
    private ?KernelAccessor $kernelAccessor = null;
    private ?Config $config = null;
    /** @var list<Command> commands registered by this plugin, unregistered on disable */
    private array $registeredCommands = [];

    final public function __construct() {}

    // ---- Kernel access (injected by PluginManager) ----

    final public function setKernelAccessor(KernelAccessor $accessor): void {
        $this->kernelAccessor = $accessor;
    }

    final protected function getKernel(): KernelAccessor {
        if ($this->kernelAccessor === null) {
            throw new \RuntimeException('Plugin is not initialized: kernel accessor not set');
        }
        return $this->kernelAccessor;
    }

    final protected function getWorld(): World {
        return $this->getKernel()->getWorld();
    }

    final protected function query(): \pocketmine\core\ecs\QueryBuilder {
        return $this->getWorld()->query();
    }

    final protected function registerSystem(\pocketmine\core\ecs\System $system, \pocketmine\core\ecs\SystemPhase $phase = \pocketmine\core\ecs\SystemPhase::SEQUENTIAL): void {
        $this->getKernel()->getSystemScheduler()->register($system, $phase);
    }

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

    final protected function getPermissionManager(): \pocketmine\api\permission\PermissionManager {
        return $this->getKernel()->getPermissionManager();
    }

    // ---- Lifecycle hooks ----

    public function onLoad(): void {}

    public function onEnable(): void {}

    public function onDisable(): void {}

    /**
     * Handler for commands declared in plugin.yml's "commands" section.
     * Return true to signal the command was handled.
     */
    public function onCommand(CommandSender $sender, string $label, array $args): bool {
        return false;
    }

    // ---- Metadata (from plugin.yml) ----

    final public function getName(): string {
        return $this->description?->getName() ?? get_class($this);
    }

    final public function getVersion(): string {
        return $this->description?->getVersion() ?? '1.0.0';
    }

    final public function getAuthor(): string {
        return $this->description?->getAuthor() ?? 'Unknown';
    }

    final public function getDepend(): array {
        return $this->description?->getDepend() ?? [];
    }

    final public function getSoftDepend(): array {
        return $this->description?->getSoftDepend() ?? [];
    }

    final public function getDescription(): PluginDescription {
        if ($this->description === null) {
            throw new \RuntimeException('Plugin is not initialized: description not set');
        }
        return $this->description;
    }

    final public function getFile(): string {
        return $this->file ?? '';
    }

    final public function isEnabled(): bool {
        return $this->enabled;
    }

    // ---- Internal setters (called by PluginManager) ----

    final public function setDescription(PluginDescription $description): void {
        $this->description = $description;
    }

    final public function setFile(string $file): void {
        $this->file = $file;
    }

    final public function setEnabled(bool $enabled): void {
        $this->enabled = $enabled;
    }

    // ---- Command registration (into the shared server-wide map) ----

    final protected function registerCommand(Command $command): void {
        $this->registeredCommands[] = $command;
        $this->getCommandPort()->register($command);
    }

    /**
     * Internal: called by PluginManager::disablePlugin to remove every command
     * this plugin registered from the shared map.
     */
    final public function unregisterCommands(): void {
        foreach ($this->registeredCommands as $command) {
            $this->getCommandPort()->unregister($command->getName());
        }
        $this->registeredCommands = [];
    }

    // ---- Convenience ----

    final protected function getLogger(): Logger {
        return new Logger($this->getName());
    }

    final protected function getDataFolder(): string {
        return \pocketmine\Kernel::getInstance()->getDataPath() . 'plugins/' . $this->getName() . '/';
    }

    final protected function saveConfig(): void {
        $this->getConfig()->save();
    }

    final protected function reloadConfig(): void {
        $this->getConfig()->reload();
    }

    final protected function getConfig(): Config {
        if ($this->config === null) {
            $this->config = new Config($this->getDataFolder() . 'config.yml');
        }
        return $this->config;
    }

    // ---- Event registration ----

    final protected function registerEvent(string $eventClass, callable $handler, int $priority = \pocketmine\api\event\EventPriority::NORMAL): void {
        $this->getEventPort()->subscribe($eventClass, $handler, $priority);
    }

    final protected function unregisterEvent(string $eventClass, callable $handler): void {
        $this->getEventPort()->unsubscribe($eventClass, $handler);
    }

    // ---- Scheduler access ----

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

    // ---- World access (typed WorldAccessor facade) ----

    final protected function getWorldAccessor(): WorldAccessor {
        return new WorldAccessor($this->getWorld());
    }
}
