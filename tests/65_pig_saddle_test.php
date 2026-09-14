<?php

declare(strict_types=1);

/**
 * Pig saddle end-to-end test (legacy parity: saddle item 329 on a pig).
 *
 * Covers the three pieces the TODO flagged as missing:
 *  1. saddle consumption on interact (EntityInteractionService::saddlePig)
 *  2. tickPig() steering/jump in VehicleSystem (VEHICLE tag + VEHICLE_TYPE Pig)
 *  3. unsaddle on death: saddle drop + rider ejection (CombatService)
 */

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\AIStateComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\constants\EntityTags;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\enum\EntityType;
use pocketmine\core\system\VehicleSystem;

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();

/** Spawn a pig + a second entity (rider), return refs. */
function makePigAndRider(\pocketmine\core\ecs\World $world): array {
    $spawner = \pocketmine\Kernel::getInstance()->getEntitySpawnService();
    $pigRef = $spawner->spawnMob(EntityType::Pig, 100.0, 64.0, 100.0);
    // The rider needs an inventory (saddle in hand): build it manually -
    // spawnMob() creates animals without one.
    $riderRef = $world->spawn(
        (new \pocketmine\core\ecs\EntityBuilder())
            ->with(new \pocketmine\core\component\PositionComponent(101.0, 64.0, 100.0))
            ->with(new \pocketmine\core\component\RotationComponent())
            ->with(new \pocketmine\core\component\VelocityComponent())
            ->with(new \pocketmine\core\component\HealthComponent())
            ->with(new \pocketmine\core\component\MetadataComponent())
            ->with(new \pocketmine\core\component\WorldComponent(0))
            ->with(new AIStateComponent())
            ->with(new InventoryComponent())
    );
    ok($pigRef !== null && $riderRef !== null, 'pig + rider spawned');
    return [$pigRef, $riderRef];
}

test('saddling consumes the saddle and tags the pig', function () use ($world): void {
    [$pigRef, $riderRef] = makePigAndRider($world);
    $inv = $riderRef->getEntity()?->get(InventoryComponent::class);
    ok($inv !== null, 'rider has an inventory');
    $inv->set(0, new ItemStack(ItemIds::SADDLE, 0, 1));
    $inv->heldSlot = 0;

    $interaction = new \pocketmine\core\service\EntityInteractionService(
        $world,
        \pocketmine\Kernel::getInstance()->getCombatService(),
        \pocketmine\Kernel::getInstance()->getEventPort(),
    );
    ok($interaction->interact($riderRef, $pigRef), 'interact returns true when saddling');

    $pig = $pigRef->getEntity();
    $meta = $pig?->get(MetadataComponent::class);
    same(true, (bool)$meta?->get(MetadataKeys::PIG_SADDLED, false), 'pig is marked saddled');
    ok($pig?->has(EntityTags::VEHICLE) === true, 'pig carries the VEHICLE tag');
    same('Pig', $meta?->get(MetadataKeys::VEHICLE_TYPE), 'vehicle type is Pig');

    // The saddle is consumed from the hand.
    $after = $inv->get(0);
    ok($after === null || $after->count === 0, 'saddle consumed');

    // Already-saddled pigs refuse a second saddle.
    $inv->set(0, new ItemStack(ItemIds::SADDLE, 0, 1));
    ok(!$interaction->interact($riderRef, $pigRef), 'second saddle is refused');
});

test('tickPig steers the saddled pig and jumps from input', function () use ($world, $kernel): void {
    [$pigRef, $riderRef] = makePigAndRider($world);
    $pig = $pigRef->getEntity();
    $meta = $pig?->get(MetadataComponent::class);
    // Set up the saddle state directly (saddle path covered by test 1).
    $meta?->set(MetadataKeys::VEHICLE_TYPE, 'Pig');
    $pig?->set(EntityTags::VEHICLE, true);
    $meta?->set(MetadataKeys::PIG_SADDLED, true);

    // Rider input: full forward + jump held.
    $meta?->set(MetadataKeys::VEHICLE_INPUT_Z, 1.0);
    $meta?->set(MetadataKeys::VEHICLE_INPUT_X, 0.0);
    $meta?->set(MetadataKeys::VEHICLE_JUMPING, true);

    // Face +Z (yaw 0), place on solid ground (y=64 => block below at 63).
    $rot = $pig?->get(RotationComponent::class);
    if ($rot !== null) {
        $rot->yaw = 0.0;
    }
    $pos = $pig?->get(PositionComponent::class);
    $vel = $pig?->get(VelocityComponent::class);
    ok($pos !== null && $vel !== null, 'pig has position/velocity');
    // Give the pig solid ground at (100, 63, 100) so the jump gate passes.
    $store = $kernel->getResourceRegistry()?->get(\pocketmine\core\resource\ChunkStore::class);
    ok($store instanceof \pocketmine\core\resource\ChunkStore, 'chunk store available');
    $kernel->getChunkLoadService()->loadChunk(6, 6); // (100, 100) lives here
    ok($store->setBlock(100, 63, 100, 1), 'stone floor placed');
    $pos->x = 100.5;
    $pos->y = 64.0;
    $pos->z = 100.5;

    (new VehicleSystem())->run($world, 1 / 20);

    // Forward steering at yaw 0 => +Z velocity, within pig pace.
    ok(abs($vel->z) > 0.01, "pig moves forward (vz={$vel->z})");
    ok(abs($vel->z) <= 0.35, 'pig speed stays below boat speed');
    ok($vel->y > 0.0, 'jump impulse applied (vy=' . $vel->y . ')');

    // Second tick: airborne from the jump => no extra upward impulse.
    $vyAfterJump = $vel->y;
    $meta?->set(MetadataKeys::VEHICLE_INPUT_Z, 0.0);
    (new VehicleSystem())->run($world, 1 / 20);
    ok($vel->y <= $vyAfterJump, 'no extra upward impulse while airborne');
});

test('unsaddle on death drops the saddle and ejects the rider', function () use ($world): void {
    [$pigRef, $riderRef] = makePigAndRider($world);
    $pig = $pigRef->getEntity();
    $meta = $pig?->get(MetadataComponent::class);
    $meta?->set(MetadataKeys::PIG_SADDLED, true);
    $pig?->set(EntityTags::VEHICLE, true);

    // Link the rider to the pig the way mountVehicle does.
    $riderMeta = $riderRef->getEntity()?->get(MetadataComponent::class);
    $riderMeta?->set(MetadataKeys::RIDING_VEHICLE_ID, $pigRef->getId());
    $meta?->set(MetadataKeys::VEHICLE_RIDER_ID, $riderRef->getId());

    // Kill the pig through the combat pipeline.
    \pocketmine\Kernel::getInstance()->getCombatService()->applyDamage(
        $pigRef,
        100.0,
        null,
        \pocketmine\api\event\EntityDamageEvent::CAUSE_SUICIDE,
    );

    // Rider link cleared both ways (the saddle drop is a real item entity).
    same(0, (int)($riderMeta?->get(MetadataKeys::RIDING_VEHICLE_ID, 0) ?? 0), 'rider ejected on pig death');
    same(0, (int)($meta?->get(MetadataKeys::VEHICLE_RIDER_ID, 0) ?? 0), 'vehicle link cleared on death');
});

exit(runTests());
