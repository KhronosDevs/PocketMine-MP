<?php

declare(strict_types=1);

namespace pocketmine\api\command;

/**
 * MCPE formatting constants for command output.
 *
 * Use these to build colored messages sent via CommandSender::sendMessage().
 * The § character is the MCPE formatting prefix; clients render the code
 * after it and discard it.
 */
final class Format {
    // Colors
    public const BLACK = '§0';
    public const DARK_BLUE = '§1';
    public const DARK_GREEN = '§2';
    public const DARK_AQUA = '§3';
    public const DARK_RED = '§4';
    public const DARK_PURPLE = '§5';
    public const GOLD = '§6';
    public const GRAY = '§7';
    public const DARK_GRAY = '§8';
    public const BLUE = '§9';
    public const GREEN = '§a';
    public const AQUA = '§b';
    public const RED = '§c';
    public const LIGHT_PURPLE = '§d';
    public const YELLOW = '§e';
    public const WHITE = '§f';

    // Formatting
    public const BOLD = '§l';
    public const ITALIC = '§o';
    public const UNDERLINE = '§n';
    public const RESET = '§r';

    // Convenience combinations
    public const HEADER = self::GOLD . self::BOLD;
    public const SUCCESS = self::GREEN;
    public const ERROR = self::RED;
    public const INFO = self::YELLOW;
    public const MUTED = self::GRAY;
    public const HIGHLIGHT = self::AQUA;
    public const VALUE = self::WHITE;

    /** Reset all formatting back to default. */
    public static function reset(): string {
        return self::RESET;
    }

    /** Build a section header line. */
    public static function header(string $text): string {
        return self::HEADER . $text . self::RESET;
    }

    /** Build a labeled value line: "Label: value". */
    public static function label(string $label, string $value): string {
        return self::INFO . $label . ': ' . self::VALUE . $value . self::RESET;
    }

    /** Build an error message. */
    public static function error(string $text): string {
        return self::ERROR . $text . self::RESET;
    }

    /** Build a success message. */
    public static function success(string $text): string {
        return self::SUCCESS . $text . self::RESET;
    }
}
