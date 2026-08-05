# Module 6 Report — Entities (`src/pocketmine/entity/` + `src/pocketmine/Player.php`)

**Commit:** `module: entities — PHP 8.2 modernization (Entity backbone, Effect duration null-fix, cross-module Potion getColor fix, booted-probe-verified)`

**Files changed:** 72 (70 entity files + Player.php + Potion.php fix) · **+587/−454**

---

## What was done

### 1. All 70 entity files modernized
- `declare(strict_types=1)` everywhere (was 0/70).
- **Safe override return types** scripted across the tree:
  - `spawnTo() : void`, `getDrops() : array`, `onUpdate() : bool`, `initEntity()/saveNBT() : void` — verified by body audit before typing.
- **Entity backbone (`Entity.php`, 2,019 lines):**
  - Typed statics: `$entityCount : int`, `$knownEntities/$shortNames : array`, `init() : void`.
  - Typed props where redeclaration-safe: `$dropExp : array`, `$canCollide : bool`, `$eyeHeight : ?float`, `$stepHeight : float` (Player), `$closed : bool`.
  - **Left untyped deliberately:** `$width/$height/$length/$gravity/$drag` — Entity declares them *without* defaults; subclasses redeclare them *with* defaults. PHP property types are invariant, so typing the parent would fatal every subclass (module-5 `$id/$meta` lesson). Also reverted an initial script pass that typed them (caught by load-all).
  - Method typing: `createBaseNBT() : CompoundTag`, `getSaveId() : string`, `setDataProperty() : bool` (Player override returns bool too), `teleport() : bool`, `setMotion() : bool`, `onUpdate() : bool`, `isAlive() : bool`, `close() : void`, `entityBaseTick() : bool`, etc.
  - **Deliberately untyped:** `attack()/heal()/addEffect()/removeEffect()` family, `getNameTag()/getDataProperty()` — Player (module 9) overrides return values that differ; typing would break variance.
- **Bases typed where zero-override-safe:** `Living` (gravity/drag/getDrops/knockBack/entityBaseTick), `Creature`, `Human` (gap-fill), `Projectile` (`$shootingEntity : ?Entity`, `$hadCollision : bool`, damage/knockback surface), `Effect`, `Attribute`, `AttributeMap`, `InstantEffect`.
- **Complex classes hand-typed:** Minecart, Item(entity), FallingSand, Boat, Painting, XPOrb, Lightning, PrimedTNT, Squid, Zombie (private helpers), ThrownPotion, Arrow, FishingHook, Bat/Ocelot/Rabbit/Villager (mob setters), projectiles.
- **Player.php:** 12 matching return types + `$stepHeight : float` added so the newly typed Entity surface stays variance-compatible (module 9 file, minimal forward-compatible touch).
- `setLinked($type = 0, Entity $entity)` left as-is: optional-before-required emits a PHP 8.2 deprecation (pre-existing in ORIG), but reordering would break the public plugin API. Documented as known debt.

### 2. Bugs found & fixed

| # | Bug | Severity | Found by |
|---|---|---|---|
| 1 | **`Effect::$duration` default `null` → `0`** — typing `int|float $duration = 0` changed `getDuration()` from `null` to `0` on fresh effects, altering the potion-effect network packet field (`$pk->duration`) and `IntTag("Duration", ...)`. | behavioral regression | **Parity harness** (ORIG vs NEW diff) |
| 2 | **`Potion::getColor(): Color` but returns `array`** (module-4 regression!) — every ThrownPotion kill threw `Return value must be of type Color, array returned` and broke the level tick. Fixed to `: array` + removed dead `Color` import. | runtime crash, cross-module | **Booted-server entity probe** (spawned 16 entity types, ticked 40 ticks) |

### 3. Validation

| Check | Result |
|---|---|
| Lint (70 entity + Player + Potion) | ✅ clean |
| Load-all (71 classes + Player) | ✅ LOADED=71 FAIL=0 |
| **Logic harness** (Effect/Attribute/AttributeMap/Entity registry + createBaseNBT) vs ORIG worktree | ✅ **84=84 lines, zero functional diff** (only pre-existing Deprecated path line) |
| **Booted-server entity probe** — 16 entity types spawned on live Level, ticked 40 ticks | ✅ correct physics: mobs `-0.078`, Zombie `-0.24`, projectiles `-1.17/-1.95/-3.9`, Minecart static, Boat `-0.08`; natural despawns (XPOrb/Item/FallingSand); **0 CRITICAL after Potion fix**; TPS 20 |
| Boot test | ✅ `Done (0.244s/0.265s)`, 0 errors |
| Mod-4 regression (post-fix) | ✅ byte-identical to validated MOD4-NEW |
| Mod-5 regression | ✅ byte-identical to validated MOD5-NEW |
| Code review | ✅ all findings addressed (validation-gap closed with booted probe) |

### 4. Compatibility notes
- **Zero public API narrowing.** Untyped params kept where plugins pass mixed values (`setHealth`, `setDuration`, `attack`, `addEffect`).
- `getDuration() : int|float|null` — callers do `null/8`, `null < null`, `null % interval`; PHP coerces null identically to ORIG (which also returned null).
- **Only pre-existing shutdown-path issue:** `pmmp\thread\Thread::start()` at `PocketMine.php:513` expects 1 arg — fires only on graceful `server->shutdown()` (the plain boot test uses timeout-kill, so this was never exercised before; unrelated to module 6, noted for the final audit).

### 5. Remaining technical debt
- `Entity::setLinked($type = 0, Entity $entity)` optional-before-required deprecation (PHP 8.2) — needs param reorder, which is an API break; defer.
- `PocketMine.php:513` shutdown `Thread::start()` argument mismatch — pre-existing, out of module scope.
- Runtime entity behavior (combat, AI targeting, inventory interactions) beyond spawn/tick/gravity is validated only via boot + probe; deeper flows land with modules 8–10.
