<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\constants\BlockIds;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;

/**
 * EnvironmentalDamageSystem: void, lava, fire + burning, suffocation,
 * drowning - old-src Entity/Living entityBaseTick and block
 * onEntityCollide parity. All sources must route through the combat
 * pipeline (EntityDamageEvent fires) so death handling works.
 */

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$store = $world->getResourceRegistry()->get(ChunkStore::class);
$registry = $world->getResourceRegistry()->get(BlockRegistry::class);

function mkPlayer($world, float $x, float $y, float $z) {
    return $world->spawn(
        (new EntityBuilder())
            ->at($x, $y, $z)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['entityType' => 'Player', 'gamemode' => 0]))
            ->withTag(PlayerTag::class)
    );
}

// Force-load one chunk and place all fixtures inside it (blocks x/z 0..15).
$store = $world->getResourceRegistry()->get(ChunkStore::class);
if (!$store instanceof ChunkStore) {
    throw new RuntimeException('no chunk store');
}
$kernel->getChunkLoadService()->loadChunk(0, 0);
$kernel->run(1);

$fired = [];
$kernel->getEventPort()->subscribe(\pocketmine\api\event\EntityDamageEvent::class, function ($e) use (&$fired) {
    $fired[$e->getCause()] = ($fired[$e->getCause()] ?? 0) + 1;
});

function placeBlock($store, $registry, int $x, int $y, int $z, int $id): void {
    if (!$store instanceof ChunkStore || !$store->setBlock($x, $y, $z, $id)) {
        throw new RuntimeException("setBlock failed at $x,$y,$z (chunk loaded?)");
    }
}

test('void: y <= -16 applies CAUSE_VOID damage', function () use ($world, $kernel, &$fired, $store, $registry): void {
    $v = mkPlayer($world, 12, -20.0, 12);
    $kernel->run(10);
    $hp = $world->getEntity($v->getId())?->get(HealthComponent::class)?->current ?? 0.0;
    ok($hp < 20.0, "void victim took damage ($hp)");
    ok(($fired[\pocketmine\api\event\EntityDamageEvent::CAUSE_VOID] ?? 0) > 0, 'CAUSE_VOID fired through the combat pipeline');
    $world->despawn($world->getEntity($v->getId()));
});

test('lava contact damages and ignites', function () use ($world, $kernel, &$fired, $store, $registry): void {
    placeBlock($store, $registry, 2, 65, 2, BlockIds::LAVA);
    $l = mkPlayer($world, 2, 65.0, 2);
    $kernel->run(30);
    $hp = $world->getEntity($l->getId())?->get(HealthComponent::class)->current;
    ok($hp < 20.0, "lava victim took lava damage ($hp)");
    ok(($fired[\pocketmine\api\event\EntityDamageEvent::CAUSE_LAVA] ?? 0) > 0, 'CAUSE_LAVA fired');
    // Burn state must be visible to the network layer (DATA_FLAG_ONFIRE).
    $fireTicks = $world->getEntity($l->getId())?->get(\pocketmine\core\component\FireComponent::class)?->ticks ?? 0;
    ok($fireTicks > 0, "entity is marked burning ($fireTicks ticks left)");
    $world->despawn($world->getEntity($l->getId()));
});

test('fire contact damages via CAUSE_FIRE', function () use ($world, $kernel, &$fired, $store, $registry): void {
    placeBlock($store, $registry, 4, 65, 4, BlockIds::FIRE);
    $f = mkPlayer($world, 4, 65.0, 4);
    $kernel->run(30);
    ok(($fired[\pocketmine\api\event\EntityDamageEvent::CAUSE_FIRE] ?? 0) > 0, 'CAUSE_FIRE fired');
    $world->despawn($world->getEntity($f->getId()));
});

test('suffocation: solid opaque block at eye height damages', function () use ($world, $kernel, &$fired, $store, $registry): void {
    // Eye height ~1.62: an entity parked at y=65 has its eyes in block y=66.
    placeBlock($store, $registry, 6, 66, 6, 1); // stone
    $s = mkPlayer($world, 6, 65.0, 6);
    $kernel->run(30);
    ok(($fired[\pocketmine\api\event\EntityDamageEvent::CAUSE_SUFFOCATION] ?? 0) > 0, 'CAUSE_SUFFOCATION fired');
    $world->despawn($world->getEntity($s->getId()));
});

