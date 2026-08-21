<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\api\event\EntityDamageEvent;
use pocketmine\api\event\EntityDeathEvent;
use pocketmine\api\event\PlayerDeathEvent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;

/**
 * Phase 12.2: combat / death / drops.
 *
 * The three previously-duplicated death paths (CombatService, DamageService,
 * EntityInteractionService) now funnel through CombatService::applyDamage /
 * kill: cancellable damage event, armor reduction, knockback, cancellable
 * death events, inventory + mob-loot drops, XP orbs, then despawn.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$combat = $kernel->getCombatService();
$damage = $kernel->getDamageService();
$eventPort = $kernel->getEventPort();

function spawnCombatant(World $world, float $x, float $z, string $entityType, array $meta = []): EntityRef {
    return $world->spawn(
        (new EntityBuilder())
            ->at($x, 65, $z)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(array_merge(['entityType' => $entityType], $meta)))
            ->with(new InventoryComponent(36))
    );
}

/** Flush queued despawns (World::despawn is applied on the next tick). */
function flushWorld(World $world): void {
    $world->tick(0.05);
}

test('creative players are immune to entity attacks; survival players are not', function () use ($world, $combat) {
    $creative = spawnCombatant($world, 200, 200, 'Player', ['gamemode' => 1]);
    $blocked = $combat->applyDamage(
        EntityRef::create($creative->getId(), $world),
        10.0,
        null,
        EntityDamageEvent::CAUSE_ENTITY_ATTACK,
    );
    same(false, $blocked, 'creative target rejects entity-attack damage');
    same(20.0, $creative->getEntity()?->get(HealthComponent::class)?->current, 'creative health unchanged');

    $survival = spawnCombatant($world, 201, 201, 'Player');
    $applied = $combat->applyDamage(
        EntityRef::create($survival->getId(), $world),
        4.0,
        null,
        EntityDamageEvent::CAUSE_ENTITY_ATTACK,
    );
    same(true, $applied, 'survival target takes entity-attack damage');
    near(16.0, $survival->getEntity()?->get(HealthComponent::class)?->current, 1e-9, 'survival health reduced');

    $world->despawn($creative->getEntity());
    $world->despawn($survival->getEntity());
    $world->tick(0.05);
});

test('damage event fires, is cancellable, and honors modified damage', function () use ($world, $combat, $eventPort) {
    $target = spawnCombatant($world, 100, 100, 'Zombie');

    $fired = 0;
    $cancelled = false;
    $eventPort->subscribe(EntityDamageEvent::class, function (EntityDamageEvent $e) use (&$fired, &$cancelled) {
        $fired++;
        if ($e->getCause() === EntityDamageEvent::CAUSE_MAGIC) {
            $cancelled = true;
            $e->setCancelled(true);
        }
        if ($e->getCause() === EntityDamageEvent::CAUSE_FIRE) {
            $e->setDamage(5.0); // override to 5
        }
    });

    // Cancelled damage: applyDamage returns false, health unchanged.
    same(false, $combat->applyDamage($target, 10.0, null, EntityDamageEvent::CAUSE_MAGIC), 'cancelled damage returns false');
    same(1, $fired, 'damage event fired');
    same(true, $cancelled, 'event was cancelled');
    near(20.0, $target->getEntity()?->get(HealthComponent::class)->current, 1e-9, 'health unchanged after cancelled damage');

    // Modified damage: 5 applied instead of 20.
    same(true, $combat->applyDamage($target, 20.0, null, EntityDamageEvent::CAUSE_FIRE), 'applyDamage returns true');
    near(15.0, $target->getEntity()?->get(HealthComponent::class)->current, 1e-9, '5 damage applied after event override');

    $world->despawn($target->getEntity());
    flushWorld($world);
});

