<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\api\block\Block;
use pocketmine\api\inventory\Inventory;
use pocketmine\api\inventory\ItemStack;
use pocketmine\api\world\World;
use pocketmine\core\component\CollisionComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;

/**
 * Phase 10.1: full protocol-84 block registry coverage + block-state
 * metadata (slab/stairs/doors) wired through placement.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$apiWorld = new World($world, 'world', 'world');

// Placement and facade tests write into chunk (0,0); load it once up front.
$apiWorld->loadChunk(0, 0);

/**
 * Every protocol-84 block id defined by the legacy BlockIds table, plus the
 * 0.15 blocks that table omits (36 piston arm, 119 end portal, 122 dragon
 * egg, 130 ender chest, 138 beacon, 160 stained glass pane, 166 barrier,
 * 168 prismarine, 169 sea lantern). Reserved/unused ids (198, 199, 243-251,
 * 255) are intentionally left to the default fallback.
 */
const CANONICAL_IDS = '0 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30 31 32 33 34 35 37 38 39 40 41 42 43 44 45 46 47 48 49 50 51 52 53 54 55 56 57 58 59 60 61 62 63 64 65 66 67 68 69 70 71 72 73 74 75 76 77 78 79 80 81 82 83 85 86 87 88 89 90 91 92 93 94 95 96 97 98 99 100 101 102 103 104 105 106 107 108 109 110 111 112 113 114 115 116 117 118 120 121 123 124 125 126 127 128 129 131 132 133 134 135 136 139 140 141 142 143 144 145 146 147 148 149 150 151 152 153 154 155 156 157 158 159 161 162 163 164 165 167 170 171 172 173 174 175 178 179 180 181 182 183 184 185 186 187 193 194 195 196 197 198 199 243 244 245 246 247 248 249 250 251 255';
const RESERVED_IDS = '198 199 243 244 245 246 247 248 249 250 251 255';
const EXTRA_IDS = '36 119 122 130 138 160 166 168 169';

function idList(string $csv): array {
    return array_map('intval', explode(' ', trim($csv)));
}

test('every real protocol-84 block id is registered with a real name', function () {
    $b = new BlockRegistry();
    $canonical = idList(CANONICAL_IDS);
    $reserved = array_flip(idList(RESERVED_IDS));
    $extras = idList(EXTRA_IDS);

    $real = array_merge(
        array_values(array_filter($canonical, fn(int $id) => !isset($reserved[$id]))),
        $extras
    );
    sort($real);
    $real = array_values(array_unique($real));

    ok(count($canonical) === 192, 'canonical protocol-84 id count is 192');
    same(189, count($real), '189 real block ids expected (192 canonical - 12 reserved + 9 extras)');

    $ids = $b->getIds();
    same(192, count($ids), 'registry has 192 explicit entries (112 + 79 added + item frame)');
    $idSet = array_flip($ids);

    foreach ($real as $id) {
        ok(isset($idSet[$id]), "block $id is explicitly registered");
        ok($b->getName($id) !== 'Unknown', "block $id has a real name, got '{$b->getName($id)}'");
    }

    // Reserved ids still behave via the default fallback.
    same('Unknown', $b->getName(198), 'reserved id falls back to Unknown');
    near(1.0, $b->getHardness(255), 0.0, 'reserved id uses default hardness');
});

