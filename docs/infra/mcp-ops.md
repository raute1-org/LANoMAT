# MCP server, game recipes and the docs site in production

Operational runbook for the three surfaces added with the agent stack:

- the **MCP server** (`Mcp` module) at `/mcp` and its REST side under `/api/v1`,
- the **game recipes** (`GameRecipes` module) and their source on the LAN share,
- the **docs site** (Zudoku, `docs-site/`) served next to the app under `/docs`.

Design and usage live elsewhere: [`docs-site/pages/mcp/overview.md`](../../docs-site/pages/mcp/overview.md)
and [`docs-site/pages/recipes/smb.md`](../../docs-site/pages/recipes/smb.md). This file is about
running them: endpoints, tokens, the sync cron, the SMB credentials, the docs deployment, the
preflight angle and backups.

The repository is public: every host, address and secret below is a placeholder.

| Placeholder       | Meaning                                                                               |
| ----------------- | ------------------------------------------------------------------------------------- |
| `<LAN_DOMAIN>`    | the name participants open the app with (see [`lan-tls-certificates.md`](lan-tls-certificates.md)) |
| `<APP_SERVER>`    | the host running the `app`/`queue`/`scheduler` containers                            |
| `<NAS_HOST>`      | the machine that exports the recipe share                                             |
| `<SHARE>`         | the SMB share name, e.g. `games-recipes`                                              |
| `<MCP_TOKEN>`     | a paired agent bearer token (`lmcp_…`), never committed                               |
| `<VENICE_KEY>`    | a Venice AI inference key (`VENICE_INFERENCE_KEY_…`), client side only                 |

## What runs where

| Component        | Where                                  | State it keeps                                            |
| ---------------- | -------------------------------------- | --------------------------------------------------------- |
| MCP endpoint     | `app` container, `POST /mcp`           | `mcp_device_codes`, `mcp_tokens` (Postgres)                |
| Agent REST API   | `app` container, `/api/v1/*`           | same database; reads recipes/files                         |
| Recipe catalogue | `app` container + Postgres             | `game_recipes`, `game_recipe_files`, `game_recipe_installs` |
| Recipe source    | bundled in the image, or the NAS share | YAML + `SKILL.md` folders, no database of its own          |
| Recipe catalogue (git) | `app`/`queue` call the GitHub REST API, only with `RECIPES_PR_ENABLED` | `game_recipes.pr_*` (Postgres); the branch and pull request live in the repository |
| Docs site        | `docs` container (compose profile)     | none — static build, rebuilt from the repository           |
| Agent            | participant/orga PC                    | token + Venice key under the user's config dir             |

Nothing about the MCP server is stateful outside Postgres. The docs container never touches the
database and holds no secret.

## Module gating

`Mcp` and `GameRecipes` are optional modules:

```dotenv
MODULES_PROFILE=full      # or keep core and enable them explicitly
MODULE_MCP=true
MODULE_GAME_RECIPES=true
```

- `GameRecipes` **requires** `Files` and `Mcp` (`Module::requires()`): recipes list and plan without
  `Files`, but their LAN files can never be matched; and the launcher pairs through the MCP
  device flow (`api/mcp/device|token`) and its install plans hand out `mcp.files.download` URLs
  (`/app` links to it). `lanomat:modules` fails the boot when a hard requirement is off.
- `Mcp` has no hard requirement, but the tool catalogue silently shrinks when modules are off — for
  example `files_*` tools disappear without `Files`, `recipes_*` without `GameRecipes`.
- After changing the profile, **recreate** the containers (`docker compose --profile prod up -d`), do
  not `restart`: `env_file` is only re-read on create, so queue/scheduler would keep the old profile.
  See the README warning under "Upgrading an existing install".

Check the resolved profile:

```bash
docker compose exec -T app php artisan lanomat:modules
```

`MCP_DEFAULT_MODEL` is only **advertised** to the agent (`lanomat-agent doctor` compares it against
its config); the server never calls a model. Leave it at the Venice default unless the bundled
installer should point at a different provider out of the box.

### Recipe pull requests (off by default)

A recipe a participant shares can travel into the git catalogue as a pull request. The switch block
lives in `config/recipes.php` (`pull_requests`) and is off with any of the three keys missing:

