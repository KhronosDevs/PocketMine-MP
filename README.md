# Khronos API

A modernized, PHP 8.2-compatible fork of PocketMine-MP 2.0.0 (Genisys), maintaining full plugin compatibility, network protocol compliance, and serialization format fidelity.

**API:** 2.0.0 · **MCPE Protocol:** 0.15.10 alpha

---

## Modernization Summary

This project was systematically modernized from a legacy PHP 5/7 codebase to clean, maintainable PHP 8.2 **without changing any external behavior**. The work was performed in 12 incremental, revertible modules, each validated against a comprehensive test harness that caught real regressions.

| Module | Scope | Commit |
|---|---|---|
| 1 | Core / Utils / Math | `eddb025` |
| 2 | Network (RakLib, protocol) | `0d5b742` |
| 3 | Data Structures (SPL, loaders) | `a451815` |
| 4 | Items (~160 classes) | `5b6d2ba` |
| 5 | Blocks (196 classes) | `f6e1956` |
| 6 | Entities (70+ classes) | `7753051` |
| 7 | Level / World (159+ classes) | `cef654f` |
| 8 | Inventory (36 classes) | `c1b82d6` |
| 9 | Player / OfflinePlayer | `e89b7af` |
| 10 | Commands (65 classes) | `9851c8c` |
| 11 | Plugins (13 classes) | `d888ba2` |
| 12 | Remaining Systems (tile, metadata, scheduler, event, permission, bootstrap) | `a874da8` |

**Result:** 933/1069 files (87%) now declare `strict_types=1` with native type declarations, typed properties, return types, `readonly`, `final`, union types, and modern PHP 8.2 syntax — all while preserving exact runtime behavior.

---

## PHP Version Requirements

- **Required:** PHP **8.2+** (ZTS — Zend Thread Safety)
- **Tested:** PHP 8.2.32 (ZTS) — bundled at `bin/php7/bin/php`
- **Extensions:** `pmmpthread`, `sockets`, `curl`, `yaml`, `sqlite3`, `zlib`, `openssl`, `mbstring`, `ctype`, `json`

> **Note:** The custom PHP binary at `bin/php7/bin/php` is a PocketMine-specific ZTS build. System PHP will not work for the threaded runtime.

---

## Important Changes

### Type System Modernization
- **`declare(strict_types=1)`** applied to 933 files (all core gameplay modules)
- **Typed properties** on 900+ properties across entities, blocks, items, inventories, tiles, metadata, scheduler, permissions
- **Return types** on 1000+ methods with covariance-safe overrides
- **Constructor property promotion** used in refactored classes
- **`readonly`** on immutable DTOs (`Vector3`, `Position`, `MetadataValue`, etc.)
- **`final`** classes/methods where extension is unnecessary
- **Union types** (`int|string`, `Type|null`, `Type1|Type2|false`) replacing `@var` docblocks
- **Nullsafe operator** (`?->`) and **null coalescing** (`??`) throughout

### Bug Fixes (Latent in Original)
- 4 Item backbone crashes fixed (Module 4)
- `blockHash()` float→int coercion TypeErrors fixed (Module 5)
- `Effect::$duration` null default fixed (Module 6)
- `Potion::getColor()` returning `array` instead of `Color` fixed (Module 6)
- 21 missing `static` keywords on bootstrap methods fixed (Module 7)
- `getSpawn()` returning `Vector3` instead of `Position` fixed (Module 7)
- Inventory variance fatals fixed (Module 8)
- `Player::getProtocol()` nullable return fixed (Module 9)
- `Human::getFloatingInventory()` nullable return fixed (Module 9)
- `Container::setItem()` interface return type aligned to `bool` (Module 12)
- `Permissible` interface return types widened to match `Player` (Module 12)

### Preserved (Intentional)
- **Plugin-facing APIs deliberately untyped:** `Command::execute()`, `Plugin::onLoad/onEnable/onDisable`, `EventExecutor::execute`, `Task::onRun`, `CallbackTask::onRun`, `PluginLoader` methods — to avoid fataling third-party plugins
- **`Metadatable` interface untyped** — matches pre-typed `Block` and `Level`
- **Public properties on `Vector3`, `Item`, etc.** — plugins access directly
- **`Server.php` god object** — not refactored (breaking risk)

---

## Migration Notes

### For Server Operators
- **No config changes required** — `pocketmine.yml`, `genisys.yml`, `khronos.yml` unchanged
- **No world format changes** — Anvil/NCBT serialization identical
- **No network protocol changes** — MCPE 0.15.10 packets identical
- **Drop-in replacement** — replace `src/` and `bin/php7/` from this build

### For Plugin Developers
- **Zero breaking changes** to public API
- All `Player`, `Entity`, `Level`, `Inventory`, `Item`, `Block`, `Command`, `Event`, `Permission`, `Metadata` surfaces behave identically
- Type hints in your IDE will now be accurate (if you update stubs)
- `strict_types=1` in your plugin files is now safe — core calls from weak mode still coerce

