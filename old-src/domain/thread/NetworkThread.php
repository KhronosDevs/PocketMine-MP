<?php

declare(strict_types=1);

namespace pocketmine\domain\thread;

use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use pocketmine\network\protocol\DataPacket;
use pocketmine\Thread;
use pocketmine\ThreadSafe;

final class NetworkThread extends Thread {
    private NetworkPort $networkPort;
    private ThreadSafe $outboundQueue;
    private ThreadSafe $inboundQueue;
    private bool $running = true;

    public function __construct(
        NetworkPort $networkPort,
    ) {
        $this->networkPort = $networkPort;
        $this->outboundQueue = new ThreadSafe();
        $this->inboundQueue = new ThreadSafe();
    }

    public function getOutboundQueue(): ThreadSafe {
        return $this->outboundQueue;
    }

    public function getInboundQueue(): ThreadSafe {
        return $this->inboundQueue;
    }

    public function run(): void {
        $this->registerClassLoader();
        
        // Start the network port (e.g., RakLib)
        // For now, we just process queues
        
        while ($this->running) {
            // 1. Process inbound packets from network
            $this->processInbound();
            
            // 2. Send outbound packets
            $this->sendOutbound();
            
            // Small sleep to prevent busy waiting
            usleep(1000); // 1ms
        }
    }

    public function shutdown(): void {
        $this->running = false;
    }

    private function processInbound(): void {
        // In a full implementation, this would:
        // 1. Receive packets from RakLib socket
        // 2. Decode them
        // 3. Push to inbound queue for coordination thread
        
        // For now, we process the inbound queue from region threads
        while (true) {
            $packet = $this->inboundQueue->shift();
            if ($packet === null) break;
            
            // Process packet (decode, validate, etc.)
            // Then forward to coordination thread
        }
    }

    private function sendOutbound(): void {
        // Send packets from outbound queue
        while (true) {
            $item = $this->outboundQueue->shift();
            if ($item === null) break;
            
            [$playerRef, $packet] = $item;
            $this->networkPort->sendPacket($playerRef, $packet);
        }
    }

    public function queueOutbound(PlayerRef $playerRef, \pocketmine\network\protocol\DataPacket $packet): void {
        $this->outboundQueue[] = [$playerRef, $packet];
    }

    public function queueInbound(string $data, string $address, int $port): void {
        $this->inboundQueue[] = [
            'data' => $data,
            'address' => $address,
            'port' => $port,
        ];
    }

    public function shutdown(): void {
        $this->running = false;
    }
}