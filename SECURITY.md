# Khronos Security

This document describes the network security posture of Khronos, the
hardening that has already shipped, the configuration knobs that control
exposure, and how to run the hostile-input test suite in
[`security/`](security/).

Khronos speaks the legacy MCPE 0.15.x protocol (protocol 84) over RakNet.
That protocol is **client-authoritative and unauthenticated by design**:
there is no session token, no encryption, and movement is simulated on the
client. Some exposure is therefore inherent to the protocol and cannot be
eliminated by server software — this document is explicit about what is
fixed, what is mitigated, and what remains.

---

## Threat model summary

| Attack class | Status | Notes |
|---|---|---|
| Crash / hang from a single crafted packet | **FIXED** | NBT depth cap, hostile-list termination, MTU floor |
| UDP reflection / amplification (unauthenticated) | **FIXED** | Offline magic verified before any response |
| Authenticated bandwidth amplification | **MITIGATED** | NACK re-acceptance capped; outbound drain hard-capped |
| Unbounded memory growth (queues, maps, skins) | **FIXED** | All known unbounded structures capped or pruned |
| Ban / whitelist / op bypass | **CONFIG** | Enforced only when `online-mode=true` |
| Chat / log injection via control characters | **FIXED** | Usernames clamped to 16 printable chars |
| NaN / Inf position pollution | **FIXED** | Non-finite coordinates rejected |
| Noclip / flight with a modified client | **INHERENT** | Client-authoritative movement; heuristics only |
| Botnet floods (many source IPs) | **INHERENT** | Per-IP limits do not aggregate |
| Bandwidth-exhaustion DDoS (pipe flooding) | **INHERENT** | Not solvable in server software |

---

## Shipped hardening (2026-09 security pass)

### Critical

- **NBT decoding** (`src/pocketmine/nbt/`)
  - Container nesting capped at 32 levels. Deeply nested hostile compounds
    (reachable from a sign edit) previously recursed until the PHP C stack
    overflowed, killing the whole process.
  - `ListTag::read()` stops on unsupported element types. Previously a
    declared count of 2^31−1 with an unknown type spun ~2 billion no-op
    iterations on the main thread — a remote, silent, repeatable hang.
  - `readCompressed()` caps decompression at 64 MB.
- **Online-mode identity verification** — with `online-mode=true`, logins
  whose Mojang-signed JWT chain does not verify are rejected. Username,
  UUID and skin are otherwise fully attacker-controlled, which defeats
  bans, whitelists and ops.

### High

- **Offline magic verification** (`src/raklib/server/SessionManager.php`) —
  unconnected packets (pings, open-connection requests) must carry the
  16-byte RakNet offline magic before anything is processed or answered.
  Previously a 1-byte spoofed datagram created a session and elicited a
  full response — a reflection/amplification vector.
- **NACK amplification cap** (`src/raklib/server/Session.php`) — at most 64
  datagrams are re-queued per NACK. Previously one hostile packet could
  keep ~2048 datagrams in permanent retransmit (~2 MB/s outbound per
  attacker session) and eventually wipe the session's own pending traffic.
- **Cross-thread queue caps** (`src/raklib/server/RakLibServer.php`) — both
  main-thread <-> wire-thread queues are capped at 1024 frames and drop on
  overflow instead of growing without bound.

### Medium

- Truncated DATA packets whose seqNumber decodes to `null` are dropped
  (they previously slipped past every reliability-window comparison, were
  ACKed, and were processed without dedup state advancing).
- Login skins are capped at 64×64×4 + head layer (~20 KB). Previously up
  to ~2 MB was stored per session and rebroadcast to every player on
  every join.
- Usernames are clamped to 16 printable characters (control/escape
  sequences cannot be smuggled into the player list or chat echoes).
- Non-finite or absurd move coordinates (> ±1,000,000) are rejected.
  NaN previously passed every anti-cheat comparison.
