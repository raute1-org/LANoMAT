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

## What it checks

Database, Redis, queue heartbeat, scheduler heartbeat, storage writability, backup marker freshness,
Discord gateway, Mumble/TeamSpeak sidecars, LiveKit, file host, desktop client download version,
certificate expiry, LanCache (optional), room audio (optional), pinned image updates.

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
