<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\port\driven\PlayerRef;

/**
 * Phase 14.4b - player data persistence.
 *
 * A player's position, health, inventory (+ held slot) and metadata survive
 * a restart: PlayerLeaveService::savePlayer persists through the storage
 * port (per-player file in the world folder) and PlayerJoinService rebuilds
 * the entity from that snapshot on the next join - the fresh-spawn starter
 * kit is only handed to players with no save on file. Also proves: dead
 * players rejoin alive (the death screen is a session state, not a login
 * one), and cross-kernel persistence (kernel A saves, kernel B reads).
 */

function rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir() && !$f->isLink()) {
            rmdir($f->getPathname());
        } else {
            unlink($f->getPathname());
        }
    }
    rmdir($dir);
}

function player_join(\pocketmine\Kernel $kernel, string $uuid, string $name): EntityRef {
    return $kernel->getPlayerJoinService()->handleJoin(new PlayerRef($uuid, -1, $name), $name);
}

function player_save(\pocketmine\Kernel $kernel, EntityRef $ref): void {
    $kernel->getPlayerLeaveService()->savePlayer($ref);
}

function player_inventory(EntityRef $ref): ?InventoryComponent {
    return $ref->getEntity()?->get(InventoryComponent::class);
}

$worldDir = getcwd() . '/worlds/world';
rmdir_recursive($worldDir);

$uuidA = 'aaaa1111-2222-3333-4444-555555555555';

// --- 1. Same-kernel round-trip: customize -> save -> rejoin -----------------
test('a returning player restores position/health/inventory/held slot and skips the starter kit', function () use ($worldDir, $uuidA): void {
    rmdir_recursive($worldDir);
    $kernel = null;
    try {
        $kernel = \pocketmine\bootstrap();

        // Fresh join: starter kit present.
        $alice = player_join($kernel, $uuidA, 'Alice');
        $inv = player_inventory($alice);
        ok($inv !== null, 'fresh player has an inventory');
        $s0 = $inv?->get(0);
        ok($s0 !== null && $s0->itemId === 5 && $s0->count === 32, 'starter kit: 32 planks in slot 0');

        // Customize: position, health, inventory, held slot, metadata. The
        // saved spot must be a safe place to stand (the join guard only
        // restores positions whose feet + head blocks are non-solid): y=200 is
        // far above any terrain or tree, so the round-trip is deterministic.
        ok($alice->teleport(12.5, 200.0, -4.25, 30.0, 45.0), 'teleport accepted');
        $health = $alice->getEntity()?->get(HealthComponent::class);
        if ($health !== null) {
            $health->current = 12.5;
        }
        $inv?->set(0, new ItemStack(5, 0, 10));    // 10 planks (down from 32)
        $inv?->set(5, new ItemStack(266, 0, 3));   // 3 iron ingots
        $inv?->set(2, null);                        // drop the starter dirt
        ok($inv?->setHeldSlot(1), 'held slot set to 1');
        $alice->getEntity()?->get(MetadataComponent::class)?->set('keepInventory', true);

        // Persist + disconnect (despawn).
        player_save($kernel, $alice);
        $kernel->getPlayerLeaveService()->handleDisconnect(new PlayerRef($uuidA, -1, 'Alice'));
        ok(file_exists($worldDir . '/players/' . $uuidA . '.dat'), 'player data file written on leave');

        // Rejoin: everything restored, no starter kit.
        $alice2 = player_join($kernel, $uuidA, 'Alice');
        $pos = $alice2->getEntity()?->get(PositionComponent::class);
        near(12.5, $pos?->x ?? 0.0, 1e-6, 'restored x');
        near(200.0, $pos?->y ?? 0.0, 1e-6, 'restored y');
        near(-4.25, $pos?->z ?? 0.0, 1e-6, 'restored z');
        $health2 = $alice2->getEntity()?->get(HealthComponent::class);
        near(12.5, $health2?->current ?? 0.0, 1e-6, 'restored health');
        $inv2 = player_inventory($alice2);
        same(10, $inv2?->get(0)?->count, 'slot 0 is the saved 10 planks, not the 32-starter');
        same(266, $inv2?->get(5)?->itemId, 'slot 5 iron ingot restored');
        same(3, $inv2?->get(5)?->count, 'slot 5 count restored');
        ok($inv2?->get(2) === null, 'starter dirt not re-granted (slot 2 empty)');
        same(1, $inv2?->heldSlot, 'held slot restored');
        same('Alice', $alice2->getEntity()?->get(MetadataComponent::class)?->get('username'), 'username metadata restored');
        same(true, $alice2->getEntity()?->get(MetadataComponent::class)?->get('keepInventory'), 'custom metadata restored');
    } finally {
        if ($kernel !== null) {
            $kernel->shutdown();
        }
        rmdir_recursive($worldDir);
    }
});

