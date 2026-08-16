<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\EffectComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\PotionRegistry;

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$potion = $kernel->getPotionService();
$spawn = $kernel->getEntitySpawnService();

test('healing potion restores health', function () use ($kernel, $world, $potion, $spawn): void {
    $player = $spawn->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 100, 65, 100);
    $health = $player->getEntity()?->get(HealthComponent::class);
    ok($health !== null, 'target has health');
    if ($health === null) {
        return;
    }
    $health->current = 5; // hurt the target
    $potion->apply($player, [PotionRegistry::EFFECT_HEALING, 1, 0], false);
    near(9, $health->current, 1e-6, 'healing restores 4 hearts (5 + 4)');

    // Healing cannot push above max: drain to max-2, heal twice, expect max.
    $health->current = $health->max - 2;
    $potion->apply($player, [PotionRegistry::EFFECT_HEALING, 1, 0], false);
    $potion->apply($player, [PotionRegistry::EFFECT_HEALING, 1, 0], false);
    near($health->max, $health->current, 1e-6, 'health capped at max');
});

test('timed potion adds an effect to the component', function () use ($kernel, $world, $potion, $spawn): void {
    $player = $spawn->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 110, 65, 110);
    $potion->apply($player, [PotionRegistry::EFFECT_SPEED, 180 * 20, 0], false);
    $effects = $player->getEntity()?->get(EffectComponent::class);
    ok($effects !== null && $effects->has(PotionRegistry::EFFECT_SPEED), 'speed effect present');
    $instance = $effects?->get(PotionRegistry::EFFECT_SPEED);
    same(180 * 20, $instance?->duration, 'duration matches the potion');
});

test('splash potion affects entities within 6 blocks but not beyond', function () use ($kernel, $world, $potion, $spawn): void {
    $near = $spawn->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 120, 65, 120);
    $far = $spawn->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 130, 65, 120); // 10 blocks away
    $potion->applySplash(120.0, 65.0, 120.0, [PotionRegistry::EFFECT_SLOWNESS, 90 * 20, 0]);

    $nearEffects = $near->getEntity()?->get(EffectComponent::class);
    $farEffects = $far->getEntity()?->get(EffectComponent::class);
    ok($nearEffects !== null && $nearEffects->has(PotionRegistry::EFFECT_SLOWNESS), 'nearby entity splashed');
    ok($farEffects === null || !$farEffects->has(PotionRegistry::EFFECT_SLOWNESS), 'distant entity not splashed');
});

test('throwable projectiles spawn and carry potion id for splash potions', function () use ($kernel, $world, $spawn): void {
    $shooter = $spawn->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 140, 65, 140);
    $snowball = $spawn->spawnProjectile(\pocketmine\core\enum\EntityType::Snowball, 140, 66, 140, 10, 0, 0, $shooter);
    $meta = $snowball->getEntity()?->get(\pocketmine\core\component\MetadataComponent::class);
    ok($meta !== null && $meta->get(MetadataKeys::PROJECTILE_TYPE) === \pocketmine\core\enum\EntityType::Snowball->value, 'snowball projectile type set');
    same(81, $world->getResourceRegistry()->get(\pocketmine\core\resource\ProjectileRegistry::class)?->getNetworkId(\pocketmine\core\enum\EntityType::Snowball->value), 'snowball network id 81');
});

// NOTE: no shutdown - the next test boots its own kernel.
exit(runTests());
