# Module 9 Report — Player (`src/pocketmine/Player.php` + related)

**Date:** 2026-08-05 · **Branch:** `php-update` · **Files:** 5 · **Diff:** +116/−108

## Scope

The Player subsystem — the highest-coupling class in the project (4,331 lines, 178 methods):

| File | Lines | Change |
|---|---|---|
| `src/pocketmine/Player.php` | 4,331 | `strict_types` + ~60 return types + `?int` protocol fix |
| `src/pocketmine/OfflinePlayer.php` | 104 | `strict_types` + return types |
| `src/pocketmine/IPlayer.php` | 53 | `strict_types` (interface deliberately untyped) |
| `src/pocketmine/Achievement.php` | 90 | `strict_types` + types |
| `src/pocketmine/entity/Human.php` | 460 | `getFloatingInventory(): ?FloatingInventory` fix |

## Refactoring performed

- **`declare(strict_types=1)`** added to all 4 module files (Player, OfflinePlayer, IPlayer, Achievement).
- **~60 return types** added to `Player.php`: `isOp:bool`, `getAddress:string`, `getPort:int`, `getProtocol:?int`, `getLeaveMessage:TranslationContainer`, `setDisplayName:void`, `setRemoveFormat:bool`, `isFishing:bool`, `getFishingHook:?FishingHook`, `hasPlayedBefore:bool`, `getFirstPlayed` (untyped — tag value can be null), `awardAchievement:bool`, `removeAchievement:void`, `isMoving:bool`, `isConnected:bool`, `getLoaderId:?int`, `isLoaderActive:bool`, `__debugInfo:array`, `getPlayer:Player`, `isOnline:bool`, `getNextPosition:?Position`, `getInAirTicks:int`, `attack:?bool`, `teleportImmediate:bool`, `addEffect:bool`, `switchLevel:void`, `save:void`, `dropItem:?Item`, `removeWindow:void`, `canInteract:bool`, etc.
- **Variance handled** against the mod-6 typed `Entity`/`Human`/`Living` parents and the `CommandSender`/`ChunkLoader`/`IPlayer`/`Damageable`/`ServerOperator`/`Permissible` interfaces — all signatures verified compatible, load-all passes with zero variance fatals.
- **Methods that can legitimately return `null` left untyped or made nullable** (`getDisplayName`, `getFirstPlayed` — pre-login state), matching ORIG behavior exactly.

## Bugs discovered & fixed

1. **`Player::getProtocol(): int` → `?int`** — *real regression caught by the booted probe.* `$this->protocol` is `null` until login; a strict-mode `int` return threw a `TypeError` on a fresh player. Widened to `?int` (ORIG returned `null` under weak mode).
2. **`Human::getFloatingInventory(): FloatingInventory` → `?FloatingInventory`** — *latent bug from mod 6/8 typing.* `Human::close()` explicitly guards `if($this->getFloatingInventory() instanceof FloatingInventory)` with an else branch for null, but mod 6 had typed the getter non-nullable — any fresh-player close would `TypeError`. Widened to `?FloatingInventory` (behavior-preserving).

## Compatibility concerns

- None breaking. All typed returns match ORIG runtime values (verified: `getLeaveMessage` returns `TranslationContainer` never null; `getAddress`/`getPort` were **already typed in HEAD** and initialized in the ctor).
- Fresh-player paths that touch `getName(): string` (null username) and `getExp()` (null `attributeMap`) are **pre-existing mod-6/8 landmines** — recorded for the final audit.

## Validation results

| Check | Result |
|---|---|
| Lint (5 files) | ✅ clean |
| Load-all vs typed parents/interfaces | ✅ zero variance fatals |
| **Booted probe** (live server, synthetic Player: 30+ runtime checks) | ✅ `[PLAYERPROBE] ALL-PASS`, 0 CRITICAL, 0 strays |
| Boot | ✅ `Done (0.246s)` |
| Mod-4/5/6/7/8 harnesses | ✅ all pass with expected outputs |
| Code review (deepseek-flash) | ✅ all findings verified; null-audit clean; probe plugin removed |

## Remaining technical debt (for final audit)

- `getName(): string` fatals on never-logged-in players (mod-6 typing; real flow always sets username at login).
- `getExp()`/attributeMap paths null until login init.
- Synthetic-player probe had to skip `setGamemode`, `isOp`, `hasPermission`, `canInteract` — all pre-existing fresh-player behaviors, identical in ORIG.

## How to revert

```bash
git revert <module-9-commit>   # after commit, or reset --hard before committing
```
