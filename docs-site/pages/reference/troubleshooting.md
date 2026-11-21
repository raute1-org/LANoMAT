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

| Dark | Light |
|---|---|
| ![Admin dashboard lists each preflight check with OK, Ausfall or Übersprungen, dark](/screens/admin-dark.png) | ![Admin dashboard lists each preflight check with OK, Ausfall or Übersprungen, light](/screens/admin-light.png) |
| `ADMIN · PREFLIGHT · OK · AUSFALL · ÜBERSPRUNGEN` | `ADMIN · LIGHT` |

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

The check-in page is the camera path that needs HTTPS: without it the scanner button stays inert and
only the manual token field works.

| Dark | Light |
|---|---|
| ![Orga check-in page with camera scan button and manual token field, dark](/screens/orga-checkin-dark.png) | ![Orga check-in page with camera scan button and manual token field, light](/screens/orga-checkin-light.png) |
| `CHECK-IN · KAMERA · TOKEN` | `CHECK-IN · LIGHT` |

## Files and gallery

| Symptom | Cause | Fix |
|---|---|---|
| Upload 413 | PHP/Livewire limit | `LIVEWIRE_MAX_UPLOAD_KB` and `upload_max_filesize`/`post_max_size` |
| Download 403/410 | Signed URL tampered/expired | Request a new link; check the file host clock |
| File host unreachable | DNS/VLAN/cert | `files.<domain>:8443` must resolve and be trusted on the LAN |
| Upload stuck at 0 | tusd hook unreachable | `FILES_TUS_HOOK_SECRET`, `internal/files/tusd-hook` |
| Gallery photo has GPS | GD re-encode disabled | Keep `IMAGE_DRIVER` empty (GD); Imagick would keep EXIF |
| Zip download missing | Event not finished | The zip is gated to `finished`/`archived` |

## Games, launcher and shared recipes

