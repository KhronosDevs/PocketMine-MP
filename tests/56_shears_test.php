<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\enum\EntityType;

/**
 * Bug 15: shears. Right-clicking a sheep with shears drops 1-3 white wool,
 * marks it shorn, wears the shears, and the fleece regrows after ~60s.
 */

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();

$player = $world->spawn(
    (new EntityBuilder())->at(50.5, 65, 50.5)
        ->with(new HealthComponent(20, 20))
        ->with(new MetadataComponent(['entityType' => 'Player', 'gamemode' => 0]))
        ->with(new InventoryComponent(36))
        ->withTag(\pocketmine\core\component\tags\PlayerTag::class)
);
$world->tick(0.05);
$inv = $world->getEntity($player->getId())?->get(InventoryComponent::class);
$inv?->set($inv?->heldSlot ?? 0, new \pocketmine\core\component\ItemStack(ItemIds::SHEARS, 0, 1));

$sheep = $kernel->getEntitySpawnService()->spawnMob(EntityType::Sheep, 52.5, 65, 50.5);

$interaction = $kernel->getEntityInteractionService();

test('shearing drops wool and marks the sheep shorn', function () use ($world, $interaction, $player, $sheep): void {
    ok($interaction->interact($player, $sheep), 'first shear succeeds');

    // Wool item entities near the sheep.
    $wool = 0;
    foreach ($world->getEntities() as $e) {
        $m = $e?->get(MetadataComponent::class)?->get(\pocketmine\core\constants\MetadataKeys::ITEM);
        if ($m instanceof \pocketmine\core\component\ItemStack && $m->itemId === \pocketmine\core\constants\ItemIds::WOOL) {
            $wool += $m->count;
        }
    }
    ok($wool >= 1 && $wool <= 3, "1-3 wool dropped ($wool)");

    $shorn = $world->getEntity($sheep->getId())?->get(MetadataComponent::class)?->get('shorn');
    same(true, (bool)$shorn, 'sheep marked shorn');
});

test('an already-shorn sheep cannot be re-sheared immediately', function () use ($interaction, $player, $sheep): void {
    same(false, $interaction->interact($player, $sheep), 'second shear rejected while shorn');
});

test('the fleece regrows after ~1200 ticks and can be shorn again', function () use ($world, $kernel, $interaction, $player, $sheep): void {
    // Deterministic regrow check: rewind shornAt so the elapsed time
    // qualifies, tick once, and re-shear.
    $sheepMeta = $world->getEntity($sheep->getId())?->get(MetadataComponent::class);
    ok($sheepMeta !== null && $sheepMeta->get('shorn') === true, 'sheep still marked shorn before regrow');
    $now = \pocketmine\Kernel::getInstance()?->getResourceRegistry()?->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0;
    $sheepMeta?->set('shornAt', $now - 1200);
    $world->tick(0.05);

    $shorn = $world->getEntity($sheep->getId())?->get(MetadataComponent::class)?->get('shorn');
    ok($shorn !== true, 'fleece regrew');

    // Keep the sheep inside interaction range for the re-shear.
    $sp2 = $world->getEntity($sheep->getId())?->get(\pocketmine\core\component\PositionComponent::class);
    $ppos2 = $world->getEntity($player->getId())?->get(\pocketmine\core\component\PositionComponent::class);
    if ($sp2 !== null && $ppos2 !== null) { $sp2->x = $ppos2->x + 1.5; $sp2->z = $ppos2->z; }

    ok($interaction->interact($player, $sheep), 're-shear after regrowth succeeds');
});

exit(runTests());
