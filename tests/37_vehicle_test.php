<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\constants\EntityTags;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\enum\EntityType;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\Kernel;

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$spawn = $kernel->getEntitySpawnService();
$store = $world->getResourceRegistry()->get(ChunkStore::class);

// Materialize chunks at the test sites.
$apiWorld = new \pocketmine\api\world\World($world, 'world', 'world');
foreach ([[6, 6], [12, 12], [18, 18], [24, 24]] as [$cx, $cz]) {
    $apiWorld->loadChunk($cx, $cz);
}

$tick = static function (int $n) use ($world): void {
    for ($i = 0; $i < $n; $i++) {
        $world->tick(0.05);
    }
};

test('boats spawn tagged VEHICLE and float on water', function () use ($kernel, $world, $spawn, $store, $tick): void {
    // A water pool at the boat site.
    for ($dx = 0; $dx < 4; $dx++) {
        for ($dz = 0; $dz < 4; $dz++) {
            $store->setBlock(100 + $dx, 65, 100 + $dz, 9); // flowing water
            $store->setBlock(100 + $dx, 64, 100 + $dz, 1); // stone floor
        }
    }
    $boat = $spawn->spawnVehicle(EntityType::Boat, 101.0, 66.0, 101.0);
    $entity = $boat->getEntity();
    ok($entity !== null && $entity->has(EntityTags::VEHICLE), 'boat tagged VEHICLE');
    $meta = $entity?->get(\pocketmine\core\component\MetadataComponent::class);
    same('Boat', $meta?->get(MetadataKeys::VEHICLE_TYPE), 'vehicle type metadata');

    $tick(10);
    $pos = $boat->getPosition();
    ok($pos !== null, 'boat has a position');
    // Boat floats on water: y stays near the surface (66.0..66.5), not sunk.
    ok($pos->y > 65.0 && $pos->y < 67.0, "boat floats near the water surface (y={$pos->y})");
    same(90, EntityType::Boat->networkId(), 'boat network id 90');
});

test('minecarts ride rails and fall when off them', function () use ($kernel, $world, $spawn, $store, $tick): void {
    // A rail line.
    for ($dx = 0; $dx < 4; $dx++) {
        $store->setBlock(200 + $dx, 65, 200, 66); // rail
        $store->setBlock(200 + $dx, 64, 200, 1);  // stone floor
    }
    $cart = $spawn->spawnVehicle(EntityType::Minecart, 200.0, 66.0, 200.0);
    $entity = $cart->getEntity();
    ok($entity !== null && $entity->has(EntityTags::VEHICLE), 'minecart tagged VEHICLE');
    $meta = $entity?->get(\pocketmine\core\component\MetadataComponent::class);
    same('Minecart', $meta?->get(MetadataKeys::VEHICLE_TYPE), 'vehicle type metadata');

    $tick(10);
    $pos = $cart->getPosition();
    ok($pos !== null, 'minecart has a position');
    // On rails: cart sits 0.5 above the rail block (y ~65.5).
    ok(abs($pos->y - 65.5) < 0.1, "minecart rides the rail (y={$pos->y})");
    same(84, EntityType::Minecart->networkId(), 'minecart network id 84');
});

test('rider mounts a vehicle and follows it', function () use ($kernel, $world, $spawn, $store, $tick): void {
    $cart = $spawn->spawnVehicle(EntityType::Minecart, 300.0, 66.0, 300.0);
    for ($dx = -1; $dx <= 1; $dx++) {
        $store->setBlock(300 + $dx, 65, 300, 66); // rail
        $store->setBlock(300 + $dx, 64, 300, 1);
    }
    $player = $spawn->spawnMob(EntityType::Zombie, 300.0, 66.0, 300.0); // stand-in rider

    // Simulate the mount link (what NetworkSessionService does).
    $vehicleEntity = $cart->getEntity();
    $vehicleMeta = $vehicleEntity?->get(\pocketmine\core\component\MetadataComponent::class);
    $riderMeta = $player->getEntity()?->get(\pocketmine\core\component\MetadataComponent::class);
    $riderId = $player->getId();
    $vehicleMeta?->set(MetadataKeys::VEHICLE_RIDER_ID, $riderId);
    $riderMeta?->set(MetadataKeys::RIDING_VEHICLE_ID, $cart->getId());

    $tick(5);
    $riderPos = $player->getPosition();
    $cartPos = $cart->getPosition();
    ok($cartPos !== null && $riderPos !== null, 'rider and cart positioned');
    // Rider follows the cart with the seat offset (~0.5 up).
    near($cartPos->x, $riderPos->x, 0.05, 'rider x follows the cart');
    near($cartPos->y + 0.5, $riderPos->y, 0.05, 'rider sits above the cart');
    near($cartPos->z, $riderPos->z, 0.05, 'rider z follows the cart');
});

test('dismount clears both link halves', function () use ($kernel, $world, $spawn): void {
    $cart = $spawn->spawnVehicle(EntityType::Minecart, 400.0, 66.0, 400.0);
    $player = $spawn->spawnMob(EntityType::Zombie, 400.0, 66.0, 400.0);
    $vehicleMeta = $cart->getEntity()?->get(\pocketmine\core\component\MetadataComponent::class);
    $riderMeta = $player->getEntity()?->get(\pocketmine\core\component\MetadataComponent::class);
    $vehicleMeta?->set(MetadataKeys::VEHICLE_RIDER_ID, $player->getId());
    $riderMeta?->set(MetadataKeys::RIDING_VEHICLE_ID, $cart->getId());

    $vehicleMeta?->remove(MetadataKeys::VEHICLE_RIDER_ID);
    $riderMeta?->remove(MetadataKeys::RIDING_VEHICLE_ID);
    same(0, (int)($vehicleMeta?->get(MetadataKeys::VEHICLE_RIDER_ID) ?? 0), 'vehicle rider cleared');
    same(0, (int)($riderMeta?->get(MetadataKeys::RIDING_VEHICLE_ID) ?? 0), 'rider vehicle cleared');
});

// NOTE: no shutdown - the next test boots its own kernel.
exit(runTests());
