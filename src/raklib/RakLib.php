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

namespace raklib;

/**
 * RakNet constants and the wire-level message IDs exchanged between the
 * main thread (the ServerInstance) and the RakLibServer thread through the
 * ServerHandler queues.
 *
 * The RakNet handshake/connected protocol packets (UNCONNECTED_PING through
 * DATA_PACKET_F, ACK, NACK) live in raklib\protocol; these constants describe
 * the *internal* control messages of the library itself.
 */
abstract class RakLib {

    /** RakLib library version. */
    public const VERSION = "0.9.0";

    /** RakNet protocol version spoken by MCPE 0.15.x clients. */
    public const PROTOCOL = 6;

    /** RakNet offline-message magic (16 bytes), present in every unconnected packet. */
    public const MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

    /**
     * Priority of an outbound encapsulated packet. LOW/UNDEFINED is normal;
     * IMMEDIATE flushes the session's send queue right away instead of
     * waiting for the next tick.
     */
    public const PRIORITY_NORMAL = 0;
    public const PRIORITY_IMMEDIATE = 1;

    /** Flag for ServerHandler::sendEncapsulated(): request an ACK notification. */
    public const FLAG_NEED_ACK = 0b00001000;

    /*
     * Internal main-thread <-> RakLib-thread packet types.
     *
     * ENCAPSULATED payload:
     *   byte (identifier length), byte[] (identifier),
     *   byte (flags), payload (binary internal EncapsulatedPacket)
     */
    public const PACKET_ENCAPSULATED = 0x01;

    /*
     * OPEN_SESSION payload:
     *   byte (identifier length), byte[] (identifier),
     *   byte (address length), byte[] (address), short (port), long (clientID)
     */
    public const PACKET_OPEN_SESSION = 0x02;

    /*
     * CLOSE_SESSION payload:
     *   byte (identifier length), byte[] (identifier), string (reason)
     */
    public const PACKET_CLOSE_SESSION = 0x03;

    /*
     * INVALID_SESSION payload:
     *   byte (identifier length), byte[] (identifier)
     */
    public const PACKET_INVALID_SESSION = 0x04;

    /* TODO: implement this
     * SEND_QUEUE payload:
     *   byte (identifier length), byte[] (identifier)
     */
    public const PACKET_SEND_QUEUE = 0x05;

    /*
     * ACK_NOTIFICATION payload:
     *   byte (identifier length), byte[] (identifier), int (identifierACK)
     */
    public const PACKET_ACK_NOTIFICATION = 0x06;

    /*
     * SET_OPTION payload:
     *   byte (option name length), byte[] (option name), byte[] (option value)
     */
    public const PACKET_SET_OPTION = 0x07;

    /*
     * RAW payload:
     *   byte (address length), byte[] (address from/to), short (port), byte[] (payload)
     */
    public const PACKET_RAW = 0x08;

    /*
     * BLOCK_ADDRESS payload:
     *   byte (address length), byte[] (address), int (timeout)
     */
    public const PACKET_BLOCK_ADDRESS = 0x09;

    /*
     * UNBLOCK_ADDRESS payload:
     *   byte (address length), byte[] (address)
     */
    public const PACKET_UNBLOCK_ADDRESS = 0x0a;

    /*
     * PING payload:
     *   byte (identifier length), byte[] (identifier), string (ping)
     */
    public const PACKET_PING = 0x0a;

    /* No payload: closes sessions, releases the socket, stops the thread. */
    public const PACKET_SHUTDOWN = 0x7e;

    /* No payload: halts the thread as-is (post-crash condition). */
    public const PACKET_EMERGENCY_SHUTDOWN = 0x7f;
}
