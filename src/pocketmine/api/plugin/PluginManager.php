<?php

declare(strict_types=1);

namespace pocketmine\api\plugin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\PluginCommand;
use pocketmine\Kernel;
use pocketmine\port\driving\CommandPort;
use pocketmine\port\driving\EventPort;
use pocketmine\port\driving\PluginPort;

/**
 * Loads plugins described by a plugin.yml from a directory or a .phar archive
 * (never .jar - those are Java binaries, not PHP plugins). The main class
 * (plugin.yml "main") must extend Plugin; its source is autoloaded from the
 * archive's src/ directory following the class's namespace layout.
 */
class PluginManager implements PluginPort {
    /** @var array<string, Plugin> */
    private array $plugins = [];
    /** @var array<string, list<callable(string): void>> plugin name => registered autoloaders */
    private array $autoloaders = [];
    /** @var array<string, list<string>> plugin name => yml-declared command names */
    private array $pluginCommandNames = [];
    private ?Kernel $kernel = null;

    public function __construct(
        private readonly CommandPort $commandPort,
        private readonly EventPort $eventPort,
    ) {}

    /**
     * Injected by the Kernel once it exists (avoids a bootstrap cycle: the
     * kernel creates this manager before it is fully constructed).
     */
    public function setKernel(Kernel $kernel): void {
        $this->kernel = $kernel;
    }

    public function loadPlugin(string $path): ?Plugin {
        if ($this->kernel === null) {
            throw new \LogicException('PluginManager is not wired to a Kernel yet');
        }

        $real = realpath($path);
        if ($real === false || !file_exists($real)) {
            error_log("Plugin load failed: $path does not exist");
            return null;
        }

        // .jar files are Java binaries - never treat them as plugins.
        if (is_file($real) && strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'jar') {
            error_log("Plugin load failed: $path is a .jar (Java) archive, not a PHP plugin");
            return null;
        }

        try {
            $description = $this->loadDescription($real);
        } catch (\Throwable $e) {
            error_log("Plugin load failed for $path: " . $e->getMessage());
            return null;
        }

        if ($this->getPlugin($description->getName()) !== null) {
            error_log("Plugin '{$description->getName()}' is already loaded");
            return null;
        }

        // API compatibility: the plugin's minimum API must fit the server's.
        if (version_compare($description->getApi(), Kernel::API_VERSION, '>')) {
            error_log("Plugin '{$description->getName()}' requires API {$description->getApi()}, server has " . Kernel::API_VERSION);
            return null;
        }

        // Dependencies must already be loaded and enabled.
        foreach ($description->getDepend() as $depend) {
            if (!$this->isPluginEnabled((string)$depend)) {
                error_log("Plugin '{$description->getName()}' depends on missing plugin '{$depend}'");
                return null;
            }
        }

        // Autoload plugin classes from src/ (namespace layout mirrors the path).
        $srcBase = $this->srcBase($real);
        if ($srcBase !== null) {
            $this->registerAutoloader($description->getName(), $srcBase);
        }

        /** @var class-string $mainClass */
        $mainClass = $description->getMain();
        if (!class_exists($mainClass)) {
            error_log("Plugin '{$description->getName()}' main class {$mainClass} was not found");
            return null;
        }

        $plugin = new $mainClass();
        if (!$plugin instanceof Plugin) {
            error_log("Plugin '{$description->getName()}' main class {$mainClass} must extend " . Plugin::class);
            return null;
        }

        $plugin->setDescription($description);
        $plugin->setFile($real);
        $plugin->setKernelAccessor(new KernelAccessor($this->kernel));
        $plugin->setEnabled(false);
        $this->plugins[$description->getName()] = $plugin;

        try {
            $plugin->onLoad();
            $this->enablePlugin($plugin);
        } catch (\Throwable $e) {
            // A failing lifecycle hook must not leave a half-enabled plugin.
            error_log('Plugin \'' . $description->getName() . '\' failed to enable: ' . $e->getMessage());
            $this->disablePlugin($plugin);
            return null;
        }
        return $plugin;
    }

    public function enablePlugin(Plugin $plugin): void {
        // Register commands declared in plugin.yml, bound to the plugin's
        // onCommand() hook so metadata (permission, aliases) stays declarative.
        foreach ($plugin->getDescription()->getCommands() as $name => $meta) {
            $meta = is_array($meta) ? $meta : [];
            $command = new PluginCommand(
                (string)$name,
                $plugin,
                (string)($meta['description'] ?? ''),
                (string)($meta['usage'] ?? ''),
                array_values((array)($meta['aliases'] ?? [])),
                isset($meta['permission']) ? (string)$meta['permission'] : null,
            );
            $command->setExecutor(
                fn(CommandSender $sender, string $label, array $args): bool => $plugin->onCommand($sender, $label, $args)
            );
            $this->commandPort->register($command);
            $this->pluginCommandNames[$plugin->getName()][] = $command->getName();
        }

        $plugin->onEnable();
        $plugin->setEnabled(true);
    }

    public function disablePlugin(Plugin $plugin): void {
        if (isset($this->plugins[$plugin->getName()])) {
            // Remove the plugin's commands (yml-declared and class-based) from
            // the shared map so a disabled plugin leaves no traces.
            foreach ($this->pluginCommandNames[$plugin->getName()] ?? [] as $commandName) {
                $this->commandPort->unregister($commandName);
            }
            unset($this->pluginCommandNames[$plugin->getName()]);
            $plugin->unregisterCommands();

            // Drop the plugin's autoloaders so a disabled plugin cannot keep
            // serving classes from its archive.
            foreach ($this->autoloaders[$plugin->getName()] ?? [] as $loader) {
                spl_autoload_unregister($loader);
            }
            unset($this->autoloaders[$plugin->getName()]);

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

    public function isPluginEnabled(string $name): bool {
        $plugin = $this->getPlugin($name);
        return $plugin !== null && $plugin->isEnabled();
    }

    /**
     * Parse plugin.yml from a directory or .phar archive.
     */
    private function loadDescription(string $real): PluginDescription {
        if (is_dir($real)) {
            $content = @file_get_contents($real . DIRECTORY_SEPARATOR . 'plugin.yml');
        } else {
            $content = @file_get_contents('phar://' . $real . '/plugin.yml');
        }
        if ($content === false) {
            throw new \RuntimeException('Missing plugin.yml in ' . $real);
        }

        $data = yaml_parse($content);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid YAML in plugin.yml of ' . $real);
        }
        return PluginDescription::fromArray($data);
    }

    /**
     * Locate the plugin's source root (its src/ directory), either on disk or
     * inside a .phar archive. Returns null when there is no src/ directory.
     */
    private function srcBase(string $real): ?string {
        if (is_dir($real)) {
            $src = $real . DIRECTORY_SEPARATOR . 'src';
            return is_dir($src) ? $src : null;
        }
        $src = 'phar://' . $real . '/src';
        return is_dir($src) ? $src : null;
    }

    /**
     * PSR-0 style autoloader for the plugin's src/ directory: a class
     * Khronos\DemoPlugin\Main is loaded from src/Khronos/DemoPlugin/Main.php.
     */
    private function registerAutoloader(string $pluginName, string $srcBase): void {
        $loader = function (string $class) use ($srcBase): void {
            $file = $srcBase . '/' . str_replace('\\', '/', $class) . '.php';
            if (is_file($file)) {
                require $file;
            }
        };
        spl_autoload_register($loader);
        $this->autoloaders[$pluginName][] = $loader;
    }
}
