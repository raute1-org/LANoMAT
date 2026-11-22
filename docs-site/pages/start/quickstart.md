---
title: Quickstart
description: Run LANoMAT locally in about ten minutes, with seeded demo data and no Discord app.
sidebar_icon: rocket
---

This gets a working LANoMAT on your machine: Postgres and Redis in Docker, the Laravel app, the
queue worker, Vite, and a demo login so you do not need a Discord OAuth app for the first look.

## Requirements

- PHP 8.4 with `pgsql`, `redis`, `sodium`, `intl` and `gd`
- Composer 2
- Node 22
- Docker with Compose

## 1. Start the dev dependencies

```bash
docker compose up -d
```

This starts Postgres 16 on host port **5434** and Redis 7 on **6380** — deliberately non-default
ports so they do not collide with a database you already run. `.env.example` already points there.

## 2. Install and configure

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Discord is the only participant login. For local work you can skip it entirely and use the demo
buttons (next section).

## 3. Migrate, seed, install

```bash
php artisan migrate
php artisan db:seed --class=ScreenshotSeeder
php artisan lanomat:install --admin-discord-id=<your-discord-id>
```

`lanomat:install` is idempotent: it runs migrations and promotes (or creates) the admin for the
given Discord id. The seeder fills an event, teams, tournaments, seats, schedule, polls and files so
the UI is not empty.

## 4. Run the app

```bash
composer run dev
```

That starts the app server, the queue worker and Vite together. Open
<http://localhost:8000/login>. Under `APP_ENV=local` the login page shows the buttons **„Als
Demo-Teilnehmer“** and **„Als Demo-Orga“**; the backing route `POST /dev/login/{role}` 404s in every
other environment.

<div class="live">LIVE 17:42 · local dev · queue worker up</div>

The seeded demo logs you into a dashboard with a next match, a ticket, a seat and the notification
bell — the same screen a participant sees at the venue.

| Dark | Light |
|---|---|
| ![Participant dashboard with next match, ticket and seat, dark](/screens/dashboard-dark.png) | ![Participant dashboard with next match, ticket and seat, light](/screens/dashboard-light.png) |
| `DASHBOARD · DARK` — next match, ticket, bell, seat | `DASHBOARD · LIGHT` — same calm desk |

## 5. Optional: Discord for a real login

1. Create an application in the [Discord Developer Portal](https://discord.com/developers/applications).
2. Add the redirect URI `http://localhost:8000/auth/discord/callback`.
3. Put `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET` and `DISCORD_REDIRECT_URI` into `.env`.
4. For announcements and DMs, add a bot token (`DISCORD_BOT_TOKEN`) and a channel id
   (`DISCORD_ANNOUNCE_CHANNEL_ID`). Without them, announcements are silently skipped.

If you want to restrict login to one guild without running the bot, set `DISCORD_LOGIN_GUILD_ID`.
Admins are exempt; the check is fail-closed.

## 6. Run the docs site

The documentation is a separate Zudoku project in `docs-site/`:

```bash
cd docs-site
npm ci
npm run dev      # http://localhost:3000/docs/
npm run build    # static output in dist/docs/
npm run check:links
```

Screenshots are copied from the app's `docs/screenshots/` by `npm run screenshots`; the copy is
committed so a clean `npm ci && npm run build` needs nothing else.

## 7. Give the agent a model

The pi agent is the LAN installer and recipe reader. It defaults to Venice AI; you can also point it
at a local LLM. The key lives only in the agent process:

```bash
export VENICE_API_KEY="…"
lanomat-agent model setup
lanomat-agent ask "install ut2004 for me on my pc"
```

Read [Models & fuel](/docs/agent/venice) for the default model, the generic `OPENAI_MODEL_*`
variables and the precedence rules.

Pairing happens in the browser, not in the terminal: `lanomat-agent login` prints a code, and you
approve it on `/mcp/connect` while signed in.

| Dark | Light |
|---|---|
| ![MCP device pairing page with the agent code waiting for approval, dark](/screens/mcp-connect-pending-dark.png) | ![MCP device pairing page with the agent code waiting for approval, light](/screens/mcp-connect-pending-light.png) |
| `MCP · DARK` — device code approved in the browser | `MCP · LIGHT` — same code, same scopes |

## 8. Optional: the desktop launcher

The LANoMAT Launcher is the no-terminal path: one download page, one pairing code, one button for
every game the launcher can install — the UT2004 pilot, the hand-verified NFSU2 recipe, browser games
and portable archives that participants share. The server offers the Linux and Windows builds on
`/app` (sidebar entry **App herunterladen**), with size and SHA-256; the token it stores is 0600. Read
[One-click launcher](/docs/guides/launcher) for the download, the pairing and what happens when a
disc image or patch is missing on the LAN. On the server, `scripts/ops/build-launcher.sh` builds and
publishes the Linux build into `clients-dist/launcher`; the Windows exe comes from the
`release-launcher.yml` workflow.

## Where to go next

| If you want to… | Read |
|---|---|
| See what is playable tonight | [Games library](/docs/system/games-library) (`/games`) |
| Understand the module map | [The system](/docs/system/overview) |
| Deploy with Coolify or Traefik | [Deployment](/docs/start/deployment) |
| Install a game through a recipe | [Game recipes](/docs/recipes/overview) |
| Let an agent drive LANoMAT | [MCP server](/docs/mcp/overview) and [pi agent](/docs/agent/overview) |
| Look up a command or env var | [Reference](/docs/reference/commands) |

The games library is what people open first at the venue: every published recipe the lineup allows,
what is on the LAN, and the one-click round. Opening a game lands on the detail page with install,
launch, the live server and the files from the LAN file host. **Spiel teilen** in the page header
opens [`/games/share`](/docs/guides/share-a-game); the server browser at `/servers` lists the servers
of the LAN with their game and the way onto them.

| Dark | Light |
|---|---|
| ![Games library with the LIVE banner, the LAN filter and the install actions, dark](/screens/games-dark.png) | ![The same games library in light](/screens/games-light.png) |
| `GAMES · DARK` — LIVE banner, LAN dots, Installieren | `GAMES · LIGHT` |

| Dark | Light |
|---|---|
| ![UT2004 game detail page with install, live server and LAN files, dark](/screens/game-ut2004-dark.png) | ![UT2004 game detail page with install, live server and LAN files, light](/screens/game-ut2004-light.png) |
| `GAME · DARK` — install, launch, live server, LAN files | `GAME · LIGHT` |

<div class="callout-live">
  <strong>The ten-minute principle.</strong> When you evaluate LANoMAT, do not tour every module.
  Open the dashboard, the event page, the seating grid and the bracket. Those four screens are the
  product; everything else supports them.
</div>
