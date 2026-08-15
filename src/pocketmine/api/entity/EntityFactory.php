<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\api\inventory\ItemStack;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;

class EntityFactory {
    private static array $entityTypes = [
        'player' => \pocketmine\api\entity\Player::class,
        'zombie' => \pocketmine\api\entity\Zombie::class,
        'skeleton' => \pocketmine\api\entity\Skeleton::class,
        'creeper' => \pocketmine\api\entity\Creeper::class,
        'pig' => \pocketmine\api\entity\Pig::class,
        'item' => \pocketmine\api\entity\ItemEntity::class,
    ];

    public static function register(string $type, string $className): void {
        self::$entityTypes[strtolower($type)] = $className;
    }

    public static function create(string $type, float $x, float $y, float $z, array $options = []): ?Entity {
        $type = strtolower($type);
        $className = self::$entityTypes[$type] ?? null;

        if (!$className || !class_exists($className)) {
            return null;
        }

        if (!method_exists($className, 'create')) {
            return null;
        }

        // Item drops need an ItemStack; every other registered type spawns
        // from just a position. Never forward the type string into create().
        if ($type === 'item') {
            $item = $options['item'] ?? null;
            return $item instanceof ItemStack ? ItemEntity::create($x, $y, $z, $item) : null;
        }

        return $className::create($x, $y, $z);
    }

    public static function getRegisteredTypes(): array {
        return array_keys(self::$entityTypes);
    }

    public static function isRegistered(string $type): bool {
        return isset(self::$entityTypes[strtolower($type)]);
    }
}