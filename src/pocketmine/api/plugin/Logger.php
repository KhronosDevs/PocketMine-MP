<?php

declare(strict_types=1);

namespace pocketmine\api\plugin;

use pocketmine\utils\Terminal;
use pocketmine\utils\TextFormat;

class Logger {
    private string $prefix;

    public function __construct(string $pluginName) {
        $this->prefix = TextFormat::GREEN . "[" . $pluginName . "]" . TextFormat::RESET . " ";
    }

    private static function out(string $message): void {
        if (Terminal::hasFormattingCodes()) {
            echo TextFormat::toANSI($message) . "\n";
        } else {
            echo TextFormat::clean($message) . "\n";
        }
    }

    public function info(string $message): void {
        self::out($this->prefix . $message);
    }

    public function warning(string $message): void {
        self::out($this->prefix . TextFormat::YELLOW . $message . TextFormat::RESET);
    }

    public function error(string $message): void {
        self::out($this->prefix . TextFormat::RED . $message . TextFormat::RESET);
    }

    public function debug(string $message): void {
        self::out($this->prefix . TextFormat::GRAY . $message . TextFormat::RESET);
    }

    public function critical(string $message): void {
        self::out($this->prefix . TextFormat::RED . TextFormat::BOLD . $message . TextFormat::RESET);
    }

    public function notice(string $message): void {
        self::out($this->prefix . TextFormat::AQUA . $message . TextFormat::RESET);
    }

    public function alert(string $message): void {
        self::out($this->prefix . TextFormat::RED . TextFormat::BOLD . $message . TextFormat::RESET);
    }

    public function emergency(string $message): void {
        self::out($this->prefix . TextFormat::RED . TextFormat::BOLD . $message . TextFormat::RESET);
    }

    public function log(string $level, string $message): void {
        self::out($this->prefix . "[$level] $message");
    }
}