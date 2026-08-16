# 👑 Royal Spin

A real-time multiplayer slot-and-dice roguelite for 2–4 players. Roll a die to earn
spins, spin a 3-reel machine, buy upgrades that reshape your own probabilities, and
race everyone else to a target score.

Built with **PHP 8.2+, vanilla JS and a PHP WebSocket service** — no Composer
packages, no build step, no bundler.

**Deploying it is: upload the folder, open the URL.** No database server, no
config file, no commands. It also runs against MySQL and a realtime service if
you have them.

> All coins are virtual and exist only inside a match. There is no deposit, no
> payout, no purchase and no real-money mechanic of any kind.

---

## Contents

1. [How the game plays](#how-the-game-plays)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Troubleshooting](#troubleshooting)
5. [Realtime: two transports](#realtime-two-transports)
6. [Playing your first match](#playing-your-first-match)
7. [Balancing](#balancing)
8. [Tests](#tests)
9. [Architecture](#architecture)
10. [Production deployment](#production-deployment)
11. [Security](#security)
12. [Known limitations](#known-limitations)

---

## How the game plays

A turn is always the same four beats:

```
🎲 ROLL  ──▶  🎰 SPINS  ──▶  🛒 SHOP  ──▶  ➡ END TURN  ──▶  next player
```

* **Roll** a W6. The face is how many spins you get this turn. Spins cost nothing, so
  a high roll is always strictly better than a low one.
* **Spin** the three reels. Two matching symbols pay a little, three pay a lot.
* **Shop**: four random upgrade cards are offered. Buy what you can afford.
* **End turn** and hand over.

First player to reach **1,500 coins** wins. There are **10 symbols** (Cherry → Royal
Trophy) and **48 upgrades** across eight categories: 🎲 Dice, 🎰 All Reels, 1️⃣2️⃣3️⃣
individual reels, 👑 Symbols, 💰 Payout and ⚡ Special.

The interesting part is that upgrades can target **one specific reel**. A global
"+12% Crown on all reels" is weaker per reel than "+30% Crown on reel 3 only", so
players end up building genuinely different machines — a Crown-stacked reel 3 with a
Third Reel Magnet plays nothing like a cheap Fruit + Golden Pair economy build.

---

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer |
| PHP extensions | `pdo_sqlite`, `mbstring`, `json`, `openssl` (all standard) |
| Database | **none required** — SQLite is used by default. MySQL/MariaDB optional. |
| Web server | Any Apache/nginx shared hosting, or PHP's built-in server |

There is **no Composer step** — the project ships its own autoloader, router,
test runner and WebSocket implementation.

---

## Installation

### Upload and open. That is the whole procedure.

1. Copy the folder onto your web space (e.g. `public_html/RoyalSpin/`).
2. Open it in a browser: `https://your-domain.tld/RoyalSpin/`

That's it. On the first request the app:

* creates its `storage/` folder,
* creates a **SQLite database file** — no database server, no credentials,
* creates all 15 tables and seeds the 48 upgrades,
* generates its own secret key,
* works out its own URL prefix, so a subfolder is fine,
* falls back to clean URLs only if `mod_rewrite` is actually available.

No `.env`, no SQL console, no commands. If anything blocks it (usually folder
permissions), you get a page that says exactly what to change instead of a
blank screen.

> **Where does the data live?** In `storage/royal-spin.sqlite`. Back that file
> up and you have backed up the whole game. Delete it and you start fresh.

### Optional: use MySQL instead

Only if you want to. Copy `.env.example` to `.env` and set:

```ini
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_DATABASE=royal_spin
DB_USERNAME=royal_spin
DB_PASSWORD=your-password
```

The tables are still created automatically on the first request.

### Optional: run the setup from a shell

```bash
php bin/migrate.php            # same thing the first web request does
php bin/migrate.php --fresh    # wipe and rebuild (destructive)
```

---

## Troubleshooting

### 403 Forbidden

Almost always an `.htaccess`/permissions issue.

1. Make sure `public/.htaccess` was uploaded — hidden files starting with a dot
   are silently skipped by many FTP clients and by some unzip tools. Turn on
   "show hidden files" and check both `.htaccess` files exist.
2. Folder permissions should be `755`, files `644`.
3. If your host disables `AllowOverride`, `.htaccess` is ignored entirely.
   That is fine — the game detects it and uses `index.php`-style URLs
   automatically. Just open `.../RoyalSpin/public/index.php`.

### 500 Internal Server Error

Usually PHP is older than 8.2, or the storage folder is not writable. Set
`APP_DEBUG=true` in `.env` temporarily to see the real message.

### "The storage folder is not writable"

Set `storage/` to `755`; if your host needs it, `777`. Nothing else.

### The page loads but links go to the wrong place

Set the prefix explicitly in `.env`:

```ini
APP_BASE_PATH=/RoyalSpin/public
```

### Everything works but feels a second behind

That is the HTTP polling transport, which is what runs when no WebSocket
service is available (normal on shared hosting). The game is fully playable;
see [Realtime](#realtime-two-transports) if you want the instant version.

---

## Realtime: two transports

The game ships two ways of keeping screens in sync, and picks automatically.

| | WebSocket | HTTP polling |
|---|---|---|
| Latency | instant | ~1 second |
| Needs | a long-running PHP process + an open port | nothing |
| Typical host | VPS, dedicated server | shared hosting |

**Both carry the identical event stream** — the server writes every event to one
outbox table and each transport just delivers it. Gameplay, reconnect and
fairness are the same either way; only latency differs.

The server decides and tells the browser, so no time is wasted attempting a
connection that cannot succeed:

* `WS_PUBLIC_URL` set → WebSocket.
* Host is `localhost` or a LAN address → WebSocket (development).
* Anything else → polling.

Force it with `WS_ENABLED=true|false` in `.env`.

### Turning on the instant version

Only possible if your host lets you run a background process (VPS, or shared
hosting with SSH + a process manager):

```bash
php bin/ws-server.php
```

then in `.env`:

```ini
WS_PUBLIC_URL=wss://your-domain.tld/ws
```

and proxy `/ws` to port 8081 (see [Production deployment](#production-deployment)).
On ordinary shared hosting this is not possible — which is exactly why the
polling transport exists.

---

## Running it locally

```bash
php -S 0.0.0.0:8080 -t public          # that is enough to play
php bin/ws-server.php                  # optional: instant updates
```

Open <http://localhost:8080>. To play from a phone on the same Wi-Fi, use your
machine's LAN IP (e.g. `http://192.168.1.20:8080`).

---

## Playing your first match

1. Open `https://your-domain.tld/RoyalSpin/`, click **Create account**, register as e.g. `Tobi`.
2. On the dashboard press **Create room**, pick a player count → you get a code like `K7M4`.
3. On a second device (or a private window), register `Alex` and enter `K7M4` under **Join room**.
4. `Alex` presses **I'm ready**, `Tobi` presses **Start match**.
5. Play. Both screens show the same dice, the same reels and the same coin totals live.

To see the reconnect system: close the browser mid-turn, log back in, and enter the
same room code. You land back in the same seat with your exact coins, remaining
spins, dice result and upgrades.

---

## Balancing

**Everything that affects pacing or odds is in `config/game.php` and
`config/upgrades.php`.** No balance number is hard-coded anywhere else.

```php
'target_coins'       => 1500,   // GAME_TARGET
'starting_coins'     => 150,    // STARTING_COINS
'max_players'        => 4,      // MAX_PLAYERS
'shop_options'       => 4,      // SHOP_OPTIONS
'payout_scale'       => 9.5,    // global economy speed
'upgrade_cost_scale' => 0.55,   // global upgrade pricing
```

Plus symbol weights, pair/triple tables, dice rules, per-symbol caps and the
anti-runaway ceilings.

### The simulator

After any change, re-validate with the built-in simulator — it runs the *real*
engines with no database:

```bash
php bin/simulate.php                              # 100k spins + 200 bot matches
php bin/simulate.php --matches=400 --players=4
php bin/simulate.php --spins=1000000 --build=crown
php bin/simulate.php --seed=42                    # reproducible
php bin/simulate.php --help
```

It reports average payout per spin, pair/triple/Crown-triple/Trophy-triple rates,
average match duration, turns to reach the target, upgrades bought, and the share of
matches landing in the 10–20 minute window.

### Balance decisions made here

Two numbers in the original design brief did not survive contact with the simulator,
and both are documented inline in `config/game.php`:

1. **`payout_scale` exists at all.** The raw pair/triple tables average ~2.5 coins per
   spin, which needs ~500 turns to reach 1,500 — hours per match. The tables keep
   their design ratios and one global multiplier sets the absolute speed.

2. **The top of the triple table is compressed** (Trophy 260 → 145, Crown 170 → 110).
   To finish in ~20 turns the target must be roughly 90× the average spin — which made
   the original Trophy triple worth *more than the entire race*. About 0.5% of
   simulated matches ended inside two minutes on one lucky spin. A Trophy triple is
   still by far the biggest moment in the game (~1,000 coins), but it now buys you the
   lead rather than the match.

Measured over 250 bot matches per player count at the shipped values:

| Players | Average match | Under 5 min | Over 25 min |
|---|---|---|---|
| 2 | 10:48 | 3.2% | 0.0% |
| 3 | 13:41 | 0.8% | 1.6% |
| 4 | 16:32 | 1.2% | 8.4% |

### Debug overlay

With `APP_ENV=local` the game screen gains a panel showing effective per-reel
probabilities, upgrade stacking, payout multipliers, the dice distribution and the
match state id. It is served by `/api/match/{id}/debug`, which returns **404 in
production** — verified by the test suite.

---

## Tests

```bash
php tests/run.php                  # everything
php tests/run.php SlotEngineTest   # one suite
```

103 tests / ~21,600 assertions, no dependencies. Database-backed suites create a
throwaway SQLite file per test.

| Suite | Covers |
|---|---|
| `SlotEngineTest` | weights, pairs, triples, wilds, payout table + ordering, combo cap, empirical vs. analytic rates, seeded reproducibility |
| `UpgradeEngineTest` | global vs. single-reel targeting, relative (not absolute) bonuses, stacking, normalisation, every cap, tampered levels, dice upgrades, "a higher roll is never worse" |
| `MultiplayerTest` | room codes, full/unknown rooms, host-only start, turn order, wrong-player rejection, double-tap idempotency, shop tampering, private events, disconnect handling, turn auto-skip |
| `ReconnectTest` | exact mid-turn restore, upgrades surviving a restart, shop offers not re-rolling on refresh, no double payout, event replay from any sequence, single-use WS tickets |
| `MatchEndTest` | target ends the match, actions blocked afterwards, placements, room released while history survives, code reuse, permanent account stats, upgrades reset between matches |
| `DeploymentTest` | subfolder installs, URL generation with and without `mod_rewrite`, `PATH_INFO` routing, and a regression guard against the `.htaccess` rule that caused a 403 |

---

## Architecture

```
config/          game.php · upgrades.php          ← all balance lives here
src/
  Support/       Env · Db · Config · Rng · Session · Validator · RateLimiter
  Auth/          AuthService (register, login, WebSocket tickets)
  Game/          SlotEngine · DiceEngine · PayoutEngine · UpgradeEngine
                 UpgradeCatalog · Symbols · GamePhase · PlayerStats
                 RoomService · GameService     ← the authority
  Realtime/      WebSocketServer · Frame · Connection · EventBus
  Http/          Router · Request · Response · Controllers/
database/        schema.mysql.sql · schema.sqlite.sql
bin/             migrate.php · ws-server.php · simulate.php
public/          index.php · assets/{css,js}
resources/views/ PHP templates (no logic)
tests/           run.php + 5 suites
```

### The server decides, the client animates

Every outcome — symbols, coins, dice, prices, upgrade levels, whose turn it is, who
won — is produced by `GameService` and persisted before any client hears about it.
The browser sends an *intent* (`spin`) and receives a *result*. There is no game rule
anywhere in the JavaScript; `reels.js` literally builds a strip of random filler
symbols and slides the server's answer into view.

### One event stream, two transports

Every authoritative mutation writes its state change **and** an event row to
`game_events` inside the same transaction:

```
action ─▶ [ tx: lock match · validate · mutate · append event ] ─▶ commit
                                                                    │
                        ┌───────────────────────────────────────────┤
                        ▼                                           ▼
             WebSocket service tails                    HTTP clients poll
             the outbox and fans out            /api/match/{id}/events?since=N
```

Both paths deliver the identical, ordered stream. That is why the HTTP fallback is a
genuine fallback rather than a degraded second implementation — and why reconnecting
is simply *"give me the state, then everything after seq N"*.

The WebSocket service contains **no game rules**. It authenticates sockets, forwards
intents to `GameService`, relays the outbox, tracks presence and un-sticks abandoned
turns. Anything it decided on its own would be a bug.

### Race conditions

Every mutating action:

1. opens a transaction and takes `SELECT … FOR UPDATE` on the `matches` row, which
   serialises all concurrent actions on that match;
2. claims the client-generated `action_id` in the `action_log` table — a duplicate
   claim returns the **stored result** instead of running again.

So a double-tapped SPIN, a retry after a dropped socket, and a page refresh all
produce exactly one payout. Rejected actions release their id so the client can
legitimately retry once the problem is fixed.

### Reconnect

Nothing about a turn lives in the browser. `match_players` stores coins, the dice
result, remaining spins, streak counters, second-roll usage and per-match stats;
`player_upgrades` and `shop_offers` store the build and the current offer set. On
reconnect the client discards its local state and asks for a fresh snapshot.

Losing connection never removes a player — presence just flips to
`🟡 Connection lost`. If the *active* player stays offline past
`connection.turn_timeout_seconds` (120s), their turn is auto-skipped so one dropped
phone cannot freeze a match; their coins, upgrades and seat are untouched.

### Room codes

Codes are 4 characters from `ABCDEFGHJKMNPQRSTUVWXYZ23456789` — no `O`/`0`, `I`/`1`
or `L`. A `rooms` row exists only while the room is live; finishing a match deletes it,
which is exactly what frees the code for reuse. History lives in `matches` /
`match_players`, which are never deleted.

### State machine

Server-authoritative phases are `waiting_for_dice → waiting_for_spin → shop →
(next player)`, plus `finished`. `GamePhase::allows()` is the single gate for every
action. The client also passes through presentation-only states
(`dice_animating`, `spin_animating`, `turn_ending`, …) which never unlock anything —
see the diagram at the top of `src/Game/GamePhase.php`.

---

## Production deployment

### nginx + php-fpm

```nginx
server {
    listen 443 ssl http2;
    server_name royalspin.example.com;
    root /var/www/royal-spin/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # proxy the realtime service so it shares the TLS certificate
    location /ws {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
    }
}
```

With that in place set:

```ini
WS_PUBLIC_URL=wss://royalspin.example.com/ws
SESSION_SECURE=true
TRUST_PROXY=true
```

Point the document root at `public/` — everything else must stay outside the web root.

### Apache

`.htaccess` files are included in `public/`. Enable `mod_rewrite` and set
`AllowOverride All` for the directory, then proxy `/ws` with `mod_proxy_wstunnel`:

```apache
ProxyPass        /ws ws://127.0.0.1:8081/
ProxyPassReverse /ws ws://127.0.0.1:8081/
```

### systemd unit for the realtime service

`/etc/systemd/system/royal-spin-ws.service`:

```ini
[Unit]
Description=Royal Spin realtime service
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/royal-spin
ExecStart=/usr/bin/php /var/www/royal-spin/bin/ws-server.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now royal-spin-ws
sudo journalctl -u royal-spin-ws -f
```

The service reconnects to the database by itself if MySQL restarts, and resumes the
event tail from wherever it left off.

### Production checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_KEY` set to a fresh random value
- [ ] `SESSION_SECURE=true` behind TLS
- [ ] Document root is `public/`
- [ ] `bin/ws-server.php` supervised and restarting
- [ ] `WS_PUBLIC_URL` set to your `wss://` endpoint

---

## Security

| Concern | How it is handled |
|---|---|
| SQL injection | PDO with `ATTR_EMULATE_PREPARES = false`; every query is a real prepared statement |
| XSS | All template output goes through `e()`; frontend data travels in `data-` attributes, never inline `<script>`; strict CSP with no `unsafe-inline` |
| CSRF | Per-session token required on every POST, via form field or `X-CSRF-Token` header |
| Session security | `HttpOnly` + `SameSite=Lax` + `Secure` cookies, strict mode, id regenerated on login, user-agent fingerprint binding |
| Passwords | `password_hash(PASSWORD_DEFAULT)` with transparent re-hashing; constant-work verification for unknown users |
| Brute force | DB-backed rate limiter on login (per IP and per account) and registration |
| WebSocket auth | Single-use, 60-second tickets minted over authenticated HTTPS; verified server-side and consumed atomically |
| Authorisation | Every action re-checks authentication, match membership, turn ownership and phase — a player can never act for another account |
| Client trust | Zero. Prices come from persisted `shop_offers`, never the request; upgrade levels are clamped to the catalogue; all RNG is server-side |
| Information leaks | Effective probabilities and the debug endpoint are gated behind `APP_ENV=local` |
| Error handling | Users see friendly messages; stack traces only in debug mode, otherwise logged |

The RNG is PHP's `Random\Engine\Secure` (CSPRNG) in production. A seeded Mt19937
engine is available for tests and the simulator only.

---

## Known limitations

Being honest about what is *not* in this build:

* **Single realtime process.** The WebSocket service is one PHP process using
  `stream_select`. That comfortably handles hundreds of concurrent matches on a small
  VPS, but it does not scale horizontally — running two instances behind a load
  balancer would deliver events twice. Multi-node would need a shared pub/sub (Redis)
  in front of the outbox. The design anticipates it: the outbox is already the single
  ordering authority.
* **Lobby uses a 2.5s poll as a safety net** alongside the WebSocket push. Lobbies
  have no match id yet, so they cannot ride the event outbox; the service diffs
  subscribed lobbies instead. Real-time in practice, but not the same mechanism as
  in-match events.
* **No spectators.** Only the players seated in a match can read its state.
* **No mid-match rejoin for new players** — by design; only original participants can
  reconnect.
* **Sound is synthesised** with the Web Audio API rather than sampled audio files.
  It is functional and hooks every documented event, but it is beeps, not a scored
  soundtrack. Replacing `sound.js` with sample playback is a contained change.
* **No email verification or password reset.** Registration is username + email +
  password; there is no mail transport wired up.
* **SQLite is the zero-setup default**, and it serialises writes. That is
  entirely fine for this game — a match is a handful of writes per turn — but if
  you expect dozens of simultaneous matches, point `DB_DRIVER` at MySQL, which
  is what the row-level locking in `GameService` was written for.
* **Bot opponents exist only in the simulator.** There are no AI players in the real
  game — a match needs real humans, exactly as specified.
* **The auto-skip timer needs the realtime service running.** If `ws-server.php` is
  down, stalled turns are not skipped (though the game itself stays fully playable
  over the HTTP fallback).
* **Browser support** targets modern evergreen browsers (ES modules, `dvh` units,
  `aspect-ratio`). It does not support IE11 or very old Android WebViews.

---

## License

Provided as-is for the commissioned brief. Virtual coins only — no gambling, no
real-money transactions, no purchases.
