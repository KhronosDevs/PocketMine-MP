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

    // --- Memory (chunk budget + distance unload) -------------------------------

    /**
     * Loaded-chunk budget: resident chunks are evicted (persisted + dropped)
     * FIFO when the count exceeds this. Sized for the 512M floor at ~310KB
     * per chunk (binary payloads + serialized wire cache + PHP overhead);
     * raise it on high-RAM hosts. See ChunkLoadService::DEFAULT_MAX_LOADED_CHUNKS.
     */
    public int $maxLoadedChunks = 1200;

    /**
     * Distance-based chunk unloading (primary eviction policy): when enabled,
     * a periodic sweep drops every resident chunk farther than
     * $chunkUnloadKeepRadius chunks from ALL players, so memory tracks what
     * players actually need instead of waiting for the count cap. The FIFO
     * cap above remains the backstop for many spread-out players.
     */
    public bool $chunkUnloadDistanceBased = true;

    /**
     * Keep radius for the distance sweep: chunks within this Chebyshev
     * distance of any player stay resident. Must exceed the max view radius
     * (12) by a margin or a moving player thrashes (unload/reload churn).
     */
    public int $chunkUnloadKeepRadius = 16;

    /**
     * How often (in ticks) the distance sweep runs. 100 ticks = every 5
     * seconds at 20 TPS; the sweep is bounded per call (see
     * ChunkUnloadService::unloadChunksFarFromPlayers) so it can never stall
     * a tick.
     */
    public int $chunkUnloadSweepIntervalTicks = 100;

    // --- Nether (14.32) ------------------------------------------------------

    /** Whether portals work at all (legacy pocketmine.yml nether.allow-nether). */
    public bool $netherEnabled = true;

    /** Folder/name of the nether dimension world (legacy nether.level-name). */
    public string $netherWorld = 'nether';

    // --- Chunk streaming ---------------------------------------------------

    /**
     * zlib compression level for chunk packets (1-9).
     * Lower = faster CPU, larger packets. Higher = slower CPU, smaller packets.
     * L2 is recommended based on benchmarking (133µs/KB saved vs L1).
     */
    public int $chunkCompressionLevel = 2;

    /**
     * Maximum chunks a single player/session can receive per tick.
     * This is a per-session cap, not the global processing limit.
     */
    public int $chunkPerTick = 2;

    /**
     * Global time budget (milliseconds) for chunk streaming per tick.
     * This is shared across ALL players, not per-player.
     * When enabled, chunk processing stops when this budget is exhausted.
     */
    public float $chunkTimeBudgetMs = 30.0;

    /**
     * Enable time-budget scheduling. When true, chunk streaming is capped
     * by the global time budget. When false, only the per-player CPT cap
     * applies (simpler but scales poorly with many players).
     */
    public bool $chunkUseTimeBudget = true;

    // --- Server -----------------------------------------------------------

    /**
     * UDP port the server binds to. null = use server.properties (legacy).
     * When set, this overrides the server.properties server-port value.
     */
    public ?int $port = null;

    /**
     * PHP memory limit (e.g. "512M", "1G", "256M"). null = use the value
     * from start.sh / the PHP CLI default. Set this to override the memory
     * floor without editing start scripts.
     */
    public ?string $memoryLimit = null;

    // --- Native acceleration (FFI) -----------------------------------------

    /**
     * Master switch for the FFI native library (native/kh_native.so): light
     * calc, terrain noise, nibble pack. When false (or when FFI/the .so is
     * unavailable) every path falls back to the pure-PHP implementation, so
     * disabling costs speed but never correctness.
     */
    public bool $nativeAccelEnabled = true;

    // --- Region pipeline (experimental) -----------------------------------

    /**
     * Enable the region pipeline: offload entity movement+gravity to worker
     * threads. When false, all simulation runs on the main thread (safe,
     * default). When true, the kernel mirrors entity snapshots to workers
     * each tick and merges results back.
     */
    public bool $pipelineEnabled = false;

    /**
     * Apply mode: when true AND pipelineEnabled is true, worker threads are
     * authoritative for movement+gravity — the main thread's MovementSystem
     * and PhysicsSystem are disabled. When false (gate mode), the main thread
     * still simulates and worker results are only compared for determinism.
     * Only enable after confirming zero mismatches in gate mode.
     */
    public bool $pipelineApplyMode = false;

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

    // Max UDP datagram size (bytes). Oversized packets are rejected
    // before processing and counted against the per-IP rate limit.
    // MTU ~1432; 1500 is a safe ceiling for legitimate traffic.
    public int $maxDatagramSize = 1500;

    // Max packets per IP per tick before blocking (rate limit).
    public int $packetLimit = 350;

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

        if (isset($data['max-loaded-chunks']) && is_numeric($data['max-loaded-chunks'])) {
            $this->maxLoadedChunks = max(64, (int)$data['max-loaded-chunks']);
        }

        $cu = $data['chunk-unload'] ?? null;
        if (is_array($cu)) {
            if (array_key_exists('distance-based', $cu) && is_bool($cu['distance-based'])) {
                $this->chunkUnloadDistanceBased = $cu['distance-based'];
            }
            if (isset($cu['keep-radius']) && is_numeric($cu['keep-radius'])) {
                // Floor of 8: below the default view radius the sweep would
                // evict chunks the client is still rendering.
                $this->chunkUnloadKeepRadius = max(8, (int)$cu['keep-radius']);
            }
            if (isset($cu['sweep-interval-ticks']) && is_numeric($cu['sweep-interval-ticks'])) {
                $this->chunkUnloadSweepIntervalTicks = max(20, (int)$cu['sweep-interval-ticks']);
            }
        }

        $nether = $data['nether'] ?? null;
        if (is_array($nether)) {
            if (array_key_exists('enabled', $nether) && is_bool($nether['enabled'])) {
                $this->netherEnabled = $nether['enabled'];
            }
            if (isset($nether['world']) && is_string($nether['world']) && trim($nether['world']) !== '') {
                $this->netherWorld = trim($nether['world']);
            }
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

        if (isset($data['port']) && is_numeric($data['port'])) {
            $port = (int)$data['port'];
            if ($port >= 1 && $port <= 65535) {
                $this->port = $port;
            }
        }
        if (isset($data['memory-limit']) && is_string($data['memory-limit']) && trim($data['memory-limit']) !== '') {
            $this->memoryLimit = trim($data['memory-limit']);
        }

        $na = $data['native-accel'] ?? null;
        if (is_array($na) && array_key_exists('enabled', $na) && is_bool($na['enabled'])) {
            $this->nativeAccelEnabled = $na['enabled'];
        }

        $pipeline = $data['pipeline'] ?? null;
        if (is_array($pipeline)) {
            if (array_key_exists('enabled', $pipeline) && is_bool($pipeline['enabled'])) {
                $this->pipelineEnabled = $pipeline['enabled'];
            }
            if (array_key_exists('apply-mode', $pipeline) && is_bool($pipeline['apply-mode'])) {
                $this->pipelineApplyMode = $pipeline['apply-mode'];
            }
        }

        $cs = $data['chunk-streaming'] ?? null;
        if (is_array($cs)) {
            if (isset($cs['compression-level']) && is_numeric($cs['compression-level'])) {
                $this->chunkCompressionLevel = max(1, min(9, (int)$cs['compression-level']));
            }
            if (isset($cs['per-tick']) && is_numeric($cs['per-tick'])) {
                // Clamp to 8: values above ~4 risk RakLib recovery queue overflow
                // (WINDOW_SIZE=2048) causing silent session drops.
                $this->chunkPerTick = max(1, min(8, (int)$cs['per-tick']));
            }
            if (isset($cs['time-budget-ms']) && is_numeric($cs['time-budget-ms'])) {
                $this->chunkTimeBudgetMs = max(1.0, (float)$cs['time-budget-ms']);
            }
            if (array_key_exists('use-time-budget', $cs) && is_bool($cs['use-time-budget'])) {
                $this->chunkUseTimeBudget = $cs['use-time-budget'];
            }
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
            if (isset($ac['max-datagram-size']) && is_numeric($ac['max-datagram-size'])) {
                $this->maxDatagramSize = max(512, (int)$ac['max-datagram-size']);
            }
            if (isset($ac['packet-limit']) && is_numeric($ac['packet-limit'])) {
                $this->packetLimit = max(10, (int)$ac['packet-limit']);
            }
        }
    }

    /** Write the full default set (with null spawn placeholders) so admins can edit it. */
    public static function writeDefaults(string $path): void {
        $json = [
            // Folder (and display name) of the default world. A fresh world is
            // generated under worlds/<folder>/ on first boot.
            'default-world' => 'world',
            // Loaded-chunk budget: resident chunks are evicted FIFO above
            // this count. Sized for the 512M floor; raise on high-RAM hosts.
            'max-loaded-chunks' => 1200,
            // Distance-based chunk unloading: a periodic sweep evicts chunks
            // farther than keep-radius from every player, so memory tracks
            // what players actually need. keep-radius must stay above the
            // max view radius (12) to avoid unload/reload churn.
            'chunk-unload' => [
                'distance-based' => true,
                'keep-radius' => 16,
                'sweep-interval-ticks' => 100,
            ],
            // Nether dimension: portals auto-create worlds/<nether.world>/ on
            // first use and teleport through it. Set enabled=false to disable
            // portals entirely (legacy nether.allow-nether).
            'nether' => [
                'enabled' => true,
                'world' => 'nether',
            ],
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
                'max-datagram-size' => 1500,  // reject oversized UDP packets
                'packet-limit' => 350,         // max packets/IP/tick before block
            ],
            // Chunk streaming: controls how chunks are compressed and sent
            // to clients. L2 is recommended (fast CPU, reasonable bandwidth).
            // Time-budget mode caps total chunk processing per tick.
            // Server settings: port and memory limit.
            // port: null = use server.properties (default 19132).
            //        Set to a number (1-65535) to override.
            // memory-limit: null = use start.sh / PHP CLI default (512M).
            //               Set to e.g. "1G" or "256M" to override.
            'port' => null,
            'memory-limit' => null,

            // Region pipeline: offload entity movement+gravity to worker
            // threads. Experimental — enable after confirming zero mismatches
            // in gate mode. apply-mode makes workers authoritative (disables
            // main-thread MovementSystem + PhysicsSystem).
            'pipeline' => [
                'enabled' => false,
                'apply-mode' => false,
            ],

            'chunk-streaming' => [
                'compression-level' => 2,    // 1-9: lower=faster CPU, larger packets
                'per-tick' => 2,            // max chunks per player per tick (DO NOT raise above ~4)
                'time-budget-ms' => 30.0,    // global budget shared across all players
                'use-time-budget' => true,   // false = fixed CPT only (scales poorly)
            ],
        ];
        file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
