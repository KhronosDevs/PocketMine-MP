# Known Plugin API Gaps

**Last updated:** 2026-08-23

These are real gaps found by plugin developers (particularly during Auth plugin
development). They are **not bugs** — they are missing features or architectural
limitations that affect specific plugin use cases.

---

## Gap 1: `InventoryOpenEvent` is not cancellable

**Status:** ✅ FIXED (2026-08-23)

**Severity:** Medium
**Affects:** Auth/permission plugins that need to block container access for
unauthenticated or unauthorized players.

### What happens

When a player right-clicks a chest, furnace, dispenser, etc., the server sends
`ContainerOpenPacket` to the client and emits `InventoryOpenEvent`. However:

- `InventoryOpenEvent` extends `Event`, **not** `CancellableEvent`
- The `emitContainerOpen()` method in `NetworkSessionService` never checks
  `$event->isCancelled()`
- The `ContainerOpenPacket` is sent regardless of any plugin handler

**Result:** The chest lid visually opens for the client even though transactions
are blocked. The player sees the chest animation, hears the sound, but cannot
move items. This is confusing UX for auth/permission plugins.

### Workaround

Use `DataPacketSendEvent` (cancellable) to intercept `ContainerOpenPacket` (id
0x2e) and suppress it. This is a packet-level hack that works but:

- Requires matching the packet ID manually
- Is fragile if the packet ID changes
- Does not prevent the interaction animation on the server side
- Does not prevent the right-click event from being processed

### What's needed

Make `InventoryOpenEvent` extend `CancellableEvent` and have
`NetworkSessionService::emitContainerOpen()` check cancellation before sending
the packet. When cancelled:

- Do not send `ContainerOpenPacket`
- Do not open the container window
- The right-click should be treated as a no-op (or fire a secondary event)

---

## Gap 2: No per-viewer visibility filter in entity broadcasting

**Status:** ✅ FIXED (2026-08-23)

**Severity:** Medium-High
**Affects:** Auth plugins, spectator modes, stealth/invisibility plugins,
region-locked entity hiding.

### What happens

`broadcastEntityStates()` sends entity metadata, movement, and action packets
to **every player within view range**. There is no callback or filter to
suppress packets for specific viewer↔entity pairs.

For auth plugins: when player A is unauthenticated and player B is nearby,
B's entity data (position, metadata, actions) is still sent to A's client.
Conversely, A's entity data is sent to B.

### The 2-second leak

When a player exits another player's view range, the entity is removed from
the `SpatialIndex` and a despawn packet is sent. But when the player
re-enters range, the entity is re-spawned immediately. If an auth plugin
was hiding the entity by suppressing packets, the entity briefly appears
(~2 seconds) during the re-enter transition before the plugin can re-apply
the filter.

This happens because:
1. Entity exits range → despawned (plugin had been suppressing anyway)
2. Entity re-enters range → spawned on client
3. Plugin's tick handler detects the new entity → starts suppressing
4. Gap between steps 2 and 3 = 1 tick = ~50ms minimum, but the plugin's
   detection may be slower (especially if it's checking auth state)

### What's needed

A `EntityVisibilityFilter` callback (or `broadcastEntityStates` accepting a
`?callable $filter` parameter) that receives `($viewerEntity, $targetEntity)`
and returns `bool` — whether to send the packet for this viewer/target pair.
This would let auth plugins, spectator modes, and stealth systems work
cleanly without packet-level hacks.

```php
// Proposed API
$networkPort->broadcastEntityStates(
    $world,
    $entities,
    function (EntityRef $viewer, EntityRef $target): bool {
        // Return true to send, false to suppress
        return $this->isPlayerAuthenticated($viewer);
    }
);
```

---

## Gap 3: Shared worker pool has no thread pinning

**Severity:** Low
**Affects:** Plugins that use `PluginTask` for database-heavy work (SQLite).

### What happens

The `ThreadingPort` worker pool (`PmmpThreadPool`) shares all tasks across
the same pool of worker threads. When a plugin submits a `PluginTask` that
does SQLite operations, and another plugin or the kernel submits a different
task, they may run on different threads.

SQLite enforces serialization at the queue level — the pool ensures only one
SQLite-touching task runs at a time (correct behavior). But this means:

- SQLite operations cannot benefit from persistent connections (each thread
  opens its own connection)
- Under load, SQLite tasks may wait in queue behind non-SQLite tasks
- No way to guarantee a task always runs on the same thread (for connection
  caching)

### What's needed

Thread pinning or a dedicated SQLite worker thread. This is a nice-to-have
for performance, not a correctness issue. The current serialization is
correct but slightly slower than a pinned persistent-connection worker
would be.

---

## Gap 4: PHP build lacks Argon2id

**Severity:** Low (environment, not API)
**Affects:** Password hashing in auth plugins.

### What happens

The bundled PHP binary at `bin/php7/bin/php` does not have the `argon2id`
extension. Auth plugins that want to use `password_hash()` with
`PASSWORD_ARGON2ID` will get an error or fallback to bcrypt.

### Workaround

- Use `PASSWORD_BCRYPT` (available everywhere, slightly less secure)
- Use `PASSWORD_ARGON2I` if available (check with `defined('PASSWORD_ARGON2I')`)
- Rebuild PHP with `--with-password-argon2` enabled

### What's needed

Either:
1. Document that Argon2id is not available in the bundled binary and recommend
   bcrypt for auth plugins, or
2. Rebuild the PHP binary with Argon2id support (`--with-password-argon2`)

The auto-detection upgrade mentioned by the user is the right approach —
once the build includes Argon2id, auth plugins should automatically use it.

---

## Summary

| # | Gap | Severity | Workaround exists? | Fix effort |
|---|-----|----------|-------------------|------------|
| 1 | InventoryOpenEvent not cancellable | ✅ FIXED | — | Done |
| 2 | No per-viewer entity visibility filter | ✅ FIXED | — | Done — setEntityVisibilityFilter() on Plugin/KernelAccessor |
| 3 | No worker thread pinning | Low | Queue-level serialization (correct but slower) | Medium — needs pool redesign |
| 4 | No Argon2id in bundled PHP | Low | Use bcrypt | Rebuild PHP binary |

**Recommended priority:** Fix Gap 1 (trivial) and Gap 2 (high impact for auth
plugins) before Gap 3 and 4.
