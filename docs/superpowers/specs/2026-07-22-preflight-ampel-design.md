# Preflight Ampel — Design

**Date:** 2026-07-22
**Status:** Draft (awaiting review)
**Origin:** JB Feature-Input Runde 3, §1.2 (2026-07-21).

## 1. Goal

One command — `php artisan lanomat:preflight` — that probes every system
LANoMAT depends on and reports a **traffic-light (ampel)** result, plus the
same result as a **status tile in the Filament admin dashboard**, plus a
**scheduled orga bell** when something is down or jobs have failed. On LAN
morning that turns "ten manual checks" into one click, and it is the standard
"are we actually ready?" gate before any event.

## 2. Scope

**In scope:**
- A `HealthCheck` abstraction + a registry of concrete checks.
- Internal checks: database, Redis, storage writable, queue-worker alive,
  scheduler ticking, `failed_jobs` empty, Reverb reachable.
- External checks (Mode-A-tolerant): Discord API, Discord gateway sidecar,
  Mumble sidecar, TeamSpeak sidecar, Pelican, Music Assistant.
- `lanomat:preflight` CLI (ampel table + non-zero exit on any `down`).
- A Filament dashboard widget showing the ampel (+ a "re-run" action).
- `lanomat:heartbeat` (writes scheduler + queue liveness markers).
- `lanomat:health-watch` (scheduled; raises an orga bell on `down`/failed jobs).

**Out of scope (non-goals):**
- Fixing/restarting anything — preflight only **reports**.
- Persisting a history of results (no new table; checks run live, briefly
  cached; only the two liveness markers are cached timestamps).
- Adding `health()` to the domain contracts — checks are **self-contained
  diagnostic probes** in the Preflight module (decided in review), so the 8
  external contracts stay untouched.
- Real-infra verification of the external systems themselves — that is the
  separate acceptance checklist (`docs/pre-lan-acceptance-checklist.md`).

## 3. Current state

- No health/status concept exists; the Filament panel uses the default
  `Dashboard` with no custom widgets.
- Scheduler entries exist in `routes/console.php`; there is no liveness marker
  for "is the scheduler/worker actually running".
- Failed queue jobs land in `failed_jobs` and are invisible today.
- External systems are reached through contracts + fakes; several
  (Pelican, TeamSpeak, Music Assistant) are Mode-A (unconfigured in dev).

## 4. Architecture

New module `app/Modules/Preflight/`.

```php
enum HealthStatus: string { case Ok = 'ok'; case Warn = 'warn'; case Down = 'down'; case Skipped = 'skipped'; }

final class HealthResult {
    public function __construct(
        public readonly HealthStatus $status,
        public readonly string $message,
    ) {}
    // + static helpers: ok(msg), warn(msg), down(msg), skipped(msg)
}

interface HealthCheck {
    public function key(): string;    // stable id, e.g. 'database', 'discord_api'
    public function label(): string;  // German UI label via lang/de/preflight.php
    public function run(): HealthResult;
}
```

- Checks are registered as a **tagged** binding (`HealthCheck::class`) in the
  Preflight service provider, in display order (internal first, then external).
- `RunPreflight` (action) resolves all tagged checks and runs each inside a
  `try/catch` — a throwing check becomes `down` with the exception message
  (a check must never crash the command or the widget). Returns a
  `list<array{key, label, status, message}>`.
- **`skipped`** is the Mode-A escape hatch: an external check whose config is
  absent returns `skipped` (grey), never `down` — so a dev machine shows a
  clean ampel (internal `ok`, external `skipped`).

## 5. The checks

**Internal:**
- `DatabaseCheck` — `DB::connection()->getPdo()` + `select 1`; down on exception.
- `RedisCheck` — `Redis::connection()->ping()`; down on exception.
- `StorageWritableCheck` — write→read→delete a temp file on the default disk; down on failure.
- `SchedulerHeartbeatCheck` — reads `cache('preflight.scheduler_tick')`; `ok` if within 2 min, `down` if older/missing (scheduler not running).
- `QueueWorkerCheck` — reads `cache('preflight.queue_tick')` (written by a heartbeat job); `ok` if within 2 min, `down` if older/missing (no worker consuming).
- `FailedJobsCheck` — `DB::table('failed_jobs')->count()`; `ok` if 0, else `warn` with the count.
- `ReverbCheck` — TCP-connect to `config('reverb…host'/'port')` (a socket open, short timeout); `ok`/`down`. `skipped` if broadcasting isn't `reverb`.

**External (all `skipped` when their config/credentials are absent):**
- `DiscordApiCheck` — `GET https://discord.com/api/v10/users/@me` with the bot token; 200 → `ok` (returns the bot tag), else `down`.
- `DiscordGatewaySidecarCheck` — GET the sidecar health URL (`DISCORD_GATEWAY_HEALTH_URL`); `ok`/`down`. (The gateway sidecar exposes a tiny `/health` — a hook already noted in the gateway spec §8; `skipped` until that URL is configured.)
- `MumbleSidecarCheck` / `TeamSpeakSidecarCheck` — GET the sidecar base URL's health route with its bearer token (`http://mumble-admin:8000`, `http://teamspeak-admin:8000`); `ok`/`down`/`skipped`.
- `PelicanCheck` — a benign authenticated GET against the Pelican API base with the API key; `ok`/`down`/`skipped`.
- `MusicAssistantCheck` — GET the MA base URL (`services.music_assistant.base_url`, `:8095`); `ok`/`down`/`skipped`.

