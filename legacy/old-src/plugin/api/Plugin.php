<?php

declare(strict_types=1);

namespace pocketmine\plugin\api;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\QueryBuilder;
use pocketmine\domain\ecs\World;
use pocketmine\domain\ecs\System;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\WorldGenPort;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\port\driving\CommandPort;
use pocketmine\port\driving\EventPort;
use pocketmine\port\driving\PluginPort;

abstract class Plugin {
    private ?string $name = null;
    private ?string $version = null;
    private ?string $author = null;
    private array $depend = [];
    private array $softDepend = [];
    private bool $enabled = false;
    private ?PluginDescription $description = null;
    
    // Injected by PluginManager
    private ?KernelAccessor $kernelAccessor = null;

    final public function __construct() {
        // Plugin constructor - no arguments
    }

    // Kernel access (injected by PluginManager)
    final public function setKernelAccessor(KernelAccessor $accessor): void {
        $this->kernelAccessor = $accessor;
    }

    final protected function getKernel(): KernelAccessor {
        if (!$this->kernelAccessor) {
            throw new \RuntimeException("Plugin not initialized - kernel accessor not set");
        }
        return $this->kernelAccessor;
    }

    // World access
    final protected function getWorld(): World {
        return $this->getKernel()->getWorld();
    }

    // ECS Query API
    final protected function query(): QueryBuilder {
        return $this->getWorld()->query();
    }

    // System registration
    final protected function registerSystem(System $system, \pocketmine\domain\ecs\SystemPhase $phase = \pocketmine\domain\ecs\SystemPhase::SEQUENTIAL): void {
        $this->getKernel()->getSystemScheduler()->register($system, $phase);
    }

    // Service access
    final protected function getPlayerJoinService(): \pocketmine\domain\service\PlayerJoinService {
        return $this->getKernel()->getPlayerJoinService();
    }

    final protected function getPlayerLeaveService(): \pocketmine\domain\service\PlayerLeaveService {
        return $this->getKernel()->getPlayerLeaveService();
    }

    final protected function getPlayerRespawnService(): \pocketmine\domain\service\PlayerRespawnService {
        return $this->getKernel()->getPlayerRespawnService();
    }

    final protected function getChunkLoadService(): \pocketmine\domain\service\ChunkLoadService {
        return $this->getKernel()->getChunkLoadService();
    }

    final protected function getChunkUnloadService(): \pocketmine\domain\service\ChunkUnloadService {
        return $this->getKernel()->getChunkUnloadService();
    }

    final protected function getChunkSendService(): \pocketmine\domain\service\ChunkSendService {
        return $this->getKernel()->getChunkSendService();
    }

    final protected function getBlockBreakService(): \pocketmine\domain\service\BlockBreakService {
        return $this->getKernel()->getBlockBreakService();
    }

    final protected function getBlockPlaceService(): \pocketmine\domain\service\BlockPlaceService {
        return $this->getKernel()->getBlockPlaceService();
    }

    final protected function getBlockUpdateService(): \pocketmine\domain\service\BlockUpdateService {
        return $this->getKernel()->getBlockUpdateService();
    }

    final protected function getEntitySpawnService(): \pocketmine\domain\service\EntitySpawnService {
        return $this->getKernel()->getEntitySpawnService();
    }

    final protected function getEntityDespawnService(): \pocketmine\domain\service\EntityDespawnService {
        return $this->getKernel()->getEntityDespawnService();
    }

    final protected function getEntityInteractionService(): \pocketmine\domain\service\EntityInteractionService {
        return $this->getKernel()->getEntityInteractionService();
    }

    final protected function getCombatService(): \pocketmine\domain\service\CombatService {
        return $this->getKernel()->getCombatService();
    }

    final protected function getDamageService(): \pocketmine\domain\service\DamageService {
        return $this->getKernel()->getDamageService();
    }

    final protected function getKnockbackService(): \pocketmine\domain\service\KnockbackService {
        return $this->getKernel()->getKnockbackService();
    }

    final protected function getInventoryService(): \pocketmine\domain\service\InventoryService {
        return $this->getKernel()->getInventoryService();
    }

    final protected function getCraftingService(): \pocketmine\domain\service\CraftingService {
        return $this->getKernel()->getCraftingService();
    }

    final protected function getContainerService(): \pocketmine\domain\service\ContainerService {
        return $this->getKernel()->getContainerService();
    }

    // Port access
    final protected function getNetworkPort(): NetworkPort {
        return $this->getKernel()->getNetworkPort();
    }

    final protected function getStoragePort(): StoragePort {
        return $this->getKernel()->getStoragePort();
    }

    final protected function getWorldGenPort(): WorldGenPort {
        return $this->getKernel()->getWorldGenPort();
    }

    final protected function getThreadingPort(): ThreadingPort {
        return $this->getKernel()->getThreadingPort();
    }

    final protected function getCommandPort(): CommandPort {
        return $this->getKernel()->getCommandPort();
    }

    final protected function getEventPort(): EventPort {
        return $this->getKernel()->getEventPort();
    }

    final protected function getPluginPort(): PluginPort {
        return $this->getKernel()->getPluginPort();
    }

    // Lifecycle
    public function onEnable(): void {}
    public function onDisable(): void {}

    // Metadata
    final public function getName(): string {
        return $this->name ?? get_class($this);
    }

    final public function getVersion(): string {
        return $this->version ?? "1.0.0";
    }

    final public function getAuthor(): string {
        return $this->author ?? "Unknown";
    }

    final public function getDepend(): array {
        return $this->depend;
    }

    final public function getSoftDepend(): array {
        return $this->softDepend;
    }

    final public function isEnabled(): bool {
        return $this->enabled;
    }

    final public function getDescription(): PluginDescription {
        if (!$this->description) {
            $this->description = new PluginDescription(
                $this->getName(),
                $this->getVersion(),
                $this->getAuthor(),
                $this->depend,
                $this->softDepend,
                [], // commands
                []  // permissions
            );
        }
        return $this->description;
    }

    // Internal setters (called by PluginManager)
    final internal function setName(string $name): void {
        $this->name = $name;
    }

    final internal function setVersion(string $version): void {
        $this->version = $version;
    }

    final internal function setAuthor(string $author): void {
        $this->author = $author;
    }

    final internal function setDepend(array $depend): void {
        $this->depend = $depend;
    }

    final internal function setSoftDepend(array $softDepend): void {
        $this->softDepend = $softDepend;
    }

    final internal function setEnabled(bool $enabled): void {
        $this->enabled = $enabled;
    }
}