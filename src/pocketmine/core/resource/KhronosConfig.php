<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_bool;
use function is_file;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function trim;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

/**
 * khronos.json - the server's JSON config surface.
 *
 * Complements the legacy key=value server.properties with the settings that
 * do not fit a flat file (nested anti-cheat thresholds, explicit world/spawn
 * overrides). Written with the built-in defaults on first boot so admins
 * always have a file to edit; every value is optional - absent or malformed
 * keys fall back to these defaults. The file is read once at bootstrap.
 *
 * Keys:
 *   default-world:  folder (and display name) of the default world. Routes
 *                   the storage adapter, so a fresh world is generated in
 *                   worlds/<folder>/.
 *   default-spawn:  explicit world spawn override. Each of x/y/z may be null
 *                   (= keep the world's own saved spawn); only when all three
 *                   are numbers does it override the persisted level spawn.
 *   anti-cheat:     Blocker 2 thresholds and punishments. movement caps are
 *                   per-tick (a sprint-jump peaks ~0.6 blocks/tick; 1.2
 *                   horizontal = 24 m/s); violations within the window
 *                   escalate from rubber-band to kick. chat/command set spam
 *                   intervals; login throttles attempts per IP.
 */
#[Resource]
final class KhronosConfig {
    // --- World -------------------------------------------------------------

    /** Folder (and display name) of the default world. */
    public string $defaultWorld = 'world';

    /** Explicit default-spawn override; null = keep the world's own spawn. */
    public ?int $spawnX = null;
    public ?int $spawnY = null;
    public ?int $spawnZ = null;

    // --- Anti-cheat (Blocker 2) --------------------------------------------

    /** Master switch: when false, movement validation is disabled entirely. */
    public bool $antiCheatEnabled = true;

    // Movement caps per tick (see the class docblock for the baseline).
    public float $maxMoveHorizontalPerTick = 1.2;
    public float $maxMoveAscentPerTick = 0.8;
    public float $maxMoveTotalPerTick = 2.0;
    /** Rejected moves accumulate; this many inside the window triggers kick. */
    public int $maxMoveViolations = 5;
    public int $moveViolationWindowTicks = 100;
    /** Snap the client back to the authoritative position on a violation. */
    public bool $rubberBandOnViolation = true;
    /** Disconnect the player once the violation limit inside the window is hit. */
    public bool $kickOnViolations = true;

    // Chat / command spam limits.
    public float $chatMinIntervalSeconds = 0.4;
    public int $maxChatLength = 256;
    public float $commandMinIntervalSeconds = 0.1;

    // Login throttle (per IP).
    public int $loginAttemptsPerMinute = 30;
    public int $maxSessionsPerIp = 25;

    /** Parse a khronos.json file, merging onto the built-in defaults. */
    public static function load(string $path): self {
        $config = new self();
        $raw = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (is_array($raw)) {
            $config->apply($raw);
        }
        return $config;
    }

