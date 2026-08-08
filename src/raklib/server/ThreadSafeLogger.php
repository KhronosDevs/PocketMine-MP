<?php

declare(strict_types=1);

/*
 * RakLib network library
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

namespace raklib\server;

use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

/**
 * Log sink usable from inside the RakLibServer thread.
 *
 * pmmpthread v6.3 forbids non-ThreadSafe objects (like a main-thread logger)
 * as Thread properties, so the server thread appends formatted lines to a
 * thread-safe queue here and the main thread drains them at its own pace
 * (Protocol84NetworkAdapter::processPendingCommands()).
 */
final class ThreadSafeLogger extends ThreadSafe {

    private ThreadSafeArray $messages;

    public function __construct(ThreadSafeArray $messages) {
        $this->messages = $messages;
    }

    public function log(string $level, string $message): void {
        $this->messages[] = $level . ': ' . $message;
    }

    public function debug(string $message): void {
        $this->log('debug', $message);
    }

    public function notice(string $message): void {
        $this->log('notice', $message);
    }

    public function warning(string $message): void {
        $this->log('warning', $message);
    }

    public function critical(string $message): void {
        $this->log('critical', $message);
    }

    public function emergency(string $message): void {
        $this->log('emergency', $message);
    }
}
