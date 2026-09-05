<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\PlayerCommandSender;
use pocketmine\api\entity\Player;
use pocketmine\api\permission\Permission;
use pocketmine\api\permission\PermissionManager;
use pocketmine\api\plugin\Plugin;
use pocketmine\api\server\Server;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\Kernel;

/**
 * Phase 13.2: permissions wiring - plugin.yml "permissions:" sections are
 * registered into the server-wide PermissionManager on enable, resolve via
 * defaults (op/notop/true), children, explicit grants and op, and gate
 * commands. The demo plugin declares:
 *
 *   permissions:
 *     demo.admin:
 *       description: Admin access
 *       default: op
 *     demo.see:
 *       description: Everyone can see
 *       default: true
 *     demo.child.parent:
 *       default: op
 *       children:
 *         demo.child.granted: true
 */

$kernel = \pocketmine\bootstrap();
$manager = $kernel->getPluginManager();
$permissionManager = $kernel->getPermissionManager();
$commandPort = $kernel->getCommandPort();
$world = $kernel->getWorld();

/** Sender whose permissions are fixed - simulates console/plugin contexts. */
final class PermSender implements CommandSender {
    public array $messages = [];

    public function __construct(
        private readonly array $perms,
        private readonly bool $isPlayer = false,
        private readonly ?Player $player = null,
    ) {}

    public function sendMessage(string $message): void {
        $this->messages[] = $message;
    }

    public function hasPermission(string $permission): bool {
        return in_array($permission, $this->perms, true) || in_array('*', $this->perms, true);
    }

    public function getName(): string {
        return 'PermSender';
    }

    public function isPlayer(): bool {
        return $this->isPlayer;
    }

    public function getPlayer(): ?Player {
        return $this->player;
    }
}

function writeFixture(string $path, string $content): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $content);
}

/** Spawn a fresh player entity and return its api facade. */
function spawnPlayer(string $username, string $uuid): Player {
    $kernel = Kernel::getInstance();
    $metadata = new MetadataComponent();
    $metadata->set('username', $username);
    $metadata->set('uniqueId', $uuid);
    $kernel->getWorld()->spawn(
        (new EntityBuilder())
            ->at(0, 65, 0)
            ->with(new HealthComponent(20, 20))
            ->with($metadata)
            ->withTag(PlayerTag::class)
    );
    $player = Server::getInstance()->getPlayer($username);
    if ($player === null) {
        throw new RuntimeException("failed to spawn player $username");
    }
    return $player;
}

$tmp = sys_get_temp_dir() . '/khronos_perm_test_' . getmypid();
$permDir = $tmp . '/permplugin';

writeFixture($permDir . '/plugin.yml', <<<'YAML'
name: PermPlugin
version: 1.0.0
author: Khronos
main: Khronos\PermPlugin\Main
api: 2.0.0
commands:
  admindemo:
    description: Admin only
    permission: demo.admin
permissions:
  demo.admin:
    description: Admin access
    default: op
  demo.see:
    description: Everyone can see
    default: true
  demo.child.parent:
    description: Parent of a granted child
    default: op
    children:
      demo.child.granted: true
YAML);

writeFixture($permDir . '/src/Khronos/PermPlugin/Main.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Khronos\PermPlugin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\plugin\Plugin;

class Main extends Plugin {
    public function onCommand(CommandSender $sender, string $label, array $args): bool {
        $sender->sendMessage('admin command ran');
        return true;
    }
}
PHP);

$plugin = $manager->loadPlugin($permDir);
if ($plugin === null) {
    throw new RuntimeException('PermPlugin failed to load');
}

test('plugin.yml permissions are registered into the server-wide manager', function () use ($permissionManager) {
    $admin = $permissionManager->getPermission('demo.admin');
    ok($admin !== null, 'demo.admin registered');
    same(Permission::DEFAULT_OP, $admin->getDefault(), 'demo.admin default is op');
    same('Admin access', $admin->getDescription(), 'description parsed');

    $see = $permissionManager->getPermission('demo.see');
    ok($see !== null, 'demo.see registered');
    same(Permission::DEFAULT_TRUE, $see->getDefault(), 'demo.see default is true');

    $parent = $permissionManager->getPermission('demo.child.parent');
    ok($parent !== null, 'demo.child.parent registered');
    ok(isset($parent->getChildren()['demo.child.granted']), 'child permission parsed from children map');
});

