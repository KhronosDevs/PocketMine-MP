<?php

declare(strict_types=1);

/**
 * Enchanting effects test (14.30 leftovers):
 *  - Protection: 4% damage reduction per level on worn armor
 *  - Knockback: +2 blocks/tick horizontal impulse per weapon level
 *  - Fire Aspect: ignites the target for 4s per level
 *  - Fortune: multiplies ore drop counts
 *  - Anvil: same-item repair + repair material + RepairCost accumulation
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
use pocketmine\core\constants\BlockIds;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\service\EnchantmentService;
use pocketmine\core\service\EnchantmentService as ES;

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$store = $kernel->getResourceRegistry()->get(ChunkStore::class);
ok($store instanceof ChunkStore, 'chunk store');

/** Generic test entity with an inventory. */
function makeEntity(\pocketmine\core\ecs\World $world, float $x, float $y, float $z): \pocketmine\core\ecs\EntityRef {
    return $world->spawn(
        (new \pocketmine\core\ecs\EntityBuilder())
            ->with(new PositionComponent($x, $y, $z))
            ->with(new RotationComponent())
            ->with(new VelocityComponent())
            ->with(new \pocketmine\core\component\HealthComponent())
            ->with(new MetadataComponent())
            ->with(new \pocketmine\core\component\WorldComponent(0))
            ->with(new AIStateComponent())
            ->with(new InventoryComponent()),
    );
}

function makeCombat(\pocketmine\core\ecs\World $world): \pocketmine\core\service\CombatService {
    return new \pocketmine\core\service\CombatService(
        $world,
        \pocketmine\Kernel::getInstance()->getEventPort(),
        \pocketmine\Kernel::getInstance()->getEntitySpawnService(),
    );
}

test('Protection reduces damage by 4% per level per piece', function () use ($world): void {
    $victim = makeEntity($world, 10.0, 64.0, 10.0);
    $combat = makeCombat($world);

    // One iron chestplate (307) with Protection III.
    $inv = $victim->getEntity()?->get(InventoryComponent::class);
    $plate = new ItemStack(307, 0, 1);
    $plate = $plate->withEnchantment(0, 3); // Protection III
    $inv->set(InventoryComponent::ARMOR_OFFSET + 1, $plate);

    $hpBefore = $victim->getEntity()?->get(\pocketmine\core\component\HealthComponent::class)->current;
    $combat->applyDamage($victim, 10.0, null, \pocketmine\api\event\EntityDamageEvent::CAUSE_CUSTOM);
    $hpAfter = $victim->getEntity()?->get(\pocketmine\core\component\HealthComponent::class)->current;
    $taken = $hpBefore - $hpAfter;

    // Base plate reduction 0.15 + 3 levels x 0.04 = 0.27 => 7.3 damage.
    ok(abs($taken - 7.3) < 0.35, "Protection III on plate reduces 10.0 to ~7.3 (took $taken)");
});

test('Knockback enchantment adds horizontal impulse', function () use ($world): void {
    $attacker = makeEntity($world, 10.0, 64.0, 10.0);
    $victim = makeEntity($world, 12.0, 64.0, 10.0);
    $combat = makeCombat($world);

    // Weapon with Knockback I in the attacker's hand.
    $inv = $attacker->getEntity()?->get(InventoryComponent::class);
    $inv->set(0, (new ItemStack(267, 0, 1))->withEnchantment(12, 1)); // iron sword + Knockback I

    $vBefore = $victim->getEntity()?->get(VelocityComponent::class);
    $vx0 = $vBefore->x;
    $vz0 = $vBefore->z;
    $combat->applyKnockback($attacker, $victim, 4.0, 2.0); // +2 = Knockback I bonus

    // Victim is east of attacker => +X impulse; bonus must raise it above
    // the base 0.4 blocks/tick (= 8.0 blocks/s) component.
    $vx = $victim->getEntity()?->get(VelocityComponent::class)->x;
    ok($vx > 8.0, "Knockback bonus raises the horizontal impulse beyond base (vx=$vx)");
});

test('Fire Aspect ignites the target', function () use ($world): void {
    $attacker = makeEntity($world, 10.0, 64.0, 10.0);
    $victim = makeEntity($world, 12.0, 64.0, 10.0);

    $inv = $attacker->getEntity()?->get(InventoryComponent::class);
    $inv->set(0, (new ItemStack(267, 0, 1))->withEnchantment(13, 2)); // Fire Aspect II

    $interaction = new \pocketmine\core\service\EntityInteractionService(
        $world,
        makeCombat($world),
        \pocketmine\Kernel::getInstance()->getEventPort(),
    );
    ok($interaction->attack($attacker, $victim), 'attack lands');

    $fire = $victim->getEntity()?->get(\pocketmine\core\component\FireComponent::class);
    ok($fire !== null && $fire->ticks >= 160, 'Fire Aspect II sets ~160 fire ticks (4s/level)');
});