```dotenv
RECIPES_PR_ENABLED=true
RECIPES_PR_REPOSITORY=<GITHUB_OWNER>/<REPOSITORY>
RECIPES_PR_TOKEN=<fine-grained token: Contents + Pull requests, read and write>
# optional: RECIPES_PR_TRIGGER=publish|contribute (default publish), RECIPES_PR_BASE=main,
#           RECIPES_PR_PATH=resources/recipes, RECIPES_PR_BRANCH_PREFIX=recipes/,
#           RECIPES_PR_PER_USER_HOURLY=6, RECIPES_PR_MAX_OPEN=30,
#           RECIPES_PR_ATTRIBUTION=false, RECIPES_PR_API_URL=https://api.github.com
```

With the default `publish`, the pull request is opened (or updated) when the orga publishes; `contribute`
opens it as soon as a participant shares. The automatic path is budgeted (per contributor per hour, and
a ceiling of open pull requests); the panel action and `lanomat:recipes:propose` are not.

What the text must look like is visible before anything is sent: the recipe's panel section
"Katalog (Pull Request)" shows **Textprüfung für den Katalog** (what a draft is missing and what
would still be missing once published) and both publish confirmations warn when the catalogue would
refuse the text. A contribution through `recipe_contribute` can be completed by the assistant that
made it: the tool takes `skill_markdown`, and its answer carries `catalog.problems` and
`catalog.problems_when_published`; repeat the call until `problems` is empty. A second call for the
same slug **replaces** the draft — send the complete recipe, because a field left out is cleared
(the tool description and the `lanomat://skills/contribute-recipe` resource say so). The orga still
reviews and publishes.

The proposal is queued, so the **queue worker must run**; without it a recipe keeps `pr_state = null`, and
a proposal skipped for a budget is visible only as `recipes.pull_request_skipped` in the queue log.
Drafts are bounded separately by `RECIPES_MAX_DRAFTS_PER_USER` (default 20). The full path — token menu,
triggers, budgets, catalogue readiness, branch/pull-request contents, public-safety gate, states,
commands, troubleshooting — is [`recipe-pull-requests.md`](recipe-pull-requests.md).

## Endpoints

| Method | Path                         | Auth              | Expected without a token | Purpose                                              |
| ------ | ---------------------------- | ----------------- | ------------------------ | ---------------------------------------------------- |
| GET    | `/up`                        | none              | `200`                    | app health (container healthcheck)                   |
| POST   | `/mcp`                       | bearer            | `401` + `WWW-Authenticate: Bearer` | streamable-HTTP MCP endpoint                |
| POST   | `/api/mcp/device`            | none, throttled   | `200`                    | start device pairing                                 |
| POST   | `/api/mcp/token`             | none, throttled   | `400` for a bad code     | exchange an approved device code for a token         |
| GET    | `/api/v1/me`                 | bearer            | `401`                    | agent identity check                                 |
| GET    | `/api/v1/recipes`            | bearer            | `401`                    | recipe catalogue (only with `GameRecipes`)           |
| POST   | `/api/v1/recipes`            | bearer            | `401`                    | contribution draft                                   |
| POST   | `/api/v1/recipes/{slug}/plan`| bearer            | `401`                    | install plan for a machine                           |
| GET    | `/api/v1/files`              | bearer            | `401`                    | file host index (only with `Files`)                  |
| GET    | `/api/v1/files/{id}/download`| bearer + throttle | `401`                    | signed download redirect                             |
| GET    | `/mcp/connect`               | browser session   | redirect to login        | approve a code, list and revoke agents               |
| GET    | `/docs/`                     | none              | `200`                    | docs site (docs container)                           |

The MCP endpoint speaks streamable HTTP, so `Accept: application/json, text/event-stream` is part of
the request. A reverse proxy in front of it must pass `Authorization` through unchanged and must not
buffer event-stream responses; Traefik does both. The bearer token is resolved on **every** request,
so a revoked token stops working immediately — there is no session cache to expire.

## Tokens: issue, inspect, revoke

### Device pairing (the normal path)

