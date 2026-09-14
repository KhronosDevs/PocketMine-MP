<?php

declare(strict_types=1);

/**
 * Java world import translation tests:
 *  - JavaBlockTranslator::javaTileType (minecraft:chest -> Chest etc.)
 *  - JavaBlockTranslator::javaItemToPe (minecraft:iron_sword -> 267)
 *  - JavaBlockTranslator::javaEntityType (zombie_pigman -> PigZombie,
 *    'minecraft:zombie' -> Zombie, old-PM 'Zombie' passthrough)
 *  - AnvilStorageAdapter::decodeTileEntities on a real Java-shaped NBT
 *    compound: chest Items list -> Khronos snapshot the ChestStore restores.
 */

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\storage\JavaBlockTranslator;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;

test('java tile ids map to Khronos tile types', function (): void {
    same('Chest', JavaBlockTranslator::javaTileType('minecraft:chest'));
    same('Chest', JavaBlockTranslator::javaTileType('trapped_chest'));
    same('Furnace', JavaBlockTranslator::javaTileType('minecraft:furnace'));
    same('Dispenser', JavaBlockTranslator::javaTileType('dispenser'));
    same('Dropper', JavaBlockTranslator::javaTileType('minecraft:dropper'));
    same('Hopper', JavaBlockTranslator::javaTileType('hopper'));
    same('BrewingStand', JavaBlockTranslator::javaTileType('minecraft:brewing_stand'));
    same('Sign', JavaBlockTranslator::javaTileType('wall_sign'));
    same('ItemFrame', JavaBlockTranslator::javaTileType('item_frame'));
    same('Painting', JavaBlockTranslator::javaTileType('minecraft:painting'));
    same(null, JavaBlockTranslator::javaTileType('minecraft:beacon'), 'unsupported tiles are null');
});

test('java item names map to PE 0.15 ids', function (): void {
    same([267, 0], JavaBlockTranslator::javaItemToPe('minecraft:iron_sword'));
    same([278, 0], JavaBlockTranslator::javaItemToPe('diamond_pickaxe'));
    same([265, 0], JavaBlockTranslator::javaItemToPe('minecraft:iron_ingot'));
    same([391, 0], JavaBlockTranslator::javaItemToPe('carrot'));
    same([322, 0], JavaBlockTranslator::javaItemToPe('golden_apple'));
    same([364, 0], JavaBlockTranslator::javaItemToPe('cooked_beef'));
    same([268, 0], JavaBlockTranslator::javaItemToPe('wooden_sword'), 'wood -> wooden alias');
    same(null, JavaBlockTranslator::javaItemToPe('minecraft:definitely_not_an_item'));
});

test('java entity ids normalize to EntityType values', function (): void {
    same('Zombie', JavaBlockTranslator::javaEntityType('minecraft:zombie'));
    same('PigZombie', JavaBlockTranslator::javaEntityType('zombie_pigman'));
    same('PigZombie', JavaBlockTranslator::javaEntityType('zombified_piglin'));
    same('ZombieVillager', JavaBlockTranslator::javaEntityType('zombie_villager'));
    same('LavaSlime', JavaBlockTranslator::javaEntityType('magma_cube'));
    same('Skeleton', JavaBlockTranslator::javaEntityType('wither_skeleton'));
    same('Boat', JavaBlockTranslator::javaEntityType('chest_boat'));
    // Old-PocketMine names pass through.
    same('Villager', JavaBlockTranslator::javaEntityType('Villager'));
    same('PigZombie', JavaBlockTranslator::javaEntityType('PigZombie'));
    same(null, JavaBlockTranslator::javaEntityType('minecraft:ender_dragon'));
});

test('AnvilStorageAdapter translates a Java chest compound into a ChestStore snapshot', function (): void {
    // Build the vanilla-shaped tile compound a 1.13+ Java chunk carries.
    $items = new ListTag('Items', []);
    $items->setTagType(NBT::TAG_Compound);
    $sword = new CompoundTag('', []);
    $sword->setString('id', 'minecraft:iron_sword');
    $sword->setByte('Slot', 3);
    $sword->setByte('Count', 1);
    $items[] = $sword;
    $bread = new CompoundTag('', []);
    $bread->setString('id', 'minecraft:bread');
    $bread->setByte('Slot', 0);
    $bread->setByte('Count', 5);
    $items[] = $bread;
    $junk = new CompoundTag('', []);
    $junk->setString('id', 'minecraft:unobtainium');
    $junk->setByte('Slot', 4);
    $junk->setByte('Count', 1);
    $items[] = $junk;

    $chest = new CompoundTag('', []);
    $chest->setString('id', 'minecraft:chest');
    $chest->setInt('x', 10);
    $chest->setInt('y', 64);
    $chest->setInt('z', -3);
    $chest->setTag('Items', $items);

    $tiles = new ListTag('TileEntities', []);
    $tiles->setTagType(NBT::TAG_Compound);
    $tiles[] = $chest;

    $adapter = new \ReflectionClass(\pocketmine\adapter\driven\storage\AnvilStorageAdapter::class);
    $method = $adapter->getMethod('decodeTileEntities');
    $method->setAccessible(true);
    /** @var \pocketmine\port\driven\TileEntitySnapshot[] $snapshots */
    $snapshots = $method->invoke(
        $adapter->newInstanceWithoutConstructor(),
        $tiles,
    );

    same(1, count($snapshots), 'one translated snapshot');
    $snap = $snapshots[0];
    same('Chest', $snap->type);
    same(10, $snap->x);
    same(64, $snap->y);
    same(-3, $snap->z);

    // The payload must be the base64 JSON array ChestStore::restoreFromSnapshots decodes.
    $inv = json_decode((string)base64_decode($snap->data['nbt'] ?? ''), true);
    ok(is_array($inv), 'inventory payload decodes');
    same(['id' => 267, 'meta' => 0, 'count' => 1], $inv[3], 'slot 3: iron sword');
    same(['id' => 297, 'meta' => 0, 'count' => 5], $inv[0], 'slot 0: bread x5');
    ok(!isset($inv[4]), 'unknown item skipped');
});

test('AnvilStorageAdapter keeps Khronos-native tiles untouched', function (): void {
    $native = new CompoundTag('', []);
    $native->setString('id', 'Sign');
    $native->setString('KhronosId', 'sign:1:2:3');
    $native->setInt('x', 1);
    $native->setInt('y', 2);
    $native->setInt('z', 3);
    $native->setByteArray('KhronosData', (string)json_encode([
        'id' => 'sign:1:2:3', 'type' => 'Sign', 'data' => ['text' => ['a', 'b', '', ''], 'creator' => 'x'],
    ]));

    $tiles = new ListTag('TileEntities', []);
    $tiles->setTagType(NBT::TAG_Compound);
    $tiles[] = $native;

    $adapter = new ReflectionClass(\pocketmine\adapter\driven\storage\AnvilStorageAdapter::class);
    $method = $adapter->getMethod('decodeTileEntities');
    $method->setAccessible(true);
    /** @var \pocketmine\port\driven\TileEntitySnapshot[] $snapshots */
    $snapshots = $method->invoke($adapter->newInstanceWithoutConstructor(), $tiles);

    same(1, count($snapshots));
    same('Sign', $snapshots[0]->type);
    same('a', $snapshots[0]->data['text'][0] ?? null, 'KhronosData round-trips');
});

exit(runTests());