test('newly added blocks carry sane properties', function () {
    $b = new BlockRegistry();

    // Quartz ore needs a pickaxe, drops xp, and drops quartz.
    same('pickaxe', $b->getToolType(153), 'quartz ore tool');
    same(2, $b->getExperienceDrop(153), 'quartz ore xp');
    same(406, $b->getDrops(153)[0]['id'], 'quartz ore drops quartz');

    // Beacon / sea lantern are light sources.
    same(15, $b->getLightLevel(138), 'beacon light');
    same(15, $b->getLightLevel(169), 'sea lantern light');
    same(15, $b->getLightLevel(124), 'lit redstone lamp light');
    same(0, $b->getLightLevel(123), 'unlit redstone lamp emits nothing');

    // Functional blocks.
    same('pickaxe', $b->getToolType(154), 'hopper tool');
    same('pickaxe', $b->getToolType(152), 'redstone block tool');
    ok(!$b->isSolid(157), 'activator rail is not solid');
    ok($b->isTransparent(101), 'iron bars are transparent');
    same('axe', $b->getToolType(96), 'trapdoor tool');
    ok($b->isFlammable(96), 'wooden trapdoor is flammable');

    // Unbreakable blocks.
    ok(!$b->isBreakable(119), 'end portal not breakable');
    ok(!$b->isBreakable(166), 'barrier not breakable');
    ok(!$b->isBreakable(122), 'dragon egg not breakable');

    // Glass panes need silk touch to drop themselves.
    same([], $b->getDrops(102), 'glass pane drops nothing without silk touch');
    same(102, $b->getDrops(102, true)[0]['id'], 'glass pane drops itself with silk touch');
});

test('slab state metadata: variants, top bit, names', function () {
    $b = new BlockRegistry();

    same('slab', $b->getStateKind(44), 'stone slab kind');
    same('double_slab', $b->getStateKind(43), 'double slab kind');
    same(8, count($b->getStateVariants(44)), 'stone slab has 8 material variants');
    same('Sandstone Slab', $b->getStateName(44, 1), 'stone slab meta 1 is sandstone');
    same('Stone Brick Slab', $b->getStateName(44, 5), 'stone slab meta 5 is stone brick');
    same('Stone Double Slab', $b->getStateName(43, 0), 'double slab naming');
    same('Quartz Double Slab', $b->getStateName(43, 6), 'double slab quartz variant');

    same('Oak Wood Slab', $b->getStateName(126, 0), 'wooden slab oak');
    same('Jungle Wood Slab', $b->getStateName(126, 3), 'wooden slab jungle');
    same('Red Sandstone Slab', $b->getStateName(182, 0), 'red sandstone slab');

    same('Stone', $b->getSlabMaterial(44, 0), 'slab material stone');
    same('Nether Brick', $b->getSlabMaterial(44, 7), 'slab material nether brick');
    ok($b->isSlabTop(44, 0) === false, 'meta 0 is bottom half');
    ok($b->isSlabTop(44, 8) === true, 'meta 8 is top half');
    ok($b->isSlabTop(44, 9) === true, 'material bit preserved with top bit');
    ok($b->isSlabTop(1, 0) === null, 'non-slab has no top state');
});

test('stairs and door state metadata', function () {
    $b = new BlockRegistry();

    same('stairs', $b->getStateKind(53), 'oak stairs kind');
    same('stairs', $b->getStateKind(180), 'red sandstone stairs kind');
    same(0, $b->getStairFacing(53, 0), 'stair facing 0');
    same(3, $b->getStairFacing(53, 3), 'stair facing 3');
    ok($b->isStairUpsideDown(53, 0) === false, 'stairs upright at meta 0');
    ok($b->isStairUpsideDown(53, 8) === true, 'stairs upside-down at meta 8');
    ok($b->getStairFacing(1, 0) === null, 'non-stairs have no facing');

    same('door', $b->getStateKind(64), 'wooden door kind');
    same('door', $b->getStateKind(197), 'dark oak door kind');
    ok($b->isDoorOpen(64, 0) === false, 'door closed at meta 0');
    ok($b->isDoorOpen(64, 4) === true, 'door open at meta 4');
    ok($b->isDoorTopHalf(64, 8) === true, 'meta 8 is the top half');
    ok($b->isDoorTopHalf(64, 0) === false, 'meta 0 is the bottom half');
    ok($b->isDoorOpen(1, 4) === null, 'non-door has no open state');

    // Placement keeps non-slab meta unchanged.
    same(4, $b->applyPlacementMeta(53, 1, 4), 'stairs keep their requested meta');
    same(3, $b->applyPlacementMeta(64, 5, 3), 'doors keep their requested meta');
});

