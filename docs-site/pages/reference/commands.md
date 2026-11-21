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
| `lanomat:check-updates` | Compare pinned image digests/tags; warn on updates. |
| `lanomat:heartbeat` | Write the queue heartbeat used by Preflight. |
| `lanomat:health-watch` | Evaluate checks and notify orga on a transition. |

`lanomat:preflight` prints the same registry the admin tile renders. `lanomat:check-updates` and
`lanomat:heartbeat` feed it; a failing row names the subsystem and the next step.

| Dark | Light |
|---|---|
| ![Admin dashboard with the Preflight-Ampel, database OK and Redis down, dark](/screens/admin-dark.png) | ![Admin dashboard with the Preflight-Ampel, database OK and Redis down, light](/screens/admin-light.png) |
| `ADMIN · PREFLIGHT-AMPEL · DB OK · REDIS DOWN` | `ADMIN · LIGHT` |

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
| `lanomat:refresh-avatars [--all] [--user=<id>] [--pause=150]` | daily 04:30 | Refresh local avatars |
| `lanomat:recipes:sync [source] [--prune] [--only=<slug>] [--json]` | daily 04:15 | Import recipes from the bundled directory, SMB share or git; updates files in place |
| `lanomat:recipes:pr-status [--json]` | hourly | Sync the state of open recipe pull requests (quiet when pull requests are off) |
| `lanomat:files:hash [--event=<id or slug>] [--limit=20] [--force] [--dry-run]` | daily 04:45 | Backfill the SHA-256 of stored files that arrived without one |
| `presence:refresh-locations` | every minute | Rebuild the "who is where" snapshot |
| `webvoice:prune` | daily | Remove old web-voice data |
| `gameservers:refresh-queries` | every minute | Query running game servers |
| `gameservers:refresh-permanent` | every minute | Reconcile permanent servers |

The reminder jobs end up in the participant's bell: event reminders, "starting soon" and change
alarms are notification categories people can mute per type. The panel below is the empty state on a
dashboard with no event running.

| Dark | Light |
|---|---|
| ![Dashboard with the notification bell open and no unread notifications, dark](/screens/notifications-dark.png) | ![Dashboard with the notification bell open and no unread notifications, light](/screens/notifications-light.png) |
| `DASHBOARD · BELL · NO UNREAD` | `DASHBOARD · LIGHT` |

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
| `provisioning:run <plan> [--config=] [--hypervisor=] --confirm` | Execute; without `--confirm` nothing happens |

## Content and recipes

| Command | What it does |
|---|---|
| `lanomat:recipes:sync [source] [--prune] [--only=<slug>] [--json]` | Import recipes. `--only` narrows what is **updated**, never what counts as present; `--prune` removes recipes of this source whose directory vanished (and nothing when the source yields no recipe at all) |
| `lanomat:recipes:export <path> [--all] [--format=skills or repository]` | Write the catalogue to disk. `skills` (default) writes Agent Skill folders (`SKILL.md` + `recipe.json`); `repository` writes the catalogue's own layout (`recipe.yaml` + `SKILL.md`) for a directory that becomes a pull request. Drafts only with `--all` |
| `lanomat:recipes:propose <slug>` | Open or update the one pull request that carries a recipe into the git catalogue, in the foreground; prints the state and either the PR number/URL or the reason it was not sent. Not subject to the automatic per-contributor or open-pull-request limits |
| `lanomat:recipes:pr-status [--json]` | Ask the repository how the pull requests LANoMAT believes are open are doing and store the answer; one failing request is reported and skipped. Quiet while pull requests are not configured |
| `node resources/recipes/schema/validate.mjs [dir]` | Validate recipes against `lanomat.recipe/v1` (the bundled catalogue, or an exported set) |
| `npm run screenshots` (in `docs-site/`) | Copy the app's screenshots into the docs |
| `npm run check:links` (in `docs-site/`) | Fail on broken internal doc links |

`lanomat:recipes:propose` needs `RECIPES_PR_ENABLED`, `RECIPES_PR_REPOSITORY` and `RECIPES_PR_TOKEN`;
without them it prints the "not activated" line and exits non-zero. The automatic path opens or
updates pull requests on the orga's publish by default (`RECIPES_PR_TRIGGER=publish`); `contribute`
is the opt-in for proposing right after a share or draft edit. A recipe is only proposed when it is a
contributed or panel recipe (or already has a pull request) and is not archived. A recipe the
readiness or public-safety check refused exits non-zero with `Nicht gesendet: <reason>`, and the
reason is also on the recipe's **Katalog (Pull Request)** section in the panel. A recipe that already
has an open pull request keeps it: the command prints `Pull Request offen (#…): …` and then warns
`Der Pull Request bleibt …, wurde aber nicht neu gesendet: <reason>` when this run could not update
it. The command is not subject to `RECIPES_PR_PER_USER_HOURLY` or `RECIPES_PR_MAX_OPEN`. See
[Recipe pull requests](/docs/system/modules/gamerecipes#pull-requests-into-the-git-catalogue).

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
