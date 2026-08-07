<?php

declare(strict_types=1);

namespace pocketmine\api\command;

use pocketmine\api\plugin\Plugin;

class PluginCommand extends Command {
    /** @var (callable(CommandSender, string, array): bool)|null */
    private $executor = null;

    public function __construct(
        string $name,
        Plugin $owner,
        string $description = "",
        string $usage = "",
        array $aliases = [],
        ?string $permission = null,
    ) {
        parent::__construct($name, $description, $usage, $aliases, $permission);
    }

    /**
     * Bind the handler invoked when the command is executed. The callback
     * receives (CommandSender $sender, string $label, array $args) and must
     * return bool.
     */
    public function setExecutor(callable $executor): void {
        $this->executor = $executor;
    }

    public function execute(CommandSender $sender, array $args): bool {
        if ($this->executor === null) {
            return false;
        }
        return (bool)($this->executor)($sender, $this->name, $args);
    }
}