```bash
# 1. On the participant's machine: ask for a code pair.
curl -s -X POST https://<LAN_DOMAIN>/api/mcp/device \
  -H 'Content-Type: application/json' \
  -d '{"device_name":"orga laptop"}'

# 2. Open verification_uri (/mcp/connect) in the browser, logged in, compare the
#    shown code with the one the agent prints, approve.

# 3. Poll once the human approved.
curl -s -X POST https://<LAN_DOMAIN>/api/mcp/token \
  -H 'Content-Type: application/json' \
  -d '{"device_code":"<device_code>"}'
```

Things that are true by design (security review surface, see `docs-site/pages/mcp/security.md`):

- only **SHA-256 digests** are stored (`mcp_device_codes`, `mcp_tokens`); the plaintext is printed
  once and never recoverable from the database;
- a user code is `XXXX-XXXX` from an alphabet without look-alikes, valid for `MCP_CODE_TTL` seconds
  (default 600), polled no faster than `MCP_POLL_INTERVAL` (default 5 s);
- a device code is single use: approving or denying consumes it;
- `mcp-device`, `mcp-token`, `mcp-approve` and `mcp-api` have their own rate limiters;
- the token itself is `lmcp_` + 48 random alphanumerics, expires after `MCP_TOKEN_TTL_DAYS` (default
  90) and **slides**: at most once every five minutes a successful request pushes `expires_at`
  forward again.

### Headless mint (no browser)

```bash
docker compose exec -T app php artisan lanomat:mcp-token orga@example.org --name="kiosk"
```

The command prints the token once, plus the matching `pi mcp add …` line. It accepts a user id, an
e-mail address or a Discord id. Use it for a kiosk, a CI seed or an orga laptop when the browser flow
is inconvenient; the token behaves exactly like a paired one.

### Inspect and revoke

- UI: `/mcp/connect` lists the caller's active agents ("Verbundene Geräte") and revokes one with the
  delete button. Admins may revoke any agent; a participant only their own (`RevokeMcpToken`).
- Immediate: revocation sets `revoked_at`; `AuthenticateMcpToken` rejects it on the next request.
- Database view, when the UI is not reachable:

  ```bash
  docker compose exec -T postgres psql -U lanomat -d lanomat -c \
    "select id, user_id, name, last_used_at, expires_at, revoked_at from mcp_tokens order by id desc limit 20;"
  ```

- Suspected leak: revoke first, then investigate. The `gitleaks` workflow scans the full history for
  the `lmcp_`, `lvc_` and `VENICE_INFERENCE_KEY_` shapes, so a token pasted into a commit is caught
  on the PR (not retroactively: run the local command from `.gitleaks.toml` if in doubt).
- A restore of an older database **un-revokes** tokens that were revoked after the dump; revoke them
  again. Token plaintext still matches, because the digest is unchanged.

## Recipe sync (scheduled)

`lanomat:recipes:sync` imports recipe folders into the catalogue. The app schedules it daily at
04:15 (`routes/console.php`); the scheduler service must be running. Run it by hand after changing
a recipe on the app host, because a sync that silently stops is worse than a late one.

```bash
docker compose exec -T app php artisan lanomat:recipes:sync --json
```

| Option        | Effect                                                                 |
| ------------- | ---------------------------------------------------------------------- |
| `source`      | override the configured source for this run (directory, `smb://…`, `git+https://…`) |
| `--prune`     | delete rows of this source kind whose slug has no recipe directory any more (never a recipe that still exists, and nothing at all when the source yields no directories) |
| `--only=slug` | update only this recipe; the others still count as present, so `--prune` keeps them |
| `--json`      | machine-readable `{source, created, updated, pruned, skipped[]}`       |

The default source is `config('recipes.source')` = `RECIPES_SOURCE`; without the variable it is the
bundled directory (`resources/recipes` in the image). `--prune` is only safe when the source is
complete — never prune a half-mounted share or a partial checkout. `--only` narrows what is
**updated**, never what counts as present: a recipe that still has a directory in the source is kept
even when it was not selected or its file failed validation, and an empty source prunes nothing.

### What the sync does to a recipe that changed

The YAML is the authority. For every recipe directory the command overwrites the row from the file:
slug, title, aliases, version, platforms, summary, description, homepage, licence, the skill text,
`requirements` and `steps`. It keeps what a file cannot express: the moderation status (a local
`status` that differs from the YAML is not overwritten), the admin's file pins (`shared_file_id`,
keyed by role and label), the row's and file rows' ids, and `in_lineup`, `contributor_user_id` and
the `pr_*` columns, which the parser never produces. That is why a recipe edit on disk (or a new
image with changed recipe text) reaches the panel and the install plans only through this command.

