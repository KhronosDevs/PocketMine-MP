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

use raklib\protocol\EncapsulatedPacket;

/**
 * The main-thread consumer of RakLib events. Implemented by the network
 * adapter; every method runs on the main thread inside
 * ServerHandler::handlePacket().
 */
interface ServerInstance {

    /** A client completed the RakNet connected handshake. */
    public function openSession(string $identifier, string $address, int $port, int $clientID): void;

    /** A session closed (server- or client-initiated, or timeout). */
    public function closeSession(string $identifier, string $reason): void;

    /** A decoded game packet arrived from a connected client. */
    public function handleEncapsulated(string $identifier, EncapsulatedPacket $packet, int $flags): void;

    /** A raw (unconnected) datagram that no RakNet packet matched. */
    public function handleRaw(string $address, int $port, string $payload): void;

    /** A previously FLAG_NEED_ACK packet was acknowledged by the client. */
    public function notifyACK(string $identifier, int $identifierACK): void;

    /** Periodic transport stats / server-option echoes (bandwidth, name). */
    public function handleOption(string $option, string $value): void;

    /** Periodic ping measurement for a session. */
    public function handlePing(string $identifier, int $ping): void;
}
