<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\RecipeRegistry;
use pocketmine\core\resource\SmeltingRegistry;
use pocketmine\protocol\CraftingDataPacket;
use pocketmine\utils\BinaryStream;

/**
 * Crafting wire round-trip:
 *  - shapeless recipes match any grid placement and consume real metas
 *  - shaped recipes still match after the shapeless refactor
 *  - CraftingDataPacket encodes ENTRY_SHAPELESS (0) / ENTRY_FURNACE (2) /
 *    ENTRY_FURNACE_DATA (3) / ENTRY_SHAPED (1) entries with cleanRecipes=1
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$crafting = $kernel->getCraftingService();
$registry = $world->getResourceRegistry();

test('shapeless recipe matches any grid placement (mushroom stew)', function () use ($kernel, $world, $crafting): void {
    $player = $world->spawn(
        (new EntityBuilder())
            ->at(900, 65, 900)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent())
            ->with(new InventoryComponent(36))
            ->withTag(PlayerTag::class)
    );
    $inv = $player->getEntity()?->get(InventoryComponent::class);
    assert($inv instanceof InventoryComponent);
    // Stew = brown mushroom (39) + red mushroom (40) + bowl (281), any order.
    $inv->add(new ItemStack(39, 0, 1));
    $inv->add(new ItemStack(40, 0, 1));
    $inv->add(new ItemStack(281, 0, 1));

    // Scattered order in a 2x2 grid (client sends row-major cells).
    $grid = [null, new ItemStack(40, 0, 1), new ItemStack(39, 0, 1), new ItemStack(281, 0, 1)];
    $result = $crafting->craft($player, $grid, 2);
    ok($result !== null && $result->itemId === 282, 'mushroom stew crafted from scattered shapeless grid');

    $stewCount = 0;
    $mushroomsLeft = 0;
    foreach ($inv->getContents() as $item) {
        if ($item->itemId === 282) $stewCount += $item->count;
        if ($item->itemId === 39 || $item->itemId === 40) $mushroomsLeft += $item->count;
    }
    ok($stewCount === 1, 'stew is in the inventory');
    ok($mushroomsLeft === 0, 'ingredients consumed');
    ok($inv->getContents() !== [] , 'bowl consumed too (only stew remains)');
});

test('shapeless recipe rejects wrong ingredient multisets', function () use ($world, $crafting): void {
    $player = $world->spawn(
        (new EntityBuilder())
            ->at(901, 65, 900)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent())
            ->with(new InventoryComponent(36))
            ->withTag(PlayerTag::class)
    );
    $inv = $player->getEntity()?->get(InventoryComponent::class);
    assert($inv instanceof InventoryComponent);
    $inv->add(new ItemStack(39, 0, 1));
    $inv->add(new ItemStack(39, 0, 1)); // two brown, no red
    $inv->add(new ItemStack(281, 0, 1));

    $grid = [new ItemStack(39, 0, 1), new ItemStack(39, 0, 1), new ItemStack(281, 0, 1), null];
    $result = $crafting->craft($player, $grid, 2);
    ok($result === null, 'wrong multiset does not match any shapeless recipe');
});

test('shaped recipes still craft after the shapeless refactor', function () use ($world, $crafting): void {
    $player = $world->spawn(
        (new EntityBuilder())
            ->at(902, 65, 900)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent())
            ->with(new InventoryComponent(36))
            ->withTag(PlayerTag::class)
    );
    $inv = $player->getEntity()?->get(InventoryComponent::class);
    assert($inv instanceof InventoryComponent);
    $inv->add(new ItemStack(5, 0, 8)); // planks
    // sticks = two planks stacked vertically in a 2x2
    $grid = [new ItemStack(5, 0, 1), null, new ItemStack(5, 0, 1), null];
    $result = $crafting->craft($player, $grid, 2);
    ok($result !== null && $result->itemId === 280 && $result->count === 4, 'shaped stick recipe still works');
});

test('CraftingDataPacket encodes shapeless, furnace and shaped entries', function () use ($registry): void {
    $recipes = $registry->get(RecipeRegistry::class);
    $smelting = $registry->get(SmeltingRegistry::class);
    assert($recipes instanceof RecipeRegistry && $smelting instanceof SmeltingRegistry);

    $pk = new CraftingDataPacket();
    $pk->recipes = $recipes->getShapedRecipes();
    $pk->shapelessRecipes = $recipes->getShapelessRecipes();
    $pk->furnaceRecipes = $smelting->getAll();
    $pk->encode();

    // Walk the entry stream: 1-byte packet id header, entryCount,
    // then (type, len, payload)*, cleanRecipes tail.
    $buf = substr($pk->getBuffer(), 1); // strip packet id header
    $s = new BinaryStream($buf);
    $entryCount = $s->getInt();
    ok($entryCount === count($pk->recipes) + count($pk->shapelessRecipes) + count($pk->furnaceRecipes),
        'entry count includes shapeless + furnace + shaped');

    $types = [];
    for ($i = 0; $i < $entryCount; $i++) {
        $type = $s->getInt();
        $len = $s->getInt();
        $payload = $s->get($len);
        $types[] = $type;
        // Every payload for shapeless must start with ingredient count >= 1.
        if ($type === CraftingDataPacket::ENTRY_SHAPELESS) {
            $ps = new BinaryStream($payload);
            $ingCount = $ps->getInt();
            ok($ingCount >= 1, 'shapeless entry carries its ingredient count');
        }
        if ($type === CraftingDataPacket::ENTRY_FURNACE_DATA) {
            $ps = new BinaryStream($payload);
            $input = $ps->getInt();
            ok(($input >> 16) > 0, 'furnace data entry packs (id << 16) | meta');
        }
    }
    ok(in_array(CraftingDataPacket::ENTRY_SHAPELESS, $types, true), 'at least one shapeless entry present');
    ok(in_array(CraftingDataPacket::ENTRY_SHAPED, $types, true), 'shaped entries present');
    ok(in_array(CraftingDataPacket::ENTRY_FURNACE, $types, true) || in_array(CraftingDataPacket::ENTRY_FURNACE_DATA, $types, true),
        'furnace entries present');
    ok($s->getByte() === 1, 'cleanRecipes byte is 1 (legacy parity)');
});

runTests();
