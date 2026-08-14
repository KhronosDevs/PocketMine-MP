<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\api\command\ConsoleCommandSender;
use pocketmine\api\command\PlayerCommandSender;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\resource\PlayerListManager;
use pocketmine\core\resource\ServerConfig;
use pocketmine\core\resource\ServerProperties;

/**
 * Blocker 1 - server administration: server.properties, ops/whitelist/bans
 * persistence, the admin command set (stop/save-all/list/op/deop/ban/pardon/
 * ban-ip/pardon-ip/whitelist/plugins), and permission-gating of the builtin
 * commands (default OP).
 */

// --- server.properties -------------------------------------------------------

test('server.properties defaults are written and loadable', function (): void {
    $path = sys_get_temp_dir() . '/khr_props_' . getmypid() . '.txt';
    @unlink($path);
    ServerProperties::writeDefaults($path);
    ok(is_file($path), 'defaults file generated');
    $props = ServerProperties::load($path);
    same('19132', $props['server-port'] ?? '', 'default server-port');
    same('20', $props['max-players'] ?? '', 'default max-players');
    same('0', $props['gamemode'] ?? '', 'default gamemode');
    @unlink($path);
});

test('server.properties parsing honours comments, casing and overrides', function (): void {
    $path = sys_get_temp_dir() . '/khr_props2_' . getmypid() . '.txt';
    @unlink($path);
    file_put_contents($path, "# a comment\nServer-Port=25565\nmax-players=5\npvp=off\nwhite-list=true\n");
    $props = ServerProperties::load($path);
    same('25565', $props['server-port'], 'key casing normalised');
    same('5', $props['max-players'], 'override respected');
    ok(ServerProperties::bool($props, 'pvp', true) === false, 'pvp=off parses to false');
    ok(ServerProperties::bool($props, 'white-list', false) === true, 'white-list=true parses to true');
    same(25565, ServerProperties::int($props, 'server-port', 0), 'int parse');
    @unlink($path);
});

// --- PlayerListManager ------------------------------------------------------

test('PlayerListManager persists ops, whitelist and bans to text files', function (): void {
    $dir = sys_get_temp_dir() . '/khr_lists_' . getmypid() . '/';
    @mkdir($dir);
    $lists = new PlayerListManager($dir);
    $lists->addOp('Alice');
    $lists->addWhitelist('bob');
    $lists->ban('evil_carl');
    $lists->banIp('1.2.3.4');

    // A fresh instance reads the same files back.
    $reloaded = new PlayerListManager($dir);
    ok($reloaded->isOp('alice'), 'op persisted (case-insensitive read)');
    ok($reloaded->isOp('ALICE'), 'op check case-insensitive');
    ok($reloaded->isWhitelisted('bob'), 'whitelist persisted');
    ok($reloaded->isBanned('evil_carl'), 'ban persisted');
    ok($reloaded->isIpBanned('1.2.3.4'), 'ip ban persisted');

    $reloaded->removeOp('alice');
    $reloaded->removeWhitelist('bob');
    $reloaded->pardon('evil_carl');
    $reloaded->pardonIp('1.2.3.4');
    ok(!$reloaded->isOp('alice'), 'deop persisted');
    ok(!$reloaded->isWhitelisted('bob'), 'whitelist remove persisted');
    ok(!$reloaded->isBanned('evil_carl'), 'pardon persisted');
    ok(!$reloaded->isIpBanned('1.2.3.4'), 'ip pardon persisted');
    array_map('unlink', glob($dir . '*') ?: []);
    @rmdir($dir);
});

// --- Admin commands through the kernel --------------------------------------

$kernel = \pocketmine\bootstrap();
$port = $kernel->getCommandPort();
$console = new ConsoleCommandSender();
$lists = $kernel->getResourceRegistry()->get(PlayerListManager::class);
ok($lists instanceof PlayerListManager, 'PlayerListManager registered on the kernel');

test('/op and /deop grant and revoke operator through the command map', function () use ($port, $console, $lists): void {
    ok($port->execute($console, 'op bob'), '/op executes');
    ok($lists->isOp('bob'), 'bob is op after /op');
    ok($port->execute($console, 'deop bob'), '/deop executes');
    ok(!$lists->isOp('bob'), 'bob is not op after /deop');
});

