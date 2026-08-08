<?php

declare(strict_types=1);

namespace pocketmine\api\permission;

use pocketmine\api\entity\Player;
use pocketmine\core\component\MetadataComponent;

/**
 * Registry of known permissions (declared in plugin.yml) with default and
 * inheritance resolution.
 *
 * A player's effective permissions are the union of:
 *   - explicit grants stored in the entity's metadata ('permissions' list),
 *   - defaults from every registered permission whose default applies to the
 *     player's op status (DEFAULT_TRUE applies to everyone),
 *   - children: holding a parent permission grants its true-valued children.
 */
class PermissionManager {
    /** @var array<string, Permission> */
    private array $permissions = [];
    /** @var array<string, Permission> */
    private array $defaultPerms = [];
    /** @var array<string, Permission> */
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

    /** @return array<string, Permission> */
    public function getPermissions(): array {
        return $this->permissions;
    }

    /** @return array<string, Permission> */
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

    /**
     * Build a Permission from a plugin.yml "permissions:" entry:
     *
     *     myplugin.foo:
     *       description: Allows the foo action
     *       default: op            # op | notop | true | false
     *       children:
     *         myplugin.foo.bar: true
     *
     * @return Permission the parsed permission (never throws on a malformed
     *         entry - unknown fields are ignored, bad defaults fall back to
     *         DEFAULT_NOT_OP)
     */
    public static function fromYaml(string $name, array $data): Permission {
        $description = (string)($data['description'] ?? '');
        $default = self::parseDefault($data['default'] ?? null);
        $children = [];
        foreach ((array)($data['children'] ?? []) as $childName => $granted) {
            if (is_string($childName) && $granted === true) {
                $children[$childName] = true;
            }
        }
        return new Permission($name, $description, $default, $children);
    }

    private static function parseDefault(mixed $raw): int {
        if ($raw === true) {
            return Permission::DEFAULT_TRUE;
        }
        if ($raw === false) {
            return Permission::DEFAULT_FALSE;
        }
        return match (strtolower(trim((string)$raw))) {
            'op' => Permission::DEFAULT_OP,
            'true', 'yes' => Permission::DEFAULT_TRUE,
            'false', 'no' => Permission::DEFAULT_FALSE,
            default => Permission::DEFAULT_NOT_OP,
        };
    }

    public function hasPermission(Player $player, string $permission): bool {
        $entity = $player->getInternalRef()->getEntity();
        if (!$entity) {
            return false;
        }
        $metadata = $entity->get(MetadataComponent::class);
        if (!$metadata) {
            return false;
        }

        /** @var list<string> $permissions */
        $permissions = $metadata->get('permissions', []);

        // Explicit grants, wildcard and op short-circuit everything else.
        if (in_array($permission, $permissions, true) || in_array('*', $permissions, true)) {
            return true;
        }
        $isOp = $this->isOp($permissions);

        // Defaults from plugin.yml apply to op / non-op players.
        $defaults = $isOp ? $this->defaultPermsOp : $this->defaultPerms;
        if (isset($defaults[$permission])) {
            return true;
        }

        // Children: holding a parent permission grants its true children.
        foreach ($this->permissions as $perm) {
            $children = $perm->getChildren();
            if (isset($children[$permission]) && $children[$permission] === true) {
                if ($this->hasPermission($player, $perm->getName())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $permissions
     */
    private function isOp(array $permissions): bool {
        return in_array('pocketmine.op', $permissions, true) || in_array('op', $permissions, true);
    }
}
