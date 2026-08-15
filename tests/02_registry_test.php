<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ItemRegistry;
use pocketmine\core\resource\WorldConfig;

/**
 * Static data registry tests: known block/item property values and world
 * config defaults. These pin the tables against accidental regressions.
 */

test('block properties: known values', function () {
    $b = new BlockRegistry();

    // Stone
    near(1.5, $b->getHardness(1), 0.0, 'stone hardness');
    near(30.0, $b->getResistance(1), 0.0, 'stone resistance');
    same('pickaxe', $b->getToolType(1), 'stone tool');
    same(1, $b->getToolLevel(1), 'stone tool level');
    same('Stone', $b->getName(1), 'stone name');

    // Torch emits light 14
    same(14, $b->getLightLevel(50), 'torch light');
    same(15, $b->getLightLevel(89), 'glowstone light');
    ok(!$b->isSolid(50), 'torch is not solid');
    ok($b->isTransparent(50), 'torch is transparent');

    // Diamond ore needs iron pickaxe and drops xp
    same(4, $b->getExperienceDrop(56), 'diamond ore xp');
    same(2, $b->getToolLevel(56), 'diamond ore tool level');

    // Flammability
    ok($b->isFlammable(5), 'planks flammable');
    ok(!$b->isFlammable(1), 'stone not flammable');
    same(30, $b->getFlammability(35), 'wool flammability');

    // Unknown block falls back to defaults
    same('Unknown', $b->getName(9999), 'unknown name default');
    near(1.0, $b->getHardness(9999), 0.0, 'unknown hardness default');
});

test('block breakability', function () {
    $b = new BlockRegistry();
    ok(!$b->isBreakable(0), 'air not breakable');
    ok(!$b->isBreakable(7), 'bedrock not breakable');
    ok(!$b->isBreakable(137), 'command block not breakable');
    ok($b->isBreakable(1), 'stone breakable');
    ok($b->isBreakable(50), 'torch breakable');
});

test('block drops', function () {
    $b = new BlockRegistry();

    // stone -> cobblestone
    $drops = $b->getDrops(1);
    same(4, $drops[0]['id'], 'stone drops cobblestone');
    same(1, $drops[0]['count'], 'stone drops one cobblestone');

    // ore -> refined item
    same(263, $b->getDrops(16)[0]['id'], 'coal ore drops coal');
    same(264, $b->getDrops(56)[0]['id'], 'diamond ore drops diamond');

    // unbreakable -> nothing
    same([], $b->getDrops(7), 'bedrock drops nothing');

    // glass: nothing without silk touch, glass itself with it
    same([], $b->getDrops(20), 'glass drops nothing without silk touch');
    $silk = $b->getDrops(20, true);
    same(20, $silk[0]['id'], 'glass drops itself with silk touch');

    // stone with silk touch keeps itself
    $stoneSilk = $b->getDrops(1, true);
    same(1, $stoneSilk[0]['id'], 'stone keeps itself with silk touch');

    // wool keeps itself with shears
    $woolShears = $b->getDrops(35, true, 'shears');
    same(35, $woolShears[0]['id'], 'wool drops itself with shears');
});

test('item registry: stack sizes, durability, names', function () {
    $i = new ItemRegistry();

    same(1, $i->getMaxStackSize(267), 'iron sword stack 1');
    same(1, $i->getMaxStackSize(278), 'diamond pickaxe stack 1');
    same(16, $i->getMaxStackSize(325), 'bucket stack 16');
    same(16, $i->getMaxStackSize(332), 'snowball stack 16');
    same(64, $i->getMaxStackSize(1), 'stone stacks to 64 (default)');
    same(64, $i->getMaxStackSize(9999), 'unknown item stacks to 64');

    same(250, $i->getMaxDurability(257), 'iron pickaxe durability');
    same(1561, $i->getMaxDurability(278), 'diamond pickaxe durability');
    same(59, $i->getMaxDurability(268), 'wooden sword durability');
    same(0, $i->getMaxDurability(1), 'blocks have no durability');

    same('Coal', $i->getName(263), 'coal name');
    same('Stone', $i->getName(1), 'stone-as-item name');
    same('Diamond Sword', $i->getName(276), 'diamond sword name');
    same('item.999999', $i->getName(999999), 'unknown item fallback name');
});

test('world config defaults + mutation', function () {
    $c = new WorldConfig();
    same('world', $c->name, 'default name');
    same(0, $c->seed, 'default seed');
    same(64, $c->spawnY, 'default spawn y');
    same(\pocketmine\core\enum\GeneratorType::Normal, $c->generator, 'default generator');
    same(20, $c->maxPlayers, 'default max players');

    $c->name = 'nether';
    $c->seed = 42;
    $c->time = 6000;
    $c->difficulty = \pocketmine\core\enum\Difficulty::Normal;
    same('nether', $c->name, 'name mutated');
    same(42, $c->seed, 'seed mutated');
    same(6000, $c->time, 'time mutated');
    same(\pocketmine\core\enum\Difficulty::Normal, $c->difficulty, 'difficulty mutated');
});

exit(runTests());