test('fatal damage fires death event, drops inventory + loot, spawns XP, despawns', function () use ($world, $combat, $eventPort) {
    $target = spawnCombatant($world, 120, 100, 'Zombie');
    $target->getEntity()?->get(InventoryComponent::class)->set(0, new ItemStack(367, 0, 2)); // rotten flesh in inventory

    $deathFired = 0;
    $eventPort->subscribe(EntityDeathEvent::class, function (EntityDeathEvent $e) use (&$deathFired) {
        // Guard: handlers persist across tests; only assert on our zombie.
        if ($e->getEntity()?->getMetadata()->get('entityType') !== 'Zombie') {
            return;
        }
        $deathFired++;
        same('Zombie', $e->getEntity()?->getMetadata()->get('entityType'), 'death event entity is the zombie');
    });

    $before = count($world->getEntities());
    same(true, $combat->applyDamage($target, 100.0), 'fatal damage applied');
    same(1, $deathFired, 'death event fired');
    flushWorld($world);
    ok(!$target->isValid(), 'entity despawned after death');

    // Inventory contents + at least one mob-loot roll should now be item entities.
    $itemEntities = 0;
    $xpOrbs = 0;
    foreach ($world->getEntities() as $entity) {
        $tags = $entity->getComponents();
        if (isset($tags['item'])) {
            $itemEntities++;
        }
        if (isset($tags['xp_orb'])) {
            $xpOrbs++;
        }
    }
    ok($itemEntities >= 1, 'at least one item entity dropped (inventory), got ' . $itemEntities);
    same(1, $xpOrbs, 'one XP orb spawned');

    // Clean up the spawned drops.
    foreach ($world->getEntities() as $entity) {
        $world->despawn($entity);
    }
    flushWorld($world);
    ok(count($world->getEntities()) < $before, 'drops cleaned up');
});

test('death event is cancellable: entity survives, no drops, no despawn', function () use ($world, $combat, $eventPort) {
    $target = spawnCombatant($world, 140, 100, 'Cow');
    $target->getEntity()?->get(InventoryComponent::class)->set(0, new ItemStack(334, 0, 1)); // leather

    $eventPort->subscribe(EntityDeathEvent::class, function (EntityDeathEvent $e) {
        if ($e->getEntity()?->getMetadata()->get('entityType') === 'Cow') {
            $e->setCancelled(true);
        }
    });

    $before = count($world->getEntities());
    same(true, $combat->applyDamage($target, 100.0), 'fatal damage applied');
    ok($target->isValid(), 'entity survives cancelled death');
    $health = $target->getEntity()?->get(HealthComponent::class);
    ok($health !== null && $health->current > 0, 'health restored after cancelled death');
    flushWorld($world);
    same(count($world->getEntities()), $before, 'no drops spawned for cancelled death');

    $world->despawn($target->getEntity());
    flushWorld($world);
});

test('player death fires PlayerDeathEvent with death message and drops inventory', function () use ($world, $combat, $eventPort) {
    $player = $world->spawn(
        (new EntityBuilder())
            ->at(160, 65, 100)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['username' => 'Steve', 'entityType' => 'Player']))
            ->with(new InventoryComponent(36))
            ->withTag(PlayerTag::class)
    );
    $player->getEntity()?->get(InventoryComponent::class)->set(0, new ItemStack(267, 0, 1)); // iron sword

    $playerDeathFired = 0;
    $eventPort->subscribe(PlayerDeathEvent::class, function (PlayerDeathEvent $e) use (&$playerDeathFired) {
        $playerDeathFired++;
        same('Steve', $e->getPlayer()->getName(), 'player event carries the player');
        ok($e->getDeathMessage() !== '', 'death message not empty: ' . $e->getDeathMessage());
    });

    same(true, $combat->applyDamage($player, 50.0), 'fatal damage applied');
    same(1, $playerDeathFired, 'player death event fired');
    flushWorld($world);
    // 14.3: players stay in the world as a dead corpse (DeadTag + 0 health)
    // so PlayerRespawnService can revive them; only mobs despawn on death.
    ok($player->isValid(), 'player stays in the world after death (corpse for respawn)');
    $deadTag = $player->getEntity()?->has(\pocketmine\core\component\tags\DeadTag::class);
    same(true, $deadTag, 'player carries DeadTag after death');

    // Iron sword should be among the dropped item entities.
    $dropped = false;
    foreach ($world->getEntities() as $entity) {
        $meta = $entity->get(MetadataComponent::class);
        $item = $meta?->get('item');
        if ($item instanceof ItemStack && $item->itemId === 267) {
            $dropped = true;
        }
    }
    ok($dropped, 'player inventory dropped as item entity');

    foreach ($world->getEntities() as $entity) {
        $world->despawn($entity);
    }
    flushWorld($world);
});