// --- 2. Cross-kernel: saved by kernel A, read by a fresh kernel B -----------
test('cross-kernel: a player saved by kernel A is restored by a fresh kernel B boot', function () use ($worldDir, $uuidA): void {
    rmdir_recursive($worldDir);
    $kernelA = null;
    $kernelB = null;
    try {
        $kernelA = \pocketmine\bootstrap();
        $alice = player_join($kernelA, $uuidA, 'Alice');
        // y=200: high above any terrain so the safe-position join guard keeps
        // the saved spot (the round-trip must prove disk, not fresh spawn).
        $alice->teleport(20.0, 200.0, 3.5);
        player_inventory($alice)?->set(5, new ItemStack(266, 0, 7)); // iron ingots
        player_inventory($alice)?->setHeldSlot(4);
        player_save($kernelA, $alice);
        $kernelA->shutdown();
        $kernelA = null;

        // Kernel B: brand-new boot reads the same world folder.
        $kernelB = \pocketmine\bootstrap();
        $alice2 = player_join($kernelB, $uuidA, 'Alice');
        $pos = $alice2->getEntity()?->get(PositionComponent::class);
        near(20.0, $pos?->x ?? 0.0, 1e-6, 'kernel B restores x');
        near(200.0, $pos?->y ?? 0.0, 1e-6, 'kernel B restores y (far above any terrain - proves disk, not fresh spawn)');
        near(3.5, $pos?->z ?? 0.0, 1e-6, 'kernel B restores z');
        same(266, player_inventory($alice2)?->get(5)?->itemId, 'kernel B restores the iron ingots');
        same(7, player_inventory($alice2)?->get(5)?->count, 'kernel B restores the ingot count');
        same(4, player_inventory($alice2)?->heldSlot, 'kernel B restores the held slot');
    } finally {
        if ($kernelA !== null) {
            $kernelA->shutdown();
        }
        if ($kernelB !== null) {
            $kernelB->shutdown();
        }
        rmdir_recursive($worldDir);
    }
});

// --- 3. Dead players rejoin alive -------------------------------------------
test('a player who disconnected mid-death rejoins alive (health restored)', function () use ($worldDir, $uuidA): void {
    rmdir_recursive($worldDir);
    $kernel = null;
    try {
        $kernel = \pocketmine\bootstrap();
        $alice = player_join($kernel, $uuidA, 'Alice');
        $health = $alice->getEntity()?->get(HealthComponent::class);
        if ($health !== null) {
            $health->current = 0.0; // corpse state
        }
        player_save($kernel, $alice);

        $alice2 = player_join($kernel, $uuidA, 'Alice');
        $health2 = $alice2->getEntity()?->get(HealthComponent::class);
        near(20.0, $health2?->current ?? 0.0, 1e-6, 'rejoined player is at full health, not dead');
    } finally {
        if ($kernel !== null) {
            $kernel->shutdown();
        }
        rmdir_recursive($worldDir);
    }
});

// --- 4. Unknown uuid -> fresh spawn with the starter kit --------------------
test('a player with no save on file spawns fresh with the starter kit', function () use ($worldDir): void {
    rmdir_recursive($worldDir);
    $kernel = null;
    try {
        $kernel = \pocketmine\bootstrap();
        $bob = player_join($kernel, 'bbbb2222-2222-3333-4444-555555555555', 'Bob');
        $health = $bob->getEntity()?->get(HealthComponent::class);
        near(20.0, $health?->current ?? 0.0, 1e-6, 'fresh player at full health');
        $inv = player_inventory($bob);
        same(32, $inv?->get(0)?->count, 'fresh starter kit: 32 planks');
        same(58, $inv?->get(4)?->itemId, 'fresh starter kit: crafting table in slot 4');
        // The save must not exist until the player actually leaves.
        ok(!file_exists($worldDir . '/players/bbbb2222-2222-3333-4444-555555555555.dat'), 'no player file before any save');
    } finally {
        if ($kernel !== null) {
            $kernel->shutdown();
        }
        rmdir_recursive($worldDir);
    }
});

exit(runTests());