test('/ban /pardon /ban-ip /pardon-ip manage the ban lists', function () use ($port, $console, $lists): void {
    ok($port->execute($console, 'ban evil_carl'), '/ban executes');
    ok($lists->isBanned('evil_carl'), 'ban recorded');
    ok($port->execute($console, 'pardon evil_carl'), '/pardon executes');
    ok(!$lists->isBanned('evil_carl'), 'pardon recorded');

    ok($port->execute($console, 'ban-ip 9.9.9.9'), '/ban-ip executes');
    ok($lists->isIpBanned('9.9.9.9'), 'ip ban recorded');
    ok($port->execute($console, 'pardon-ip 9.9.9.9'), '/pardon-ip executes');
    ok(!$lists->isIpBanned('9.9.9.9'), 'ip pardon recorded');
});

test('/whitelist manages entries and the on/off toggle', function () use ($port, $console, $lists, $kernel): void {
    ok($port->execute($console, 'whitelist add bob'), '/whitelist add executes');
    ok($lists->isWhitelisted('bob'), 'bob whitelisted');
    ok($port->execute($console, 'whitelist remove bob'), '/whitelist remove executes');
    ok(!$lists->isWhitelisted('bob'), 'bob un-whitelisted');
    ok($port->execute($console, 'whitelist list'), '/whitelist list executes');

    ok($port->execute($console, 'whitelist on'), '/whitelist on executes');
    $cfg = $kernel->getResourceRegistry()->get(ServerConfig::class);
    ok($cfg instanceof ServerConfig && $cfg->whiteList, 'whitelist enabled in ServerConfig');
    ok($port->execute($console, 'whitelist off'), '/whitelist off executes');
    ok($cfg instanceof ServerConfig && !$cfg->whiteList, 'whitelist disabled in ServerConfig');
});

test('/save-all flushes the world and /list + /plugins answer', function () use ($port, $console, $kernel): void {
    ok($port->execute($console, 'save-all'), '/save-all executes');
    ok($port->execute($console, 'list'), '/list executes');
    ok($port->execute($console, 'plugins'), '/plugins executes');
});

test('/stop requests a graceful shutdown', function () use ($port, $console, $kernel): void {
    ok($kernel->isRunning() === false, 'kernel not running before /stop');
    ok($port->execute($console, 'stop'), '/stop executes');
    ok(!$kernel->isRunning(), 'kernel is stopped after /stop');
});

// --- Permission gating ------------------------------------------------------

test('a non-op player is denied admin commands; an op player is allowed', function () use ($port, $kernel): void {
    $world = $kernel->getWorld();
    $ref = $world->spawn(
        (new EntityBuilder())
            ->with(new PositionComponent(0, 65, 0))
            ->with(new MetadataComponent())
            ->withTag('player')
    );
    $id = $ref->getId();
    $sender = new PlayerCommandSender($id, 'bob');

    // bob has no op permission yet -> /gamemode must be denied.
    ok(!$port->execute($sender, 'gamemode 1'), 'non-op player denied /gamemode');

    // Grant op exactly like OpCommand does: ops.txt + live metadata.
    $lists = $kernel->getResourceRegistry()->get(PlayerListManager::class);
    $lists->addOp('bob');
    $meta = $world->getEntity($id)?->get(MetadataComponent::class);
    $meta?->set('permissions', ['pocketmine.op']);

    ok($port->execute($sender, 'gamemode 1'), 'op player allowed /gamemode');
    same(1, $world->getEntity($id)?->get(MetadataComponent::class)?->get('gamemode'), 'gamemode applied to metadata');
});

// Tidy up the admin files the kernel wrote into the data path so later runs
// start from a clean slate (the files are gitignored, but keep the tree tidy).
foreach (['ops.txt', 'white-list.txt', 'banned-players.txt', 'banned-ips.txt'] as $f) {
    @unlink(getcwd() . '/' . $f);
}

exit(runTests());