test('kill() fires death without damage event; DamageService routes to combat', function () use ($world, $combat, $damage, $eventPort) {
    // kill() bypasses the damage event but still fires death + drops.
    $victim = spawnCombatant($world, 180, 100, 'Skeleton');
    $damageFired = 0;
    $eventPort->subscribe(EntityDamageEvent::class, function () use (&$damageFired) {
        $damageFired++;
    });
    $combat->kill($victim);
    same(0, $damageFired, 'kill() does not fire a damage event');
    flushWorld($world);
    ok(!$victim->isValid(), 'kill() despawned the entity');

    // DamageService::applyDamage goes through the full combat pipeline.
    $victim2 = spawnCombatant($world, 200, 100, 'Pig');
    same(true, $damage->applyDamage($victim2, 3.0), 'DamageService applies damage');
    $health = $victim2->getEntity()?->get(HealthComponent::class);
    near(17.0, $health->current, 1e-9, '3 damage applied via DamageService');
    ok($damage->isAlive($victim2), 'still alive');
    same(true, $damage->applyDamage($victim2, 100.0), 'fatal via DamageService');
    flushWorld($world);
    ok(!$victim2->isValid(), 'fatal DamageService damage despawned entity');

    // isAlive() agrees with despawn after kill() (kill zeroes health).
    // Not a Cow: an earlier test's cancellable-death handler targets Cows.
    $victim3 = spawnCombatant($world, 220, 100, 'Pig');
    $combat->kill($victim3);
    ok(!$damage->isAlive($victim3), 'kill() makes isAlive() false immediately');
    flushWorld($world);

    foreach ($world->getEntities() as $entity) {
        $world->despawn($entity);
    }
    flushWorld($world);
});

test('EntityInteractionService::attack delegates to combat pipeline', function () use ($world, $kernel, $combat) {
    $attacker = spawnCombatant($world, 240, 100, 'Player', ['username' => 'Alex']);
    $target = spawnCombatant($world, 241, 100, 'Zombie');

    $interaction = $kernel->getEntityInteractionService();
    $targetHealth = $target->getEntity()?->get(HealthComponent::class);

    // 1 base damage from bare hands: health 20 -> 19, no death.
    same(true, $interaction->attack($attacker, $target), 'attack landed');
    near(19.0, $targetHealth->current, 1e-9, '1 damage applied through the combat pipeline');

    // Sword attack: base 1 + iron sword 4 = 5 damage.
    $attacker->getEntity()?->get(InventoryComponent::class)->set(0, new ItemStack(267, 0, 1));
    same(true, $interaction->attack($attacker, $target), 'sword attack landed');
    near(14.0, $targetHealth->current, 1e-9, '5 damage (base + weapon) applied');

    // Fatal: finish the zombie through the same pipeline.
    same(true, $combat->applyDamage($target, 20.0), 'finish the zombie');
    flushWorld($world);
    ok(!$target->isValid(), 'zombie despawned via attack -> combat death path');

    foreach ($world->getEntities() as $entity) {
        $world->despawn($entity);
    }
    flushWorld($world);
});

