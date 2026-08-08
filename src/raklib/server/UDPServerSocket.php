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

use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_recvfrom;
use function socket_sendto;
use function socket_set_nonblock;
use function socket_set_option;
use function strlen;
use const AF_INET;
use const SO_RCVBUF;
use const SO_REUSEADDR;
use const SO_SNDBUF;
use const SOCK_DGRAM;
use const SOL_SOCKET;
use const SOL_UDP;

/**
 * Non-blocking UDP socket owned by the RakLibServer thread.
 *
 * Buffer sizes are raised to 8 MiB so a burst of clients cannot overflow the
 * kernel socket buffers and drop packets (dropped DATA packets force RakNet
 * retransmission, which costs far more than the socket memory).
 */
class UDPServerSocket {

    private ThreadSafeLogger $logger;
    /** @var \Socket */
    private \Socket $socket;

    /**
     * @throws \RuntimeException when the socket cannot bind (port in use)
     */
    public function __construct(ThreadSafeLogger $logger, int $port = 19132, string $interface = "0.0.0.0") {
        $this->logger = $logger;
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new \RuntimeException("Failed to create UDP socket: " . socket_strerror(socket_last_error()));
        }
        $this->socket = $socket;

        if (@socket_bind($this->socket, $interface, $port) !== true) {
            $err = socket_strerror(socket_last_error());
            throw new \RuntimeException("Failed to bind UDP socket to $interface:$port: $err (is another server running on that port?)");
        }
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 0);
        $this->setSendBuffer(1024 * 1024 * 8)->setRecvBuffer(1024 * 1024 * 8);
        socket_set_nonblock($this->socket);
    }

    public function getSocket(): \Socket {
        return $this->socket;
    }

    public function close(): void {
        socket_close($this->socket);
    }

    /**
     * @param string &$buffer
     * @param string &$source
     * @param int    &$port
     */
    public function readPacket(&$buffer, &$source, &$port) {
        return socket_recvfrom($this->socket, $buffer, 65535, 0, $source, $port);
    }

    public function writePacket(string $buffer, string $dest, int $port): int|false {
        return socket_sendto($this->socket, $buffer, strlen($buffer), 0, $dest, $port);
    }

    public function setSendBuffer(int $size): self {
        @socket_set_option($this->socket, SOL_SOCKET, SO_SNDBUF, $size);
        return $this;
    }

    public function setRecvBuffer(int $size): self {
        @socket_set_option($this->socket, SOL_SOCKET, SO_RCVBUF, $size);
        return $this;
    }
}
