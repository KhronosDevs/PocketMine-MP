# Module 4 — Items (`src/pocketmine/item/`)

**Commit:** `module: items — PHP 8.2 modernization (Item backbone, 4 latent crashes fixed)`
**Scope:** 160 files, +761/−447. All item classes, the Item/Tool/Armor/Food/ItemBlock bases, FoodSource/ItemIds interfaces, and `enchantment/` (Enchantment, EnchantmentEntry, EnchantmentList, EnchantmentLevelTable).

## Refactoring performed

- **`declare(strict_types=1)`** in all 160 files.
- **`Item` base class** — typed properties (`protected ?Block $block = null`, `protected int $id`, `protected ?int $meta = null`, `private string $tags`, `private ?CompoundTag $cachedNBT`, `public int $count`, `protected int $durability = 0`, `protected string $name`, typed statics) and ~40 method signatures typed: `getFuelTime(): ?int`, `useOn(): bool`, `isTool(): bool`, `getMaxDurability(): int|bool`, the tier-returning `is*()` family and `getArmorValue(): int|bool` (accurate union — tool subclasses return `Tool::TIER_*` ints, armor subclasses return `true`, bases return `false`), `isShears()/isArmor(): bool`, `getAttackDamage(): int`, `getModifyAttackDamage(): int|float`, `getDestroySpeed(): int`, `onActivate(): bool`, `onConsume(): void`, `setDamage(?int): void`, `setCount(): void`, plus `: Item`/`: ?CompoundTag`/`: ?Tag`/`: ?Enchantment`/`: ?int` on the NBT/enchantment/creative API.
- **`Tool`/`Armor`/`Food`/`ItemBlock`** — typed ctors and returns; `Tool::getMaxDurability()` typed `: int|bool` with a `?? false` guard on the `$levels` lookup (see below).
- **`enchantment/`** — `Enchantment` fully typed (typed private props/ctor, `: Enchantment`/`: int`/`: bool`/`: string` returns); `EnchantmentEntry`/`EnchantmentList`/`EnchantmentLevelTable` typed.
- **~120 subclasses** — constructors typed `(?int $meta = 0, int $count = 1)` and literal-return overrides typed (`getMaxDurability/getArmorValue/getArmorTier/getArmorType/getAttackDamage: int`, tool `isX(): int`, armor `isX(): bool`, `getResidue(): Item`). Complex subclasses (Potion, Dye, SplashPotion, Fish, Painting, Bucket, Boat, Minecart, SpawnEgg, GlassBottle, FlintSteel) hand-reviewed; complex `onActivate` overrides typed `: bool`.

## Bugs discovered & fixed (all harness-proven)

| # | Bug | Original behavior | Fix |
|---|-----|-------------------|-----|
| 1 | `Item::get(DYE, null)` / `(POTION, null)` / `(SPLASH_POTION, null)` | **Fatal TypeError** (typed `getNameByMeta(int)` received null) | `?int` ctors + `$meta ?? 0` before the typed call. Wildcard `-1` meta + meta-0 name, matching the SAND-null pattern |
| 2 | `Item::get("diamond", null)` + `setDamage(null)` (string path) | **Fatal TypeError** (`setDamage(int)` received null) | `setDamage(?int)` widened on `Item` + `ItemBlock`; null flows to `-1`/`0` exactly as weak-mode coercion did |
| 3 | `Fish::getFoodRestore() : int` returning `1.2` | Weak-mode truncation to `1` + **deprecation** under PHP 8.2 | `return (int) 1.2;` — identical value, no deprecation |
| 4 | `Fish::__construct` reads `$this->meta` before `parent::__construct` (pre-existing bug: name always "Raw Fish") | Worked only because the property was untyped | Preserved faithfully via `protected ?int $meta = null;` (nullable property absorbs the pre-init read — identical branch outcomes) |

## Behavior parity

Dedicated harness sweeping **every registered item × 11 metas** (incl. `null`/`-1`/`32767`) plus potions, dyes, enchantments, NBT round-trips, `fromString`, fuel times, equality — compared byte-for-byte against the module-3 baseline:

- **3,694/3,694 lines identical**, except the 4 intentional fixes above.
- All 160 item classes verified to load (incl. `FishingRod`, which is *not* registered in `Item::$list` and would have been missed by a registration-based sweep).

## Compatibility concerns (documented, no action required)

- **`setDamage(?int)` widening** — plugin subclasses declaring `setDamage(int $meta)` (like in-repo `ItemBlock`) will now fatal at class-load. This is the deliberate price of fixing a real crash; keep plugin-side overrides `?int` or untyped.
- **`Tool::__construct(int $id, ...)`** narrows `$id` (legal via constructor exemption); strict-mode plugins passing non-int ids would TypeError.
- **`EnchantmentEntry::__construct(array, int, string)`** dropped the `(int) $cost` cast; numeric-string cost from strict-mode callers now TypeErrors.
- **`Tool::getMaxDurability() ?? false`** — exotic plugin tool ids (not in the durability table) previously yielded `null` + a warning; now `false` (the docblock's documented `int|bool`). Identical in every numeric context (`false` and `null` both coerce to 0); warning removed.
- **`EnchantmentList::getSlot(): EnchantmentEntry`** — matches its docblock; unset slots fatal at the caller in both old and new code (pre-existing).

## Validation results

| Check | Result |
|---|---|
| Lint (160 files) | ✅ clean |
| Behavior-parity harness (~3,700 assertions) | ✅ identical (4 documented fixes only) |
| Load-all-classes check (160 classes, incl. unregistered) | ✅ all load |
| Boot test | ✅ `Done (0.252s)!`, TPS 20, 0 errors |
| Module-2 regressions (wire fidelity + fuzz) | ✅ identical / 0 fatals |
| Module-3 regression (spl smoke) | ✅ 30/30 |
| Code review | ✅ findings resolved (load-all gap closed, compat notes documented) |

## Remaining technical debt

- `Fish::__construct` still reads `$this->meta` before init (faithful to upstream; flag for a future behavior-fix PR).
- `Potion::getEffectsById` on no-effect potions (id 0–4) still hits `count(false)`/`false[0]` — pre-existing upstream garbage path, untouched.
- Block-constructor null-meta flow (`new $class(null)` inside `Item::get` for ids < 256) is module-5 scope — noted for the Blocks module.
