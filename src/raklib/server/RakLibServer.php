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

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;
use function class_exists;

/**
 * The RakNet server thread.
 *
 * Owns the UDP socket and the entire RakNet protocol state machine
 * (SessionManager + per-client Sessions with reliability windows, ACK/NACK
 * and split-packet reassembly). The main thread never touches the socket:
 * it exchanges packets through ServerHandler and the two thread-safe queues,
 * and receives decoded game packets through the ServerInstance callbacks.
 *
 * pmmpthread v6.3 only permits thread-safe values (scalars, ThreadSafe,
 * ThreadSafeArray) as properties of a Thread subclass, so every property
 * here is a scalar or a ThreadSafe container.
 */
class RakLibServer extends Thread {

    private int $port;
    private string $interface;
    /** Lines logged by the thread, drained by the main thread. */
    private ThreadSafeArray $logQueue;
    /** main-thread -> RakLib thread commands (ServerHandler writes, SessionManager reads). */
    private ThreadSafeArray $internalQueue;
    /** RakLib thread -> main-thread events (SessionManager writes, ServerHandler reads). */
    private ThreadSafeArray $externalQueue;
    private ThreadSafe $state;
    private ?ThreadSafeLogger $logger;

    /**
     * Force-load every class the thread uses on the MAIN thread before
     * start(). pmmpthread v6 workers cannot autoload - they only inherit the
     * class table that was loaded when the thread started - so without this
     * the worker dies with "Class not found" the moment it touches a raklib
     * class. (Same pattern as ParallelGeneratorAdapter's preload.)
     */
    public static function preload(): void {
        foreach ([
            \raklib\RakLib::class,
            \raklib\Binary::class,
            ThreadSafeLogger::class,
            SessionManager::class,
            UDPServerSocket::class,
            Session::class,
            \raklib\protocol\Packet::class,
            \raklib\protocol\DataPacket::class,
            \raklib\protocol\EncapsulatedPacket::class,
            \raklib\protocol\PacketReliability::class,
            \raklib\protocol\AcknowledgePacket::class,
            \raklib\protocol\UNCONNECTED_PING::class,
            \raklib\protocol\UNCONNECTED_PING_OPEN_CONNECTIONS::class,
            \raklib\protocol\UNCONNECTED_PONG::class,
            \raklib\protocol\ADVERTISE_SYSTEM::class,
            \raklib\protocol\OPEN_CONNECTION_REQUEST_1::class,
            \raklib\protocol\OPEN_CONNECTION_REPLY_1::class,
            \raklib\protocol\OPEN_CONNECTION_REQUEST_2::class,
            \raklib\protocol\OPEN_CONNECTION_REPLY_2::class,
            \raklib\protocol\CLIENT_CONNECT_DataPacket::class,
            \raklib\protocol\CLIENT_HANDSHAKE_DataPacket::class,
            \raklib\protocol\CLIENT_DISCONNECT_DataPacket::class,
            \raklib\protocol\SERVER_HANDSHAKE_DataPacket::class,
            \raklib\protocol\PING_DataPacket::class,
            \raklib\protocol\PONG_DataPacket::class,
            \raklib\protocol\ACK::class,
            \raklib\protocol\NACK::class,
            \raklib\protocol\DATA_PACKET_0::class,
            \raklib\protocol\DATA_PACKET_1::class,
            \raklib\protocol\DATA_PACKET_2::class,
            \raklib\protocol\DATA_PACKET_3::class,
            \raklib\protocol\DATA_PACKET_4::class,
            \raklib\protocol\DATA_PACKET_5::class,
            \raklib\protocol\DATA_PACKET_6::class,
            \raklib\protocol\DATA_PACKET_7::class,
            \raklib\protocol\DATA_PACKET_8::class,
            \raklib\protocol\DATA_PACKET_9::class,
            \raklib\protocol\DATA_PACKET_A::class,
            \raklib\protocol\DATA_PACKET_B::class,
            \raklib\protocol\DATA_PACKET_C::class,
            \raklib\protocol\DATA_PACKET_D::class,
            \raklib\protocol\DATA_PACKET_E::class,
            \raklib\protocol\DATA_PACKET_F::class,
        ] as $class) {
            class_exists($class);
        }
    }

    /**
     * @throws \InvalidArgumentException when the port is outside the UDP range
     */
    public function __construct(int $port, string $interface = "0.0.0.0") {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("Invalid port range: $port");
        }
        $this->port = $port;
        $this->interface = $interface;
        $this->logQueue = new ThreadSafeArray();
        $this->internalQueue = new ThreadSafeArray();
        $this->externalQueue = new ThreadSafeArray();
        $this->state = new ThreadSafe();
        $this->state->running = true;
        $this->logger = null; // created inside run() (thread-local)
    }

    public function isShutdown(): bool {
        return $this->state->running === false;
    }

    /** Ask the thread to stop; it exits once its loop observes the flag. */
    public function shutdown(): void {
        $this->state->running = false;
    }

    public function getPort(): int {
        return $this->port;
    }

    public function getInterface(): string {
        return $this->interface;
    }

    public function getLogger(): ThreadSafeLogger {
        if ($this->logger === null) {
            // Only reachable from inside run() (thread-local).
            $this->logger = new ThreadSafeLogger($this->logQueue);
        }
        return $this->logger;
    }

    public function getLogQueue(): ThreadSafeArray {
        return $this->logQueue;
    }

    public function getExternalQueue(): ThreadSafeArray {
        return $this->externalQueue;
    }

    public function getInternalQueue(): ThreadSafeArray {
        return $this->internalQueue;
    }

    public function pushMainToThreadPacket(string $str): void {
        $this->internalQueue[] = $str;
    }

    public function readMainToThreadPacket(): ?string {
        return $this->internalQueue->shift();
    }

    public function pushThreadToMainPacket(string $str): void {
        $this->externalQueue[] = $str;
    }

    public function readThreadToMainPacket(): ?string {
        return $this->externalQueue->shift();
    }

    public function run(): void {
        $logger = new ThreadSafeLogger($this->logQueue);
        $this->logger = $logger;
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use ($logger): bool {
            $logger->debug("[$errno] $errstr in $errfile at line $errline");
            return true;
        });

        try {
            $sessionManager = new SessionManager($this, new UDPServerSocket($logger, $this->port, $this->interface));
            $sessionManager->registerPackets();
            $sessionManager->initialize();
            $sessionManager->run();
        } catch (\Throwable $e) {
            // A dead RakNet thread must never be silent: the main thread
            // drains these lines and can surface them in logs/tests.
            $logger->critical(get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $this->state->running = false;
        }
    }
}
