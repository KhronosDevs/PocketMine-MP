<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\enum\EntityType;

/**
 * Bug 20: animal feeding + breeding. Wheat on cows puts them in love mode;
 * two in-love cows pair up and spawn a calf.
 */

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();

$player = $world->spawn(
    (new EntityBuilder())->at(60.5, 65, 60.5)
        ->with(new HealthComponent(20, 20))
        ->with(new MetadataComponent(['entityType' => 'Player', 'gamemode' => 0]))
        ->with(new InventoryComponent(36))
        ->withTag(\pocketmine\core\component\tags\PlayerTag::class)
);
$world->tick(0.05);
$inv = $world->getEntity($player->getId())?->get(InventoryComponent::class);
$inv?->set(0, new \pocketmine\core\component\ItemStack(ItemIds::WHEAT_ITEM ?? 337, 0, 4));
$world->getEntity($player->getId())?->get(InventoryComponent::class)?->setHeldSlot(0);

$cowA = $kernel->getEntitySpawnService()->spawnMob(EntityType::Cow, 61.5, 65, 60.5);
$cowB = $kernel->getEntitySpawnService()->spawnMob(EntityType::Cow, 62.5, 65, 61.5);
$interaction = $kernel->getEntityInteractionService();

test('feeding wheat puts cows in love mode', function () use ($world, $interaction, $player, $cowA, $cowB): void {
    ok($interaction->interact($player, $cowA), 'feed cow A');
    ok($interaction->interact($player, $cowB), 'feed cow B');
    foreach ([$cowA, $cowB] as $cow) {
        $meta = $world->getEntity($cow->getId())?->get(MetadataComponent::class);
        ok((int)($meta?->get('inLoveUntil', 0)) > 0, 'cow entered love mode');
    }
});

test('two in-love cows pair up and spawn a calf', function () use ($world, $kernel, $interaction, $player, $cowA, $cowB): void {
    // Keep them close (AI wanders); run up to 40 ticks for the pairing pass.
    for ($i = 0; $i < 40; $i++) {
        foreach ([$cowA, $cowB] as $cow) {
            $sp = $world->getEntity($cow->getId())?->get(\pocketmine\core\component\PositionComponent::class);
            $pp = $world->getEntity($player->getId())?->get(\pocketmine\core\component\PositionComponent::class);
            if ($sp !== null && $pp !== null) { $sp->x = $pp->x + 2.0; $sp->z = $pp->z + 1.0; }
        }
        $kernel->run(1);
    }

    $cowCount = 0;
    $babyId = null;
    foreach ($world->getEntities() as $e) {
        $m = $e?->get(MetadataComponent::class);
        if ($m !== null && $m?->get(\pocketmine\core\constants\MetadataKeys::MOB_TYPE) === EntityType::Cow->value) {
            $cowCount++;
            $babyId ??= null;
        }
    }
    ok($cowCount >= 3, "calf spawned between the two cows (cows=$cowCount)");

    // Parents leave love mode immediately after breeding.
    foreach ([$cowA, $cowB] as $cow) {
        $meta = $world->getEntity($cow->getId())?->get(MetadataComponent::class);
        ok((int)($meta?->get('inLoveUntil', 0)) === 0 || (int)($meta?->get('inLoveUntil', 0)) < (\pocketmine\Kernel::getInstance()?->getResourceRegistry()?->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0),
            'parent left love mode');
    }
});

exit(runTests());