test('drowning: head in water drains air then applies CAUSE_DROWNING', function () use ($world, $kernel, &$fired, $store, $registry): void {
    // Water column so head (y+1.62) and body are submerged.
    for ($y = 64; $y <= 67; $y++) {
        placeBlock($store, $registry, 8, $y, 8, BlockIds::WATER);
    }
    $d = mkPlayer($world, 8, 65.0, 8);
    // Air 300 drains at 4/tick: drowning starts after 75 ticks, first hit at
    // 95 ticks. Run well past that.
    for ($i = 0; $i < 130; $i++) { $kernel->run(1); }
    ok(($fired[\pocketmine\api\event\EntityDamageEvent::CAUSE_DROWNING] ?? 0) > 0, 'CAUSE_DROWNING fired after air ran out');
    $world->despawn($world->getEntity($d->getId()));
});

test('creative players are immune to environmental damage', function () use ($world, $kernel, $store, $registry): void {
    placeBlock($store, $registry, 10, 65, 10, BlockIds::LAVA);
    $c = $world->spawn(
        (new EntityBuilder())
            ->at(10, 65, 10)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['entityType' => 'Player', 'gamemode' => 1])) // creative
            ->withTag(PlayerTag::class)
    );
    $kernel->run(30);
    $hp = $world->getEntity($c->getId())?->get(HealthComponent::class)->current;
    same(20.0, $hp, 'creative player took no lava damage');
    $world->despawn($world->getEntity($c->getId()));
});

test('undead mobs burn in direct daylight (CAUSE_FIRE_TICK)', function () use ($world, $kernel, &$fired, $store, $registry): void {
    // Regression: the sun-burn branch read $meta before it was assigned, so
    // $mobType was always '' and zombies/skeletons never caught fire in the
    // day - dead code plus a per-entity warning each tick.
    //
    // Fixture (all inside chunk 0,0 so every setBlock lands): one stone
    // platform x 2..14 / z 2..14 rising to y 80 with a cleared-air corridor
    // above it, so the zombie and its player-target stand in full daylight
    // on flat ground and the zombie cannot wander under a tree canopy while
    // burning.
    for ($y = 60; $y <= 80; $y++) {
        for ($px = 2; $px <= 14; $px++) {
            for ($pz = 2; $pz <= 14; $pz++) {
                placeBlock($store, $registry, $px, $y, $pz, 1); // stone
            }
        }
    }
    for ($y = 82; $y <= 200; $y++) {
        for ($px = 8; $px <= 13; $px++) {
            for ($pz = 8; $pz <= 9; $pz++) {
                placeBlock($store, $registry, $px, $y, $pz, 0); // clear sky
            }
        }
    }
    // Recompute the chunk's light from the live block grid so the stored
    // sky-light nibbles reflect the cleared corridor (independent of what
    // the generated terrain originally placed there).
    $store->recalculateLight(0, 0, $registry);
    ok($store->getSkyLightLevel(8, 82, 8) >= 14, 'fixture column has direct sky light');
    $zombie = $kernel->getEntitySpawnService()->spawnMob(
        \pocketmine\core\enum\EntityType::Zombie,
        8.5, 81.0, 8.5,
    );
    $sun = mkPlayer($world, 12.5, 81.0, 8.5); // survival anchor + chase target
    $kernel->run(40);
    ok(($fired[\pocketmine\api\event\EntityDamageEvent::CAUSE_FIRE_TICK] ?? 0) > 0,
        'zombie in direct daylight took CAUSE_FIRE_TICK damage');
    $world->despawn($world->getEntity($zombie->getId()));
    $world->despawn($world->getEntity($sun->getId()));
});

foreach ($world->getEntities() as $entity) {
    $world->despawn($entity);
}
$kernel->run(1);

exit(runTests());