test('applyPlacementMeta resolves slab top/bottom from the face', function () {
    $b = new BlockRegistry();

    // Face 0 (clicking the bottom) places a top-half slab; material kept.
    same(9, $b->applyPlacementMeta(44, 0, 1), 'sandstone slab on bottom face -> top half, material 1');
    same(8, $b->applyPlacementMeta(44, 0, 0), 'stone slab on bottom face -> top half');
    // Face 1 (clicking the top) places a bottom-half slab.
    same(1, $b->applyPlacementMeta(44, 1, 1), 'sandstone slab on top face -> bottom half');
    same(0, $b->applyPlacementMeta(44, 1, 0), 'stone slab on top face -> bottom half');
    // Any other face clears the top bit (bottom half), keeping the material.
    same(2, $b->applyPlacementMeta(44, 4, 10), 'side face clears the top bit to bottom half');
    same(2, $b->applyPlacementMeta(44, 4, 2), 'side face keeps the meta');
    // Double slabs have no top/bottom.
    same(3, $b->applyPlacementMeta(43, 0, 3), 'double slab meta unchanged');
});

test('slab placement in the service writes the resolved state meta', function () use ($kernel, $world, $apiWorld) {
    $h = $apiWorld->getHighestBlockAt(9, 9);
    $y = $h + 1;

    $playerRef = $world->spawn(
        (new EntityBuilder())
            ->at(9.5, $y, 9.5)
            ->with(new CollisionComponent(0.6, 1.8))
            ->with(new InventoryComponent(36))
            ->with(new MetadataComponent(['gamemode' => 1]))
            ->withTag('player')
    );
    $inv = new Inventory($playerRef, $world);
    $inv->setItem(0, new ItemStack(44, 0, 64)); // stone slab, material 0

    $store = $world->getResourceRegistry()->get(ChunkStore::class);
    ok($store instanceof ChunkStore, 'chunk store available');

    // Face 0 -> top-half slab (meta 8); the inventory is charged the plain item meta.
    ok($kernel->getBlockPlaceService()->placeBlock($playerRef, 9, $y, 9, 0, 44, 0), 'slab placed on bottom face');
    same(44, $store->getBlock(9, $y, 9), 'slab id stored');
    same(8, $store->getBlockMeta(9, $y, 9), 'top-half slab meta stored (face 0)');
    same(63, $inv->countItem(44), 'one slab consumed from inventory');

    // Face 1 -> bottom-half slab (meta 0).
    ok($kernel->getBlockPlaceService()->placeBlock($playerRef, 9, $y + 1, 9, 1, 44, 0), 'slab placed on top face');
    same(0, $store->getBlockMeta(9, $y + 1, 9), 'bottom-half slab meta stored (face 1)');
});

test('api Block facade exposes the block state', function () use ($apiWorld) {
    $world = $apiWorld->getEcsWorld();
    $store = $world->getResourceRegistry()->get(ChunkStore::class);

    $store->setBlock(5, 70, 5, 44, 9); // sandstone slab, top half
    $b = new Block($apiWorld, 5, 70, 5);
    same('slab', $b->getStateKind(), 'facade state kind');
    same('Sandstone Slab', $b->getStateName(), 'facade state name includes variant');
    same('Sandstone', $b->getSlabMaterial(), 'facade slab material');
    ok($b->isSlabTop() === true, 'facade top half');

    $store->setBlock(5, 71, 5, 53, 8); // oak stairs, upside down
    $stairs = new Block($apiWorld, 5, 71, 5);
    same('stairs', $stairs->getStateKind(), 'facade stairs kind');
    same(0, $stairs->getStairFacing(), 'facade stair facing');
    ok($stairs->isStairUpsideDown() === true, 'facade stairs upside-down');

    $store->setBlock(5, 72, 5, 64, 12); // door: open (4) + top half (8)
    $door = new Block($apiWorld, 5, 72, 5);
    ok($door->isDoorOpen() === true, 'facade door open');
    ok($door->isDoorTopHalf() === true, 'facade door top half');

    $store->setBlock(5, 73, 5, 1, 0); // plain stone
    $plain = new Block($apiWorld, 5, 73, 5);
    ok($plain->getStateKind() === null, 'plain block has no state');
    same('Stone', $plain->getStateName(), 'plain block state name is the plain name');
});

exit(runTests());
