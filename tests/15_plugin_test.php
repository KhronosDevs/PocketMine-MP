<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\api\command\CommandSender;
use pocketmine\api\entity\Player;
use pocketmine\api\event\PlayerCommandPreprocessEvent;
use pocketmine\api\plugin\Plugin;
use pocketmine\api\server\Server;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\Kernel;

/**
 * Phase 12.4: plugins (plugin.yml + base class, loaded from directories and
 * .phar archives - never .jar) and one unified server-wide command map.
 */

$kernel = \pocketmine\bootstrap();
$manager = $kernel->getPluginManager();
$commandPort = $kernel->getCommandPort();
$world = $kernel->getWorld();

/** Captures sent messages instead of printing them. */
final class CapturingSender implements CommandSender {
    public array $messages = [];

    public function sendMessage(string $message): void {
        $this->messages[] = $message;
    }

    public function hasPermission(string $permission): bool {
        return true;
    }

    public function getName(): string {
        return 'TestSender';
    }

    public function isPlayer(): bool {
        return false;
    }

    public function getPlayer(): ?Player {
        return null;
    }
}

function writeFixture(string $path, string $content): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $content);
}

$tmp = sys_get_temp_dir() . '/khronos_plugin_test_' . getmypid();

$demoDir = __DIR__ . '/fixtures/demo-plugin';
$pharFile = __DIR__ . '/fixtures/demo-plugin.phar';

test('directory plugin loads from plugin.yml (metadata, lifecycle, autoload)', function () use ($manager, $demoDir) {
    $plugin = $manager->loadPlugin($demoDir);
    ok($plugin !== null, 'directory plugin loads');
    ok($plugin instanceof Plugin, 'main class extends Plugin');
    same('Demo', $plugin->getName(), 'name from plugin.yml');
    same('1.0.0', $plugin->getVersion(), 'version from plugin.yml');
    same('Khronos', $plugin->getAuthor(), 'author from plugin.yml');
    same('Khronos\\DemoPlugin\\Main', $plugin->getDescription()->getMain(), 'main class parsed from plugin.yml');
    ok($plugin->loaded && $plugin->enabled, 'onLoad and onEnable hooks ran');
    ok($plugin->isEnabled(), 'plugin flagged enabled');
    ok($manager->isPluginEnabled('Demo'), 'PluginManager reports it enabled');
    ok($manager->getPlugin('Demo') === $plugin, 'getPlugin returns the same instance');
});

test('one stable PluginManager is shared by kernel, port and Server', function () use ($kernel, $manager) {
    ok($kernel->getPluginManager() === $manager, 'kernel.getPluginManager is the plugin port instance');
    ok($kernel->getPluginPort() === $manager, 'plugin port is the api PluginManager');
    ok(Server::getInstance()->getPluginManager() === $manager, 'Server facade returns the stable manager');
    same('2.0.0', Kernel::API_VERSION, 'API_VERSION constant matches plugin.yml api contract');
});

test('plugin.yml commands register into the unified map and dispatch via onCommand', function () use ($commandPort, $manager) {
    ok($manager->getPlugin('Demo') !== null, 'Demo plugin is loaded');
    $command = $commandPort->getCommand('demo');
    ok($command !== null, 'yml command "demo" registered in the kernel command map');
    same('demo', $command->getName(), 'registered under its name');
    ok($commandPort->getCommand('d') !== null, 'alias "d" resolves to the same command');
    ok(Server::getInstance()->getCommand('demo') !== null, 'Server facade sees the same command');

    $sender = new CapturingSender();
    ok($commandPort->execute($sender, 'demo'), 'command executes through the port');
    same('Demo says hi', $sender->messages[0] ?? null, 'onCommand hook received the dispatch');

    $aliasSender = new CapturingSender();
    ok($commandPort->execute($aliasSender, 'd'), 'alias dispatches too');
    same('Demo says hi', $aliasSender->messages[0] ?? null, 'alias routes to the same onCommand');
});

test('class-based commands registered in onEnable join the unified map', function () use ($commandPort, $kernel) {
    $command = $commandPort->getCommand('demoecho');
    ok($command !== null, 'class command "demoecho" from onEnable is registered');
    same('demoecho', $command->getName(), 'name matches');
    ok($kernel->getCommandPort()->getCommand('de') !== null, 'alias "de" registered');

    $sender = new CapturingSender();
    ok($commandPort->execute($sender, 'demoecho hi there'), 'class command executes');
    same('echo: hi there', $sender->messages[0] ?? null, 'class command executed with args');
    same(1, count($sender->messages), 'single message');

    $serverSender = new CapturingSender();
    ok(Server::getInstance()->dispatchCommand('demoecho via server', $serverSender), 'Server::dispatchCommand runs the command');
    same('echo: via server', $serverSender->messages[0] ?? null, 'Server facade reaches the same map');
});

test('duplicate plugin names are rejected', function () use ($manager, $demoDir) {
    ok($manager->loadPlugin($demoDir) === null, 'second load of an already loaded plugin name returns null');
    same(1, count($manager->getPlugins()), 'only one instance kept');
});

test('missing plugin.yml is rejected', function () use ($manager, $tmp) {
    $dir = $tmp . '/noyml';
    writeFixture($dir . '/readme.txt', 'not a plugin');
    ok($manager->loadPlugin($dir) === null, 'directory without plugin.yml loads nothing');
});

test('.jar archives are rejected (Java binaries are not PHP plugins)', function () use ($manager, $tmp) {
    $jar = $tmp . '/evil.jar';
    writeFixture($jar, 'PK' . "\x03\x04" . 'not really a zip but a jar-ish name');
    ok($manager->loadPlugin($jar) === null, '.jar is never scanned as a plugin');
});

