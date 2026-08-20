<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\protocol\LevelEventPacket;

/**
 * Unified sound + particle broadcast service.
 *
 * Both sounds and particles travel over the same wire packet
 * (LevelEventPacket).  Sounds use event IDs 1000-3507; particles use
 * EVENT_ADD_PARTICLE_MASK (0x4000) | particle type.
 *
 * Every public method creates a LevelEventPacket and hands it to
 * NetworkSessionService::broadcastWorldEvent() which sends it to every
 * session in the same world that has the relevant chunk loaded.
 */
final class WorldEventService {

    // ── Sound event IDs ────────────────────────────────────────────────
    public const SOUND_CLICK              = 1000;
    public const SOUND_CLICK_FAIL         = 1001;
    public const SOUND_SHOOT              = 1002;
    public const SOUND_DOOR               = 1003;
    public const SOUND_FIZZ               = 1004;
    public const SOUND_TNT                = 1005;
    public const SOUND_GHAST              = 1007;
    public const SOUND_GHAST_SHOOT        = 1008;
    public const SOUND_BLAZE_SHOOT        = 1009;
    public const SOUND_DOOR_BUMP          = 1010;
    public const SOUND_DOOR_CRASH         = 1012;
    public const SOUND_BAT_FLY            = 1015;
    public const SOUND_ZOMBIE_INFECT      = 1016;
    public const SOUND_ZOMBIE_HEAL        = 1017;
    public const SOUND_ENDERMAN_TELEPORT  = 1018;
    public const SOUND_ANVIL_BREAK        = 1020;
    public const SOUND_ANVIL_USE          = 1021;
    public const SOUND_ANVIL_FALL         = 1022;
    public const SOUND_DROP_ITEM          = 1030;
    public const SOUND_THROW_PROJECTILE   = 1031;
    public const SOUND_ITEMFRAME_ADD      = 1040;
    public const SOUND_ITEMFRAME_PLACE    = 1041;
    public const SOUND_ITEMFRAME_DROP     = 1043;
    public const SOUND_ITEMFRAME_ROTATE   = 1044;
    public const SOUND_EXP_PICKUP         = 1051;
    public const SOUND_BLOCK_PLACE        = 1052;
    public const SOUND_BUTTON_CLICK       = 3500;
    public const SOUND_EXPLODE            = 3501;
    public const SOUND_SPELL              = 3504;
    public const SOUND_SPLASH             = 3506;
    public const SOUND_GRAY_SPLASH        = 3507;

    // ── Particle event IDs ─────────────────────────────────────────────
    public const PARTICLE_SHOOT           = 2000;
    public const PARTICLE_DESTROY         = 2001;
    public const PARTICLE_SPLASH          = 2002;
    public const PARTICLE_EYE_DESPAWN     = 2003;
    public const PARTICLE_SPAWN           = 2004;

    // ── Generic particle type IDs (OR with EVENT_ADD_PARTICLE_MASK) ────
    public const TYPE_BUBBLE              = 1;
    public const TYPE_CRITICAL            = 2;
    public const TYPE_SMOKE               = 3;
    public const TYPE_EXPLODE             = 4;
    public const TYPE_WHITE_SMOKE         = 5;
    public const TYPE_FLAME               = 6;
    public const TYPE_LAVA                = 7;
    public const TYPE_LARGE_SMOKE         = 8;
    public const TYPE_REDSTONE            = 9;
    public const TYPE_ITEM_BREAK          = 10;
    public const TYPE_SNOWBALL_POOF       = 11;
    public const TYPE_LARGE_EXPLODE       = 12;
    public const TYPE_HUGE_EXPLODE        = 13;
    public const TYPE_MOB_FLAME           = 14;
    public const TYPE_HEART               = 15;
    public const TYPE_TERRAIN             = 16;
    public const TYPE_TOWN_AURA           = 17;
    public const TYPE_PORTAL              = 18;
    public const TYPE_WATER_SPLASH        = 19;
    public const TYPE_WATER_WAKE          = 20;
    public const TYPE_DRIP_WATER          = 21;
    public const TYPE_DRIP_LAVA           = 22;
    public const TYPE_DUST                = 23;
    public const TYPE_MOB_SPELL           = 24;
    public const TYPE_MOB_SPELL_AMBIENT   = 25;
    public const TYPE_MOB_SPELL_INSTANT   = 26;
    public const TYPE_INK                 = 27;
    public const TYPE_SLIME               = 28;
    public const TYPE_RAIN_SPLASH         = 29;
    public const TYPE_VILLAGER_ANGRY      = 30;
    public const TYPE_VILLAGER_HAPPY      = 31;
    public const TYPE_ENCHANTMENT_TABLE   = 32;

    // ── World event IDs ────────────────────────────────────────────────
    public const EVENT_START_RAIN         = 3001;
    public const EVENT_START_THUNDER      = 3002;
    public const EVENT_STOP_RAIN          = 3003;
    public const EVENT_STOP_THUNDER       = 3004;

    /** @param NetworkSessionService|object $sessions */
    public function __construct(
        private readonly object $sessions,
    ) {}

    // ── Low-level: send a raw LevelEventPacket ─────────────────────────

