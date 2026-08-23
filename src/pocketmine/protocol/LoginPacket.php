<?php



/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\protocol;



use function base64_decode;
use function chr;
use function explode;
use function extension_loaded;
use function in_array;
use function json_decode;
use function openssl_verify;
use function ord;
use function str_repeat;
use function strtr;
use function substr;
use function time;
use function wordwrap;
use function zlib_decode;

class LoginPacket extends DataPacket
{
    const NETWORK_ID = Info::LOGIN_PACKET;

    const MOJANG_PUBKEY = "MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAE8ELkixyLcwlZryUQcu1TvPOmI2B7vX83ndnWRUaXm74wFfa5f/lwQNTfrLVHa2PmenpGI6JhIMUJaWZrjmMj90NoKNFSNBuKdm8rYiXsfaz3K36x/1U26HpG0ZxK/V1V";

    public string $username = "";
    public int $protocol = 0;

    public string $clientUUID = "";
    public int $clientId = 0;
    public ?string $identityPublicKey = null;
    public string $serverAddress = "";

    /** @var string|null */
    public $skinId = null;
    /** @var string|null (base64_decode() can also return false for invalid data) */
    public $skin = null;

    public function decode(): void
    {
        $this->protocol = $this->getInt();
        if (!in_array($this->protocol, Info::ACCEPTED_PROTOCOLS, true)) {
            return; //Do not attempt to decode for non-accepted protocols
        }
        $str = zlib_decode($this->get($this->getInt()), 2 * 1024 * 1024);
        if ($str === false) {
            return; //Corrupt payload
        }
        $this->setBuffer($str, 0);

        $time = time();

        $chainData = json_decode($this->get($this->getLInt()))->{"chain"};
        // Start with the trusted one
        $chainKey = self::MOJANG_PUBKEY;
        while (!empty($chainData)) {
            foreach ($chainData as $index => $chain) {
                list($verified, $webtoken) = $this->decodeToken($chain, $chainKey);
                if (isset($webtoken["extraData"])) {
                    if (isset($webtoken["extraData"]["displayName"])) {
                        $this->username = $webtoken["extraData"]["displayName"];
                    }
                    if (isset($webtoken["extraData"]["identity"])) {
                        $this->clientUUID = $webtoken["extraData"]["identity"];
                    }
                }
                if ($verified) {
                    $verified = isset($webtoken["nbf"]) && $webtoken["nbf"] <= $time && isset($webtoken["exp"]) && $webtoken["exp"] > $time;
                }
                if ($verified && isset($webtoken["identityPublicKey"])) {
                    // Looped key chain. #blamemojang
                    if ($webtoken["identityPublicKey"] != self::MOJANG_PUBKEY) $chainKey = $webtoken["identityPublicKey"];
                    break;
                } elseif ($chainKey === null) {
                    // We have already gave up
                    break;
                }
            }
            if (!$verified && $chainKey !== null) {
                $chainKey = null;
            } else {
                unset($chainData[$index]);
            }
        }
        list($verified, $skinToken) = $this->decodeToken($this->get($this->getLInt()), $chainKey);
        if (isset($skinToken["ClientRandomId"])) {
            $this->clientId = $skinToken["ClientRandomId"];
        }
        if (isset($skinToken["ServerAddress"])) {
            $this->serverAddress = $skinToken["ServerAddress"];
        }
        if (isset($skinToken["SkinData"])) {
            $this->skin = base64_decode($skinToken["SkinData"], true);
        }
        if (isset($skinToken["SkinId"])) {
            $this->skinId = $skinToken["SkinId"];
        }
        if ($verified) {
            $this->identityPublicKey = $chainKey;
        }
    }

    public function encode(): void {}

    public function decodeToken(string $token, ?string $key): array
    {
        $tokens = explode(".", $token);
        list($headB64, $payloadB64, $sigB64) = $tokens;

        if ($key !== null && extension_loaded("openssl")) {
            $sig = base64_decode(strtr($sigB64, '-_', '+/'), true);
            $rawLen = 48; // ES384
            for ($i = $rawLen; $i > 0 && $sig[$rawLen - $i] == chr(0); $i--) {
            }
            $j = $i + (ord($sig[$rawLen - $i]) >= 128 ? 1 : 0);
            for ($k = $rawLen; $k > 0 && $sig[2 * $rawLen - $k] == chr(0); $k--) {
            }
            $l = $k + (ord($sig[2 * $rawLen - $k]) >= 128 ? 1 : 0);
            $len = 2 + $j + 2 + $l;
            $derSig = chr(48);
            if ($len > 255) {
                throw new \RuntimeException("Invalid signature format");
            } elseif ($len >= 128) {
                $derSig .= chr(81);
            }
            $derSig .= chr($len) . chr(2) . chr($j);
            $derSig .= str_repeat(chr(0), $j - $i) . substr($sig, $rawLen - $i, $i);
            $derSig .= chr(2) . chr($l);
            $derSig .= str_repeat(chr(0), $l - $k) . substr($sig, 2 * $rawLen - $k, $k);

            $verified = openssl_verify($headB64 . "." . $payloadB64, $derSig, "-----BEGIN PUBLIC KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END PUBLIC KEY-----\n", OPENSSL_ALGO_SHA384) === 1;
        } else {
            $verified = false;
        }

        return [$verified, json_decode(base64_decode($payloadB64, true), true)];
    }
}
