<?php

declare(strict_types=1);

namespace pocketmine\api\plugin;

use pocketmine\api\scheduler\Scheduler;
use pocketmine\core\ecs\World;
use pocketmine\Kernel;

class KernelAccessor {
    public function __construct(
        private readonly Kernel $kernel,
    ) {}

    public function getWorld(): World {
        return $this->kernel->getWorld();
    }

    public function getScheduler(): Scheduler {
        return $this->kernel->getScheduler();
    }

    public function getSystemScheduler(): \pocketmine\core\ecs\SystemScheduler {
        return $this->kernel->getSystemScheduler();
    }

    public function getPlayerJoinService(): \pocketmine\core\service\PlayerJoinService {
        return $this->kernel->getPlayerJoinService();
    }

    public function getPlayerLeaveService(): \pocketmine\core\service\PlayerLeaveService {
        return $this->kernel->getPlayerLeaveService();
    }

    public function getPlayerRespawnService(): \pocketmine\core\service\PlayerRespawnService {
        return $this->kernel->getPlayerRespawnService();
    }

    public function getChunkLoadService(): \pocketmine\core\service\ChunkLoadService {
        return $this->kernel->getChunkLoadService();
    }

    public function getChunkUnloadService(): \pocketmine\core\service\ChunkUnloadService {
        return $this->kernel->getChunkUnloadService();
    }

    public function getChunkSendService(): \pocketmine\core\service\ChunkSendService {
        return $this->kernel->getChunkSendService();
    }

    public function getBlockBreakService(): \pocketmine\core\service\BlockBreakService {
        return $this->kernel->getBlockBreakService();
    }

    public function getBlockPlaceService(): \pocketmine\core\service\BlockPlaceService {
        return $this->kernel->getBlockPlaceService();
    }

    public function getBlockUpdateService(): \pocketmine\core\service\BlockUpdateService {
        return $this->kernel->getBlockUpdateService();
    }

    public function getEntitySpawnService(): \pocketmine\core\service\EntitySpawnService {
        return $this->kernel->getEntitySpawnService();
    }

    public function getEntityDespawnService(): \pocketmine\core\service\EntityDespawnService {
        return $this->kernel->getEntityDespawnService();
    }

    public function getEntityInteractionService(): \pocketmine\core\service\EntityInteractionService {
        return $this->kernel->getEntityInteractionService();
    }

    public function getCombatService(): \pocketmine\core\service\CombatService {
        return $this->kernel->getCombatService();
    }

    public function getDamageService(): \pocketmine\core\service\DamageService {
        return $this->kernel->getDamageService();
    }

    public function getKnockbackService(): \pocketmine\core\service\KnockbackService {
        return $this->kernel->getKnockbackService();
    }

    public function getInventoryService(): \pocketmine\core\service\InventoryService {
        return $this->kernel->getInventoryService();
    }

    public function getCraftingService(): \pocketmine\core\service\CraftingService {
        return $this->kernel->getCraftingService();
    }

    public function getContainerService(): \pocketmine\core\service\ContainerService {
        return $this->kernel->getContainerService();
    }

    public function getNetworkPort(): \pocketmine\port\driven\NetworkPort {
        return $this->kernel->getNetworkPort();
    }

    public function getStoragePort(): \pocketmine\port\driven\StoragePort {
        return $this->kernel->getStoragePort();
    }

    public function getWorldGenPort(): \pocketmine\port\driven\WorldGenPort {
        return $this->kernel->getWorldGenPort();
    }

    public function getThreadingPort(): \pocketmine\port\driven\ThreadingPort {
        return $this->kernel->getThreadingPort();
    }

    public function getCommandPort(): \pocketmine\port\driving\CommandPort {
        return $this->kernel->getCommandPort();
    }

    public function getEventPort(): \pocketmine\port\driving\EventPort {
        return $this->kernel->getEventPort();
    }

    public function getPluginPort(): \pocketmine\port\driving\PluginPort {
        return $this->kernel->getPluginPort();
    }

    public function getPermissionManager(): \pocketmine\api\permission\PermissionManager {
        return $this->kernel->getPermissionManager();
    }
}
