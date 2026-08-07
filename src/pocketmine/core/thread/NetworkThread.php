<?php

declare(strict_types=1);

namespace pocketmine\core\thread;

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;
use function count;
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

    /** @var ThreadSafeArray<int, string> inbound frames awaiting batching: json_encode([addrKey, payload]) */
    private ThreadSafeArray $inboundQueue;
    /** @var ThreadSafeArray<int, string> encoded outbound frames: json_encode([addrKey, payload]) */
    private ThreadSafeArray $outboundQueue;
    /** @var ThreadSafeArray<int, string> batched frames ready for the adapter to send */
    private ThreadSafeArray $sendQueue;
    private ThreadSafe $state;

    public function __construct() {
        $this->inboundQueue = new ThreadSafeArray();
        $this->outboundQueue = new ThreadSafeArray();
        $this->sendQueue = new ThreadSafeArray();
        $this->state = new ThreadSafe();
        $this->state->running = true;
    }

    public function queueOutboundFrame(string $addrKey, string $payload): void {
        $this->outboundQueue[] = json_encode([$addrKey, $payload]);
    }

    public function queueInboundFrame(string $addrKey, string $payload): void {
        $this->inboundQueue[] = json_encode([$addrKey, $payload]);
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
            $this->processInbound();
            $this->processOutbound();
            usleep(1000);
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
        while (($frame = $this->outboundQueue->shift()) !== null) {
            $this->sendQueue[] = $frame;
        }
    }

    public function shutdown(): void {
        $this->state->running = false;
    }
}