    /** Merge a decoded khronos.json array onto this config (defaults kept for absent/malformed keys). */
    public function apply(array $data): void {
        if (isset($data['default-world']) && is_string($data['default-world']) && trim($data['default-world']) !== '') {
            $this->defaultWorld = $data['default-world'];
        }

        // default-spawn: only all-three-numeric overrides apply; any null or
        // missing coordinate keeps the world's own saved spawn.
        $spawn = $data['default-spawn'] ?? null;
        if (is_array($spawn)
            && isset($spawn['x'], $spawn['y'], $spawn['z'])
            && is_numeric($spawn['x']) && is_numeric($spawn['y']) && is_numeric($spawn['z'])) {
            $this->spawnX = (int)$spawn['x'];
            $this->spawnY = (int)$spawn['y'];
            $this->spawnZ = (int)$spawn['z'];
        }

        $ac = $data['anti-cheat'] ?? null;
        if (is_array($ac)) {
            if (array_key_exists('enabled', $ac) && is_bool($ac['enabled'])) {
                $this->antiCheatEnabled = $ac['enabled'];
            }
            $movement = $ac['movement'] ?? null;
            if (is_array($movement)) {
                if (isset($movement['max-horizontal-per-tick']) && is_numeric($movement['max-horizontal-per-tick'])) {
                    $this->maxMoveHorizontalPerTick = (float)$movement['max-horizontal-per-tick'];
                }
                if (isset($movement['max-ascent-per-tick']) && is_numeric($movement['max-ascent-per-tick'])) {
                    $this->maxMoveAscentPerTick = (float)$movement['max-ascent-per-tick'];
                }
                if (isset($movement['max-total-per-tick']) && is_numeric($movement['max-total-per-tick'])) {
                    $this->maxMoveTotalPerTick = (float)$movement['max-total-per-tick'];
                }
                if (isset($movement['max-violations']) && is_numeric($movement['max-violations'])) {
                    $this->maxMoveViolations = max(1, (int)$movement['max-violations']);
                }
                if (isset($movement['violation-window-ticks']) && is_numeric($movement['violation-window-ticks'])) {
                    $this->moveViolationWindowTicks = max(1, (int)$movement['violation-window-ticks']);
                }
                if (array_key_exists('rubber-band', $movement) && is_bool($movement['rubber-band'])) {
                    $this->rubberBandOnViolation = $movement['rubber-band'];
                }
                if (array_key_exists('kick-on-violations', $movement) && is_bool($movement['kick-on-violations'])) {
                    $this->kickOnViolations = $movement['kick-on-violations'];
                }
            }
            $chat = $ac['chat'] ?? null;
            if (is_array($chat)) {
                if (isset($chat['min-interval-seconds']) && is_numeric($chat['min-interval-seconds'])) {
                    $this->chatMinIntervalSeconds = (float)$chat['min-interval-seconds'];
                }
                if (isset($chat['max-length']) && is_numeric($chat['max-length'])) {
                    $this->maxChatLength = max(1, (int)$chat['max-length']);
                }
            }
            $command = $ac['command'] ?? null;
            if (is_array($command) && isset($command['min-interval-seconds']) && is_numeric($command['min-interval-seconds'])) {
                $this->commandMinIntervalSeconds = (float)$command['min-interval-seconds'];
            }
            $login = $ac['login'] ?? null;
            if (is_array($login)) {
                if (isset($login['attempts-per-minute']) && is_numeric($login['attempts-per-minute'])) {
                    $this->loginAttemptsPerMinute = max(1, (int)$login['attempts-per-minute']);
                }
                if (isset($login['max-sessions-per-ip']) && is_numeric($login['max-sessions-per-ip'])) {
                    $this->maxSessionsPerIp = max(1, (int)$login['max-sessions-per-ip']);
                }
            }
        }
    }

    /** Write the full default set (with null spawn placeholders) so admins can edit it. */
    public static function writeDefaults(string $path): void {
        $json = [
            // Folder (and display name) of the default world. A fresh world is
            // generated under worlds/<folder>/ on first boot.
            'default-world' => 'world',
            // Explicit spawn override. null = keep the world's own saved spawn;
            // set all three to numbers to force a fixed spawn point.
            'default-spawn' => ['x' => null, 'y' => null, 'z' => null],
            'anti-cheat' => [
                'enabled' => true,
                'movement' => [
                    // Caps per tick: horizontal 1.2 = 24 m/s; ascent peak ~0.42.
                    'max-horizontal-per-tick' => 1.2,
                    'max-ascent-per-tick' => 0.8,
                    'max-total-per-tick' => 2.0,
                    // Violations inside the window escalate to a kick.
                    'max-violations' => 5,
                    'violation-window-ticks' => 100,
                    'rubber-band' => true,
                    'kick-on-violations' => true,
                ],
                'chat' => [
                    'min-interval-seconds' => 0.4,
                    'max-length' => 256,
                ],
                'command' => [
                    'min-interval-seconds' => 0.1,
                ],
                'login' => [
                    'attempts-per-minute' => 30,
                    'max-sessions-per-ip' => 25,
                ],
            ],
        ];
        file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
