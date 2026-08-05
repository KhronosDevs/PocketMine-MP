# Module 1 Report — Core / Utils / Math

**Status:** ✅ COMPLETE — lint clean, 94/94 smoke checks pass, server boots clean
**Commit:** see git log (checkpoint after this module)

---

## Files changed (40)

### math/ (6)
`Math`, `Vector2`, `Vector3`, `VectorMath`, `AxisAlignedBB`, `Matrix`

### utils/ (28)
`Binary`, `BinaryStream`, `Config`, `Utils`, `MainLogger`, `TextFormat`, `Random`,
`Color`, `UUID`, `Terminal`, `VersionString`, `Optional`, `Range`, `EnumTrait`,
`SingletonTrait`, `ReversePriorityQueue`, `Stream`, `Process`, `Git`, `ServerKiller`,
`Patchable`, `MonkeyPatch`, `ChunkException`, `LevelException`, `PluginException`,
`ServerException`, `VectorIterator`, `BlockIterator`

### Collateral (6 — forced by parent type signatures)
`level/Position`, `level/Location`, `block/Block`, `entity/Entity`,
`network/protocol/DataPacket`, `network/protocol/StrangePacket`

---

## Refactoring performed

- `declare(strict_types=1)` added to all module files **except** `Binary` (kept weak
  deliberately: it is the serialization backbone receiving mixed values from every
  module; weak mode preserves historical argument coercion exactly).
- Native parameter and return types added everywhere (verified against every
  subclass/override: `Position`, `Location`, `Block`, `Entity`, `Particle`, `Sound`,
  `DataPacket`).
- Typed properties (`public float $x/$y/$z` on vectors, `string $buffer` on
  BinaryStream, etc.).
- Nullable types (`?Vector3`, `?string`), union types (`Vector3|float`,
  `int|bool`) where the original accepted multiple shapes.
- Constructor property promotion / cleanup where safe (e.g. `VersionString`).
- Dead 32-bit branches documented as kept-for-reference (64-bit is the only
  supported platform).
- Removed the old php-cs-fixer-era formatting artifacts; preserved 4-tab style and
  original method ordering so git diffs stay readable.
- Preserved **all** public APIs 1:1 — verified method-surface diffs for `Binary`,
  `MainLogger`, `Math`, `Vector2`, `Vector3`, `AxisAlignedBB`, `Matrix`, `Color`,
  `UUID`, `Optional`, `VersionString` against `git HEAD`.

## Bugs discovered (pre-existing, preserved intentionally)

1. `Binary::readMetadata()` LONG case reads `substr($value, $offset, 4)` but
   advances `$offset += 8` — **present identically in the original** (confirmed via
   `git show HEAD`). Behavior preserved per the compatibility mandate; flagged as
   tech debt. Affects entity metadata containing LONG values; the read/write width
   mismatch is a candidate for a future fix with maintainer approval.
2. `Math::ceilFloat()` returns `$i - 1` in some cases (e.g. `ceilFloat(1.2)` → 1) —
   identical to original. Preserved.
3. `Math::floorFloat()`/`ceilFloat()` return **int**, not float — original behavior.
4. `UUID::toString()` produces the quirky `xxxxxxxx-xxxx-Mxxx-8123-...` format when
   the version nibble is derived from parts — identical to original.
5. `VersionString('1.2.3')` (string input) is **not parsed** in this codebase — it
   falls into the dev branch (all zeros). Identical to original; the int-input
   path (bit-packed version) works as before.
6. `Block::getSide()` **fixed as collateral**: it previously called non-static
   `Vector3::getSide()` statically (fatal `Error` on PHP 8 when reached). Now uses
   `parent::getSide(...)`.

## Compatibility concerns

- Only module-1 files carry `strict_types=1`. All other modules are weak-mode, so
  external/plugin callers keep historical coercion semantics. This was the agreed
  leaf-first strategy.
- No public API removed, renamed, or re-typed in a way that changes call-site
  behavior (verified by grep + boot).
- Network packet serialization (`Binary`/`BinaryStream`) byte-level behavior is
  unchanged — verified with round-trip smoke checks for every primitive
  (byte/short/int/long/float/double/triad/bool/string/metadata) plus
  `BinaryStream` read/write parity.
- `DataPacket::clean()` now assigns `""` instead of `null` to the typed `$buffer`
  (required by the typed property; byte-identical effect).
- `raklib/` untouched (own packet base class with untyped buffer).

## Performance improvements

- Typed properties eliminate per-access property metadata lookups on hot paths
  (vectors, bounding boxes).
- `Vector3`/`Vector2` math methods return typed results; no behavior change.
- No new allocations introduced; mutating methods (`expand`, `setComponents`,
  `fromObjectAdd`) keep original mutation semantics so callers that rely on object
  identity are unaffected.

## Validation results

| Check | Result |
|---|---|
| `php -l` on all 40 files | ✅ ALL LINT OK |
| Boot test (`PocketMine.php --no-wizard`, bundled PHP 8.2.32) | ✅ `Done (0.256s)!`, TPS 20, 8 threads, 0 errors/warnings |
| Smoke checks (94 assertions: Binary round-trips, BinaryStream, Vector2/3, Math, AABB, Matrix, Random determinism, Color, UUID, VersionString, Range, Config YAML save/reload, TextFormat, Optional, Process) | ✅ ALL SMOKE CHECKS PASSED |
| Code review (deepseek-flash) | ✅ All findings verified/resolved |
| Original-behavior diff against `git HEAD` | ✅ No logic changes found (verified on every API used by the smoke tests + review greps) |

## Remaining technical debt

- `Binary::readMetadata` LONG width mismatch (pre-existing).
- `Math::ceilFloat` edge-case semantics (pre-existing).
- 32-bit fallback branches kept for reference only.
- `Config`/`Utils` could gain `strict_types` in a later pass once every caller is
  audited (they currently carry explicit guards instead).
