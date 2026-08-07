<?php

declare(strict_types=1);

namespace pocketmine\api\plugin;

use pocketmine\utils\TextFormat;

class Logger {
    private string $prefix;

    public function __construct(string $pluginName) {
        $this->prefix = TextFormat::GREEN . "[" . $pluginName . "]" . TextFormat::RESET . " ";
    }

    public function info(string $message): void {
        echo $this->prefix . $message . "\n";
    }

    public function warning(string $message): void {
        echo $this->prefix . TextFormat::YELLOW . $message . TextFormat::RESET . "\n";
    }

    public function error(string $message): void {
        echo $this->prefix . TextFormat::RED . $message . TextFormat::RESET . "\n";
    }

    public function debug(string $message): void {
        echo $this->prefix . TextFormat::GRAY . $message . TextFormat::RESET . "\n";
    }

    public function critical(string $message): void {
        echo $this->prefix . TextFormat::RED . TextFormat::BOLD . $message . TextFormat::RESET . "\n";
    }

    public function notice(string $message): void {
        echo $this->prefix . TextFormat::AQUA . $message . TextFormat::RESET . "\n";
    }

    public function alert(string $message): void {
        echo $this->prefix . TextFormat::RED . TextFormat::BOLD . $message . TextFormat::RESET . "\n";
    }

    public function emergency(string $message): void {
        echo $this->prefix . TextFormat::RED . TextFormat::BOLD . $message . TextFormat::RESET . "\n";
    }

    public function log(string $level, string $message): void {
        echo $this->prefix . "[$level] $message\n";
    }
}