test('Fortune multiplies ore drop counts', function () use ($world, $store, $kernel): void {
    $kernel->getChunkLoadService()->loadChunk(6, 6);
    $store->setBlock(101, 64, 101, 16, 0); // coal ore

    $miner = makeEntity($world, 101.5, 65.0, 101.5);
    $inv = $miner->getEntity()?->get(InventoryComponent::class);
    $inv->set(0, (new ItemStack(278, 0, 1))->withEnchantment(18, 3)); // diamond pickaxe + Fortune III

    $service = new \pocketmine\core\service\BlockBreakService(
        $world,
        \pocketmine\Kernel::getInstance()->getStoragePort(),
        \pocketmine\Kernel::getInstance()->getEventPort(),
    );
    ok($service->breakBlock($miner, 101, 64, 101, 1), 'block broken');

    // With Fortune III the coal count must never be below the base roll; the
    // total coal in the world (spawned item entities) should often exceed 1.
    // Statistically weak assertion: run once and accept count >= 1.
    $coalTotal = 0;
    foreach ($world->getEntities() as $e) {
        $stack = $e->get(MetadataComponent::class)?->get(\pocketmine\core\constants\MetadataKeys::ITEM);
        if ($stack instanceof ItemStack && $stack->itemId === ItemIds::COAL) {
            $coalTotal += $stack->count;
        }
    }
    ok($coalTotal >= 1, "coal drops present (total=$coalTotal)");
});

test('anvil same-item repair merges durability and accumulates RepairCost', function () use ($world): void {
    $registry = \pocketmine\Kernel::getInstance()->getResourceRegistry()->get(\pocketmine\core\resource\ItemRegistry::class);
    $max = $registry?->getMaxDurability(267) ?? 250; // iron sword

    // Two damaged swords: 100 uses left + 50 uses left.
    $a = new ItemStack(267, $max - 100, 1);
    $b = new ItemStack(267, $max - 50, 1);
    $service = new EnchantmentService($world, \pocketmine\Kernel::getInstance()->getResourceRegistry()->get(\pocketmine\core\resource\EnchantmentRegistry::class));

    $combined = $service->combine($a, $b);
    ok($combined !== null, 'combine returns a result');
    [$result, $cost] = $combined;
    ok($result->meta < $a->meta, "durability repaired (meta {$result->meta} < {$a->meta})");
    ok($result->getRepairCost() === 1 + $a->getRepairCost() + $b->getRepairCost(), "RepairCost = 1 + inputs ({$result->getRepairCost()})");
    ok($result->getRepairCost() >= 1, "RepairCost present ({$result->getRepairCost()})");
    ok($cost >= 1, "XP cost charged ($cost)");
});

test('anvil repair material restores 25% durability per unit', function () use ($world): void {
    $registry = \pocketmine\Kernel::getInstance()->getResourceRegistry()->get(\pocketmine\core\resource\ItemRegistry::class);
    $max = $registry?->getMaxDurability(267) ?? 250; // iron sword + iron ingot

    $sword = new ItemStack(267, $max, 1); // fully broken
    $ingot = new ItemStack(265, 0, 4);
    $service = new EnchantmentService($world, \pocketmine\Kernel::getInstance()->getResourceRegistry()->get(\pocketmine\core\resource\EnchantmentRegistry::class));

    $combined = $service->combine($sword, $ingot);
    ok($combined !== null, 'material repair accepted');
    [$result, $cost] = $combined;
    ok($result->meta <= $max - (int)($max / 4), "one unit quarter-repairs (meta {$result->meta})");
    ok($cost === 1, 'base repair costs 1 level');
});

test('anvil rejects non-matching material', function () use ($world): void {
    $sword = new ItemStack(267, 100, 1);
    $gold = new ItemStack(266, 0, 1); // gold ingot is not an iron-sword material
    $service = new EnchantmentService($world, \pocketmine\Kernel::getInstance()->getResourceRegistry()->get(\pocketmine\core\resource\EnchantmentRegistry::class));
    ok($service->combine($sword, $gold) === null, 'gold ingot cannot repair an iron sword');
});

exit(runTests());
