<?php

declare(strict_types=1);

namespace pocketmine\plugin\api;

use pocketmine\plugin\api\command\CommandExecutor;
use pocketmine\plugin\api\command\CommandMap;
use pocketmine\plugin\api\command\ConsoleCommandSender;
use pocketmine\plugin\api\event\EventBus;
use pocketmine\plugin\api\permission\PermissionManager;
use pocketmine\plugin\api\scheduler\Scheduler;
use pocketmine\plugin\api\world\WorldAccessor;
use pocketmine\port\driving\PluginPort;
use pocketmine\Kernel;

final class PluginManager implements PluginPort {
    private array $plugins = [];

    public function __construct(
        private readonly Kernel $kernel,
    ) {}

    public function loadPlugin(string $path): ?Plugin {
        // In a real implementation, this would load a .phar or directory
        // For now, return null
        return null;
    }

    public function enablePlugin(Plugin $plugin): void {
        $this->plugins[$plugin->getName()] = $plugin;
        
        // Set up kernel accessor
        $kernelAccessor = new KernelAccessor($this->kernel);
        $plugin->setKernelAccessor($kernelAccessor);
        
        // Register commands
        $commandExecutor = new CommandExecutor(
            new CommandMap(),
            new EventBus()
        );
        
        // Register events
        $eventBus = new EventBus();
        
        // Register scheduler
        $scheduler = new Scheduler(
            $this->kernel->getWorld(),
            $this->kernel->getThreadingPort()
        );
        
        // Register permissions
        $permissionManager = new PermissionManager();
        
        // Register world accessor
        $worldAccessor = new WorldAccessor($this->kernel->getWorld());
        
        // Call onEnable
        $plugin->onEnable();
        $plugin->setEnabled(true);
    }

    public function disablePlugin(Plugin $plugin): void {
        if (isset($this->plugins[$plugin->getName()])) {
            $plugin->onDisable();
            $plugin->setEnabled(false);
            unset($this->plugins[$plugin->getName()]);
        }
    }

    public function getPlugin(string $name): ?Plugin {
        return $this->plugins[$name] ?? null;
    }

    public function getPlugins(): array {
        return array_values($this->plugins);
    }
}