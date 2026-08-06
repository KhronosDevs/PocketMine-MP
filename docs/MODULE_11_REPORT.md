# Module 11 Report — Plugins (`src/pocketmine/plugin/`)

**Date:** 2026-08-05 · **Branch:** `php-update` · **Files:** 13 · **Diff:** +209/−207

## Scope

The complete plugin subsystem — 13 root files:

| Area | Files | Change |
|---|---|---|
| `Plugin.php` (interface) | 1 | `strict_types` + typed getters (`isEnabled:bool`, `isDisabled:bool`, `getDataFolder:string`, `getDescription`, `getResource(string)`, `saveResource(string,bool):bool`, `getResources:array`, `getConfig`, `saveConfig:void`, `saveDefaultConfig:void`, `reloadConfig:void`, `getServer`, `getName:string`, `getLogger`, `getPluginLoader`) |
| `PluginBase.php` | 1 | 10 typed private props; typed finals/helpers (`isEnabled/setEnabled/isDisabled/getDataFolder/getDescription/init/getLogger/isInitialized/getCommand(string):?PluginCommand/isPhar/getResource/saveResource/getResources/getConfig/saveConfig/saveDefaultConfig/reloadConfig/getServer/getName/getFullName/getFile/getPluginLoader`); `use PluginCommand` added |
| `PluginDescription.php` | 1 | 14 typed props (except `$version` = `int\|float\|string`); ctor `string\|array`; all getters typed except `getVersion()` |
| `PluginLogger.php` | 1 | Typed props/methods, implements `\AttachableLogger` (`log(int\|string,string):void`) |
| `RegisteredListener.php` | 1 | Typed ctor `(Listener, EventExecutor, int, Plugin, bool, TimingsHandler)` + typed getters/callEvent; **`__destruct()` left untyped** (PHP forbids destructor return types) |
| `MethodEventExecutor.php` | 1 | `string $method`; `execute:void` / `getMethod:string` |
| `EventExecutor.php` | 1 | `strict_types` only — `execute()` deliberately untyped (plugin API) |
| `PluginLoader.php` (interface) | 1 | `strict_types` only — all methods deliberately untyped |
| `PluginLoadOrder.php` | 1 | `strict_types` only |
| `FolderPluginLoader.php` | 1 | `Server $server`; `loadPlugin:?Plugin`, `getPluginDescription:?PluginDescription`, **`getPluginFilters:string`** (regex!), `initPlugin`/`enablePlugin`/`disablePlugin` typed |
| `PharPluginLoader.php` | 1 | Same as Folder (uses `\Phar`) |
| `ScriptPluginLoader.php` | 1 | Same (header-parsing loader) |
| `PluginManager.php` | 1 | `Server $server` + `SimpleCommandMap $commandMap` typed; full typed method surface; `static ?TimingsHandler $pluginParentTimer = null`; `static bool $useTimings = false` |

## Refactoring performed

- **`declare(strict_types=1)`** on all 13 files.
- **Plugin-facing lifecycle methods deliberately untyped**: `Plugin::onLoad/onEnable/onDisable`, `CommandExecutor::onCommand`, `EventExecutor::execute`, and every `PluginLoader` interface method — plugins override these, typing them would fatal third-party plugins. Verified with a MockPlugin (untyped overrides) in load-all.
- **§6.2 invariance rule**: `protected` props in `PluginManager` (`$plugins`, `$permissions`, `$defaultPerms`, `$defaultPermsOp`, `$permSubs`, `$defSubs`, `$defSubsOp`, `$fileAssociations`) and `PluginDescription::$version` left **untyped** (version is `int|float|string` from YAML). `private` props typed.
- **Fixed one latent ORIG bug**: geniapi loop now `explode(".", strval($version))` — mirrors the API loop and avoids a TypeError when a plugin's `geniapi` is numeric YAML (e.g. `geniapi: 1.0`).
- **Removed unreachable dead code** (identical in ORIG and NEW): WeakRef cleanup blocks after an unconditional `return` in `getPermissionSubscriptions()` and `getDefaultPermSubscriptions()`.
- **`getPluginFilters()` typed `: string`, NOT `: array`** — the loaders return a regex string consumed by `new RegexIterator(...)`. (Initially typed `: array`; the parity harness caught the fatal `Return value must be of type array, string returned`; corrected to `string`.)
- **`__destruct()` untyped** in `RegisteredListener` — PHP 8.2 hard error: destructors cannot declare a return type (lint caught it).
- `PluginDescription` regex quirk preserved: `preg_replace("[^A-Za-z0-9 _.-]", ...)` (missing delimiters) silently no-ops in both trees — only `str_replace(" ", "_", ...)` actually sanitizes names. Kept byte-identical.

## Compatibility concerns

- None breaking. All signature diffs vs ORIG are **pure type additions**.
- `saveResource(string, bool): bool`, `getResource(string)` param typed — ORIG callers pass strings/bools; strict coercion hazards audited (none).
- `PluginManager::registerEvent(string, Listener, int, EventExecutor, Plugin, bool)` — ORIG call sites (core + booted probe) pass matching types.
- `removePermission(string|Permission): void` — union covers both documented call forms.
- `Plugin::getCommand(string): ?PluginCommand` — was `Command|PluginIdentifiableCommand` in docblock; every ORIG call passes a string.

## Validation results

| Check | Result |
|---|---|
| Lint (13 files) | ✅ clean |
| Load-all (variance vs untyped plugin overrides) | ✅ LOADED=13 FAIL=0, byte-identical on NEW + ORIG |
| **Logic harness** (PluginLoadOrder, PluginDescription rich/minimal/int-version/load/empty-name, PluginBase lifecycle+resources+config+logger, RegisteredListener+MethodEventExecutor dispatch/priorities, EventExecutor, PluginManager permission subscriptions+useTimings+statics, 3 loader filters/descriptions, ScriptPluginLoader header parsing) vs ORIG | ✅ **HARNESS-IDENTICAL** (only warning line-numbers differ) |
| **Booted probe** (folder-plugin load/enable, getPlugin, permissions from plugin.yml, add/removePermission, registerEvents + registerEvent dispatch, getCommand incl. aliases, onCommand) | ✅ **ALL-PASS** (12 checks), 0 CRITICAL |
| Boot | ✅ `Done (0.239s)` |
| Mod-4/5/6/7/8/9/10 regressions | ✅ all harnesses IDENTICAL |
| Code review | ✅ stray banner typo + stray banner block reverted; `getPluginFilters:string` corrected; dead WeakRef code removed only after parity proof |

## Remaining technical debt (for final audit)

- None introduced by this module. `PluginDescription::loadMap` sanitizer regex (missing delimiters) left as-is per parity-first policy.
- `ScriptPluginLoader::getPluginDescription` still emits PHP warnings for a missing-file script (ORIG behavior, harness-verified identical).

## How to revert

```bash
git revert <module-11-commit>   # after commit, or reset --hard before committing
```
