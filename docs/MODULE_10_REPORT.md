# Module 10 Report — Commands (`src/pocketmine/command/`)

**Date:** 2026-08-05 · **Branch:** `php-update` · **Files:** 65 · **Diff:** +261/−132

## Scope

The complete command subsystem — 11 root files + 54 default leaf commands:

| Area | Files | Change |
|---|---|---|
| `Command.php` (313 ln) | 1 | Backbone: typed props + ctor + ~20 typed methods |
| `VanillaCommand.php` | 1 | Typed ctor + `getInteger:int` / `getDouble:float` / `getRelativeDouble:float` |
| `PluginCommand.php` | 1 | Typed ctor + `getExecutor:?CommandExecutor` / `setExecutor:void` / `getPlugin:Plugin` |
| `ConsoleCommandSender.php` | 1 | `PermissibleBase $perm` + 12 typed methods |
| `RemoteConsoleCommandSender.php` | 1 | Typed props + `sendMessage:void` / `getMessage:string` |
| `FormattedCommandAlias.php` | 1 | Typed ctor + `buildCommand:string` |
| `SimpleCommandMap.php` | 1 | Typed props + 11 typed methods |
| `CommandReader.php` | 1 | Typed props + `getLine:?string` / `quit:void` / `getThreadName:string` |
| Leaf commands (`defaults/`) | 54 | `strict_types` + `__construct(string $name)` |
| Interfaces (CommandSender, CommandExecutor, CommandMap, PluginIdentifiableCommand) | 4 | `strict_types` only — **methods deliberately untyped** (plugin API) |

## Refactoring performed

- **`declare(strict_types=1)`** on all 65 files.
- **`Command` backbone fully typed**: 11 properties (`string $name/$nextLabel/$label`, `array $aliases/$activeAliases`, `?CommandMap $commandMap`, `?string $permission/$permissionMessage`, `TimingsHandler $timings`), ctor `(string $name, string $description = "", ?string $usageMessage = null, array $aliases = [])`, and methods (`getPermission:?string`, `testPermission:bool`, `setLabel:string→bool`, `register/unregister:bool`, `broadcastCommandMessage:void` etc.).
- **`execute()` deliberately left untyped on ALL commands** — it's the plugin API entry (`@return mixed` in ORIG). Audited every leaf: all bodies return only bools, but typing would force plugin overrides to match → fatal risk. Leaving untyped = plugin-compatible. This is the #1 deliberate decision.
- **Interfaces deliberately left untyped** (project convention since mod 8) — typed returns on implementations only.
- **§6.2 invariance rule applied**: `protected` properties (`Command::$description/$usageMessage`, `SimpleCommandMap::$knownCommands/$commandConfig`) left **untyped** — typing them would fatal third-party subclasses that redeclare them (the exact mod-6 Entity lesson). `private` properties typed.

## Compatibility concerns

- None breaking. All signature diffs vs ORIG are **pure type additions** (verified by automated signature comparison — every `<`/`>` line adds a type, none removes/renames).
- `execute()` untyped everywhere protects plugin `execute` overrides (incl. `PluginCommand` subclasses).
- Interface params left untyped (`registerAll($fallbackPrefix, …)`, `dispatch($commandLine)` etc.) — contravariance-safe.

## Validation results

| Check | Result |
|---|---|
| Lint (65 files) | ✅ clean |
| Load-all (variance vs typed Player/Entity + interfaces) | ✅ LOADED=65 FAIL=0 |
| Signature diff vs ORIG worktree | ✅ only type additions |
| **Logic harness** (Command backbone, VanillaCommand helpers, leaf ctors, senders) vs ORIG | ✅ **HARNESS-IDENTICAL** |
| **Booted probe** (live dispatch: /version, custom VanillaCommand, FormattedCommandAlias, Console/Remote senders, permission attach) | ✅ **ALL-PASS** (28 checks), 0 CRITICAL (only known shutdown debt) |
| Boot | ✅ `Done (0.191s)` |
| Mod-4/5/6/7/8 regressions | ✅ all pass |
| Strict-coercion sweep (float→int at call sites) | ✅ zero hazards |
| Code review | ✅ protected-prop invariance fixed; `execute`-untyped decision confirmed correct |

## Remaining technical debt (for final audit)

- None introduced by this module. Pre-existing shutdown `Thread::start()` debt (§7 item 2) still the only boot-path CRITICAL.

## How to revert

```bash
git revert <module-10-commit>   # after commit, or reset --hard before committing
```
