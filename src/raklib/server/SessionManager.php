<?php

declare(strict_types=1);

namespace raklib\server;

use raklib\Binary;
use raklib\protocol\ACK;
use raklib\protocol\ADVERTISE_SYSTEM;
use raklib\protocol\DATA_PACKET_0;
use raklib\protocol\DATA_PACKET_1;
use raklib\protocol\DATA_PACKET_2;
use raklib\protocol\DATA_PACKET_3;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\DATA_PACKET_5;
use raklib\protocol\DATA_PACKET_6;
use raklib\protocol\DATA_PACKET_7;
use raklib\protocol\DATA_PACKET_8;
use raklib\protocol\DATA_PACKET_9;
use raklib\protocol\DATA_PACKET_A;
use raklib\protocol\DATA_PACKET_B;
use raklib\protocol\DATA_PACKET_C;
use raklib\protocol\DATA_PACKET_D;
use raklib\protocol\DATA_PACKET_E;
use raklib\protocol\DATA_PACKET_F;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\OPEN_CONNECTION_REPLY_1;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;
use raklib\protocol\Packet;
use raklib\protocol\UNCONNECTED_PING;
use raklib\protocol\UNCONNECTED_PING_OPEN_CONNECTIONS;
use raklib\protocol\UNCONNECTED_PONG;
use raklib\RakLib;
use function asort;
use function chr;
use function count;
use function max;
use function microtime;
use function mt_rand;
use function ord;
use function serialize;
use function strlen;
use function strval;
use function substr;
use function time_sleep_until;
use const PHP_INT_MAX;

/**
 * Runs the RakNet server loop on the RakLibServer thread.
 *
 * Responsibilities:
 *  - read datagrams off the UDP socket, answer the offline handshake
 *    (ping/pong, open-connection request/reply) and dispatch everything else
 *    to the owning Session;
 *  - consume main-thread commands from the ServerHandler queue (outbound
 *    encapsulated packets, close-session, options, shutdown);
 *  - stream events back to the main thread (open/close session, received
 *    game packets, pings).
 *
 * All state here is thread-local: only the queues and the socket are shared.
 */
class SessionManager {

    private int $rakLibTps;
    private float $rakLibTimePerTick;

    /** @var \SplFixedArray|Packet[] */
    protected \SplFixedArray $packetPool;

    protected RakLibServer $server;

    protected UDPServerSocket $socket;

    protected int $receiveBytes = 0;
    protected int $sendBytes = 0;

    /** @var Session[] */
    protected array $sessions = [];

    protected string $name = "";

    protected int $packetLimit = 250;

    /** Max datagram size in bytes. UDP packets larger than this are
     *  rejected before any processing — they cannot be legitimate MCPE
     *  traffic (MTU ~1432) and are likely abuse. Counted against the
     *  per-IP rate limit. */
    protected int $maxDatagramSize = 1500;

    protected bool $shutdown = false;

    /**
     * When KHRONOS_WIRE_TRACE=1, every inbound/outbound packet id (and a hex
     * dump of the small handshake packets) is logged through the thread
     * logger. Off by default: the hot loop pays nothing.
     */
    private bool $wireTrace = false;

    protected int $ticks = 0;
    protected float $lastMeasure;

    /** @var int[] source address => unblock timestamp */
    protected array $block = [];
    /** @var int[] source address => packets seen this tick */
    protected array $ipSec = [];

    public int $serverId;

    public bool $portChecking;

    public function __construct(RakLibServer $server, UDPServerSocket $socket) {
        $this->server = $server;
        $this->socket = $socket;
        $this->serverId = mt_rand(0, PHP_INT_MAX);
    }

    /**
     * Apply the server loop configuration. The old implementation read a
     * khronos.yml; the new server runs a fast loop unconditionally - game
     * logic lives on the kernel main thread, this thread only does wire I/O.
     */
    public function initialize(): void {
        $this->rakLibTps = 100;
        $this->rakLibTimePerTick = 1 / 100;
        $this->packetLimit = 250;
        $this->portChecking = false;
        $this->wireTrace = getenv('KHRONOS_WIRE_TRACE') === '1';
    }

