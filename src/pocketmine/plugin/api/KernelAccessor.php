<?php

declare(strict_types=1);

namespace pocketmine\plugin\api;

use pocketmine\domain\ecs\SystemScheduler;
use pocketmine\domain\ecs\World;
use pocketmine\domain\service\PlayerJoinService;
use pocketmine\domain\service\PlayerLeaveService;
use pocketmine\domain\service\PlayerRespawnService;
use pocketmine\domain\service\ChunkLoadService;
use pocketmine\domain\service\ChunkUnloadService;
use pocketmine\domain\service\ChunkSendService;
use pocketmine\domain\service\BlockBreakService;
use pocketmine\domain\service\BlockPlaceService;
use pocketmine\domain\service\BlockUpdateService;
use pocketmine\domain\service\EntitySpawnService;
use pocketmine\domain\service\EntityDespawnService;
use pocketmine\domain\service\EntityInteractionService;
use pocketmine\domain\service\CombatService;
use pocketmine\domain\service\DamageService;
use pocketmine\domain\service\KnockbackService;
use pocketmine\domain\service\InventoryService;
use pocketmine\domain\service\CraftingService;
use pocketmine\domain\service\ContainerService;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\WorldGenPort;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\port\driving\CommandPort;
use pocketmine\port\driving\EventPort;
use pocketmine\port\driving\PluginPort;
use pocketmine\Kernel;

final class KernelAccessor {
    public function __construct(
        private readonly Kernel $kernel,
    ) {}

    public function getWorld(): World {
        return $this->kernel->getWorld();
    }

    public function getSystemScheduler(): SystemScheduler {
        return $this->kernel->getSystemScheduler();
    }

    public function getPlayerJoinService(): PlayerJoinService {
        return $this->kernel->getPlayerJoinService();
    }

    public function getPlayerLeaveService(): PlayerLeaveService {
        return $this->kernel->getPlayerLeaveService();
    }

    public function getPlayerRespawnService(): PlayerRespawnService {
        return $this->kernel->getPlayerRespawnService();
    }

    public function getChunkLoadService(): ChunkLoadService {
        return $this->kernel->getChunkLoadService();
    }

    public function getChunkUnloadService(): ChunkUnloadService {
        return $this->kernel->getChunkUnloadService();
    }

    public function getChunkSendService(): ChunkSendService {
        return $this->kernel->getChunkSendService();
    }

    public function getBlockBreakService(): BlockBreakService {
        return $this->kernel->getBlockBreakService();
    }

    public function getBlockPlaceService(): BlockPlaceService {
        return $this->kernel->getBlockPlaceService();
    }

    public function getBlockUpdateService(): BlockUpdateService {
        return $this->kernel->getBlockUpdateService();
    }

    public function getEntitySpawnService(): EntitySpawnService {
        return $this->kernel->getEntitySpawnService();
    }

    public function getEntityDespawnService(): EntityDespawnService {
        return $this->kernel->getEntityDespawnService();
    }

    public function getEntityInteractionService(): EntityInteractionService {
        return $this->kernel->getEntityInteractionService();
    }

    public function getCombatService(): CombatService {
        return $this->kernel->getCombatService();
    }

    public function getDamageService(): DamageService {
        return $this->kernel->getDamageService();
    }

    public function getKnockbackService(): KnockbackService {
        return $this->kernel->getKnockbackService();
    }

    public function getInventoryService(): InventoryService {
        return $this->kernel->getInventoryService();
    }

    public function getCraftingService(): CraftingService {
        return $this->kernel->getCraftingService();
    }

    public function getContainerService(): ContainerService {
        return $this->kernel->getContainerService();
    }

    public function getNetworkPort(): NetworkPort {
        return $this->kernel->getNetworkPort();
    }

    public function getStoragePort(): StoragePort {
        return $this->kernel->getStoragePort();
    }

    public function getWorldGenPort(): WorldGenPort {
        return $this->kernel->getWorldGenPort();
    }

    public function getThreadingPort(): ThreadingPort {
        return $this->kernel->getThreadingPort();
    }

    public function getCommandPort(): CommandPort {
        return $this->kernel->getCommandPort();
    }

    public function getEventPort(): EventPort {
        return $this->kernel->getEventPort();
    }

    public function getPluginPort(): PluginPort {
        return $this->kernel->getPluginPort();
    }
}