test('api Entity::kill() facade routes through the unified death pipeline', function () use ($world, $eventPort) {
    $core = spawnCombatant($world, 260, 100, 'Zombie');
    $apiEntity = \pocketmine\api\entity\Entity::wrap($core, $world);

    $deathFired = 0;
    $eventPort->subscribe(EntityDeathEvent::class, function (EntityDeathEvent $e) use (&$deathFired) {
        if ($e->getEntity()?->getMetadata()->get('entityType') !== 'Zombie') {
            return;
        }
        $deathFired++;
    });

    $apiEntity->kill();
    same(1, $deathFired, 'Entity::kill() fired the death event');
    flushWorld($world);
    ok(!$core->isValid(), 'Entity::kill() despawned the entity');

    foreach ($world->getEntities() as $entity) {
        $world->despawn($entity);
    }
    flushWorld($world);
});

test('knockback follows old-src Living::knockBack semantics', function () use ($world, $combat): void {
    // Source west of target: impulse pushes the target east (+x).
    $source = $world->spawn(
        (new EntityBuilder())
            ->at(10, 65, 10)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['entityType' => 'zombie']))
            ->with(new \pocketmine\core\component\VelocityComponent())
    );
    $target = $world->spawn(
        (new EntityBuilder())
            ->at(13, 65, 10)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['entityType' => 'zombie']))
            ->with(new \pocketmine\core\component\VelocityComponent(8.0, 4.0, 0.0))
    );

    $combat->applyKnockback($source, $target, 5.0);

    $vel = $world->getEntity($target->getId())?->get(\pocketmine\core\component\VelocityComponent::class);
    ok($vel !== null, 'target still has a velocity component');
    if ($vel !== null) {
        // Per-tick: prior (0.4, 0.2) halved + 0.4/tick eastward impulse,
        // vertical capped at the 0.4/tick base. Stored as blocks/s (x20).
        ok(abs($vel->x - 12.0) < 1e-9, 'horizontal = halved prior + fixed 0.4/tick impulse (' . $vel->x . ')');
        ok(abs($vel->y - 8.0) < 1e-9, 'vertical capped at base (0.4/tick = 8 b/s)');
        ok(abs($vel->z) < 1e-9, 'no lateral drift on an axis-aligned hit');
    }

    // Second hit: prior motion halved again - growth is bounded, the old
    // += accumulation that made victims drift forever is gone.
    $combat->applyKnockback($source, $target, 5.0);
    $vel = $world->getEntity($target->getId())?->get(\pocketmine\core\component\VelocityComponent::class);
    if ($vel !== null) {
        ok(abs($vel->x - 14.0) < 1e-9, 'second hit: halving keeps accumulation bounded (' . $vel->x . ')');
    }
});

test('mobs carry drag so knockback decays instead of sliding forever', function () use ($kernel, $world): void {
    $pig = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Pig, 100, 90, 100);
    $entity = $pig->getEntity();
    ok($entity !== null && $entity->has(\pocketmine\core\component\DragComponent::class), 'spawnMob attaches DragComponent');

    // Decay proof on a non-AI drag carrier (a mob's AISystem re-writes its
    // velocity every tick, which would mask the physics here): same
    // DragComponent archetype path the mob's knockback residue goes through.
    $item = $world->spawn(
        (new EntityBuilder())
            ->at(50, 90, 50)
            ->with(new \pocketmine\core\component\PositionComponent(50, 90, 50))
            ->with(new \pocketmine\core\component\VelocityComponent(16.0, 0.0, 0.0))
            ->with(new \pocketmine\core\component\DragComponent())
    );
    $vel = $world->getEntity($item->getId())?->get(\pocketmine\core\component\VelocityComponent::class);
    ok($vel !== null, 'drag entity has a velocity component');
    if ($vel !== null) {
        for ($i = 0; $i < 10; $i++) {
            $world->tick(0.05);
        }
        ok($vel->x < 14.0 && $vel->x > 0.0, "horizontal knockback decays under drag ({$vel->x})");
    }
});

exit(runTests());