A per-file validation error is a **warning**: the file lands in `skipped[]`, its slug still counts
as present, and every other recipe is imported (exit code `0`). Only a source that cannot be
materialised at all (missing directory, `smbclient` failure, failed clone) throws — then the command
prints the reason, exits non-zero and imports nothing. `--prune` on an unchanged catalogue changes
nothing; the sync is safe to repeat.

### Suggested cron entry

The scheduler already runs the sync at 04:15. The host cron entry is only for an instance whose
`scheduler` service is not running (or to have a second, logged run):

```cron
# Daily at 04:30, after the SMB share is available and before the morning check.
30 4 * * * cd /opt/lanomat && docker compose exec -T app php artisan lanomat:recipes:sync --prune --json >> /var/log/lanomat-recipes.log 2>&1
```

Keep the output (`--json`) in a log: the Preflight and the docs have no recipe-sync probe, so the
log plus the exit code is the only history. Only a source that cannot be materialised at all exits
non-zero and imports nothing; a file that fails validation is a warning in `skipped[]` while the
other recipes are imported.

### Source: local mount vs `smb://`

`smbclient` **is** installed in the app image (`docker/Dockerfile` installs `smbclient` and `git` for
exactly this), so `RECIPES_SOURCE=smb://<NAS_HOST>/<SHARE>/<subdir>` works without any change. The
sync copies the share into a temporary directory with `smbclient -N` (guest) or an
`-A` credentials file, imports it and removes the copy.

Alternative — mount the share on the host and sync from the local path (the mount handles
credentials and caching once; useful when `smbclient` from the container cannot reach the NAS):

```bash
sudo mkdir -p /mnt/<SHARE>
sudo mount -t cifs //<NAS_HOST>/<SHARE> /mnt/<SHARE> \
  -o username=lanomat,uid=$(id -u),gid=$(id -g),vers=3.0,ro
# then, in .env (or the compose override):
# RECIPES_SOURCE=/mnt/<SHARE>/recipes
```

Mount it read-only, and mount it into the `app` container at the same path
(`volumes: - /mnt/<SHARE>:/mnt/<SHARE>:ro`) — the sync runs inside the container, not on the host.

Guest-readable share: leave `RECIPES_SMB_USER` and `RECIPES_SMB_PASSWORD` empty; the sync calls
`smbclient -N`. A password with `%`, spaces or `$` is fine: the arguments are passed escaped, never
through a shell string.

`RECIPES_PREFER_LAN` is read into `config('recipes.prefer_lan')` and **nothing else reads it**
(2026-11-22: `grep` finds the key only in `config/recipes.php`). The plan prefers a LAN match by
itself: a requirement with a matched upload is offered as a LAN file, and only a requirement without
one carries the recipe's external URL — the agent downloads those with `--allow-external`. Do not
expect the switch to change a plan today.

### Verify a sync

```bash
# counts in the log; then with a token:
curl -s https://<LAN_DOMAIN>/api/v1/recipes \
  -H "Authorization: Bearer $LANOMAT_TOKEN" | head -c 400
```

## Docs deployment

The docs are a separate static container, path-routed on the **app host** so `/docs` needs no extra
DNS name. `docs-site/Dockerfile` builds with Zudoku (`basePath: /docs`) and serves the result from
nginx; the SPA fallback lives under `/docs/` only, so a deep link such as
`/docs/recipes/ut2004` returns `200` (prerendered page, then `index.html`).

Two equivalent ways to run it:

```bash
# a) as a compose profile in the repository's stack (recommended on the app host)
docker compose --profile docs up -d docs

# b) standalone, as its own compose project with an external Traefik network
docker compose -f docs-site/deploy/compose.docs.yml up -d --build
```

Traefik routes `Host(<LAN_DOMAIN>) && PathPrefix(/docs)` to it with priority `1000`, above the app's
catch-all. `DOCS_URL=/docs` is the switch the app uses for its docs link; it is deployment-only (no
`config/*.php` entry) and documented in `.env.example`.

