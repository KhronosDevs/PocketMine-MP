<?php

declare(strict_types=1);

namespace pocketmine\core\enum;

/**
 * World generator types. The enum value is the canonical string persisted in
 * level.dat ('generatorName') and read from khronos.json, so it must match
 * the wire/storage format exactly.
 */
enum GeneratorType: string {
    case Normal = 'normal';
    case Flat = 'flat';
    case Void = 'void';

    /**
     * Coerce an unknown/foreign value (e.g. from a foreign level.dat that has
     * no generator info) to a GeneratorType, defaulting to Void - a dropped-in
     * world is never regenerated around the player's build.
     */
    public static function coerce(mixed $value): self {
        return self::tryFrom((string)$value) ?? self::Void;
    }
}
