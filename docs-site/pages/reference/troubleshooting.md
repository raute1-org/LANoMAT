---
title: Troubleshooting
description: The failures that actually happen — login, realtime, QR, voice, files, queue and the docs.
sidebar_icon: wrench
---

Start with the traffic light:

```bash
php artisan lanomat:preflight
```

It names the failing check and the next step. The admin tile shows the same. If the app itself is
down, work from the outside in: Traefik → app container → database/Redis → queue/scheduler.

## Login and access

| Symptom | Cause | Fix |
|---|---|---|
| Discord login loops | Redirect URI mismatch | Set `DISCORD_REDIRECT_URI` exactly as registered in the portal |
| "Not a member of this server" | `DISCORD_LOGIN_GUILD_ID` gate | Join the guild, or add the user's Discord id as admin |
| Login returns 500 after a Redis restart | Rate limiter on Redis | `CACHE_LIMITER_STORE=database` (the default) |
| `/admin` 403 | Role is `participant` | Promote with `lanomat:install --admin-discord-id=<id>` |
| Demo buttons missing | Not `APP_ENV=local` | Expected; the dev route 404s elsewhere |

## Realtime

| Symptom | Cause | Fix |
|---|---|---|
| Bracket does not update live | Reverb unreachable | Check `REVERB_HOST/PORT/SCHEME`; the page still works after reload |
| Websocket handshake 403 | `REVERB_ALLOWED_ORIGINS` with a scheme | Use the host name only (`lan.example`) |
| Native client cannot connect | No `Origin` header | The client must send the server's origin |
| Queue/scheduler stale after deploy | Only `app` was rebuilt | Build all four services (`scripts/deploy/deploy.sh`) |

## Dates and QR codes

| Symptom | Cause | Fix |
|---|---|---|
| Times off by one or two hours | Browser zone used instead of display zone | Render via `resources/js/lib/datetime.ts`; `APP_DISPLAY_TIMEZONE` |
| Printed QR unreachable | `APP_URL` is not the venue host | Set `APP_URL` before printing; rotate the token if it leaked |
| Camera scanner does nothing | Page not HTTPS | Browsers allow cameras only on `https://` or `localhost` |
| Check-in says "not yet" | Outside the 12-hour window | `SelfCheckInWindow`; event must be `live` or close to start |

## Files and gallery

| Symptom | Cause | Fix |
|---|---|---|
| Upload 413 | PHP/Livewire limit | `LIVEWIRE_MAX_UPLOAD_KB` and `upload_max_filesize`/`post_max_size` |
| Download 403/410 | Signed URL tampered/expired | Request a new link; check the file host clock |
| File host unreachable | DNS/VLAN/cert | `files.<domain>:8443` must resolve and be trusted on the LAN |
| Upload stuck at 0 | tusd hook unreachable | `FILES_TUS_HOOK_SECRET`, `internal/files/tusd-hook` |
| Gallery photo has GPS | GD re-encode disabled | Keep `IMAGE_DRIVER` empty (GD); Imagick would keep EXIF |
| Zip download missing | Event not finished | The zip is gated to `finished`/`archived` |

## Voice

| Symptom | Cause | Fix |
|---|---|---|
| Mumble join link does nothing | Client not installed / scheme unhandled | Install a Mumble client; the link includes the name |
| "Wrong password" | `MUMBLE_SERVER_PASSWORD` changed | Recreate the containers; old links carry the old password |
| TeamSpeak sidecar connects then drops | ServerQuery flood/closed connection | Check the sidecar logs and the query account rights |
| Web voice has no microphone | Insecure context | `LIVEKIT_URL` must be `wss://` on a trusted cert |
| Empty voice room stays | Someone is still in it | Rooms with occupants wait; the job rechecks every 2 min |
| Desktop download says version mismatch | Server and client built from different commits | Rebuild the client on the deployed commit |

## Queue, scheduler and notifications

| Symptom | Cause | Fix |
|---|---|---|
| Reminders never arrive | `scheduler` not running | `php artisan schedule:work` / the `scheduler` service |
| Queue grows, nothing sends | `queue` not running or stale image | `queue:work` logs; rebuild all four |
| Preflight says queue stale | Heartbeat older than the threshold | Start the worker; `lanomat:heartbeat` writes the marker |
| Discord announcement missing | No announce channel / bot rights | Set `DISCORD_ANNOUNCE_CHANNEL_ID`, check channel permissions |
| Duplicate announcement | Outbox dedup bypassed | Check `discord_outbox.dedup_key`; retries are idempotent |

## Database and storage

| Symptom | Cause | Fix |
|---|---|---|
| 500 on every request | Postgres down / wrong credentials | `DB_*`; compose `postgres` health |
| Migrations fail on deploy | Old image / new schema | Run migrations inside the new `app` container |
| Uploads vanish after deploy | Storage volume not mounted | Keep the storage volume on the app and queue services |
| Backup check warns | Marker older than the threshold | Run `scripts/ops/backup.sh`; check `BACKUP_MARKER_PATH` |

## Docs container

| Symptom | Cause | Fix |
|---|---|---|
| `/docs` serves the app | Traefik priority | Give the docs router a higher priority (`PathPrefix('/docs')`) |
| Docs assets 404 | `/docs` prefix stripped | Do not strip; Zudoku's basePath is `/docs` |
| `/docs/api` 404 | OpenAPI file missing at build | `apis/lanomat.openapi.yaml`; rebuild |
| Screenshots missing | `public/screens` not copied | `npm run screenshots` then rebuild |
| Old docs after deploy | Browser cache | HTML is short-cached; hard reload |

## Collecting a report

```bash
php artisan lanomat:preflight > preflight.txt
docker compose --profile prod logs --since=30m app queue scheduler reverb-prod > stack.log
```

Redact tokens, cookies, e-mail addresses and `APP_KEY` before sharing. The agent's own failures are
on [Agent troubleshooting](/docs/agent/troubleshooting).

<div class="callout-live">
  <strong>Reload first.</strong> Realtime is an enhancement. If a page looks wrong, reload it before
  debugging websockets — a correct page after reload means the problem is the broadcast, not the
  data.
</div>
