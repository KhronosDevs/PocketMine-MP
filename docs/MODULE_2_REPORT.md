# Module 2 Report — Network (pocketmine/network + raklib)

**Commit:** `module 2: network — PHP 8.2 modernization + hostile-input robustness fixes`
**Baseline:** `eddb025` (Module 1). **Scope:** `src/pocketmine/network/` (~54 protocol packets, Network, RakLibInterface, query, rcon, upnp, interfaces) + `src/raklib/` (Binary, protocol layer, SessionManager/Session thread core). **~95 files, ~8,500 lines.**

## What was done

### Refactoring (PHP 8.2 modernization)
- `declare(strict_types=1)` on all leaf files (the two `Binary` serialization backbones stay weak — see Module 1 rationale).
- Native type declarations, typed properties, `?int`/`?float`/`?string` nullable props, return types, `void` returns.
- Packet classes: typed properties for eid/coordinates/flags/etc., preserving field names and wire order exactly.
- `#[AllowDynamicProperties]` added to `pocketmine\network\protocol\DataPacket` (plugins set `reliability`/`orderChannel`/`__encapsulatedPacket` dynamically) — **must be fully qualified** `#[\AllowDynamicProperties]` in namespaced files (verified empirically: the bare form silently no-ops).
- Thread-side classes (`RakLibServer`, `SessionManager`, `Session`, `RCONInstance`) modernized conservatively — typed props are all assigned in constructors or before first read, safe under pmmpthread object-copy semantics.
- 16 `DATA_PACKET_0..F` files generated from the shared base `DataPacket`.

## Bugs discovered & fixed

| # | Bug | Severity | Fix |
|---|-----|----------|-----|
| 1 | `QueryHandler::$token` typed property read before init (first `regenerateToken()` reads `$token` to seed `$lastToken`) — **fatal at server start** | CRITICAL | Default `= ""` (unobservable vs original `null`, constructor always regenerates before any `handle()`) |
| 2 | Typed `: int`/`: float` returns on raklib `Binary::read*` turned truncated-input `null`s (original weak-mode behavior) into **TypeErrors** — hostile client could kill the RakLib thread. **Proven by fuzz: 31 new fatals vs 0 original** | CRITICAL | `readTriad/readLTriad/readShort/readLShort → ?int`, `readFloat/readLFloat/readDouble/readLDouble → ?float` with clean length guards; widened `Packet::get(int\|bool\|null)` + `getShort/getTriad/getLTriad → ?int` (original Session logic null-checks `seqNumber !== null`, `messageIndex === null`) |
| 3 | `EncapsulatedPacket::toBinary()` wrote nullable `messageIndex/orderIndex/orderChannel/split*` unguarded — strict-mode TypeErrors (fuzz-proven) | HIGH | `?? 0` guards on all nullable writes (wire-identical: original weak mode wrote 0) |
| 4 | `raklib\protocol\DataPacket::encode()` → `putLTriad($this->seqNumber)` with `null` after `clean()` | HIGH | `putLTriad($this->seqNumber ?? 0)` |
| 5 | `AcknowledgePacket::encode()` wrote possibly-null seq numbers | MEDIUM | `?? 0` guards |
| 6 | `#[AllowDynamicProperties]` bare (no FQ) on `RCONInstance` — silently no-op | MEDIUM | `#[\AllowDynamicProperties]` (empirically verified FQ required in namespaces) |
| 7 | `RCON::check()` worker-restart path instantiated `RCONInstance($socket, $password, $clientsPerThread)` — shifted args vs constructor `($logger, $socket, $password, $maxClients)` | MEDIUM | Corrected to full 4-arg call (verified against original constructor) |
| 8 | **Cross-module (Module 1)**: `pocketmine/utils/Binary.php` had the same unguarded readers (`readShort/readTriad/readLTriad/readLShort/readFloat*/readDouble*/readLong`) — main-thread hostile-packet TypeErrors | HIGH | Length guards returning `0`/`0.0`, matching the existing `readInt` guard precedent |

### Pre-existing quirks preserved (verified identical to original)
- `Packet::get()` negative/`true` len semantics; `getAddress()` by-ref version param.
- Network `handlePacket` query branch (`$this->queryHandler instanceof ...`) is dead code in the original too (property never assigned) — preserved faithfully.
- RCONInstance `continue`-in-switch warnings — present in original, harmless.
- RakLibServer's 4-space indentation — matches the original file's own style.
- `ExplodePacket::encode` uses `putFloat($this->radius)` — identical to original (reviewer false alarm resolved).

## Validation results

| Check | Result |
|---|---|
| `php -l` all module files | ✅ ALL CLEAN |
| 74-case byte-fidelity harness (encode output vs `git HEAD` original classes, dual classloader) | ✅ IDENTICAL (both before and after robustness fixes) |
| Hostile-input fuzz (25 truncated/garbage buffers × 16 packet classes + fromBinary + toBinary null fields) | ✅ 0 fatals in both original and new — **full parity** (was 31 vs 0 before fixes) |
| Boot test (`PocketMine.php --no-wizard`) | ✅ `Done (0.245s)!` normal running state, Query running, **0 errors/fatals** |
| Code review (deepseek-flash) | ✅ 8 findings → all verified, 6 required fixes applied, 3 resolved as non-issues with evidence |

## Performance notes
- No hot-path regressions: encode/decode wire output byte-identical; the added guards are `strlen()` comparisons on the same strings already being unpacked.
- `CompressBatchedTask` still frees `data` after compression (`?string` → null) as in original.

## Remaining tech debt (documented, not changed)
- `Network::handlePacket` dead query branch — matches original; query replies rely on `Server::getQueryHandler()` construction only. Functionally query over the RakNet port was inert in the original as well.
- `pocketmine\utils\Binary::readMetadata` LONG width quirk (reads 4/advances 8) — pre-existing, preserved.
- `str_pad` argument-order fix noted in module 1 report remains the only intentional wire-path behavior change (original would TypeError on PHP 8.2).