    /**
     * Send a sound or particle event to every session in $worldId that
     * has chunk ($chunkX, $chunkZ) loaded.
     */
    public function broadcastEvent(
        int $worldId,
        int $chunkX,
        int $chunkZ,
        int $evid,
        float $x,
        float $y,
        float $z,
        int $data = 0,
    ): void {
        $pk = new LevelEventPacket();
        $pk->evid = $evid;
        $pk->x = $x;
        $pk->y = $y;
        $pk->z = $z;
        $pk->data = $data;
        $this->sessions->broadcastWorldEvent($worldId, $chunkX, $chunkZ, $pk);
    }

    // ── Sound helpers ──────────────────────────────────────────────────

    public function playSound(
        int $worldId,
        int $chunkX,
        int $chunkZ,
        float $x,
        float $y,
        float $z,
        int $soundId,
        int $data = 0,
    ): void {
        $this->broadcastEvent($worldId, $chunkX, $chunkZ, $soundId, $x, $y, $z, $data);
    }

    public function playBlockPlaceSound(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z, int $blockId): void {
        $this->playSound($worldId, $chunkX, $chunkZ, $x, $y, $z, self::SOUND_BLOCK_PLACE, $blockId);
    }

    public function playBlockBreakSound(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        // EVENT_PARTICLE_DESTROY plays the break sound + particles for the
        // block id in $data.
        $this->playSound($worldId, $chunkX, $chunkZ, $x, $y, $z, self::PARTICLE_DESTROY);
    }

    public function playExplodeSound(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->playSound($worldId, $chunkX, $chunkZ, $x, $y, $z, self::SOUND_EXPLODE);
    }

    public function playDoorSound(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->playSound($worldId, $chunkX, $chunkZ, $x, $y, $z, self::SOUND_DOOR);
    }

    public function playFizzSound(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->playSound($worldId, $chunkX, $chunkZ, $x, $y, $z, self::SOUND_FIZZ);
    }

    public function playTntSound(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->playSound($worldId, $chunkX, $chunkZ, $x, $y, $z, self::SOUND_TNT);
    }

    public function playClickSound(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->playSound($worldId, $chunkX, $chunkZ, $x, $y, $z, self::SOUND_CLICK);
    }

    public function playExpPickupSound(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->playSound($worldId, $chunkX, $chunkZ, $x, $y, $z, self::SOUND_EXP_PICKUP);
    }

    public function playDropItemSound(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->playSound($worldId, $chunkX, $chunkZ, $x, $y, $z, self::SOUND_DROP_ITEM);
    }

    public function playThrowSound(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->playSound($worldId, $chunkX, $chunkZ, $x, $y, $z, self::SOUND_THROW_PROJECTILE);
    }

    // ── Particle helpers ───────────────────────────────────────────────

    /**
     * Spawn a generic particle (type from TYPE_* constants).
     */
    public function spawnParticle(
        int $worldId,
        int $chunkX,
        int $chunkZ,
        float $x,
        float $y,
        float $z,
        int $particleType,
        int $data = 0,
    ): void {
        $evid = LevelEventPacket::EVENT_ADD_PARTICLE_MASK | ($particleType & 0xFFF);
        $this->broadcastEvent($worldId, $chunkX, $chunkZ, $evid, $x, $y, $z, $data);
    }

    /**
     * Destroy-block particle + sound.  $data = blockId + (meta << 12).
     */
    public function spawnBlockBreakParticle(
        int $worldId,
        int $chunkX,
        int $chunkZ,
        float $x,
        float $y,
        float $z,
        int $blockId,
        int $meta = 0,
    ): void {
        $this->broadcastEvent(
            $worldId, $chunkX, $chunkZ,
            self::PARTICLE_DESTROY,
            $x, $y, $z,
            $blockId + ($meta << 12),
        );
    }

    public function spawnSmokeParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z, int $scale = 0): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_SMOKE, $scale);
    }

    public function spawnCriticalParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z, int $scale = 2): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_CRITICAL, $scale);
    }

    public function spawnFlameParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_FLAME);
    }

    public function spawnHeartParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_HEART);
    }

    public function spawnLavaParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_LAVA);
    }

    public function spawnWaterSplashParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_WATER_SPLASH);
    }

    public function spawnDustParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z, int $data = 0): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_DUST, $data);
    }

    public function spawnLargeExplodeParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_LARGE_EXPLODE);
    }

    public function spawnHugeExplodeParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_HUGE_EXPLODE);
    }

    public function spawnTerrainParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z, int $data = 0): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_TERRAIN, $data);
    }

    public function spawnItemBreakParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z, int $data = 0): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_ITEM_BREAK, $data);
    }

    public function spawnPortalParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_PORTAL);
    }

    public function spawnRainSplashParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_RAIN_SPLASH);
    }

    public function spawnEnchantmentTableParticle(int $worldId, int $chunkX, int $chunkZ, float $x, float $y, float $z): void {
        $this->spawnParticle($worldId, $chunkX, $chunkZ, $x, $y, $z, self::TYPE_ENCHANTMENT_TABLE);
    }
}