Every HTTP probe uses a **short timeout** (≈3 s) so a dead external system
can't hang the ampel. In tests every probe is driven by `Http::fake()` /
config presence — **never a real endpoint**.

## 6. Outputs

**6.1 CLI — `lanomat:preflight`.** Runs `RunPreflight`, prints a table
(`System | ● status | message`) using colour + a glyph per status
(●ok green, ●warn amber, ●down red, ○skipped grey). **Exit code 1 if any
check is `down`, else 0** — usable as a shell/CI "ready?" gate. `warn` and
`skipped` do not fail the command.

**6.2 Filament dashboard widget — `PreflightStatusWidget`.** A tile on the
admin `Dashboard` listing each check with its ampel dot + message. Results are
`Cache::remember('preflight.results', 15s, …)` so a dashboard load doesn't
re-probe every external system on every render; a header action
"Neu prüfen" forgets the cache key and reloads. Orga/admin-gated (the panel
already is). Follows the Signalpult design system (semantic status colours;
`text-live`/amber only for the amber signal; mono for machine data).

**6.3 Scheduled orga bell — `lanomat:health-watch`.** Scheduled
`everyFiveMinutes`. Runs `RunPreflight`; if any check is `down` **or**
`failed_jobs > 0`, sends the existing in-app orga notification (the bell,
a new `system` category) summarising which systems are unhealthy. **Edge-
triggered:** it stores the last aggregate state in `cache('preflight.watch_state')`
and only notifies on a healthy→unhealthy transition (and once more on
recovery), so it never spams every 5 minutes while something stays down.

## 7. Liveness heartbeat — `lanomat:heartbeat`

Scheduled `everyMinute`:
- writes `cache(['preflight.scheduler_tick' => now()], 10 min)` — proves the
  scheduler loop itself is running (read by `SchedulerHeartbeatCheck`);
- dispatches a tiny `QueueHeartbeatJob` whose `handle()` writes
  `cache(['preflight.queue_tick' => now()], 10 min)` — proves a worker is
  consuming the queue (read by `QueueWorkerCheck`).

This is the only "new moving part" the checks depend on; both markers are
plain cache timestamps, no schema.

## 8. Config / env

- `DISCORD_GATEWAY_HEALTH_URL` (new; e.g. `http://discord-gateway:8080/health`) — `skipped` if unset.
- Reuses existing config for the rest (`services.discord.bot_token`, the voice
  sidecar URLs/tokens, `services.pelican.*`, `services.music_assistant.*`,
  Reverb host/port, `broadcasting`).
- Staleness thresholds (`2 min`) and the health-watch cadence (`5 min`) are
  constants in the checks/command (not env) — tune in code if needed.

## 9. Testing (Pest, sequential; never a real API)

- Each internal check: `ok` and `down`/`warn` paths via a real test DB /
  faked cache timestamps (fresh vs stale) / a full `failed_jobs` row.
- Each external check: `ok` (via `Http::fake()` 200), `down` (`Http::fake()`
  500 / connection exception), and `skipped` (config absent).
- `RunPreflight`: a throwing check is caught and reported `down`, not
  propagated.
- `lanomat:preflight`: exit code 0 when nothing is `down`, 1 when a check is
  `down` (force one via `Http::fake()`).
- `lanomat:health-watch`: with `failed_jobs > 0`, an orga notification is sent
  (`Notification::fake`); edge-trigger — a second run while still unhealthy
  sends nothing.
- `lanomat:heartbeat`: writes both cache markers (queue marker after the job
  runs, `Queue::fake`/sync).
- Widget: a lightweight assertion that it renders the results (no screenshot).

## 10. Docs

- `docs/pre-lan-acceptance-checklist.md` §8: replace the "to build" preflight
  bullet with "built — run `lanomat:preflight`", and reference the widget.
- Short section in `docs/prod-test.md`: "LAN-morning check → `lanomat:preflight`".
- Register the two new scheduled commands in `routes/console.php`.

## 11. Open decisions for review

1. **`failed_jobs > 0` severity in the ampel:** proposed `warn` (attention,
   doesn't fail the exit code) while the scheduled bell still fires on it.
   Acceptable, or should a non-empty `failed_jobs` be `down`?
2. **Health-watch scope:** proposed to bell on *any* `down` or failed jobs.
   Too broad (e.g. a Mode-A external that's simply not deployed yet would be
   `skipped`, not `down`, so it won't bell — intended)? Confirm skipped never
   bells.
3. **Cadence:** heartbeat `everyMinute`, health-watch `everyFiveMinutes`,
   widget cache `15 s`, staleness `2 min`. Fine, or tune?
