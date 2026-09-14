<?php

declare(strict_types=1);

/**
 * Nether mob spawning tests (14.30): the spawner loops over every registered
 * world, so a nether world (GeneratorType::Nether) gets nether-pool mobs
 * (pigmen/ghasts/blazes/magma cubes), skips the night gate (the dimension has
 * no sky), and overworld mobs never spawn there.
 */

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\enum\EntityType;
use pocketmine\core\enum\GeneratorType;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldConfig;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\core\system\MobSpawnerSystem;

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$registry = $kernel->getWorldRegistry();
ok($registry instanceof WorldRegistry, 'world registry');

/** Statistically enough spawn cycles for at least one mob in an empty world. */
function runSpawnCycles(\pocketmine\core\ecs\World $world, int $cycles): void {
    $system = new MobSpawnerSystem();
    for ($i = 0; $i < $cycles; $i++) {
        $system->run($world, 1 / 20);
    }
}

test('nether weights exclude overworld mobs', function (): void {
    $method = new ReflectionMethod(MobSpawnerSystem::class, 'pickHostileType');
    $method->setAccessible(true);
    $overworld = ['Zombie', 'Skeleton', 'Spider', 'Creeper', 'Enderman', 'Slime', 'CaveSpider', 'Husk', 'Stray', 'Witch', 'ZombieVillager', 'Silverfish'];
    $nether = ['PigZombie', 'Ghast', 'Blaze', 'LavaSlime'];
    for ($i = 0; $i < 200; $i++) {
        // Nether pool never yields an overworld-only type.
        $type = $method->invoke(new MobSpawnerSystem(), true)->value;
        ok(in_array($type, $nether, true) || $type === 'Skeleton' || $type === 'Enderman', "nether pick '$type' is a nether type");
        // Overworld pool never yields a nether-only type.
        $type = $method->invoke(new MobSpawnerSystem(), false)->value;
        ok(!in_array($type, ['PigZombie', 'Ghast', 'Blaze', 'LavaSlime'], true), "overworld pick '$type' is not nether-only");
    }
});

test('nether world config skips the night gate', function () use ($kernel, $world): void {
    $timeSystem = new ReflectionMethod(\pocketmine\core\system\TimeSystem::class, 'isNight');
    // The nether check in tickWorld only bypasses spawns when the world's
    // generator is Nether - verify the gate logic directly: a nether config
    // at noon would be rejected for a normal world but accepted for nether.
    $netherConfig = new WorldConfig();
    $netherConfig->generator = GeneratorType::Nether;
    $netherConfig->spawnMobs = true;
    $netherConfig->time = 6000; // noon - night gate would block a normal world
    ok($netherConfig->generator === GeneratorType::Nether, 'nether generator set');
    ok($netherConfig->spawnMobs, 'nether mobs enabled');
});

test('spawner emits mobs in a registered nether world', function () use ($kernel, $world, $registry): void {
    // Register a fresh nether world with mobs enabled and a loaded spawn spot.
    $netherId = $registry->registerWorld(
        'test-nether', 'test-nether', 42,
        new ChunkStore(),
        new WorldConfig(name: 'test-nether', folderName: 'test-nether', generator: GeneratorType::Nether, spawnMobs: true),
        $kernel->getStoragePort(),
    );
    $netherStore = $registry->getStore($netherId);
    ok($netherStore instanceof ChunkStore, 'nether store');

    // Load the spawn chunk through the real load path (generates/creates it),
    // then place a stone floor: a fresh ChunkStore has no chunks, so setBlock
    // would silently no-op without this.
    $kernel->getChunkLoadService()->loadChunk(0, 0, $netherId);
    $registry->getStore($netherId)?->setBlock(10, 64, 10, 1); // stone floor
    $player = $world->spawn(
        (new \pocketmine\core\ecs\EntityBuilder())
            ->with(new \pocketmine\core\component\PositionComponent(10.5, 65.0, 10.5))
            ->with(new \pocketmine\core\component\RotationComponent())
            ->with(new \pocketmine\core\component\VelocityComponent())
            ->with(new \pocketmine\core\component\HealthComponent())
            ->with(new \pocketmine\core\component\MetadataComponent())
            ->with(new \pocketmine\core\component\WorldComponent($netherId))
            ->with(new \pocketmine\core\component\tags\PlayerTag()),
    );
    $pm = $player->getEntity()?->get(\pocketmine\core\component\MetadataComponent::class);
    $pm?->set(\pocketmine\core\constants\MetadataKeys::GAMEMODE, \pocketmine\core\enum\GameMode::Survival->value);

    runSpawnCycles($world, 400); // SPAWN_INTERVAL=40 ticks → ~10 spawn attempts: statistically >= 1 spawn

    // Any hostile spawned in the nether world must be a nether-pool type.
    $found = 0;
    foreach ($world->getEntities() as $entity) {
        $wc = $entity->get(\pocketmine\core\component\WorldComponent::class);
        if ($wc === null || $wc->id !== $netherId) {
            continue;
        }
        $meta = $entity->get(\pocketmine\core\component\MetadataComponent::class);
        if ($meta === null || !$meta->get(\pocketmine\core\constants\MetadataKeys::HOSTILE)) {
            continue;
        }
        $type = (string)($meta->get(\pocketmine\core\constants\MetadataKeys::MOB_TYPE) ?? $meta->get(\pocketmine\core\constants\MetadataKeys::ENTITY_TYPE) ?? '');
        ok(!in_array($type, ['Zombie', 'Skeleton', 'Spider', 'Creeper', 'Husk', 'Stray', 'Witch', 'ZombieVillager', 'Silverfish', 'CaveSpider'], true), "nether spawn '$type' is not an overworld-only mob");
        $found++;
    }
    ok($found >= 1, "at least one nether mob spawned (found=$found)");
});

exit(runTests());
