<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;

/**
 * Projectile registry (14.18).
 *
 * The single source of truth for every projectile type the server can spawn:
 * name => the legacy 0.15 network id the client renders for it, plus the
 * defining stats (damage, gravity, drag) and behaviour (sticky). Bows fire
 * 'Arrow' (network id 80); future projectiles (snowballs, eggs, ...) register
 * here and automatically gain spawn, rendering and behaviour wiring -
 * EntitySpawnService, NetworkSessionService and ArrowSystem all consult this
 * registry instead of hard-coding type names and ids.
 *
 * NOTE: only `drag` and `sticky` are consumed today. `damage` is used for
 * entity hits, and `gravity` is reserved for future projectiles with custom
 * physics - arrows ride the generic PhysicsSystem (1.6 blocks/s^2) like every
 * other entity, so their registry gravity is informational.
 */
#[Resource]
final class ProjectileRegistry {

    /**
     * @var array<string, array{networkId: int, damage: float, gravity: float, drag: float, sticky: bool}>
     */
    private array $projectiles = [];

    /**
     * Register a projectile type.
     *
     * @param float $damage  base damage the projectile deals on entity hit
     * @param float $gravity gravity in blocks/tick^2 (legacy Projectile subclass)
     * @param float $drag    per-tick velocity retention loss (legacy 0.01)
     * @param bool  $sticky  whether entity hits embed the projectile in the
     *                       victim (arrows) or despawn it (snowballs, eggs)
     */
    public function register(string $name, int $networkId, float $damage = 2.0, float $gravity = 0.05, float $drag = 0.01, bool $sticky = true): void {
        $this->projectiles[$name] = [
            'networkId' => $networkId,
            'damage' => $damage,
            'gravity' => $gravity,
            'drag' => $drag,
            'sticky' => $sticky,
        ];
    }

    public function isProjectile(string $name): bool {
        return isset($this->projectiles[$name]);
    }

    /**
     * The protocol-84 network id for a projectile type, or null when the name
     * is not a registered projectile.
     */
    public function getNetworkId(string $name): ?int {
        return $this->projectiles[$name]['networkId'] ?? null;
    }

    /**
     * @return array{networkId: int, damage: float, gravity: float, drag: float, sticky: bool}|null
     */
    public function get(string $name): ?array {
        return $this->projectiles[$name] ?? null;
    }

    /**
     * @return array<string, array{networkId: int, damage: float, gravity: float, drag: float, sticky: bool}>
     */
    public function all(): array {
        return $this->projectiles;
    }
}
