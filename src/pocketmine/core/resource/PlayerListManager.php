<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;

/**
 * Server player lists: ops, whitelist, and name + IP bans, persisted in the
 * classic per-line text files (ops.txt, white-list.txt, banned-players.txt,
 * banned-ips.txt) in the server data path. Loaded once at bootstrap; every
 * mutation rewrites its file immediately so a crash never loses a change.
 * Names are matched case-insensitively; UUIDs (when a player has joined) are
 * also recorded so a rename cannot dodge a ban.
 */
#[Resource]
final class PlayerListManager {
    /** @var list<string> lowercased player names / uuids */
    private array $ops = [];
    private array $whitelist = [];
    private array $bannedNames = [];
    /** @var list<string> raw ip strings */
    private array $bannedIps = [];

    /** @var array<string, string> file path => list reference */
    private array $paths;

    public function __construct(string $dataPath) {
        $this->paths = [
            'ops' => $dataPath . 'ops.txt',
            'whitelist' => $dataPath . 'white-list.txt',
            'banned' => $dataPath . 'banned-players.txt',
            'ips' => $dataPath . 'banned-ips.txt',
        ];
        $this->ops = $this->read($this->paths['ops']);
        $this->whitelist = $this->read($this->paths['whitelist']);
        $this->bannedNames = $this->read($this->paths['banned']);
        $this->bannedIps = $this->read($this->paths['ips']);
    }

    /** @return list<string> */
    private function read(string $file): array {
        if (!is_file($file)) {
            return [];
        }
        $out = [];
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== '#') {
                    $out[] = strtolower($line);
                }
            }
        }
        return $out;
    }

    private function write(string $file, array $entries): void {
        @file_put_contents($file, implode("\n", $entries) . "\n");
    }

    // --- Ops ---------------------------------------------------------------

    public function isOp(string $nameOrUuid): bool {
        $key = strtolower($nameOrUuid);
        return in_array($key, $this->ops, true);
    }

    public function addOp(string $nameOrUuid): void {
        $key = strtolower($nameOrUuid);
        if (!in_array($key, $this->ops, true)) {
            $this->ops[] = $key;
            $this->write($this->paths['ops'], $this->ops);
        }
    }

    public function removeOp(string $nameOrUuid): void {
        $this->ops = array_values(array_diff($this->ops, [strtolower($nameOrUuid)]));
        $this->write($this->paths['ops'], $this->ops);
    }

    /** @return list<string> */
    public function getOps(): array {
        return $this->ops;
    }

    // --- Whitelist ---------------------------------------------------------

    public function isWhitelisted(string $nameOrUuid): bool {
        return in_array(strtolower($nameOrUuid), $this->whitelist, true);
    }

    /** @return list<string> current whitelist entries */
    public function getWhitelist(): array {
        return $this->whitelist;
    }

    public function addWhitelist(string $nameOrUuid): void {
        $key = strtolower($nameOrUuid);
        if (!in_array($key, $this->whitelist, true)) {
            $this->whitelist[] = $key;
            $this->write($this->paths['whitelist'], $this->whitelist);
        }
    }

    public function removeWhitelist(string $nameOrUuid): void {
        $this->whitelist = array_values(array_diff($this->whitelist, [strtolower($nameOrUuid)]));
        $this->write($this->paths['whitelist'], $this->whitelist);
    }

    // --- Bans --------------------------------------------------------------

    public function isBanned(string $nameOrUuid): bool {
        return in_array(strtolower($nameOrUuid), $this->bannedNames, true);
    }

    public function ban(string $nameOrUuid): void {
        $key = strtolower($nameOrUuid);
        if (!in_array($key, $this->bannedNames, true)) {
            $this->bannedNames[] = $key;
            $this->write($this->paths['banned'], $this->bannedNames);
        }
    }

    public function pardon(string $nameOrUuid): void {
        $this->bannedNames = array_values(array_diff($this->bannedNames, [strtolower($nameOrUuid)]));
        $this->write($this->paths['banned'], $this->bannedNames);
    }

    public function isIpBanned(string $ip): bool {
        return in_array(strtolower(trim($ip)), $this->bannedIps, true);
    }

    public function banIp(string $ip): void {
        $key = strtolower(trim($ip));
        if (!in_array($key, $this->bannedIps, true)) {
            $this->bannedIps[] = $key;
            $this->write($this->paths['ips'], $this->bannedIps);
        }
    }

    public function pardonIp(string $ip): void {
        $this->bannedIps = array_values(array_diff($this->bannedIps, [strtolower(trim($ip))]));
        $this->write($this->paths['ips'], $this->bannedIps);
    }

    /** Persist every list (called on shutdown so nothing is stale). */
    public function saveAll(): void {
        $this->write($this->paths['ops'], $this->ops);
        $this->write($this->paths['whitelist'], $this->whitelist);
        $this->write($this->paths['banned'], $this->bannedNames);
        $this->write($this->paths['ips'], $this->bannedIps);
    }
}
