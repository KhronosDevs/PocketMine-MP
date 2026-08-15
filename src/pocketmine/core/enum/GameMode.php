<?php

declare(strict_types=1);

namespace pocketmine\core\enum;

/**
 * Player gamemodes. The enum value is the protocol-84 gamemode id (also
 * stored in MetadataComponent 'gamemode'), so it must match the wire format.
 */
enum GameMode: int {
    case Survival = 0;
    case Creative = 1;
    case Adventure = 2;
    case Spectator = 3;

    /** Coerce a raw int (metadata/config/wire) to a GameMode, defaulting to Survival. */
    public static function coerce(mixed $value): self {
        return self::tryFrom((int)$value) ?? self::Survival;
    }
}