- Login-attempt windows are pruned (one entry per host previously lived
  forever — a slow leak under a rotating-source flood).

### Low

- The client-declared RakNet MTU is floored at 400 (an `mtuSize` near 0
  previously crashed the wire-thread send path on the next outbound send).

---

## Configuration knobs

### `server.properties`

| Key | Default | Effect |
|---|---|---|
| `online-mode` | `false` | When `true`, every login must carry a valid Mojang signature chain. **Enable this if your server faces the public internet.** When `false` (offline mode), identity is client-claimed and bans/whitelist/ops are advisory only. |
| `white-list` | `false` | When `true`, only whitelisted names/UUIDs can join. |
| `allow-flight` | `false` | When `true`, the movement validator exempts ascent checks for ALL gamemodes (trusts flying clients). Keep `false` on public servers. |
| `max-players` | (config) | Hard cap on concurrent sessions. |

### `khronos.json` → `anti-cheat`

```jsonc
{
    "anti-cheat": {
        "enabled": true,                  // false = trust clients entirely (LAN only)
        "movement": {
            "max-horizontal-per-tick": 1.2,
            "max-ascent-per-tick": 0.8,
            "max-total-per-tick": 2.0,
            "max-move-violations": 5,     // kicks at N violations
            "violation-window-ticks": 100,
            "rubber-band": true,          // snap back on violation
            "kick-on-violations": true
        },
        "chat": {
            "min-interval-seconds": 0.4,  // spam throttle
            "max-length": 256
        },
        "command": { "min-interval-seconds": 0.1 },
        "login": {
            "attempts-per-minute": 30,    // per-IP login throttle
            "max-sessions-per-ip": 25     // NAT-burst cap
        },
        "max-datagram-size": 1500,        // reject oversized UDP early
        "packet-limit": 350               // per-IP packets per RakLib tick
    }
}
```

Notes:

- `packet-limit` is the per-IP-per-tick wire budget; exceeding it blocks
  the source IP. Raising it weakens flood protection.
- `max-datagram-size` rejects oversized datagrams before any processing
  and counts them against the per-IP limit.
- `movement.enabled=false` (or `anti-cheat.enabled=false`) disables the
  movement validator — fine for LAN/creative, unsafe in public.

### Unbanning an IP

`/pardon-ip <ip>` (alias `/unban-ip`, console + ops) clears **both** layers:
the player-list IP ban and the wire-layer packet-flood block held inside the
RakLib thread. The wire block trips automatically when a source exceeds
`packet-limit` datagrams per tick, so a NAT'd network that shares one public
IP can occasionally hit it — unbanning without the alias-aware command would
leave the address silently blocked at the wire layer.

---

## The `security/` test suite

Run everything:

```bash
bin/php7/bin/php security/run_all.php
```

Or one script at a time:

```bash
bin/php7/bin/php security/hostile_raklib_test.php    # offline-layer floods & malformed datagrams
bin/php7/bin/php security/hostile_nbt_test.php       # NBT bombs (depth, hostile lists, zlib)
bin/php7/bin/php security/hostile_game_test.php      # game-layer DoS (sign NBT, batch, moves)
bin/php7/bin/php security/flood_test.php             # packet-limit / block behavior (live server)
```

Every script is self-contained, boots its own throwaway state, and prints
`PASS`/`FAIL` per scenario with a non-zero exit code on failure. They are
**not** part of `tests/run.php` — the live-server ones boot a real RakLib
thread and bind random high ports, so they stay out of the unit-test loop.

The scripts only ever talk to `127.0.0.1` on ports they chose themselves;
they contain no external endpoints and no personal data.

---

## Reporting

Found a vulnerability? Please open a GitHub issue with the `security`
label, including: the affected file/line, a minimal reproduction (a packet
capture or a script in the style of `security/`), and the observed vs
expected behavior. For exploits that affect running servers, prefer a
private report to a public issue.
