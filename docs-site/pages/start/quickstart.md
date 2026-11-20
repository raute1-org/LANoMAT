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

That starts `php artisan serve`, the queue worker and Vite together. Open
<http://localhost:8000/login>. Under `APP_ENV=local` the login page shows **Demo-Teilnehmer** and
**Demo-Orga** buttons; the backing route `POST /dev/login/{role}` 404s in every other environment.

<div class="live">LIVE 17:42 · local dev · queue worker up</div>

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

## Where to go next

| If you want to… | Read |
|---|---|
| See what is playable tonight | [Games library](/docs/system/games-library) (`/games`) |
| Understand the module map | [The system](/docs/system/overview) |
| Deploy with Coolify or Traefik | [Deployment](/docs/start/deployment) |
| Install a game through a recipe | [Game recipes](/docs/recipes/overview) |
| Let an agent drive LANoMAT | [MCP server](/docs/mcp/overview) and [pi agent](/docs/agent/overview) |
| Look up a command or env var | [Reference](/docs/reference/commands) |

<div class="callout-live">
  <strong>The ten-minute principle.</strong> When you evaluate LANoMAT, do not tour every module.
  Open the dashboard, the event page, the seating grid and the bracket. Those four screens are the
  product; everything else supports them.
</div>
