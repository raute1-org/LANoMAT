---
title: LAN party runbook (Orga)
description: The Orga checklist for a LANoMAT weekend — recipes and ISOs before the LAN, preflight and backups, the day-of sequence, the docs/MCP endpoints, and what to do after the last match.
sidebar_icon: clipboard-check
---

This is the Orga's operational guide. It assumes the server is deployed (see
[Deployment](/docs/start/deployment)) and the docs site runs at `/docs`.

## Two weeks before

1. **Recipes**: sync the share and review drafts.

   ```bash
   RECIPES_SOURCE=smb://nas.local/games-recipes php artisan lanomat:recipes:sync
   php artisan lanomat:recipes:export storage/app/recipe-bundle   # offline bundle
   ```

   Open `/admin/recipes`: check `requires` (the files the recipe needs), the steps, and the
   optional flags (UT2004VR is optional; the base game is not). Publish only recipes whose
   files are on the LAN or have a verified external URL.

2. **Files**: upload the ISOs, patches, VR mods and avatar packs to the event Files page (or
   the NAS share the file host mirrors). Approve them. The game page must show **LAN** for
   every required file; a `missing` in the plan means the file name did not match or the
   upload is still pending.
3. **Avatars**: collect the `.vrm` files, record author/license, approve them. The installer
   offers them as a multi-select; the live converter is at <https://ut.raute1.org/>.
4. **Docs**: deploy the docs container (`docker compose --profile docs up -d docs`) and open
   `/docs/` once from a phone on the LAN Wi-Fi.
5. **Preflight**: `php artisan lanomat:preflight`. Fix red checks; yellow is a warning
   (`recipe_sync` > 14 days, token growth, docs 404). The MCP checks probe `/mcp` (401),
   `/api/v1/me` (401), the device endpoint, and `/docs`.
6. **Backup**: run the backup script and confirm the marker
   (`/var/backups/lanomat/.last-success`); the Preflight freshness check reads it.

## The day before

- Start the file host, the app, queue, scheduler and Reverb; `docker compose --profile prod up -d`.
- `php artisan lanomat:preflight --json` green; `scripts/ops/mcp-smoke.sh` green.
- Test one agent end to end on a spare PC: `login`, `mcp install`, `skills sync`,
  `plan ut2004`, `install ut2004 --dry-run`, `/mcp` in `pi mcp list`.
- Check the games page on a phone: `/games`, "Nur LAN-Spiele", one game detail, the server card.
- Print/display the QR codes: event page, check-in, docs.

## Day of

1. Open check-in (`/orga/events/<slug>/checkin`), then self check-in (`/checkin/<token>`).
2. Watch `/admin` and the infoscreen (`/screen/<slug>`); the Preflight checks are the truth.
3. Announce tournaments; the bracket, Discord channels and voice rooms are created by the
   tournament lifecycle. Check the first match's join link before the players do.
4. For a casual round: the game page's **Runde ankündigen** creates an LFG post; the voice
   chat link is next to it.
5. If a participant cannot install: `/games/<slug>` shows exactly which requirement is
   `missing` (upload/approve/pin), the plan shows `external` (needs the internet), and the
   agent's `doctor` shows pairing/model problems.
6. Keep the docs open at `/docs/guides/install-ut2004` (and the other guides) for the
   participants; the docs container is read-only and needs no database.

## After the last match

- Finish tournaments; the winner moment and recap scene use the stored results.
- Close polls/catering; the stats page reads the event.
- Run the backup; export the recap/gallery if the event is archived.
- `php artisan lanomat:recipes:export` again if the catalog changed; commit/keep the bundle.
- Revoke agent tokens that belonged to shared/kiosk machines (`/mcp/connect`).

## Healthy-state probes

| Probe | Expected |
|---|---|
| `GET /up` | `200` |
| `POST /mcp` without token | `401` + `WWW-Authenticate: Bearer` |
| `GET /api/v1/me` without token | `401` |
| `GET /docs/` | `200` |
| `GET /events/<slug>/files` | the approved files, no pending foreign ones |
| `GET /games/<slug>` | the requirements show LAN matches; the server row is live |
| `lanomat:preflight` | no red check; `recipe_sync` not older than 14 days |

## Troubleshooting

| Symptom | Fix |
|---|---|
| App is up, files 404 | The file host / signed URL is down; check `FILES_HOST_*` and the host nginx. |
| `/docs/` 404 behind Traefik | The docs router `PathPrefix(/docs)` must point at port 8080; no StripPrefix. |
| Agents cannot pair | `/mcp/connect` reachable; `MCP_*` env; the device throttle (10/min/IP) is not exhausted. |
| Plan says `external` for the patch | The patch is not on the LAN; upload it or accept `--allow-external`. |
| Tournament bracket empty | The tournament is not started; the bracket is generated on start, not on enrollment. |
| Discord channels missing | The Discord module/bot/guild settings; the tournament lifecycle creates them on Ready. |
| Reverb/voice dead | `REVERB_ALLOWED_ORIGINS` is the host **without** scheme; `ws.<domain>` and the LiveKit sidecar must be up. |

## Checklist

- [ ] Recipes synced, drafts reviewed, required files approved
- [ ] Every published game shows **LAN** for its required files
- [ ] Avatars approved, licenses recorded, converter linked
- [ ] Docs at `/docs/` reachable from the LAN Wi-Fi
- [ ] Preflight green, backup fresh, MCP smoke green
- [ ] One full agent install tested on a spare PC
- [ ] QR codes for event, check-in and docs printed
- [ ] Day-of: check-in, tournaments, LFG, infoscreen, voice
- [ ] After: backups, recap, token revoke
