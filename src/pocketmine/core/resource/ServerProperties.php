<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

/**
 * server.properties loader (legacy key=value format, one pair per line, '#'
 * comments). The file is read once at bootstrap and the values are applied to
 * ServerConfig / WorldConfig / the Server facade; a missing file is generated
 * with the current defaults so admins always have a config to edit. Values
 * that are absent or malformed fall back to the built-in defaults below.
 */
final class ServerProperties {
    public const DEFAULTS = [
        'motd' => 'Khronos Server',
        'server-name' => 'Khronos Server',
        'server-port' => '19132',
        'server-ip' => '0.0.0.0',
        'max-players' => '20',
        'gamemode' => '0',
        'difficulty' => '1',
        'view-distance' => '10',
        'pvp' => 'true',
        'spawn-animals' => 'true',
        'spawn-mobs' => 'true',
        'online-mode' => 'false',
        'white-list' => 'false',
        'force-gamemode' => 'false',
        'allow-flight' => 'false',
        'language' => 'eng',
        'level-name' => 'world',
        'level-seed' => '',
        'hardcore' => 'false',
        'autosave-interval' => '60', // seconds between world saves
    ];

    /** @return array<string, string> normalized properties (defaults merged) */
    public static function load(string $path): array {
        $props = self::DEFAULTS;
        if (is_file($path)) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }
                    $eq = strpos($line, '=');
                    if ($eq === false) {
                        continue;
                    }
                    $key = strtolower(trim(substr($line, 0, $eq)));
                    $value = trim(substr($line, $eq + 1));
                    $props[$key] = $value;
                }
            }
        }
        return $props;
    }

    /** Write the full default set (with a header) so admins can edit it. */
    public static function writeDefaults(string $path): void {
        $lines = [
            '#Khronos server.properties',
            '#Edit at your own risk - the server rewrites this file only when missing.',
            '',
        ];
        foreach (self::DEFAULTS as $key => $value) {
            $lines[] = $key . '=' . $value;
        }
        @file_put_contents($path, implode("\n", $lines) . "\n");
    }

    public static function bool(array $props, string $key, bool $default = false): bool {
        $v = strtolower((string)($props[$key] ?? ''));
        return match ($v) {
            'true', 'on', '1', 'yes' => true,
            'false', 'off', '0', 'no' => false,
            default => $default,
        };
    }

    public static function int(array $props, string $key, int $default): int {
        $v = $props[$key] ?? '';
        return ctype_digit($v) || (is_string($v) && $v[0] === '-' && ctype_digit(substr($v, 1)))
            ? (int)$v
            : $default;
    }
}
