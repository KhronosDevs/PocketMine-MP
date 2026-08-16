<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\EffectComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\PotionRegistry;
use pocketmine\Kernel;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use pocketmine\protocol\MobEffectPacket;
use pocketmine\utils\Binary;

/**
 * Potion application (14.23).
 *
 * Applies a potion's effect (from PotionRegistry) to an entity:
 *   - instant effects (healing/harming, duration 1) change health immediately
 *     (healing heals, harming damages - harming through CombatService so the
 *     full damage pipeline runs)
 *   - timed effects go into the entity's EffectComponent, where EffectSystem
 *     ticks the countdown (amplifier/potency is stored for future stat
 *     systems; the client shows the icon via MobEffectPacket)
 *
 * Splash potions call applySplash(): every living entity within the legacy
 * 6-block radius gets the effect (full strength at the center).
 */
final class PotionService {

    public function __construct(
        private readonly World $world,
        private readonly NetworkPort $networkPort,
        private readonly CombatService $combatService,
    ) {}

    /**
     * Apply a potion to a single entity (drinking / splash center hit).
     *
     * @param array{0: int, 1: int, 2: int}|null $effect [effectId, duration, amplifier]
     */
    public function apply(EntityRef $targetRef, ?array $effect, bool $splash = false): void {
        if ($effect === null) {
            return;
        }
        [$effectId, $duration, $amplifier] = $effect;
        $entity = $targetRef->getEntity();
        if ($entity === null) {
            return;
        }

        // Instant effects resolve immediately; splash halves the potency the
        // further the target is, but the registry stores center potency and the
        // caller scales distance (see applySplash).
        if ($effectId === PotionRegistry::EFFECT_HEALING) {
            $health = $entity->get(HealthComponent::class);
            if ($health !== null) {
                $amount = 4 * ($amplifier + 1); // legacy: 4 hearts per level
                $health->current = min($health->max, $health->current + $amount);
            }
            return;
        }
        if ($effectId === PotionRegistry::EFFECT_HARMING) {
            $amount = 6 * ($amplifier + 1); // legacy harming damage
            $this->combatService->applyDamage(
                $targetRef,
                (float)$amount,
                null,
                \pocketmine\api\event\EntityDamageEvent::CAUSE_MAGIC,
            );
            return;
        }

        // Timed effect: add to the component, then broadcast the client icon.
        $effects = $entity->get(EffectComponent::class);
        if ($effects === null) {
            $effects = new EffectComponent();
            $entity->set(EffectComponent::class, $effects);
        }
        $effects->add($effectId, $amplifier, $duration);
        $this->broadcastEffect($targetRef, $effectId, $amplifier, $duration, true);
    }

    /**
     * Splash: apply to every living entity within 6 blocks of (x, y, z),
     * with potency halving by distance (legacy splash falloff).
     *
     * @param array{0: int, 1: int, 2: int}|null $effect
     */
    public function applySplash(float $x, float $y, float $z, ?array $effect, ?EntityRef $source = null): void {
        if ($effect === null) {
            return;
        }
        [$effectId, $duration, $amplifier] = $effect;
        $sourceId = $source?->getId() ?? -1;
        foreach ($this->world->getEntities() as $id => $entity) {
            if ($id === $sourceId) {
                continue;
            }
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            $dx = $pos->x - $x;
            $dy = $pos->y - $y;
            $dz = $pos->z - $z;
            $distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            if ($distance > 6.0) {
                continue;
            }
            // Distance falloff: full potency at the center, halved each ~3
            // blocks (legacy: (1 - distance/6) scaling on the amplifier).
            $scaledAmplifier = (int)floor($amplifier + (1 - $distance / 6.0) - 1);
            $this->apply(EntityRef::create($id, $this->world), [$effectId, $duration, max(0, $scaledAmplifier)], true);
        }
    }

    /** Send MobEffectPacket (ADD) to the target player's session + viewers. */
    private function broadcastEffect(EntityRef $targetRef, int $effectId, int $amplifier, int $duration, bool $particles): void {
        $entity = $targetRef->getEntity();
        if ($entity === null || !$entity->has(PlayerTag::class)) {
            return; // non-player effects have no session to push to
        }
        $pk = new MobEffectPacket();
        $pk->eid = $targetRef->getId();
        $pk->eventId = MobEffectPacket::EVENT_ADD;
        $pk->effectId = $effectId;
        $pk->amplifier = $amplifier;
        $pk->particles = $particles;
        $pk->duration = $duration;
        // Resolve the connected session's PlayerRef so the adapter can route
        // the packet to the right socket (NetworkPort addresses sessions, not
        // ECS entities).
        $playerRef = Kernel::getInstance()?->getNetworkSessionService()?->getPlayerRefByEntity($targetRef->getId());
        if ($playerRef instanceof PlayerRef) {
            $this->networkPort->sendPacket($playerRef, $pk);
        }
    }
}
