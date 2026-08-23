<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\Format;

/**
 * /help [page] — shows registered commands in colored, paginated groups.
 * Usage: /help           → first page
 *        /help 2          → page 2
 *        /help <command>  → usage for a specific command
 */
final class HelpCommand extends BuiltinCommand {
    private const PER_PAGE = 8;

    public function __construct() {
        parent::__construct(
            'help',
            'List available commands',
            '/help [page|command]',
            ['?'],
            'khronos.command.help',
            category: 'general',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }

        $all = $kernel->getCommandPort()->getCommands();

        // Deduplicate: commands and their aliases point to the same object.
        $seen = [];
        $commands = [];
        foreach ($all as $cmd) {
            $id = spl_object_id($cmd);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $commands[] = $cmd;
            }
        }

        if ($commands === []) {
            $sender->sendMessage(Format::error('No commands registered.'));
            return true;
        }

        $arg = $args[0] ?? '';

        // /help <command> → show usage for that command
        if ($arg !== '' && !ctype_digit($arg)) {
            $cmd = $kernel->getCommandPort()->getCommand($arg);
            if ($cmd === null) {
                $sender->sendMessage(Format::error("Unknown command: $arg"));
                return false;
            }
            $sender->sendMessage(Format::header('/' . $cmd->getName()));
            $sender->sendMessage(Format::MUTED . $cmd->getDescription() . Format::RESET);
            $sender->sendMessage(Format::INFO . 'Usage: ' . Format::VALUE . $cmd->getUsage() . Format::RESET);
            $aliases = $cmd->getAliases();
            if ($aliases !== []) {
                $sender->sendMessage(Format::INFO . 'Aliases: ' . Format::VALUE . implode(', ', $aliases) . Format::RESET);
            }
            return true;
        }

        // /help [page] → paginated list
        $page = max(1, (int)($arg ?: '1'));
        $totalPages = max(1, (int)ceil(count($commands) / self::PER_PAGE));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * self::PER_PAGE;
        $slice = array_slice($commands, $offset, self::PER_PAGE);

        // Group by category for display
        $categoryOrder = ['player', 'world', 'admin', 'general'];
        $categoryNames = [
            'player' => 'Player',
            'world' => 'World',
            'admin' => 'Admin',
            'general' => 'General',
        ];

        // Build sorted slice
        $sorted = [];
        foreach ($categoryOrder as $cat) {
            foreach ($slice as $cmd) {
                if ($cmd->category === $cat) {
                    $sorted[] = $cmd;
                }
            }
        }

        // Header
        $sender->sendMessage(Format::header('=== Khronos Commands ===') . ' ' . Format::MUTED . "Page $page/$totalPages" . Format::RESET);
        $sender->sendMessage('');

        // Commands
        $lastCat = '';
        foreach ($sorted as $cmd) {
            $cat = $cmd->category;
            if ($cat !== $lastCat) {
                $name = $categoryNames[$cat] ?? ucfirst($cat);
                $sender->sendMessage(Format::HIGHLIGHT . '--- ' . $name . ' ---' . Format::RESET);
                $lastCat = $cat;
            }
            $sender->sendMessage(
                Format::GREEN . '/' . $cmd->getName() .
                Format::MUTED . ' — ' .
                Format::VALUE . $cmd->getDescription() .
                Format::RESET
            );
        }

        // Footer
        $sender->sendMessage('');
        $sender->sendMessage(
            Format::MUTED . 'Use ' .
            Format::INFO . '/help <command>' .
            Format::MUTED . ' for details  *  ' .
            Format::INFO . '/help ' . ($page < $totalPages ? ($page + 1) : 1) .
            Format::MUTED . ' for next page' .
            Format::RESET
        );

        return true;
    }
}