| Symptom | Cause | Fix |
|---|---|---|
| **Installieren** does nothing | The click fired a `lanomat://` deep link, but the LANoMAT Launcher is not installed on that PC, or it was installed and never started (it can open behind the browser) | Install or start the launcher from `/launcher`; the notice under the click (**„Kein Launcher-Fenster aufgegangen?“**) has **Launcher herunterladen** and **Nochmal versuchen**. When the launcher did take over, the page confirms **„Launcher geöffnet. Schau in die Taskleiste, falls du kein Fenster siehst.“** |
| **Im Launcher beitreten** does nothing | Same deep link (`lanomat://join/<slug>`), same missing or never-started launcher | Same notice and the same two buttons; the server address on the row stays valid for a manual join |
| The game page has no launcher download, only the deep link | No launcher build is published, so `launcher_available` is `false` and **Installieren** is the `lanomat://install/<slug>` deep link; or the recipe is not `launcher_supported` | Publish a build with `scripts/ops/build-launcher.sh` (writes `manifest.json` last into `clients-dist/launcher`, read via `LAUNCHER_DIST_PATH`), then check `launcher_build` in Preflight. For the recipe, see the **Bald im Launcher** row |
| A shared game does not appear on `/games` | The recipe is still **Entwurf**, or it is published but outside the lineup. An approved file alone does not put it there | In `/admin` → **Spielrezepte**: if it is still a draft, **Dateien freigeben und veröffentlichen** approves the pinned archive and publishes in one step; if it is published, switch **In der Spieleliste zeigen** on. Publishing a contributed recipe already sets that flag |
| A shared game is approved and published but has no server row | No published, lineup-allowed recipe matches the server's game (`RecipeGameMatcher`) | The server's game slug/name and the recipe's slug or search terms must match; check the match on `/servers` |
| No pull request was opened | `RECIPES_PR_ENABLED` is off or repository/token are missing; the default `RECIPES_PR_TRIGGER=publish` waits for the orga, so a draft has none yet; an automatic attempt was skipped by `RECIPES_PR_PER_USER_HOURLY` or `RECIPES_PR_MAX_OPEN`; the recipe is archived; or the proposal was refused | `php artisan lanomat:recipes:propose <slug>` runs it by hand (not limited) and prints `Nicht gesendet: …` with the reason; the panel's **Katalog (Pull Request)** section shows the same. Check `recipe_repository` in Preflight for the token's push right |
| The recipe says **Nicht gesendet** | The readiness check found something the repository validator would reject — `SKILL.md` outside 60–150 lines or without front matter, a draft outside 3–6 steps or without the LAN-verification sentence, `&&` in a command, a shell that does not match the step's OS, a glob with a path separator — or the public-safety check found a private, public or link-local IP address, an e-mail address, a key or an access token | Read the reason in the **Katalog (Pull Request)** section; fix it in the recipe (skill text, steps, commands, file patterns, placeholders such as `lan.example` and `203.0.113.x`), then **Pull Request öffnen / aktualisieren** again |
| The recipe says **Fehlgeschlagen** | The repository could not take the proposal (bad token, rate limit, unreachable API) and no earlier pull request exists | Read the reason in the **Katalog (Pull Request)** section; fix the named item, then propose again. A transient failure is retried twice by the queued job (after 30 s and 120 s) before it is recorded |
| A recipe that already had a pull request stays **Pull Request offen** after a change | The new text was refused (readiness or public safety) and LANoMAT keeps the existing open pull request instead of dropping it | The refusal reason is in the **Katalog (Pull Request)** section; `lanomat:recipes:propose` also warns `Der Pull Request bleibt …, wurde aber nicht neu gesendet` |
| The launcher card says **„Bald im Launcher“** | `LauncherSupport` says this launcher build cannot install the recipe: it is not the UT2004 pilot, not the hand-verified NFSU2 recipe, has no `play_url`, and is not portable | Make it portable (`metadata.portable: true`, one required `base` archive, a `file_exists`/`dir_exists` check in a non-optional step, at least one launch target), or set the launcher override to **Immer anbieten** in the panel. The card links to `/games/<slug>` meanwhile |
| **Spiel teilen** refuses the archive name | The name carries a backtick, `*`, `?` or a control character, or it has fewer than three letters/digits left once brackets become `*` | Rename the archive to plain letters, digits, spaces and hyphens (for example `skyline-rally.zip`) and upload it again. The exact messages are on [Share a game](/docs/guides/share-a-game#troubleshooting) |
| **Spiel teilen** says every draft slot is taken | The participant already has `RECIPES_MAX_DRAFTS_PER_USER` drafts waiting for review (20 by default) | The orga publishes or archives one of them; the message is „Du hast schon 20 Spiele, die auf die Prüfung durch die Orga warten. Sag der Orga Bescheid, dann kannst du weitere teilen.“ (the number follows the setting) |

A **skipped** `recipe_repository` in Preflight only means pull requests are off; that is not an
error. The full check list is on [Preflight](/docs/system/modules/preflight); the workflow is on
[Game recipes](/docs/system/modules/gamerecipes).

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

If the bell is quiet while the checks are green, the problem is upstream of the UI: the worker lists
the job as pending, or the scheduler never enqueued it.

| Dark | Light |
|---|---|
| ![Dashboard with the notification bell panel open and no unread items, dark](/screens/notifications-dark.png) | ![Dashboard with the notification bell panel open and no unread items, light](/screens/notifications-light.png) |
| `DASHBOARD · BELL · NOTHING QUEUED` | `DASHBOARD · LIGHT` |

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
php artisan lanomat:preflight --json > preflight.json
docker compose --profile prod logs --since=30m app queue scheduler reverb-prod > stack.log
```

Redact tokens, cookies, e-mail addresses and `APP_KEY` before sharing. The agent's own failures are
on [Agent troubleshooting](/docs/agent/troubleshooting).

<div class="callout-live">
  <strong>Reload first.</strong> Realtime is an enhancement. If a page looks wrong, reload it before
  debugging websockets — a correct page after reload means the problem is the broadcast, not the
  data.
</div>
