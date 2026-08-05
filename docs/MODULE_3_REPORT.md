# Module 3 Report — Data Structures (`src/spl/`)

**Commit:** `module: data structures (spl/) — PHP 8.2 modernization`
**Baseline:** `0d5b742` (Module 2). **Scope:** all 20 files in `src/spl/` (global namespace, bootstrap-critical): Logger chain (`Logger`, `LoggerAttachment`, `AttachableLogger`, `ThreadedLogger`, `ThreadedLoggerAttachment`, `AttachableThreadedLogger`, `LogLevel`), classloader (`ClassLoader`, `BaseClassLoader`), `SplFixedByteArray`, and 10 exception stubs. **+100/−57 lines.**

## What was done
- `declare(strict_types=1)` on all 20 files (leaf-first policy; these are required/autoloaded by the bootstrap before any other module).
- **`BaseClassLoader`**: typed props (`?ClassLoader $parent`, `ThreadSafeArray $lookup/$classes`), typed params/returns on all methods, explicit `return spl_autoload_register(...)` in `register()`. Logic preserved verbatim (synchronized prepend, `getAndRemoveLookupEntries`, `onClassLoaded` reflection check, 64/32-bit `findClass`).
- **`SplFixedByteArray`**: typed `bool $convert`, `int`/`bool`/`self` params, `string|array` union on `chunk()`, `: string` on `toString()/__toString()`. No internal callers (public plugin API kept behavior-identical).
- **Logger chain**: typed props `?ThreadedLoggerAttachment $attachment = null` and `: void`/`: array` returns on the concrete classes; `final call(int|string $level, string $message): void`.
- **Interfaces** (`Logger`, `LoggerAttachment`, `AttachableLogger`, `ClassLoader`): param types only (`string $message`, `int|string $level`, `?ClassLoader $parent`). **No return types on interfaces** — deliberate variance policy: untyped implementers (module-1 `MainLogger`, module-11 `PluginLogger`, external plugins) stay compatible; concrete classes carry the return types covariantly.
- Exception stubs: strict_types only; all `extends` parents preserved (incl. cross-spl `UndefinedConstantException extends InvalidStateException`).

## Bugs found / pitfalls navigated
- **pmmpthread ThreadSafe constraints** (verified empirically): no `array`-typed props or array *defaults* on ThreadSafe classes (`NonThreadSafeValueError`); null writes to ThreadSafe props are effectively ignored — the original `removeAttachment()`/`removeAttachments()` "no-op" quirks are **pre-existing platform behavior, proven byte-identical to the original** via side-by-side worktree tests (typed prop changes nothing).
- **Variance traps avoided**: adding `: void` to `Logger` would fatal untyped `PluginLogger` at load; narrowed params would fatal `MainLogger`. Interface return types kept off entirely for this reason.
- **PHP 8.4-prep**: implicit nullable `ClassLoader $parent = null` → `?ClassLoader $parent = null`.

## Validation results
| Check | Result |
|---|---|
| `php -l` all 20 files | ✅ CLEAN |
| spl smoke test (30 assertions: loader find/load/parent/missing-throws, SplFixedByteArray round-trips incl. convert/chunk, **MainLogger + PluginLogger interface compliance**, attachment chain, LogLevel, exception hierarchy) | ✅ 30/30 PASS |
| Module-2 regression: 74-case wire-fidelity harness | ✅ IDENTICAL |
| Module-2 regression: hostile-input fuzz | ✅ 0 fatals both modes |
| Boot test (`PocketMine.php --no-wizard`) | ✅ `Done (0.234s)!` TPS 20, 0 errors |
| Code review | ✅ 4 findings fixed (interface de-typing, PluginLogger load assertion, docblock trim, `?array $trace`) |

## Remaining tech debt
- `removeAttachment`/`removeAttachments` on ThreadSafe attachments are effectively no-ops due to the pmmpthread identity/null-write quirks — **pre-existing** (proven identical in original), documented here for future maintainers.
- `ThreadedLoggerAttachment::log()` stays abstract/untyped-impl (plugin contract) — by design.
- `BaseClassLoader` keeps its original 4-space indentation style (matches the original file; pocketmine files use tabs).
