---
title: Changelog
description: What changed since the first production deployment — grouped by date, with links to the full log.
sidebar_icon: history
---

LANoMAT has no versioned releases after the `m13` tag; work lands on `main` and is listed in the
repository's [`CHANGELOG.md`](https://github.com/raute1-org/LANoMAT/blob/main/CHANGELOG.md). The
phase tags `m0`–`m13` mark the MVP history.

<div class="live">LIVE 17:42 · main · docs built 2026-11-21</div>

## 2026-11-21

**Added**

- MCP server at `/mcp`: device-code pairing, 24 tools, the recipe-skill resource and the
  `install-game` prompt, with a bearer REST surface under `/api/v1`.
- Game recipes: `recipe.yaml` + `SKILL.md` catalogue, LAN file matching, install plans, avatar
  selection, SMB/git import and the participant games library at `/games`.
- `lanomat-agent`: login, skills sync, one-shot installs, avatars, deep links and the Venice model
  default.
- This documentation site (Zudoku) with a static container served at `/docs`.

**Changed**

- The games library, recipes and agent are optional modules (`GameRecipes`, `Mcp`), off in the
  `core` profile.

## 2026-11-20

**Fixed**

- `gameservers:import-eggs` works in the production image: `symfony/yaml` is a runtime dependency.
- Venue entry: the TCP `HostSNI` rule no longer uses a wildcard; it lists the app and the `ws.` name.

**Changed**

- Docs: venue network runbook for the CRS354 (flat LAN, LACP server link, LanCache DNS, WireGuard on
  the switch, measured routing limits) and a verified cut-over section.

## 2026-11-01

**Added**

- Desktop client: a Beitreten button on every place (voice room, game, server) in "Wo ist wer".
- File host preflight check and certificate renewal.
- Update watch for pinned third-party images.
- Retract your own gallery photos (uploader or orga).
- Dedicated `MatchStatus::Live`.
- Native voice client API with device authorization pairing.
- Rust core and CLI for native web voice (`clients/voice/`).
- Tauri v2 desktop app on the voice core.
- Chat, realtime auth and notifications on the native bearer API.
- Event features in the native client (now feed, tournaments, matches, game servers).
- Profile, linked accounts and friends in the native client.
- Windows port and client release workflow.
- Download the native client inside LANoMAT, always the server's version.
- "Wo ist wer" with follow, and Discord avatars kept and served by LANoMAT.
- Room audio: Denon AV receiver control for the orga (`RoomAudio` module, telnet sidecar).

**Changed / Fixed**

- Untracked `.pyc`, ignored bytecode; docs synced with the finished backlog.
- Paper preset needs `USER_AGENT`; a series of venue, TLS and sidecar fixes (see the full log).

## 2026-10-31

**Added**

- LANoMAT Garry's Mod egg, server presets, port pool and A2S readiness.
- Hypervisor registration, dry-run plans and queued runs (`Provisioning`).
- Event-level game servers get their own voice channel; per-match text and voice channels.
- Cloud entry point that passes TLS through to an app at the venue.
- Mumble server password on the voice setup page.
- Browser voice and text chat with self-hosted LiveKit.
- Trusted TLS for Music Assistant, Pelican and Mumble.
- All file types, downloads from the LanCache file host, resumable tus uploads.
- Latency diagnostics, self-test and headset mode.
- Permanent game servers across events (Pelican, Docker, external).

**Fixed**

- Request body caps, team-logo metadata stripping, Discord-gateway lockfile.
- Beamer bracket fit, live scene list, once-only tombola draws, bounded inputs.
- Confirmed-registration gate, item limits, price bounds, per-option counts and CSV export.
- Phone seat map, stable ICS, reminders and change alarms.
- Orga decision on any match, race guards, roster rules, round-robin ties.
- TeamSpeak sidecar against a real server; Music Assistant client against the real API.
- Match channels private; the bot stays inside them.
- Self-hosted fonts so the frontend build needs no network.
- 404 for missing stored files; pages no longer claim a failed load after a rejected form.
- Mumble admin sidecar for Mumble 1.5.

## Before that

The MVP phases are tagged in git:

| Tag | Scope |
|---|---|
| `m0` | Foundation: Laravel 13, Fortify, Discord-only login, Filament, Docker, CI |
| `m1` | Events, public event page, profile |
| `m2` | Registration, QR tickets, check-in, seating, notifications, Discord base |
| `m3` | Teams, tournaments, bracket engine, Reverb, Discord interactions, Mumble |
| `m4` | Schedule, catering, voting, LFG |
| `m5` | Infoscreen / beamer |
| `m6` | Game servers via Pelican, stats |
| `m7` | Files, hosts, custom servers, LanCache, registry, Traefik |
| `m8` | TeamSpeak voice |
| `m9` | Linked accounts (Steam, Twitch), friends |
| `m10` | Presence, casting |
| `m11` | Jukebox / Music Assistant |
| `m12` | Gallery, recap, news |
| `m13` | Design polish: the Signalpult system |

## How this page is maintained

The repository's changelog is the source of truth. Add one line per merged pull request under its
merge date and section (`feat` → Added, `fix` → Fixed, everything else → Changed; docs-only entries
carry a "Docs:" prefix). This page mirrors the highlights; the full log has every entry.

<div class="callout-live">
  <strong>Docs version.</strong> This site is built from the same commit as the app. A docs deploy
  that does not match the app version is a stale site, not a different release.
</div>
