<?php

declare(strict_types=1);

namespace pocketmine\core\thread;

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;
use function count;
use function pack;
use function strlen;
use function zlib_encode;
use const ZLIB_ENCODING_DEFLATE;

/**
 * Packet pipeline worker thread.
 *
 * pmmpthread v6.3 only permits thread-safe values (scalars, ThreadSafe,
 * ThreadSafeArray) as properties of a Thread subclass. All socket I/O and
 * protocol encode/decode therefore happens on the main thread (see
 * Protocol84NetworkAdapter); this thread performs pure data transformations
 * (batch compression / packet framing) on scalar payloads exchanged through
 * thread-safe queues.
 */
final class NetworkThread extends Thread {
    private const BATCH_THRESHOLD = 512;
    private const BATCH_MAX_PACKETS = 64;
    /** Cap frames drained per pass so a burst cannot monopolize the loop. */
    private const OUTBOUND_FRAME_CAP = 512;

    /** @var ThreadSafeArray<int, string> inbound frames awaiting batching: json_encode([addrKey, payload]) */
    private ThreadSafeArray $inboundQueue;
    /** @var ThreadSafeArray<int, string> encoded outbound frames: json_encode([addrKey, payload]) */
    private ThreadSafeArray $outboundQueue;
    /** @var ThreadSafeArray<int, string> batched frames ready for the adapter to send */
    private ThreadSafeArray $sendQueue;
    private ThreadSafe $state;
    /** Wait/notify condvar: sleep between polls instead of busy-waiting. */
    private SnoozeHandle $sleeper;

    public function __construct() {
        $this->inboundQueue = new ThreadSafeArray();
        $this->outboundQueue = new ThreadSafeArray();
        $this->sendQueue = new ThreadSafeArray();
        $this->state = new ThreadSafe();
        $this->state->running = true;
        $this->sleeper = new SnoozeHandle();
    }

    public function queueOutboundFrame(string $addrKey, string $payload): void {
        // Payloads are raw packet buffers (zlib-compressed batches): arbitrary
        // binary that json_encode() would reject as invalid UTF-8 (returning
        // false and crashing the consumer). Base64 keeps every queue frame a
        // valid JSON string.
        $this->outboundQueue[] = json_encode([$addrKey, base64_encode($payload)]);
        $this->sleeper->wakeup();
    }

    public function queueInboundFrame(string $addrKey, string $payload): void {
        $this->inboundQueue[] = json_encode([$addrKey, base64_encode($payload)]);
        $this->sleeper->wakeup();
    }

    public function getSendQueue(): ThreadSafeArray {
        return $this->sendQueue;
    }

    public function getInboundQueue(): ThreadSafeArray {
        return $this->inboundQueue;
    }

    public function getOutboundQueue(): ThreadSafeArray {
        return $this->outboundQueue;
    }

    public function run(): void {
        while ($this->state->running) {
            // Block until frames arrive (the adapter wakes us on every queue
            // push). The 5ms timeout is a shutdown/edge-case safety net, not
            // the steady-state path - an idle thread burns ~zero CPU.
            $this->sleeper->sleep(5_000);
            $this->sleeper->consumeWakeups();
            $this->processInbound();
            $this->processOutbound();
        }
    }

    private function processInbound(): void {
        $batch = [];
        $total = 0;
        while (($frame = $this->inboundQueue->shift()) !== null) {
            $decoded = json_decode($frame, true);
            if (!is_array($decoded)) {
                continue;
            }
            // Payload stays base64 (ASCII) so the batch json_encode below
            // never sees raw binary.
            $batch[] = $decoded;
            $total += strlen((string)$decoded[1]);
            if (count($batch) >= self::BATCH_MAX_PACKETS || $total >= self::BATCH_THRESHOLD) {
                break;
            }
        }
        if (empty($batch)) {
            return;
        }
        // Batch-compress frames so the main thread can forward them efficiently.
        $compressed = zlib_encode(
            json_encode($batch),
            ZLIB_ENCODING_DEFLATE
        );
        $this->sendQueue[] = $compressed === false ? '' : $compressed;
    }

    private function processOutbound(): void {
        // Coalesce per-destination frames into single batched datagrams so the
        // adapter makes one socket_sendto per burst instead of one per packet.
        // Each payload is length-prefixed (2-byte big-endian) so the batch is
        // self-delimiting: [len:2][body][len:2][body]... A receiver splits the
        // datagram by reading lengths. (Raw getBuffer() bodies are NOT
        // length-prefixed on their own, hence the explicit framing here.)
        $batches = []; // addrKey => concatenated length-prefixed payloads
        $processed = 0;
        while (($frame = $this->outboundQueue->shift()) !== null && $processed < self::OUTBOUND_FRAME_CAP) {
            $decoded = json_decode($frame, true);
            if (!is_array($decoded) || count($decoded) < 2) {
                continue;
            }
            [$addrKey, $payload] = $decoded;
            if (!is_string($addrKey) || !is_string($payload)) {
                continue;
            }
            $payload = base64_decode($payload, true);
            if ($payload === false) {
                continue;
            }
            $processed++;
            $batches[$addrKey] = ($batches[$addrKey] ?? '') . pack('n', strlen($payload)) . $payload;
            if (strlen($batches[$addrKey]) >= self::BATCH_THRESHOLD) {
                $this->sendQueue[] = json_encode([$addrKey, base64_encode($batches[$addrKey])]);
                unset($batches[$addrKey]);
            }
        }
        foreach ($batches as $addrKey => $payload) {
            $this->sendQueue[] = json_encode([$addrKey, base64_encode($payload)]);
        }
    }

    public function shutdown(): void {
        $this->state->running = false;
        $this->sleeper->wakeup(); // break the worker out of sleep() immediately
    }
}
