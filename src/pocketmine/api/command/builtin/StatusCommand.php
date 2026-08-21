<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;

/**
 * /status — show server status: TPS, memory, entities, chunks, players,
 * pipeline stats, and uptime. Available to ops and the console.
 */
final class StatusCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'status',
            'Show server status and performance info',
            '/status',
            [],
            'khronos.command.status',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }

        $lines = [];

        // Header
        $lines[] = '§6=== Khronos Server Status ===';
        $lines[] = '';

        // Uptime
        $lines[] = '§eUptime: §f' . $this->formatUptime($kernel->getUptime());
        $lines[] = '';

        // Tick stats
        $tickStats = $kernel->getTickStats();
        if ($tickStats['count'] > 0) {
            // Estimate TPS: count ticks in the last second
            $tps = round(1000.0 / max($tickStats['mean_ms'], 0.001), 1);
            $lines[] = '§eTPS: §f' . min($tps, 20.0) . ' §7(mean tick: ' . $tickStats['mean_ms'] . 'ms)';
            $lines[] = '§eTick p95: §f' . $tickStats['p95_ms'] . 'ms §7p99: ' . $tickStats['p99_ms'] . 'ms';
        } else {
            $lines[] = '§eTPS: §f— §7(no ticks recorded yet)';
        }
        $lines[] = '';

        // Memory
        $memProfile = $kernel->getMemoryProfile();
        $phpCurrent = $memProfile['phpCurrentBytes'];
        $phpPeak = $memProfile['phpPeakBytes'];
        $lines[] = '§eMemory: §f' . $this->formatBytes($phpCurrent) . ' §7(peak: ' . $this->formatBytes($phpPeak) . ')';
        $lines[] = '';

        // Entities
        $entityCount = $memProfile['entities'];
        $lines[] = '§eEntities: §f' . number_format($entityCount);
        $lines[] = '';

        // Chunks
        $chunkCount = $memProfile['loadedChunks'];
        $chunkBytes = $memProfile['chunkBytes'];
        $maxChunks = $memProfile['maxLoadedChunks'];
        $lines[] = '§eChunks: §f' . number_format($chunkCount) . ' §7(peak: ' . number_format($maxChunks) . ', ' . $this->formatBytes($chunkBytes) . ')';
        $lines[] = '';

        // Players
        $players = $kernel->getNetworkSessionService()->getOnlinePlayers();
        $playerCount = count($players);
        $lines[] = '§ePlayers: §f' . $playerCount;
        if ($playerCount > 0) {
            $names = [];
            foreach ($players as $p) {
                $names[] = $p['username'];
            }
            $lines[] = '§7' . implode(', ', $names);
        }
        $lines[] = '';

        // Pipeline
        $pipelineStats = $kernel->getRegionPipelineStats();
        if ($pipelineStats['enabled']) {
            $mode = $pipelineStats['applyMode'] ? '§aAPPLY' : '§eGATE';
            $lines[] = '§ePipeline: §f' . $mode . '§e mode';
            $lines[] = '§7Mirrored: ' . number_format($pipelineStats['mirrored'])
                . '  Skipped: ' . number_format($pipelineStats['skipped'])
                . '  Applied: ' . number_format($pipelineStats['applied']);
            if (!$pipelineStats['applyMode']) {
                $lines[] = '§7Compared: ' . number_format($pipelineStats['compared'])
                    . '  Mismatches: ' . ($pipelineStats['lastTickMismatches'] > 0 ? '§c' : '§a') . $pipelineStats['lastTickMismatches'];
            }
            $lines[] = '§7Splits: ' . $pipelineStats['splits'] . '  Merges: ' . $pipelineStats['merges'];
        } else {
            $lines[] = '§ePipeline: §7disabled';
        }

        $sender->sendMessage(implode("\n", $lines));
        return true;
    }

    private function formatUptime(int $seconds): string {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0 || $days > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0 || $hours > 0 || $days > 0) {
            $parts[] = $minutes . 'm';
        }
        $parts[] = $secs . 's';

        return implode(' ', $parts);
    }

    private function formatBytes(int $bytes): string {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