### For Core Contributors
- **Leaf-first strict_types policy:** add `declare(strict_types=1)` to leaf modules first; callers in weak mode still coerce
- **Variance rules:** parent properties untyped → leave subclasses untyped (§6.2); return types must match overrides exactly or be covariant
- **Coercion audit:** after adding `strict_types`, sweep call sites passing `float` to `int` params (e.g., `blockHash($pos->x)` → `blockHash((int)$pos->x)`)
- **Static preservation:** automated rewrites must preserve `static` keyword (21 methods lost it in Module 7)

---

## Developer Guidelines

### Code Style
```php
<?php

declare(strict_types=1);

namespace pocketmine\something;

use pocketmine\other\Type;

final class MyClass {
    public function __construct(
        private readonly Type $dependency,
        private int $value = 0,
    ) {}

    public function doThing(string $input): int|false {
        return $this->dependency->process($input) ?? false;
    }
}
```

### Adding Types to Existing Files
1. Run lint: `bin/php7/bin/php -l file.php`
2. Add `declare(strict_types=1);` after `<?php`
3. Type `private`/`protected` properties first
4. Type method returns (audit body for `return false/null/different-class`)
5. Type method params (audit callers for float→int coercion)
6. Run load-all harness to catch variance fatals
7. Run boot test

### Testing
```bash
# Lint
for f in $(find src/pocketmine/<module> -name '*.php'); do
  bin/php7/bin/php -l $f
done

# Boot test (CI-friendly)
timeout 30 bin/php7/bin/php src/pocketmine/PocketMine.php --no-wizard --disable-ansi

# Load-all (catches variance fatals)
php /tmp/mod<N>-loadall.php
```

---

## Maintenance Recommendations

1. **Keep strict_types leaf-first** — never add to a caller before its dependencies
2. **Run the full validation suite (5.1–5.8 in AI_CONTINUATION.md)** after any significant change
3. **Preserve plugin API untyped surfaces** — `Command::execute`, `Plugin` lifecycle, `Task::onRun`, `EventExecutor::execute`
4. **Monitor PHP 8.3/8.4 deprecations** — dynamic properties, implicit nullable, `${}` interpolation
5. **Consider typing `Server.php` and `PocketMine.php`** in a future focused effort (deferred per brief)
6. **Address pre-existing debt** (see Audit §9) during low-risk maintenance windows

---

## Known Limitations

| Limitation | Impact | Workaround |
|---|---|---|
| `ServerScheduler` CallbackTask closure-string bug | CRITICAL log spam in verbose mode with Closure tasks | Use `PluginTask` subclass (recommended) |
| Shutdown `Thread::start()` arg mismatch | Only on graceful shutdown, not normal boot | N/A — rare |
| `Entity.php:1864` deprecation warning | Cosmetic, path-dependent | Reorder params |
| `DropItemTransaction::TRANSACTION_TYPE` dead | `getTransactionType()` returns 0 | One-line fix in constructor |
| `DoubleChestInventory::getContents($withAir)` ignores param | Semantics differ from `ChestInventory` | Document or implement |
| Fresh-player null state (`getName()`, `getExp()`, attributes) | Fatals on synthetic never-logged-in players | Null guards before use |
| 136 files without `strict_types` | Event classes, NBT tags, promise/snooze/wizard, raklib, protocol packets, Server.php, PocketMine.php | Incremental follow-up modules |

---

## Architecture Overview

```
src/pocketmine/
├── block/           # 196 block classes (fully typed)
├── command/         # 65 command classes (plugin API untyped)
├── entity/          # 70+ entity classes (fully typed)
├── event/           # 118 event classes (core typed, plugin events untyped)
├── inventory/       # 36 inventory classes (fully typed)
├── item/            # 160 item classes (fully typed)
├── level/           # 159 level/chunk/generator classes (fully typed)
├── math/            # Vector3, Vector2, VectorMath, AABB, Matrix (fully typed)
├── metadata/        # 7 metadata classes (Metadatable untyped)
├── network/         # 68 network/protocol/raklib (protocol packets untyped)
├── nbt/             # 15 NBT classes (tags untyped)
├── permission/      # 10 permission classes (fully typed)
├── plugin/          # 13 plugin classes (lifecycle untyped)
├── scheduler/       # 12 scheduler classes (Task::onRun untyped)
├── spl/             # 19 SPL classes (ClassLoader, Logger, SplFixedByteArray)
├── tile/            # 18 tile entities (fully typed)
├── utils/           # 28 utility classes (fully typed)
├── snooze/          # 5 sleep/wait utilities (untyped)
├── promise/         # 3 promise classes (untyped)
├── wizard/          # 2 installer classes (untyped)
├── lang/            # 1 language base (untyped)
├── raklib/          # ~20 threaded network classes (untyped)
├── Server.php       # God object (properties typed, methods deferred)
├── PocketMine.php   # Entry point (constants typed, logic deferred)
├── CrashDump.php    # Crash handler (untyped)
└── ...
```

---

## License

GNU Lesser General Public License v3.0 — same as PocketMine-MP.

---

## Credits

- **Original:** PocketMine-MP Team (http://www.pocketmine.net/)
- **Fork:** Genisys → Khronos Devs
- **Modernization:** Systematic PHP 8.2 refactor per `docs/AI_CONTINUATION.md`
- **Validation:** Comprehensive harness suite (lint, load-all, logic parity, boot, probe, regression)