Operational notes:

- **Deploy = rebuild.** The container has no state; after a merge to `main` run
  `docker compose --profile docs up -d --build docs`.
- The app's security headers come from Traefik; the docs container repeats its own
  (`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`).
- Screenshots are copied from `docs/screenshots/` into `docs-site/public/screens/`
  (`npm run screenshots` in `docs-site`); the link checker fails on a page that references a missing
  file.
- Health: the image's `HEALTHCHECK` requests `http://127.0.0.1/docs/`; the compose profile does not
  add a Traefik healthcheck, so use the probe below.
- The docs are public to everyone who can reach the host. They contain no event data and no secret;
  if the app is behind the Discord guild gate, remember that `/docs` is **not** gated by it.

Verify after a deploy:

```bash
curl -sI https://<LAN_DOMAIN>/docs/ | head -1                         # 200
curl -sI https://<LAN_DOMAIN>/docs/recipes/ut2004 | head -1           # 200
curl -sI https://<LAN_DOMAIN>/docs | head -1                          # 301 -> /docs/
curl -sI https://<LAN_DOMAIN>/ | head -1                              # 302 -> /docs/
```

## Preflight and health probes

`php artisan lanomat:preflight` runs the tagged checks and prints a traffic-light table; it exits
`1` when any check is `down`. The checks relevant to this stack are the shared ones — there is **no
dedicated MCP or recipe-sync probe** yet:

| Check key              | What it says here                                                        |
| ---------------------- | ------------------------------------------------------------------------ |
| `database`             | `mcp_tokens` / `game_recipes` are readable                               |
| `redis`                | rate limiters and cache                                                 |
| `storage`              | uploads/avatars (recipe avatars, gallery)                                |
| `queue_worker`         | the queue container is alive (MCP follow-up work, notifications)         |
| `scheduler_heartbeat`  | `schedule:work` runs (health-watch, update watch)                        |
| `failed_jobs`          | warn when jobs failed — check after a token/recipe change                |
| `backup_freshness`     | the backup marker is fresh (see below)                                   |
| `dependency_updates`   | pinned images trail upstream (docs site: `nginx` only, no lookup)        |
| `recipe_repository`    | **only while `GameRecipes` is on**: the recipe pull request link (token, repository, `permissions.push`). `skipped` when `RECIPES_PR_ENABLED=false`, `warn` for every failure — never `down` |

`lanomat:health-watch` (scheduled every five minutes) turns a `down` into a one-time in-app
notification for orga/admin accounts (edge-triggered; it does not re-notify for the same outage).

Because no Preflight check covers `/mcp` or `/docs`, probe them directly after every deploy and from
monitoring. `scripts/ops/mcp-smoke.sh` does exactly that:

```bash
# unauthenticated surface only (401s + /up + docs)
scripts/ops/mcp-smoke.sh --base https://<LAN_DOMAIN>

# including the authenticated path; keep the token out of the process list
scripts/ops/mcp-smoke.sh --base https://<LAN_DOMAIN> --token "$(cat /root/.lanomat-mcp-token)"
```

| Probe                          | Expected | If it is not                                         |
| ------------------------------ | -------- | ---------------------------------------------------- |
| `GET /up`                      | `200`    | app/container down, Traefik not routing, TLS          |
| `POST /mcp` without bearer     | `401`    | `404`: module off or wrong profile; `200`: auth middleware missing (security bug); `5xx`: app log |
| `GET /api/v1/me` without bearer| `401`    | `404`: `Mcp` off; `302`: request hit the session guard instead of the token guard |
| `GET /docs/`                   | `200`    | docs container down or Traefik priority wrong (`404` from the app) |
| `GET /api/v1/me` with bearer   | `200`    | token expired/revoked (`401`), user deleted (`401`), wrong host |
| `POST /mcp` with bearer        | `200`    | `401`: token; `406`/`400`: missing `Accept: application/json, text/event-stream` |

A cron entry for the unauthenticated half (no token file in the environment):

```cron
*/15 * * * * /opt/lanomat/scripts/ops/mcp-smoke.sh --base https://<LAN_DOMAIN> >/var/log/lanomat-mcp-smoke.log 2>&1
```

Log check for a failing MCP request:

