<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\Format;

/**
 * /status — show server status and performance info.
 *
 * Sections: Performance, Memory, Entities & Chunks, Players, World, Pipeline.
 * All values use MCPE color codes for in-game readability.
 */
final class StatusCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'status',
            'Show server status and performance info',
            '/status',
            [],
            'khronos.command.status',
            category: 'general',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }

        $lines = [];

        // ── Header ──
        $lines[] = Format::header('══════ Khronos Status ══════');
        $lines[] = '';

        // ── Performance ──
        $lines[] = Format::HIGHLIGHT . '▸ Performance' . Format::RESET;

        // Uptime
        $lines[] = '  ' . Format::label('Uptime', $this->formatUptime($kernel->getUptime()));

        // Tick stats
        $tickStats = $kernel->getTickStats();
        if ($tickStats['count'] > 0) {
            $tps = round(1000.0 / max($tickStats['mean_ms'], 0.001), 1);
            $tpsDisplay = min($tps, 20.0);
            $tpsColor = $tpsDisplay >= 19.0 ? Format::GREEN : ($tpsDisplay >= 15.0 ? Format::YELLOW : Format::RED);
            $lines[] = '  ' . Format::label('TPS',
                $tpsColor . $tpsDisplay . Format::RESET .
                Format::MUTED . ' (avg ' . $tickStats['mean_ms'] . ' ms/tick)' . Format::RESET
            );
            $lines[] = '  ' . Format::label('Tick spread',
                Format::MUTED . 'p50 ' . Format::VALUE . $tickStats['median_ms'] . 'ms' .
                Format::MUTED . '  p95 ' . Format::VALUE . $tickStats['p95_ms'] . 'ms' .
                Format::MUTED . '  p99 ' . Format::VALUE . $tickStats['p99_ms'] . 'ms' .
                Format::MUTED . '  max ' . Format::VALUE . $tickStats['max_ms'] . 'ms' .
                Format::RESET
            );
        } else {
            $lines[] = '  ' . Format::label('TPS', Format::MUTED . '— (no ticks recorded)' . Format::RESET);
        }
        $lines[] = '';

        // ── Memory ──
        $lines[] = Format::HIGHLIGHT . '▸ Memory' . Format::RESET;
        $memProfile = $kernel->getMemoryProfile();
        $phpCurrent = $memProfile['phpCurrentBytes'];
        $phpPeak = $memProfile['phpPeakBytes'];

        $memColor = $phpCurrent < 128 * 1024 * 1024 ? Format::GREEN : ($phpCurrent < 256 * 1024 * 1024 ? Format::YELLOW : Format::RED);
        $lines[] = '  ' . Format::label('PHP usage',
            $memColor . $this->formatBytes($phpCurrent) . Format::RESET .
            Format::MUTED . ' (peak: ' . $this->formatBytes($phpPeak) . ')' . Format::RESET
        );
        $lines[] = '';

        // ── Entities & Chunks ──
        $lines[] = Format::HIGHLIGHT . '▸ Entities & Chunks' . Format::RESET;
        $entityCount = $memProfile['entities'];
        $lines[] = '  ' . Format::label('Entities', Format::VALUE . number_format($entityCount) . Format::RESET);

        $chunkCount = $memProfile['loadedChunks'];
        $chunkBytes = $memProfile['chunkBytes'];
        $maxChunks = $memProfile['maxLoadedChunks'];
        $lines[] = '  ' . Format::label('Chunks',
            Format::VALUE . number_format($chunkCount) . Format::RESET .
            Format::MUTED . ' loaded (' . $this->formatBytes($chunkBytes) . ')' .
            Format::MUTED . '  peak: ' . Format::VALUE . number_format($maxChunks) . Format::RESET
        );
        $lines[] = '';

        // ── Players ──
        $lines[] = Format::HIGHLIGHT . '▸ Players' . Format::RESET;
        $players = $kernel->getNetworkSessionService()->getOnlinePlayers();
        $playerCount = count($players);
        $max = $kernel->getWorldRegistry()->getConfig(0)?->maxPlayers ?? 20;
        $lines[] = '  ' . Format::label('Online',
            Format::VALUE . $playerCount . Format::MUTED . '/' . $max . Format::RESET
        );
        if ($playerCount > 0) {
            $names = [];
            foreach ($players as $p) {
                $names[] = Format::GREEN . $p['username'] . Format::RESET;
            }
            $lines[] = '  ' . Format::MUTED . implode(', ', $names) . Format::RESET;
        }
        $lines[] = '';

        // ── World ──
        $lines[] = Format::HIGHLIGHT . '▸ World' . Format::RESET;
        $config = $kernel->getWorld()->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
        if ($config instanceof \pocketmine\core\resource\WorldConfig) {
            $timeStr = $this->formatWorldTime($config->time);
            $lines[] = '  ' . Format::label('Time', Format::VALUE . $config->time . Format::MUTED . ' (' . $timeStr . ')' . Format::RESET);

            $weatherNames = ['clear', 'rain', 'thunder', 'storm'];
            $weather = $weatherNames[$config->weather] ?? 'unknown';
            $lines[] = '  ' . Format::label('Weather', Format::VALUE . ucfirst($weather) . Format::RESET);

            $seed = $kernel->getWorldRegistry()->getConfig(0)?->seed ?? 0;
            $lines[] = '  ' . Format::label('Seed', Format::VALUE . $seed . Format::RESET);
        }
        $lines[] = '';

        // ── Pipeline ──
        $pipelineStats = $kernel->getRegionPipelineStats();
        if ($pipelineStats['enabled']) {
            $lines[] = Format::HIGHLIGHT . '▸ Pipeline' . Format::RESET;
            $mode = $pipelineStats['applyMode'] ? Format::GREEN . 'APPLY' : Format::YELLOW . 'GATE';
            $lines[] = '  ' . Format::label('Mode', $mode . Format::RESET);

            $stats = [];
            $stats[] = Format::MUTED . 'mirrored ' . Format::VALUE . number_format($pipelineStats['mirrored']) . Format::RESET;
            $stats[] = Format::MUTED . 'skipped ' . Format::VALUE . number_format($pipelineStats['skipped']) . Format::RESET;
            $stats[] = Format::MUTED . 'applied ' . Format::VALUE . number_format($pipelineStats['applied']) . Format::RESET;
            $lines[] = '  ' . implode('  ', $stats);

            if (!$pipelineStats['applyMode']) {
                $mismatches = $pipelineStats['lastTickMismatches'];
                $mismatchColor = $mismatches > 0 ? Format::RED : Format::GREEN;
                $lines[] = '  ' . Format::MUTED . 'compared ' . Format::VALUE . number_format($pipelineStats['compared']) .
                    Format::MUTED . '  mismatches ' . $mismatchColor . number_format($mismatches) . Format::RESET;
            }
        }
        $lines[] = '';

        // ── Footer ──
        $lines[] = Format::MUTED . '═══════════════════════════' . Format::RESET;

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

    private function formatWorldTime(int $ticks): string {
        // MCPE: 0 = dawn, 6000 = noon, 12000 = dusk, 18000 = midnight
        $hour = intdiv(($ticks + 6000) % 24000, 1000);
        $suffix = 'AM';
        $displayHour = $hour;
        if ($hour >= 12) {
            $suffix = 'PM';
            $displayHour = $hour - 12;
        }
        if ($displayHour === 0) {
            $displayHour = 12;
        }
        return $displayHour . ':00 ' . $suffix;
    }
}