    public function isWireTrace(): bool {
        return $this->wireTrace;
    }

    public function getPort(): int {
        return $this->server->getPort();
    }

    public function getLogger(): ThreadSafeLogger {
        return $this->server->getLogger();
    }

    public function run(): void {
        $this->tickProcessor();
    }

    private function tickProcessor(): void {
        $this->lastMeasure = microtime(true);

        while (!$this->shutdown) {
            try {
                $start = microtime(true);
                while ($this->receivePacket()) {
                }
                while ($this->receiveStream()) {
                }
                $time = microtime(true) - $start;
                if ($time < $this->rakLibTimePerTick) {
                    time_sleep_until(microtime(true) + $this->rakLibTimePerTick - $time);
                }
                $this->tick();
            } catch (\Throwable $e) {
                // A packet handling error must never silently kill the wire
                // thread: log it, drop the offending session, keep serving.
                $this->getLogger()->critical('RakLib loop error: ' . $e->getMessage()
                    . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        }
    }

    private function tick(): void {
        $time = microtime(true);
        foreach ($this->sessions as $session) {
            $session->update($time);

            if ($this->ticks % 40 != 0) {
                continue;
            }
            $this->streamPing($session);
        }

        $this->ipSec = [];

        $check = $this->ticks % $this->rakLibTps === 0;
        if ($check) {
            $diff = max(0.005, $time - $this->lastMeasure);
            $this->streamOption("bandwidth", serialize([
                "up" => $this->sendBytes / $diff,
                "down" => $this->receiveBytes / $diff,
            ]));
            $this->lastMeasure = $time;
            $this->sendBytes = 0;
            $this->receiveBytes = 0;

            if (count($this->block) > 0) {
                asort($this->block);
                $now = microtime(true);
                foreach ($this->block as $address => $timeout) {
                    if ($timeout <= $now) {
                        unset($this->block[$address]);
                    } else {
                        break;
                    }
                }
            }
        }

        ++$this->ticks;
    }

    protected function streamPing(Session $session): void {
        $identifier = $session->getAddress() . ":" . $session->getPort();
        $ping = $session->getPing();

        $buffer = chr(RakLib::PACKET_PING) . chr(strlen($identifier)) . $identifier . chr(strlen(strval($ping))) . strval($ping);
        $this->server->pushThreadToMainPacket($buffer);
    }

    private function receivePacket(): bool {
        $len = $this->socket->readPacket($buffer, $source, $port);
        if ($buffer !== null) {
            $this->receiveBytes += $len;
            if (isset($this->block[$source])) {
                return true;
            }

            if (isset($this->ipSec[$source])) {
                if (++$this->ipSec[$source] >= $this->packetLimit) {
                    $this->blockAddress($source);
                    return true;
                }
            } else {
                $this->ipSec[$source] = 1;
            }

            // Reject oversized datagrams early — no legitimate MCPE traffic
            // exceeds the negotiated MTU (~1432 bytes).  Oversized packets
            // count against the per-IP rate limit to penalise senders.
            if ($len > $this->maxDatagramSize) {
                return true;
            }

            if ($len > 0) {
                $pid = ord($buffer[0]);
                $this->tracePacket($source, $port, $pid, $len, $buffer);

                if ($pid === UNCONNECTED_PING::$ID) {
                    // No need to create a session for just pings.
                    $packet = new UNCONNECTED_PING();
                    $packet->buffer = $buffer;
                    $packet->decode();

                    $pk = new UNCONNECTED_PONG();
                    $pk->serverID = $this->getID();
                    $pk->pingID = $packet->pingID;
                    $pk->serverName = $this->getName();
                    $this->sendPacket($pk, $source, $port);
                } elseif ($pid === UNCONNECTED_PONG::$ID) {
                    // ignored
                } elseif (($packet = $this->getPacketFromPool($pid)) !== null) {
                    $packet->buffer = $buffer;
                    try {
                        $this->getSession($source, $port)->handlePacket($packet);
                    } catch (\Throwable $e) {
                        // Hostile/foreign input must not kill the thread or
                        // wedge a session: log, drop, move on.
                        $this->getLogger()->critical('Error handling 0x' . dechex($pid)
                            . ' from ' . $source . ':' . $port . ': ' . $e->getMessage()
                            . ' @ ' . $e->getFile() . ':' . $e->getLine());
                        $session = $this->sessions[$source . ':' . $port] ?? null;
                        if ($session !== null) {
                            $this->removeSession($session, 'packet error');
                        }
                    }
                } else {
                    if (substr($buffer, 0, 2) !== "\xfe\xfd") {
                        return true; // not even RakNet-shaped; drop
                    }
                    $this->streamRaw($source, $port, $buffer);
                }
            }
            return true;
        }

        return false;
    }

    public function sendPacket(Packet $packet, string $dest, int $port): void {
        $packet->encode();
        if ($this->wireTrace) {
            $buf = $packet->buffer ?? '';
            $hex = strlen($buf) > 0 ? ' hex=' . bin2hex(substr($buf, 0, 64)) : '';
            $this->getLogger()->debug('snd ' . $dest . ':' . $port . ' pid=0x'
                . str_pad(dechex(ord($buf[0])), 2, '0', STR_PAD_LEFT) . ' len=' . strlen($buf) . $hex);
        }
        $this->sendBytes += $this->socket->writePacket($packet->buffer, $dest, $port);
    }

    /**
     * Trace an inbound packet when KHRONOS_WIRE_TRACE=1: always the id/len,
     * plus a hex dump for the small handshake packets (pings, open-connection
     * requests, connected control packets) so a real client's exact bytes can
     * be inspected. Data packets (0x80-0x8f) are only id/len to keep the
     * trace readable.
     */
    private function tracePacket(string $source, int $port, int $pid, int $len, string $buffer): void {
        if (!$this->wireTrace) {
            return;
        }
        $isData = $pid >= 0x80 && $pid <= 0x8f;
        $hex = (!$isData && $len > 0) ? ' hex=' . bin2hex(substr($buffer, 0, 96)) : '';
        $this->getLogger()->debug('rcv ' . $source . ':' . $port . ' pid=0x'
            . str_pad(dechex($pid), 2, '0', STR_PAD_LEFT) . ' len=' . $len . $hex);
    }

    public function streamEncapsulated(Session $session, EncapsulatedPacket $packet, int $flags = RakLib::PRIORITY_NORMAL): void {
        $id = $session->getAddress() . ":" . $session->getPort();
        $buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($id)) . $id . chr($flags) . $packet->toBinary(true);
        $this->server->pushThreadToMainPacket($buffer);
    }

    public function streamRaw(string $address, int $port, string $payload): void {
        $buffer = chr(RakLib::PACKET_RAW) . chr(strlen($address)) . $address . Binary::writeShort($port) . $payload;
        $this->server->pushThreadToMainPacket($buffer);
    }

    protected function streamClose(string $identifier, string $reason): void {
        $buffer = chr(RakLib::PACKET_CLOSE_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($reason)) . $reason;
        $this->server->pushThreadToMainPacket($buffer);
    }

    protected function streamInvalid(string $identifier): void {
        $buffer = chr(RakLib::PACKET_INVALID_SESSION) . chr(strlen($identifier)) . $identifier;
        $this->server->pushThreadToMainPacket($buffer);
    }

    protected function streamOpen(Session $session): void {
        $identifier = $session->getAddress() . ":" . $session->getPort();
        $buffer = chr(RakLib::PACKET_OPEN_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($session->getAddress())) . $session->getAddress() . Binary::writeShort($session->getPort()) . Binary::writeLong($session->getID());
        $this->server->pushThreadToMainPacket($buffer);
    }

    protected function streamACK(string $identifier, int $identifierACK): void {
        $buffer = chr(RakLib::PACKET_ACK_NOTIFICATION) . chr(strlen($identifier)) . $identifier . Binary::writeInt($identifierACK);
        $this->server->pushThreadToMainPacket($buffer);
    }

    protected function streamOption(string $name, string $value): void {
        $buffer = chr(RakLib::PACKET_SET_OPTION) . chr(strlen($name)) . $name . $value;
        $this->server->pushThreadToMainPacket($buffer);
    }

    private function checkSessions(): void {
        if (count($this->sessions) > 4096) {
            foreach ($this->sessions as $i => $s) {
                if ($s->isTemporal()) {
                    unset($this->sessions[$i]);
                    if (count($this->sessions) <= 4096) {
                        break;
                    }
                }
            }
        }
    }

    public function receiveStream(): bool {
        $packet = $this->server->readMainToThreadPacket();

        if ($packet !== null && strlen($packet) > 0) {
            $id = ord($packet[0]);
            $offset = 1;
            if ($id === RakLib::PACKET_ENCAPSULATED) {
                $len = ord($packet[$offset++]);
                $identifier = substr($packet, $offset, $len);
                $offset += $len;
                if (isset($this->sessions[$identifier])) {
                    $flags = ord($packet[$offset++]);
                    $buffer = substr($packet, $offset);
                    $this->sessions[$identifier]->addEncapsulatedToQueue(EncapsulatedPacket::fromBinary($buffer, true), $flags);
                } else {
                    $this->streamInvalid($identifier);
                }
            } elseif ($id === RakLib::PACKET_RAW) {
                $len = ord($packet[$offset++]);
                $address = substr($packet, $offset, $len);
                $offset += $len;
                $port = Binary::readShort(substr($packet, $offset, 2));
                $offset += 2;
                $payload = substr($packet, $offset);
                $this->socket->writePacket($payload, $address, $port);
            } elseif ($id === RakLib::PACKET_CLOSE_SESSION) {
                $len = ord($packet[$offset++]);
                $identifier = substr($packet, $offset, $len);
                if (isset($this->sessions[$identifier])) {
                    $this->removeSession($this->sessions[$identifier]);
                } else {
                    $this->streamInvalid($identifier);
                }
            } elseif ($id === RakLib::PACKET_INVALID_SESSION) {
                $len = ord($packet[$offset++]);
                $identifier = substr($packet, $offset, $len);
                if (isset($this->sessions[$identifier])) {
                    $this->removeSession($this->sessions[$identifier]);
                }
            } elseif ($id === RakLib::PACKET_SET_OPTION) {
                $len = ord($packet[$offset++]);
                $name = substr($packet, $offset, $len);
                $offset += $len;
                $value = substr($packet, $offset);
                switch ($name) {
                    case "name":
                        $this->name = $value;
                        break;
                    case "portChecking":
                        $this->portChecking = (bool)$value;
                        break;
                    case "packetLimit":
                        $this->packetLimit = (int)$value;
                        break;
                    case "maxDatagramSize":
                        $this->maxDatagramSize = max(512, (int)$value);
                        break;
                }
            } elseif ($id === RakLib::PACKET_BLOCK_ADDRESS) {
                $len = ord($packet[$offset++]);
                $address = substr($packet, $offset, $len);
                $offset += $len;
                $timeout = Binary::readInt(substr($packet, $offset, 4));
                $this->blockAddress($address, $timeout);
            } elseif ($id === RakLib::PACKET_UNBLOCK_ADDRESS) {
                $len = ord($packet[$offset++]);
                $address = substr($packet, $offset, $len);
                $offset += $len;
                $this->unblockAddress($address);
            } elseif ($id === RakLib::PACKET_SHUTDOWN) {
                foreach ($this->sessions as $session) {
                    $this->removeSession($session);
                }

                $this->socket->close();
                $this->shutdown = true;
            } elseif ($id === RakLib::PACKET_EMERGENCY_SHUTDOWN) {
                $this->shutdown = true;
            } else {
                return false;
            }

            return true;
        }

        return false;
    }

    public function blockAddress(string $address, int $timeout = 300): void {
        $final = microtime(true) + $timeout;
        if (!isset($this->block[$address]) || $timeout === -1) {
            if ($timeout === -1) {
                $final = PHP_INT_MAX;
            } else {
                $this->getLogger()->notice("IP $address blocked for $timeout seconds (packet flood)");
            }
            $this->block[$address] = $final;
        } elseif ($this->block[$address] < $final) {
            $this->block[$address] = $final;
        }
    }

    public function unblockAddress(string $address): void {
        unset($this->block[$address]);
    }

    public function getSession(string $ip, int $port): Session {
        $id = $ip . ":" . $port;
        if (!isset($this->sessions[$id])) {
            $this->checkSessions();
            $this->sessions[$id] = new Session($this, $ip, $port);
        }

        return $this->sessions[$id];
    }

    public function removeSession(Session $session, string $reason = "unknown"): void {
        $id = $session->getAddress() . ":" . $session->getPort();
        if (isset($this->sessions[$id])) {
            $this->sessions[$id]->close();
            unset($this->sessions[$id]);
            $this->streamClose($id, $reason);
        }
    }

    public function openSession(Session $session): void {
        $this->streamOpen($session);
    }

    public function notifyACK(Session $session, int $identifierACK): void {
        $this->streamACK($session->getAddress() . ":" . $session->getPort(), $identifierACK);
    }

    public function getName(): string {
        return $this->name;
    }

    public function getID(): int {
        return $this->serverId;
    }

    private function registerPacket(int $id, string $class): void {
        $this->packetPool[$id] = new $class();
    }

    public function getPacketFromPool(int $id): ?Packet {
        $pk = $this->packetPool[$id];
        if ($pk !== null) {
            return clone $pk;
        }

        return null;
    }

    public function registerPackets(): void {
        $this->packetPool = new \SplFixedArray(256);

        $this->registerPacket(UNCONNECTED_PING_OPEN_CONNECTIONS::$ID, UNCONNECTED_PING_OPEN_CONNECTIONS::class);
        $this->registerPacket(OPEN_CONNECTION_REQUEST_1::$ID, OPEN_CONNECTION_REQUEST_1::class);
        $this->registerPacket(OPEN_CONNECTION_REPLY_1::$ID, OPEN_CONNECTION_REPLY_1::class);
        $this->registerPacket(OPEN_CONNECTION_REQUEST_2::$ID, OPEN_CONNECTION_REQUEST_2::class);
        $this->registerPacket(OPEN_CONNECTION_REPLY_2::$ID, OPEN_CONNECTION_REPLY_2::class);
        $this->registerPacket(UNCONNECTED_PONG::$ID, UNCONNECTED_PONG::class);
        $this->registerPacket(ADVERTISE_SYSTEM::$ID, ADVERTISE_SYSTEM::class);
        $this->registerPacket(DATA_PACKET_0::$ID, DATA_PACKET_0::class);
        $this->registerPacket(DATA_PACKET_1::$ID, DATA_PACKET_1::class);
        $this->registerPacket(DATA_PACKET_2::$ID, DATA_PACKET_2::class);
        $this->registerPacket(DATA_PACKET_3::$ID, DATA_PACKET_3::class);
        $this->registerPacket(DATA_PACKET_4::$ID, DATA_PACKET_4::class);
        $this->registerPacket(DATA_PACKET_5::$ID, DATA_PACKET_5::class);
        $this->registerPacket(DATA_PACKET_6::$ID, DATA_PACKET_6::class);
        $this->registerPacket(DATA_PACKET_7::$ID, DATA_PACKET_7::class);
        $this->registerPacket(DATA_PACKET_8::$ID, DATA_PACKET_8::class);
        $this->registerPacket(DATA_PACKET_9::$ID, DATA_PACKET_9::class);
        $this->registerPacket(DATA_PACKET_A::$ID, DATA_PACKET_A::class);
        $this->registerPacket(DATA_PACKET_B::$ID, DATA_PACKET_B::class);
        $this->registerPacket(DATA_PACKET_C::$ID, DATA_PACKET_C::class);
        $this->registerPacket(DATA_PACKET_D::$ID, DATA_PACKET_D::class);
        $this->registerPacket(DATA_PACKET_E::$ID, DATA_PACKET_E::class);
        $this->registerPacket(DATA_PACKET_F::$ID, DATA_PACKET_F::class);
        $this->registerPacket(NACK::$ID, NACK::class);
        $this->registerPacket(ACK::$ID, ACK::class);
    }
}
