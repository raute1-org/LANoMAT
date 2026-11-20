---
title: Commands
description: Artisan commands for installation, operations, content and provisioning.
sidebar_icon: terminal
---

All commands run inside the app container in production:

```bash
docker compose --profile prod exec app php artisan <command>
```

In development, `php artisan <command>` from the repository root.

## Install and inspect

| Command | What it does |
|---|---|
| `lanomat:install --admin-discord-id=<id> [--admin-name=Admin]` | Migrate and promote/create the admin. Idempotent. |
| `lanomat:modules [--json]` | Print the resolved module profile and every enabled module. |
| `lanomat:preflight` | Run the health-check registry and print the traffic light. |
| `lanomat:heartbeat` | Write the queue heartbeat used by Preflight. |
| `lanomat:health-watch` | Evaluate checks and notify orga on a transition. |

## Scheduler jobs

These are registered in `routes/console.php` and run via `php artisan schedule:work` (the
`scheduler` service in production). They are safe to run manually.

| Command | Cadence | Purpose |
|---|---|---|
| `lanomat:send-reminders` | every 5 min | Event reminders; no-op without a Discord announce channel |
| `lanomat:send-schedule-reminders` | every minute | "starting soon" and change alarms |
| `lanomat:sweep-discord-outbox` | every 5 min | Retry undelivered Discord messages |
| `lanomat:tournament-tick` | every minute | Tournament state housekeeping |
| `lanomat:prune-lfg` | every 5 min | Close expired LFG posts |
| `lanomat:jukebox-tick` | every minute | Poll Music Assistant and advance the queue |
| `lanomat:sweep-file-uploads` | every 10 min | Release abandoned tus reservations |
| `lanomat:check-updates` | every 6 h | Compare pinned image digests/tags; warn on updates |
| `lanomat:refresh-avatars [--all] [--user=<id>] [--pause=150]` | daily 04:30 | Refresh local avatars |
| `presence:refresh-locations` | every 30 s (live only) | Rebuild the "who is where" snapshot |
| `webvoice:prune` | daily | Remove old web-voice data |
| `gameservers:refresh-queries` | every minute | Query running game servers |
| `gameservers:refresh-permanent` | every minute | Reconcile permanent servers |

## Discord

| Command | What it does |
|---|---|
| `discord:register-commands` | Register the slash commands with Discord |
| `gameservers:pelican-check [--lifecycle] [--egg=] [--node=] [--wait=180]` | Verify the Pelican API; `--lifecycle` creates/starts/stops/deletes a throwaway server |
| `gameservers:import-eggs [--check]` | Import or verify the repository eggs on the panel |

## Provisioning

All provisioning commands take `--as=<user-id>` when run from the CLI without a session.

| Command | What it does |
|---|---|
| `provisioning:register-hypervisor <name> <base_url> <token_id> [--node=pve] [--tls-pin=] [--jump-host=]` | Store an encrypted Proxmox token |
| `provisioning:probe-hypervisor <hypervisor>` | Check the API and the host keys |
| `provisioning:plan <plan> [--config=] [--hypervisor=]` | Dry run: print every step |
| `provisioning:run <plan> [--config=] [--hypervisor=] --confirm [--rollback] [--sync]` | Execute; without `--confirm` nothing happens |

## Content and recipes

| Command | What it does |
|---|---|
| `lanomat:recipes:sync [source] [--prune] [--only=<slug>] [--json]` | Import recipes from the bundled directory, `smb://host/share` or `git+https://…` |
| `lanomat:recipes:export <path> [--all]` | Write the catalogue as portable skill directories (`SKILL.md` + `recipe.json`) |
| `lanomat:mcp-token <user> [--name=CLI]` | Mint an MCP bearer token (id, e-mail or Discord id); printed once |
| `node resources/recipes/schema/validate.mjs` | Validate every recipe against `lanomat.recipe/v1` |
| `npm run screenshots` (in `docs-site/`) | Copy the app's screenshots into the docs |
| `npm run check:links` (in `docs-site/`) | Fail on broken internal doc links |

## Deploy

| Command | What it does |
|---|---|
| `scripts/deploy/deploy.sh` | Build all four app services, export version, migrate, restart |
| `scripts/ops/build-client.sh` | Build the native voice client for Linux into `clients-dist/` |
| `scripts/ops/backup.sh` | Database + storage backup; writes the Preflight marker |
| `docker compose --profile prod up -d` | Start the production stack |

<div class="callout-live">
  <strong>Build all four.</strong> A deploy that builds only <code>app</code> leaves queue,
  scheduler and reverb on an old image and breaks the desktop-client version match.
</div>
