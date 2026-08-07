<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

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
        
        return $className::create(...func_get_args());
    }

    public static function getRegisteredTypes(): array {
        return array_keys(self::$entityTypes);
    }

    public static function isRegistered(string $type): bool {
        return isset(self::$entityTypes[strtolower($type)]);
    }
}