test('kernel and registry expose the SAME manager instance', function () use ($kernel, $permissionManager) {
    ok($kernel->getResourceRegistry()->get(PermissionManager::class) === $permissionManager,
        'resource registry returns the kernel permission manager');
});

test('default permissions resolve by op status', function () use ($world) {
    $alice = spawnPlayer('PermAlice', 'aaaaaaaa-0000-0000-0000-000000000001');
    $bob = spawnPlayer('PermBob', 'bbbbbbbb-0000-0000-0000-000000000002');

    // Neither is op yet.
    ok(!$alice->isOp(), 'alice starts as non-op');
    ok(!$alice->hasPermission('demo.admin'), 'op-default permission denied to non-op');
    ok($alice->hasPermission('demo.see'), 'true-default permission granted to everyone');
    ok(!$alice->hasPermission('demo.nonexistent'), 'unknown permission denied');

    // Make Bob an op.
    $bob->setOp(true);
    ok($bob->isOp(), 'bob is op after setOp');
    ok($bob->hasPermission('demo.admin'), 'op-default permission granted to op');

    // Explicit grants bypass defaults.
    $alice->getMetadata()->set('permissions', ['demo.explicit']);
    ok($alice->hasPermission('demo.explicit'), 'explicit grant resolves');
    ok($bob->hasPermission('demo.explicit'), 'op players are granted every permission');
    ok(!$alice->hasPermission('demo.admin'), 'op-default permission still denied to non-op');

    // Children: granting the parent grants the child.
    $carol = spawnPlayer('PermCarol', 'cccccccc-0000-0000-0000-000000000003');
    $carol->setOp(true);
    ok($carol->hasPermission('demo.child.granted'), 'child permission inherited from granted parent');
    ok(!$alice->hasPermission('demo.child.granted'), 'child permission not granted without the parent');
});

test('op check uses the same metadata the manager reads', function () use ($world) {
    $dave = spawnPlayer('PermDave', 'dddddddd-0000-0000-0000-000000000004');
    $dave->setOp(true);
    ok($dave->hasPermission('demo.admin'), 'op player has op-default permissions');
    $dave->setOp(false);
    ok(!$dave->hasPermission('demo.admin'), 'de-op removes op-default permissions');
});

test('commands gate on plugin.yml permissions through the player', function () use ($commandPort, $world) {
    // A plain (non-op, no grants) player must be denied.
    $eve = spawnPlayer('PermEve', 'eeeeeeee-0000-0000-0000-000000000005');
    $eveSender = new PlayerCommandSender($eve);
    ok(!$commandPort->execute($eveSender, 'admindemo'), 'command denied without the permission');

    // Granting the permission through metadata unlocks it (the sender
    // delegates hasPermission to the player facade, which resolves via the
    // server-wide manager).
    $eve->getMetadata()->set('permissions', ['demo.admin']);
    ok($eve->hasPermission('demo.admin'), 'explicit grant unlocks the permission');
    $grantedSender = new PlayerCommandSender($eve);
    ok($commandPort->execute($grantedSender, 'admindemo'), 'command runs with the permission');

    // A console-style sender with '*' bypasses.
    $console = new PermSender(['*']);
    ok($commandPort->execute($console, 'admindemo'), 'wildcard sender bypasses the gate');
});

test('disablePlugin removes the plugin permissions from the manager', function () use ($manager, $permissionManager, $plugin) {
    ok($permissionManager->getPermission('demo.admin') !== null, 'permission present while enabled');
    $manager->disablePlugin($plugin);
    ok($permissionManager->getPermission('demo.admin') === null, 'permission removed on disable');
    ok($permissionManager->getPermission('demo.see') === null, 'second permission removed on disable');
    ok($permissionManager->getPermission('demo.child.parent') === null, 'child parent removed on disable');
});

test('PermissionManager::fromYaml handles malformed entries gracefully', function () {
    $perm = PermissionManager::fromYaml('broken.perm', ['default' => 'bogus', 'children' => ['x' => 'not-bool']]);
    same(Permission::DEFAULT_NOT_OP, $perm->getDefault(), 'bad default falls back to notop');
    same([], $perm->getChildren(), 'non-true children are dropped');

    $truePerm = PermissionManager::fromYaml('true.perm', ['default' => true]);
    same(Permission::DEFAULT_TRUE, $truePerm->getDefault(), 'boolean true default parsed');
});

// Cleanup: the spawned player entities keep the world non-empty, but that is
// harmless here (no pipeline enabled). Nothing else to tear down - the kernel
// is left for the runner's process isolation.
exit(runTests());
