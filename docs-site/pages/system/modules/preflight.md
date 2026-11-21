---
title: Preflight
description: The health-check registry, the traffic-light command, the Filament tile and update watch.
sidebar_icon: activity
---

`Preflight` is the honest answer to "is the LAN ready?". One registry of checks, one command, one
admin tile. Every check returns `ok`, `warn`, `down` or `skipped` with a sentence that names the
next step.

## Who uses it

- **Orga** runs `php artisan lanomat:preflight` before doors open and watches the tile during the
  event.
- **The scheduler** runs the health watch and notifies orga when something goes down.

## Key screens

- Filament dashboard tile — grouped checks with status and detail.
- `php artisan lanomat:preflight` — terminal output for scripts and runbooks.
- Notifications — one `SystemUnhealthy` notification per transition, not per check.

| Dark | Light |
|---|---|
| ![Filament dashboard with the Preflight traffic-light tile, dark](/screens/admin-dark.png) | ![Filament dashboard with the Preflight traffic-light tile, light](/screens/admin-light.png) |
| `PREFLIGHT · DASHBOARD TILE` — grouped checks, one status and one sentence each | `PREFLIGHT · LIGHT` — the same tile in light mode |
| ![Remote hosts list with role, status and last-probe columns, dark](/screens/admin-remote-hosts-dark.png) | ![Remote hosts list with role, status and last-probe columns, light](/screens/admin-remote-hosts-light.png) |
| `PREFLIGHT · REMOTE HOSTS` — role, status and "Zuletzt geprüft" per host | `PREFLIGHT · LIGHT` — the host table in light mode |
| ![Custom servers list with per-server status column, dark](/screens/admin-custom-servers-dark.png) | ![Custom servers list with per-server status column, light](/screens/admin-custom-servers-light.png) |
| `PREFLIGHT · CUSTOM SERVERS` — host, image, container and readiness status | `PREFLIGHT · LIGHT` — the same server table |

## What it checks

Database, Redis, queue heartbeat, scheduler heartbeat, storage writability, backup marker freshness,
Discord gateway, Mumble/TeamSpeak sidecars, LiveKit, file host, desktop client download version,
launcher builds (`launcher_build`), certificate expiry, LanCache (optional), room audio (optional),
pinned image updates, recipe pull requests (`recipe_repository`, optional).

### Recipe pull requests (`recipe_repository`)

Shown as **Rezept-Katalog (Pull Requests)**. It answers whether the token may read the repository and
— the part that fails silently otherwise — may push to it. It reads `config('recipes.pull_requests')`
and calls `GET /repos/<owner>/<name>` on the GitHub REST API; it never turns the event-day check red.

| Result | When | Message |
|---|---|---|
| `skipped` | `RECIPES_PR_ENABLED` is off | „Nicht konfiguriert.“ |
| `warn` | Enabled, but repository or token missing | „Pull Requests sind aktiviert, aber Repository oder Token fehlen (RECIPES_PR_REPOSITORY, RECIPES_PR_TOKEN).“ |
| `warn` | The API could not be reached | „Die GitHub-API ist nicht erreichbar. Geteilte Rezepte bleiben als Entwurf liegen.“ |
| `ok` | HTTP 200 and `permissions.push` is true | „Das Token darf den Rezept-Katalog lesen und Pull Requests anlegen.“ |
| `warn` | HTTP 200 without push right | „Das Token kann den Rezept-Katalog lesen, aber nicht schreiben (Contents und Pull requests: write fehlen).“ |
| `warn` | HTTP 401 | „GitHub lehnt das Token ab (HTTP 401). RECIPES_PR_TOKEN prüfen.“ |
| `warn` | HTTP 403 | „Das Token darf nicht auf das Repository zugreifen (HTTP 403), oder das Rate-Limit ist erschöpft.“ |
| `warn` | HTTP 404 | „Das Repository wurde nicht gefunden (HTTP 404). RECIPES_PR_REPOSITORY als "owner/name" prüfen.“ |
| `warn` | Any other status | „GitHub antwortet auf die Repository-Abfrage mit HTTP :status.“ |

A `skipped` row is not a failure: an installation without a catalogue link is normal. The check only
answers whether the *link* works: a single recipe can still be **Nicht gesendet** because the
readiness check (repository validator rules) or the public-safety check refused its text, and an
automatic proposal can be skipped by `RECIPES_PR_PER_USER_HOURLY` or `RECIPES_PR_MAX_OPEN`.

## Guards and rules

- A check never throws; an exception becomes `down` with the exception message, not a 500.
- `warn` is for degraded-but-usable; `down` is for "this will hurt at the LAN".
- The queue check uses a heartbeat written by a scheduled job; a stale heartbeat means the worker is
  not running, even if the process exists.
- The backup check compares the marker file's age, not the backup's log line.
- The update watch compares pinned image digests and tags; it warns, never auto-updates.
- Probes for optional modules are only registered when the module is enabled.
- Notification dedup: a state transition notifies once.

## Docs for agents

No MCP tool yet. The status is in the admin tile and the command output.

<div class="callout-live">
  <strong>The one failure that looks healthy.</strong> A dead queue worker leaves the app fully
  usable until reminders and Discord messages silently stop. The heartbeat exists for that.
</div>
