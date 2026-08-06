<?php

declare(strict_types=1);

namespace pocketmine\command;

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pocketmine\utils\Utils;
use function extension_loaded;
use function feof;
use function fgets;
use function fopen;
use function function_exists;
use function getopt;
use function is_resource;
use function posix_isatty;
use function readline_add_history;
use function readline_callback_handler_install;
use function readline_callback_handler_remove;
use function readline_callback_read_char;
use function stream_select;
use function trim;

class CommandReader extends Thread
{

    private bool $readline = false;

    /** @var ThreadSafeArray */
    protected ThreadSafeArray $buffer;

    private bool $shutdown = false;

    public function __construct()
    {
        $opts = getopt("", ["disable-readline"]);

        if (
            extension_loaded("readline") &&
            !isset($opts["disable-readline"]) &&
            (!function_exists("posix_isatty") || posix_isatty(STDIN))
        ) {
            $this->readline = true;
        }

        $this->buffer = new ThreadSafeArray();

        $this->start(Thread::INHERIT_ALL);
    }

    public function shutdown() : void
    {
        $this->shutdown = true;
    }

    private function readline_callback($line) : void
    {
        if ($line !== "") {
            $this->buffer[] = $line;
            readline_add_history($line);
        }
    }

    private function readLine($stdin) : void
    {
        if (!$this->readline) {
            $line = trim(fgets($stdin));

            if ($line !== "") {
                $this->buffer[] = $line;
            }
        } else {
            readline_callback_read_char();
        }
    }

    /**
     * Reads a line from console, if available. Returns null if not available
     *
     * @return string|null
     */
    public function getLine() : ?string
    {
        if ($this->buffer->count() !== 0) {
            return $this->buffer->shift();
        }

        return null;
    }

    public function quit() : void
    {
        $this->shutdown = true;

        if (Utils::getOS() !== "win") {
            parent::quit();
        }
    }

    public function run(): void
    {
        $stdin = fopen("php://stdin", "r");

        if (!is_resource($stdin)) {
            return;
        }

        if ($this->readline) {
            readline_callback_handler_install("Genisys> ", [$this, "readline_callback"]);
        }

        while (!$this->shutdown) {

            $r = [$stdin];
            $w = null;
            $e = null;

            if (stream_select($r, $w, $e, 0, 200000) > 0) {

                if (feof($stdin)) {

                    if (Utils::getOS() === "win") {
                        $stdin = fopen("php://stdin", "r");

                        if (!is_resource($stdin)) {
                            break;
                        }
                    } else {
                        break;
                    }
                }

                $this->readLine($stdin);
            }
        }

        if ($this->readline) {
            readline_callback_handler_remove();
        }
    }

    public function getThreadName() : string
    {
        return "Console";
    }
}