```bash
docker compose logs --since 15m app queue | grep -i -E 'mcp|recipe'
docker compose exec -T app php artisan about
```

## Backups and restore

`scripts/ops/backup.sh` already covers everything this stack keeps:

- the Postgres dump contains `mcp_tokens`, `mcp_device_codes`, `game_recipes`, `game_recipe_files`,
  `game_recipe_installs`. Token rows are digests only, so a database backup never yields a usable
  token.
- the storage tarball contains recipe avatars and any uploaded content the catalogue points at.
- the docs site has **no** state: rebuild it from the repository.
- the bundled recipes live in the image; a sync from the NAS repopulates anything the dump does not.

Not in the backup, and needed after a disaster: the production `.env` (at least
`RECIPES_SMB_PASSWORD`, `MCP_*`, `DOCS_URL`). Keep it in the same encrypted place as the other
secrets, never in the repository.

After a restore:

```bash
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan lanomat:recipes:sync --json      # if the source is reachable
scripts/ops/mcp-smoke.sh --base https://<LAN_DOMAIN>                    # /up, 401s, /docs
docker compose exec -T app php artisan lanomat:preflight                # full table
```

Notes:

- An agent token keeps working across a restore only if it existed in the dump and was not revoked
  afterwards. When in doubt, revoke and re-pair.
- `lanomat:health-watch` uses a cache flag (`preflight.watch_state`). A restore with an empty cache
  re-notifies once for an ongoing outage; that is expected.
- `BackupFreshnessCheck` reads `.last-success` from the backup directory, not the database; a
  database-only restore does not reset it.

## Periodic work

| Job                          | Where                     | Frequency            | Command / source                                    |
| ---------------------------- | ------------------------- | -------------------- | --------------------------------------------------- |
| Recipe sync                  | scheduler (app)           | daily at 04:15       | `lanomat:recipes:sync` in `routes/console.php`      |
| Recipe pull request status   | scheduler (app)           | hourly               | `lanomat:recipes:pr-status` (quiet while not configured) |
| File hash backfill           | scheduler (app)           | daily at 04:45       | `lanomat:files:hash --limit=20`                     |
| Health watch                 | scheduler (app)           | every 5 minutes      | `lanomat:health-watch` in `routes/console.php`      |
| Dependency update watch      | scheduler (app)           | every 6 hours        | `lanomat:check-updates`                             |
| MCP/docs smoke               | host cron or monitoring   | every 15 minutes     | `scripts/ops/mcp-smoke.sh`                          |
| Docs rebuild                 | deploy step               | per merge to `main`  | `docker compose --profile docs up -d --build docs`  |

The scheduler service must be running for all of the `routes/console.php` entries. `php artisan
schedule:list` prints what the current profile schedules; a `lanomat:recipes:pr-status` runs on an
instance without a catalogue link (it exits `0` immediately).

## Acceptance checklist

- [ ] `php artisan lanomat:modules` shows `Mcp` and `GameRecipes` enabled, `GameRecipes` with `Files`.
- [ ] `scripts/ops/mcp-smoke.sh --base https://<LAN_DOMAIN>` is green.
- [ ] `scripts/ops/mcp-smoke.sh --base https://<LAN_DOMAIN> --token "$(cat <file>)"` is green.
- [ ] A fresh pairing works end to end on a participant machine (`lanomat-agent login`), and the new
      device shows up under `/mcp/connect`.
- [ ] Revoking that device makes the next `/api/v1/me` answer `401` within one request.
- [ ] `lanomat:recipes:sync --json` imports the NAS catalogue; `--only=<slug>` updates one recipe;
      the log has no `skipped[]` entries that point at a real problem.
- [ ] With recipe pull requests on: Preflight **Rezept-Katalog (Pull Requests)** is `ok`;
      `lanomat:recipes:pr-status` prints `N offene Pull Requests geprüft.`; a test proposal appears in
      the repository ([`recipe-pull-requests.md`](recipe-pull-requests.md)).
- [ ] `/docs/` and `/docs/recipes/ut2004` answer `200`; `/docs` answers `301`; `/` answers `302`.
- [ ] `lanomat:preflight` has no `down`; `failed_jobs` and `backup_freshness` are not `warn`.
- [ ] `gitleaks detect` (full history) is clean after the deploy commit.
