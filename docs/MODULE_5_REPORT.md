# Module 5 Report — Blocks (`src/pocketmine/block/`)

**Scope:** 196 files (18,158 lines) — the `Block` base class, 6 abstract bases (`Solid`, `Transparent`, `Flowable`, `Fallable`, `Liquid`, `Door`, `Stair`, `Crops`, `Thin`, ...) and ~180 concrete blocks.
**Commit:** (module 5 checkpoint) · Revertible via `git revert <sha>`

---

## Refactoring performed

### `Block.php` (the 834-line backbone)
- **Typed statics:** `public static array $list`, `public static int $updateDelay`, `protected static array $randomUpdateBlocks`, `private static ?Block $airCache`.
- **Typed props where PHP allows it:** `?Vector3 $position`, `?Level $level`, `?AxisAlignedBB $boundingBox`. **`$id` / `$meta` deliberately left untyped** — 181 subclasses redeclare them, and PHP property redeclaration is invariant (typing the base would fatal at load). This is the module-4-flagged `new $class(null)` flow's resolution.
- **Typed ctor** `Block(int $id, int $meta = 0)` and `get(): Block` return. **`get($id, $meta = 0, Position $pos = null)` params left untyped** — one-arg callsites (`GroundCover`, `Liquid`, `FallingSand`, `Painting`, `Sugarcane`) and weak-mode plugins passing numeric strings stay fully compatible.
- Zero-override signatures typed (methods no subclass overrides).

### All leaf blocks (scripted + hand-verified)
- `declare(strict_types=1);` everywhere.
- Ctors typed `?int $meta = 0` (nullable — null-meta flows from `Item::get(id, null)` → `ItemBlock` → `Block::get`).
- Literal-return typing: `getHardness()/getResistance() : int|float`, `getToolType()/getLightLevel() : int`, bool methods `: bool`.

### Variance web (audited with a purpose-built script, 2 passes)
- `Flowable::getResistance : int|float` + `Lever/PressurePlate/Rail : float` (float literals 2.5/3.5), `TrappedChest : int|float` (`getHardness() * 5` int/float trap).
- `Stair::collidesWithBB` flagged by audit but is commented-out code (false positive).

---

## Bugs discovered & fixed
1. **Int→float coercion regression (caught by the parity harness, the headline catch):** scripted `getHardness(): float` coerced every int literal (`2` → `2.0`, `0` → `0.0`), and `Block::getResistance()` = `getHardness() * 5` **cascaded** the coercion (`res=10` → `10.0`). Fixed with `: int|float` — union types in strict mode do **not** widen ints, so `2` stays `2`. Re-verified byte-identical.
2. **Randomized drops made the harness non-deterministic** (Gravel flint chance, LapisOre 4–8) — harness now seeds `mt_srand`/`srand` per block/meta; production code untouched.
3. **PHP property-redeclaration invariance** — would have been a fatal had `$id`/`$meta` been typed in the base; detected before it shipped.

---

## Compatibility concerns
- **None material.** No public signature was narrowed: `Block::get` params untyped, base ctor params `int` match subclass `?int $meta` (contravariant widening, allowed), all overrides covariant.
- `getHardness()/getResistance()` now declare `int|float` — plugins reading these get the exact same int/float values as before (harness-proven), just with a declared contract.
- Known pre-existing quirk (deferred, module-1 Vector3 artifact): `getBoundingBox()` throws on **unpositioned** blocks — identical in baseline, isolated in harness.

## Performance
- `strict_types` is compile-time; typed returns are neutral-to-slightly-positive. `getHardness`/`getResistance` are not tick-hot (break/placement only). No hot-path regressions.

## Validation results
| Check | Result |
|---|---|
| Lint (196 files) | ✅ clean |
| **Behavior-parity harness** — 256 ids × 10 metas (incl. null/-1/32767) + cross-module `Item::get`→`getBlock()` + getSide/topSolid/metadata | ✅ **0 diff lines** (1,868/1,868 vs module-4 baseline) |
| Load-all classes (incl. 3 interfaces: BlockIds, ElectricalAppliance, SolidLight) | ✅ 196/196 |
| Boot test | ✅ `Done (0.302s)!` TPS 20, 0 errors |
| Module-2 regressions (wire fidelity + fuzz) / Module-3 (spl smoke) | ✅ identical / 0 fatals / 30-30 |
| Module-4 harness re-run | ✅ identical (only line-number noise in pre-existing warnings) |
| Code review | ✅ all findings addressed (one-arg `get` verified, untyped `getResistance` overrides confirmed safe, whitespace normalized) |

## Remaining technical debt
- `activate`/`deactivate`/`turnOn`/`turnOff`/`isOpened`/`canConnect`/`isLightedByAround` need a Level to execute — provably safe (script only typed literal-return bodies; base only typed zero-override sigs; load-all confirms no variance fatal) but should get runtime coverage in the Level module.
- Cosmetic whitespace variations remain across files (pre-existing style); a repo-wide `php-cs-fixer` pass is deferred to keep module diffs minimal.

---

## Next up
**Module 6 — Entities** (`src/pocketmine/entity/`): `Entity` base + `Living`/`Creature`/`Human`/`Projectile` chains, `Effect`/`Attribute`/`Skin`/`EntityDamageEvent`; note `Item`/`Block` ctor surfaces are now typed — entity code paths passing null meta/ids are exercised by the new cross-module checks.
