<?php

declare(strict_types=1);

namespace pocketmine\core\enum;

/**
 * World difficulties. The enum value is the protocol-84 difficulty id (also
 * persisted in level.dat 'Difficulty'), so it must match the wire format.
 */
enum Difficulty: int {
    case Peaceful = 0;
    case Easy = 1;
    case Normal = 2;
    case Hard = 3;

    /** Coerce a raw int (metadata/config/wire) to a Difficulty, defaulting to Easy. */
    public static function coerce(mixed $value): self {
        return self::tryFrom((int)$value) ?? self::Easy;
    }
}