test('missing main class is rejected', function () use ($manager, $tmp) {
    $dir = $tmp . '/nomain';
    writeFixture($dir . '/plugin.yml', "name: NoMain\nversion: 1.0.0\nmain: Missing\\Plugin\\Main\n");
    ok($manager->loadPlugin($dir) === null, 'plugin.yml pointing at a missing class loads nothing');
});

test('a main class that does not extend Plugin is rejected', function () use ($manager, $tmp) {
    $dir = $tmp . '/notplugin';
    writeFixture($dir . '/plugin.yml', "name: NotPlugin\nversion: 1.0.0\nmain: Khronos\\NotAPlugin\\Main\n");
    writeFixture($dir . '/src/Khronos/NotAPlugin/Main.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Khronos\NotAPlugin;

class Main {
}
PHP);
    ok($manager->loadPlugin($dir) === null, 'main class must extend Plugin');
});

test('api version mismatch is rejected', function () use ($manager, $tmp) {
    $dir = $tmp . '/toonew';
    writeFixture($dir . '/plugin.yml', "name: TooNew\nversion: 1.0.0\nmain: Khronos\\TooNew\\Main\napi: 3.0.0\n");
    ok($manager->loadPlugin($dir) === null, 'plugin requiring a newer API is rejected');
});

test('missing dependencies block loading, satisfied dependencies load', function () use ($manager, $tmp) {
    $libDir = $tmp . '/lib';
    writeFixture($libDir . '/plugin.yml', "name: Lib\nversion: 1.0.0\nmain: Khronos\\LibPlugin\\Main\n");
    writeFixture($libDir . '/src/Khronos/LibPlugin/Main.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Khronos\LibPlugin;

use pocketmine\api\plugin\Plugin;

class Main extends Plugin {
}
PHP);

    $needsDir = $tmp . '/needslib';
    writeFixture($needsDir . '/plugin.yml', "name: NeedsLib\nversion: 1.0.0\nmain: Khronos\\NeedsLib\\Main\ndepend: [Lib]\n");
    writeFixture($needsDir . '/src/Khronos/NeedsLib/Main.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Khronos\NeedsLib;

use pocketmine\api\plugin\Plugin;

class Main extends Plugin {
}
PHP);

    ok($manager->loadPlugin($needsDir) === null, 'dependent plugin is rejected while Lib is missing');
    ok($manager->loadPlugin($libDir) !== null, 'Lib loads');
    ok($manager->loadPlugin($needsDir) !== null, 'dependent plugin loads once Lib is present');
});

test('phar plugins load, autoload from the archive, and dispatch', function () use ($manager, $commandPort, $pharFile) {
    $plugin = $manager->loadPlugin($pharFile);
    ok($plugin !== null, 'phar plugin loads');
    same('PharDemo', $plugin->getName(), 'phar plugin.yml name');
    ok($plugin->loaded && $plugin->enabled, 'phar plugin lifecycle hooks ran');
    ok($plugin instanceof Plugin, 'phar main class extends Plugin');

    $sender = new CapturingSender();
    ok($commandPort->execute($sender, 'pdemo'), 'phar yml command executes');
    same('PharDemo says hi', $sender->messages[0] ?? null, 'phar onCommand hook works');

    $classSender = new CapturingSender();
    ok($commandPort->execute($classSender, 'phello'), 'phar class command executes');
    same('phello!', $classSender->messages[0] ?? null, 'phar class command works');
});

test('player command preprocess event fires and can cancel dispatch', function () use ($kernel, $commandPort, $world) {
    // Spawn an online player with a username so Server can resolve it.
    $metadata = new MetadataComponent();
    $metadata->set('username', 'Steve');
    $world->spawn(
        (new EntityBuilder())
            ->at(0, 65, 0)
            ->with(new HealthComponent(20, 20))
            ->with($metadata)
            ->withTag(PlayerTag::class)
    );
    $player = Server::getInstance()->getPlayer('Steve');
    ok($player !== null, 'player resolvable by name');

    $eventPort = $kernel->getEventPort();

    $fired = false;
    $observer = function (PlayerCommandPreprocessEvent $event) use (&$fired): void {
        $fired = true;
    };
    $eventPort->subscribe(PlayerCommandPreprocessEvent::class, $observer);
    ok(Server::getInstance()->dispatchCommand('demo', 'Steve'), 'player dispatch runs through the preprocess event');
    ok($fired, 'preprocess event was emitted for the player dispatch');
    $eventPort->unsubscribe(PlayerCommandPreprocessEvent::class, $observer);

    $cancelled = false;
    $blocker = function (PlayerCommandPreprocessEvent $event) use (&$cancelled): void {
        $cancelled = true;
        $event->setCancelled(true);
    };
    $eventPort->subscribe(PlayerCommandPreprocessEvent::class, $blocker);
    ok(!Server::getInstance()->dispatchCommand('demo', 'Steve'), 'cancelled preprocess blocks the command');
    ok($cancelled, 'blocker ran');
    $eventPort->unsubscribe(PlayerCommandPreprocessEvent::class, $blocker);
});

test('disablePlugin removes the plugin and frees the name', function () use ($manager, $demoDir, $commandPort) {
    $plugin = $manager->getPlugin('Demo');
    ok($plugin !== null, 'Demo loaded');
    $manager->disablePlugin($plugin);
    ok(!$plugin->isEnabled(), 'plugin disabled after disablePlugin');
    ok($manager->getPlugin('Demo') === null, 'name freed from the registry');
    ok($commandPort->getCommand('demo') === null, 'yml commands removed with the plugin');

    ok($manager->loadPlugin($demoDir) !== null, 'same name can be loaded again after disable');
    ok($commandPort->getCommand('demo') !== null, 'commands re-registered on reload');
});

exit(runTests());
