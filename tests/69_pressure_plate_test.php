<?php

declare(strict_types=1);

/**
 * Pressure plate tests (legacy PressurePlate::onEntityCollide parity):
 *  - a living entity standing on a plate presses it (meta 0x08)
 *  - the plate unpresses after the grace period once the entity leaves
 *  - dead entities do not press plates
 */

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\AIStateComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\WorldComponent;
use pocketmine\core\constants\BlockIds;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\TickCounter;
use pocketmine\core\system\PressurePlateSystem;

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$store = $kernel->getResourceRegistry()->get(ChunkStore::class);
ok($store instanceof ChunkStore, 'chunk store');
$kernel->getChunkLoadService()->loadChunk(6, 6);

// Plate at (102, 64, 102); entity stands at y=65 (feet on the plate).
$store->setBlock(102, 64, 102, BlockIds::STONE_PRESSURE_PLATE, 0);
$ticks = $kernel->getResourceRegistry()->get(TickCounter::class);
ok($ticks instanceof TickCounter, 'tick counter');

/** Advance the global tick counter by n (the system's grace clock). */
function advanceTicks(TickCounter $ticks, int $n): void {
    $ticks->value = $ticks->value + $n;
}

function runPlates(\pocketmine\core\ecs\World $world): void {
    (new PressurePlateSystem())->run($world, 1 / 20);
}

/** A live test entity at the given feet position. */
function makeLiving(\pocketmine\core\ecs\World $world, float $x, float $y, float $z): \pocketmine\core\ecs\EntityRef {
    return $world->spawn(
        (new \pocketmine\core\ecs\EntityBuilder())
            ->with(new PositionComponent($x, $y, $z))
            ->with(new RotationComponent())
            ->with(new VelocityComponent())
            ->with(new HealthComponent())
            ->with(new MetadataComponent())
            ->with(new WorldComponent(0))
            ->with(new AIStateComponent())
            ->with(new InventoryComponent()),
    );
}

test('standing on a plate presses it', function () use ($world, $store): void {
    $entity = makeLiving($world, 102.5, 65.0, 102.5);
    runPlates($world);
    same(BlockIds::STONE_PRESSURE_PLATE, $store->getBlock(102, 64, 102), 'plate still stone plate');
    same(0x08, $store->getBlockMeta(102, 64, 102) & 0x08, 'meta pressed bit set');
});

test('plate unpresses after the grace period once the entity leaves', function () use ($world, $store, $ticks): void {
    // Fresh plate: earlier tests' entities still stand on the previous one.
    $store->setBlock(103, 64, 103, BlockIds::STONE_PRESSURE_PLATE, 0);
    $entity = makeLiving($world, 103.5, 65.0, 103.5);
    advanceTicks($ticks, 5);
    runPlates($world); // press
    same(0x08, $store->getBlockMeta(103, 64, 103) & 0x08, 'pressed');

    // Walk away: no entity on the plate any more.
    $pos = $entity->getEntity()?->get(PositionComponent::class);
    $pos->x = 110.5;
    $pos->z = 110.5;

    // Within the grace window: still pressed.
    advanceTicks($ticks, 5);
    runPlates($world);
    same(0x08, $store->getBlockMeta(103, 64, 103) & 0x08, 'still pressed inside grace window');

    // Past the grace window (stone: 20 ticks): unpressed.
    advanceTicks($ticks, 25);
    runPlates($world);
    same(0, $store->getBlockMeta(103, 64, 103) & 0x08, 'unpressed after grace');
});

test('dead entities do not press plates', function () use ($world, $store): void {
    // Move the plate test to a fresh coordinate.
    $store->setBlock(104, 64, 104, BlockIds::WOODEN_PRESSURE_PLATE, 0);
    $entity = makeLiving($world, 104.5, 65.0, 104.5);
    $health = $entity->getEntity()?->get(HealthComponent::class);
    if ($health !== null) {
        $health->current = 0;
    }
    $dead = $entity->getEntity();
    if ($dead !== null) {
        $dead->set(\pocketmine\core\component\tags\DeadTag::class, new \pocketmine\core\component\tags\DeadTag());
    }

    runPlates($world);
    same(0, $store->getBlockMeta(104, 64, 104) & 0x08, 'dead entity does not press');
});

test('block registry declares plates', function () use ($kernel): void {
    $blocks = $kernel->getResourceRegistry()->get(BlockRegistry::class);
    ok($blocks instanceof BlockRegistry, 'block registry available');
    // Sanity: plate ids are non-solid blocks the client renders flat.
    foreach ([BlockIds::STONE_PRESSURE_PLATE, BlockIds::WOODEN_PRESSURE_PLATE] as $id) {
        ok(!$blocks->isSolid($id), "plate $id is non-solid");
    }
});

exit(runTests());
