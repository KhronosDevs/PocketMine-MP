<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\enum\EntityType;

$p = $world->spawn(
    (new EntityBuilder())->at(50.5, 65, 50.5)
        ->with(new HealthComponent(20, 20))
        ->with(new MetadataComponent(['entityType' => 'Player', 'gamemode' => 0]))
        ->with(new InventoryComponent(36))
        ->withTag(PlayerTag::class)
);
$world->tick(0.05);
$inv = $world->getEntity($p->getId())?->get(InventoryComponent::class);
$inv?->set($inv?->heldSlot ?? 0, new \pocketmine\core\component\ItemStack(ItemIds::SHEARS, 0, 1));

// Spawn a sheep like the test does
$sheep = $kernel->getEntitySpawnService()->spawnMob(EntityType::Sheep, 52.5, 65, 50.5);

for ($i = 1; $i <= 3; $i++) {
    $e = $world->getEntity($p->getId());
    echo "tick $i: invGet=" . var_export($e?->get(InventoryComponent::class), true) . "\n";
    $world->tick(0.05);
}
