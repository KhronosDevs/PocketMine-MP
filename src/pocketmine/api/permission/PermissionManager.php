<?php

declare(strict_types=1);

namespace pocketmine\api\permission;

class PermissionManager {
    private array $permissions = [];
    private array $defaultPerms = [];
    private array $defaultPermsOp = [];

    public function addPermission(Permission $permission): bool {
        if (isset($this->permissions[$permission->getName()])) {
            return false;
        }
        
        $this->permissions[$permission->getName()] = $permission;
        $this->calculatePermissionDefault($permission);
        return true;
    }

    public function removePermission(string $name): void {
        unset($this->permissions[$name]);
        unset($this->defaultPerms[$name]);
        unset($this->defaultPermsOp[$name]);
    }

    public function getPermission(string $name): ?Permission {
        return $this->permissions[$name] ?? null;
    }

    public function getDefaultPermissions(bool $op): array {
        return $op ? $this->defaultPermsOp : $this->defaultPerms;
    }

    public function recalculatePermissionDefaults(Permission $permission): void {
        unset($this->defaultPermsOp[$permission->getName()]);
        unset($this->defaultPerms[$permission->getName()]);
        $this->calculatePermissionDefault($permission);
    }

    private function calculatePermissionDefault(Permission $permission): void {
        if ($permission->getDefault() === Permission::DEFAULT_OP || $permission->getDefault() === Permission::DEFAULT_TRUE) {
            $this->defaultPermsOp[$permission->getName()] = $permission;
        }

        if ($permission->getDefault() === Permission::DEFAULT_NOT_OP || $permission->getDefault() === Permission::DEFAULT_TRUE) {
            $this->defaultPerms[$permission->getName()] = $permission;
        }
    }

    public function hasPermission(\pocketmine\api\entity\Player $player, string $permission): bool {
        $entity = $player->getInternalRef()->getEntity();
        if (!$entity) return false;
        
        $metadata = $entity->get(\pocketmine\core\component\MetadataComponent::class);
        if (!$metadata) return false;
        
        $permissions = $metadata->get('permissions', []);
        
        // Check explicit permissions
        if (in_array($permission, $permissions) || in_array('*', $permissions)) {
            return true;
        }
        
        // Check default permissions
        $isOp = in_array('op', $permissions);
        $defaults = $isOp ? $this->defaultPermsOp : $this->defaultPerms;
        
        if (isset($defaults[$permission])) {
            return true;
        }
        
        // Check parent permissions
        foreach ($this->permissions as $perm) {
            if (isset($perm->getChildren()[$permission])) {
                return $this->hasPermission($player, $perm->getName());
            }
        }
        
        return false;
    }
}
