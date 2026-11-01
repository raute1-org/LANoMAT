# Deploy runbook: lan.raute1.org (core profile)

Short runbook for putting LANoMAT live at `https://lan.raute1.org` with
`MODULES_PROFILE=core` (registration, QR tickets, Discord and Steam/Twitch
account linking, notifications). Same app and same database later move to
`MODULES_PROFILE=full` without a data migration (see the last section).

Background: [`docs/traefik-setup.md`](traefik-setup.md) (routing and TLS),
[`docs/prod-test.md`](prod-test.md) (the longer local prod walkthrough).

## 1. DNS and firewall

- A records, both pointing at the server's public IPv4:
  - `lan.raute1.org`
  - `ws.lan.raute1.org` (Reverb websockets)
- Open TCP **80** and **443** on the host. Port 80 is needed for the Let's
  Encrypt HTTP-01 challenge. Do not open 8000 or 8081: they are bound to
  loopback only.
- Check DNS first: `dig +short lan.raute1.org ws.lan.raute1.org`.

## 2. External registrations

- **Discord Developer Portal** (application > OAuth2 > Redirects): add
  `https://lan.raute1.org/auth/discord/callback`. Copy the Client ID and
  Client Secret into `.env` (`DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`).
- **Steam Web API key**: <https://steamcommunity.com/dev/apikey>, domain
  `lan.raute1.org`. Put it in `STEAM_API_KEY`. The Steam entry appears on
  `/settings/connections` once the key is set.

## 3. `.env` on the server

Start from `.env.example` and set at least:

```bash
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # e.g. echo "base64:$(openssl rand -base64 32)"
APP_URL=https://lan.raute1.org
APP_DOMAIN=lan.raute1.org
ACME_EMAIL=<mailbox for Let's Encrypt notices>
MODULES_PROFILE=core
LOG_LEVEL=warning
APP_LOCALE=de                 # UI copy exists only in German; the code default is de as well

# Inside the compose network, not 127.0.0.1
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=lanomat
DB_USERNAME=lanomat
DB_PASSWORD=<strong password>
POSTGRES_PASSWORD=<same strong password>   # read by compose's postgres service
REDIS_HOST=redis
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_LIMITER_STORE=database   # rate limiters (login, check-in) stay off Redis, so a Redis outage cannot break login

DISCORD_CLIENT_ID=...
DISCORD_CLIENT_SECRET=...
DISCORD_REDIRECT_URI=https://lan.raute1.org/auth/discord/callback
DISCORD_LOGIN_GUILD_ID=<server ID, optional, see section 3a>

STEAM_API_KEY=...
STEAM_REDIRECT_URI=https://lan.raute1.org/settings/connections/steam/callback
STEAM_ALLOWED_HOSTS=lan.raute1.org

REVERB_APP_ID=<random>
REVERB_APP_KEY=<random>
REVERB_APP_SECRET=<random>
REVERB_HOST=ws.lan.raute1.org
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=lan.raute1.org   # host name only, no scheme: Reverb compares the host of the Origin header
```

Notes:

- `DB_PASSWORD` and `POSTGRES_PASSWORD` must be identical. Postgres reads
  `POSTGRES_PASSWORD` only on the first start, when `pgdata` is empty. Set it
  before the first `up`. Changing it later means `ALTER USER` inside the
  container.
- Leave the Mumble, TeamSpeak, Pelican and Music Assistant keys empty.
  Their modules are off in `core`.
- Compose reads `.env` for interpolation (`APP_DOMAIN`, `ACME_EMAIL`,
  `POSTGRES_*`), and the containers read it through `env_file`. Recreate
  containers after any change (`up -d`). `restart` does not re-read it.

## 3a. Discord login gate (optional)

Set `DISCORD_LOGIN_GUILD_ID` to restrict login to members of one Discord server.
Empty (the default) means anyone with a Discord account can log in.

- **Get the server ID:** in Discord, open *User Settings > Advanced* and enable
  *Developer Mode*. Then right-click the server icon and choose *Copy Server ID*.
- **No bot is needed for this check.** The app asks Discord, on the user's own
  OAuth token, whether that user is a member of the server. This adds the
  `guilds.members.read` scope to the login request. Nothing else changes in the
  Discord application setup.
- **Behaviour:** the check runs on every login. Users who are not members (or
  whose Discord account is not in the server) get a message and no session. If
  Discord cannot answer (outage, rate limit, timeout), login is denied with a
  "try again" message. It never falls through to allowing the user in.
- **Admins are exempt** (an existing user with role `admin`, matched by Discord
  ID), so the operator cannot be locked out.
- Sessions that are already active are not revoked when someone leaves the
  server. They are blocked at their next login.

### Timezone

Timestamps are stored in UTC (`app.timezone`, never change it on a live database). Everything a person reads or types uses one display zone, `APP_DISPLAY_TIMEZONE` (default `Europe/Berlin`, `config('lanomat.timezone')`): arrival days (`EventDays`), Filament date-time inputs/columns (`FilamentTimezone`), notification times, and the Vue pages (shared Inertia prop `displayTimezone`, formatter `resources/js/lib/datetime.ts`). Do not call `toLocaleString()` without `timeZone` in new UI; use `formatInstant()`.

## 3b. Discord bot features (optional, checklist)

The login gate in 3a needs no bot. Everything below does: announcements,
reminders, per-match channels, DMs, slash commands and voice presence. All of it
was exercised live against a Discord test server (bot, gateway sidecar,
`/help`, announcement, 24 h reminder with de-duplication, match text and voice
channel with cleanup, DM). It is **off in production** until the owner decides to
switch it on. Placeholders only; never commit real values.

**1. Decide what the bot may post on its own.** With the module on, the bot
posts automatically: a "registration open" message and a 24 h and 1 h reminder
in the announcement channel; per match a private text channel (roster only) and
a voice room for each team, visible to everyone but joinable only by that team
and the admins; DMs to users for the notification categories they enabled; slash
command answers. Every event gets a Discord category of its own for its match
channels. A while after a match, its text channel moves into the category
"Archiv: <event>" and turns read-only, so the history of every LAN stays on the
server; the team rooms are deleted once nobody is in them. Discord holds 50
channels per category, so a busy event gets numbered follow-up categories. If
any of that is unwanted, leave the matching setting empty: no
`DISCORD_ANNOUNCE_CHANNEL_ID` means no announcements or reminders. Setting
`DISCORD_MATCH_CATEGORY_ID` forces all match channels into that one category
instead of one per event (the archive stays per event).

**2. Developer Portal.**

- Bot tab: enable the _Server Members Intent_ (the gateway sidecar needs it).
  The Message Content intent is not used.
- Invite the bot to the real server with these permissions: View Channels,
  Manage Channels, Manage Roles (for the per-match overwrites), Send Messages,
  Embed Links, Connect.
- Leave the _Interactions Endpoint URL_ **empty**. Slash commands arrive through
  the gateway sidecar; the HTTP endpoint stays a dormant fallback.
- The bot's own user id equals the Application ID.

**3. `.env` on the server** (the token is a secret: write it into the file
yourself, never into chat, commits or screenshots):

```bash
MODULE_DISCORD=true
DISCORD_BOT_TOKEN=<bot token>
DISCORD_GUILD_ID=<server ID>
DISCORD_APPLICATION_ID=<application ID>   # required: match channels fail without it
DISCORD_PUBLIC_KEY=<public key>           # only for the HTTP fallback
DISCORD_ANNOUNCE_CHANNEL_ID=<channel ID>  # empty = no announcements or reminders
DISCORD_MATCH_CATEGORY_ID=                # empty = one category per event (recommended)
DISCORD_GATEWAY_BRIDGE_SECRET=<random>    # e.g. openssl rand -hex 32
DISCORD_GATEWAY_INGRESS_URL=http://app/internal/discord/gateway
```

**4. Start.** `deploy.sh` does not start the sidecar. Run the deploy as usual
first (it recreates the app), then start exactly one gateway session:

```bash
cd /opt/lanomat && ./scripts/deploy/deploy.sh
docker compose --profile prod up -d --build discord-gateway
docker compose --profile prod exec app php artisan discord:register-commands
```

Global slash commands can take up to an hour to appear. Never run a second
sidecar with the same token (for example a laptop test): Discord allows one
session per token.

**5. Verify on the real server before the event:**

- [ ] Sidecar log says it is logged in; `lanomat:preflight` is green.
- [ ] `/help` answers in the channel.
- [ ] Join a voice channel: `/discord/voice` shows the count, with no names for
      unlinked users.
- [ ] Move a test event to _Registration_: exactly one announcement appears.
- [ ] A test match creates `match-N` and `Match N`, visible only to its roster,
      and both vanish after the match.
- [ ] A DM reaches an enabled user; a user who blocks DMs from server members
      does not break anything else.

**Cautions.** The match channel is created once per match (outbox guard). If
someone deletes it by hand, a repeated `MatchReady` does not recreate it. Users
who left the server are skipped in the channel overwrites, they just cannot see
it. Rollback: set `MODULE_DISCORD=false`, `docker compose --profile prod stop
discord-gateway`, recreate the app (`up -d`), and reset the token in the portal
if it was ever exposed.

## 4. Start the stack

```bash
docker compose --profile prod up -d traefik app queue scheduler reverb-prod postgres redis
```

This starts only what `core` needs. Discord gateway, TeamSpeak, Music
Assistant and Mumble stay down. Do not run a bare `docker compose --profile
prod up -d` on this host: it also starts Music Assistant (host networking) and
the voice servers.

Check health: `docker compose --profile prod ps`. `app` should be `healthy`.
`queue` and `scheduler` have no healthcheck by design.

### Updating later

On the host: `/opt/lanomat/scripts/deploy/deploy.sh` (or via
`ssh <ssh-user>@<APP_SERVER> /opt/lanomat/scripts/deploy/deploy.sh`; the real host and SSH user are kept out of this public repo). It pulls
`origin/main` fast-forward only, rebuilds with `build --pull` (fresh FrankenPHP/Node base images, so PHP and Node patch releases are picked up on every deploy), recreates the core services,
waits for `app` to be healthy and runs `migrate --force`. It never touches
volumes or `.env`.

**Every application service builds its own image.** `app`, `queue`, `scheduler` and `reverb-prod` each have a
`build:` section for the same Dockerfile, so a deploy must build and recreate ALL of them, never just `app`:

```bash
docker compose --profile prod build app queue scheduler reverb-prod
docker compose --profile prod up -d app queue scheduler reverb-prod
```

A partial build leaves the queue worker and the scheduler on old code (failed jobs after a deploy are the
symptom). `deploy.sh` does the right thing and also exports `APP_VERSION` / `APP_COMMIT` for the build; if you
build by hand, `source scripts/ops/lib/version.sh` and export `APP_VERSION="$(lanomat_version .)"` and
`APP_COMMIT="$(lanomat_commit .)"` first, otherwise the images report `dev`.

**Native voice client download.** The Desktop-App is offered inside LANoMAT (`/talk/client`), always in the
version that matches the server. After pulling and BEFORE `up -d`, build it (a few minutes with a warm cache;
needs the docker images of `clients/voice`, see its README):

```bash
scripts/ops/build-client.sh        # refuses a dirty tree; publishes ./clients-dist (mounted read-only)
```

`./clients-dist` is mounted into `app` and `scheduler`. Build before the first `up`, because Docker creates a
missing bind-mount directory as root. Only Linux x86_64 is built. The Preflight check `client_build` warns
("Die Orga muss den Client neu bauen") whenever the published client does not match the deployed version, so
run the build script after every deploy that changes the version.

**One-time after the deploy that introduces local avatars.** LANoMAT now keeps its own copy of every Discord
avatar (nothing is loaded from the Discord CDN by browsers or the desktop client any more). New logins fetch
theirs in the background, and the scheduler refreshes active users daily, but the people who are already in
the database have no copy yet (they see monograms until then). Fill them once, after the migration, from a
machine that can reach the internet:

```bash
docker compose --profile prod exec app php artisan lanomat:refresh-avatars --all
```

It fetches slowly on purpose (a short pause between users) and never fails as a whole because one avatar
fails; run it again to retry the failures. Files land on the private disk (`storage/app/private/avatars`),
which is part of the storage volume.

## 5. Migrate and create the admin

```bash
docker compose --profile prod exec app php artisan lanomat:install --admin-discord-id=<your Discord user id>
```

This runs `migrate --force` and promotes or creates that account as admin.

## 6. Create the event

1. Open <https://lan.raute1.org>, log in with Discord.
2. Open <https://lan.raute1.org/admin>, go to Events, and create the event:
   name, date, `max_participants`, and the ticket settings (`settings.tickets`).
3. Advance the status with the header actions: **Draft → Announced →
   Registration**. The public form `/events/{slug}/register` only opens in
   Registration.

## 7. Test checklist

- [ ] `https://lan.raute1.org` shows a valid certificate (not Traefik's
      self-signed default).
- [ ] Discord login completes end to end, back to `lan.raute1.org`.
- [ ] A second account (not admin) registers for the event and gets a QR
      ticket.
- [ ] `/settings/connections`: the Steam entry links, and the link is stored.
- [ ] Avatar upload works. Its URL starts with `https://`. (Mixed content or
      `http://` URLs mean the proxy headers are not reaching the app.)
- [ ] Browser devtools show a `wss://ws.lan.raute1.org` connection established.
- [ ] Notifications arrive. `docker compose --profile prod logs -f queue`
      shows the job being processed.
- [ ] Persistence: `docker compose --profile prod down` then `up -d` (no `-v`).
      The avatar still loads and the registration is still there.
- [ ] `docker compose --profile prod exec app php artisan lanomat:preflight`
      shows no red rows for the modules that are enabled.

## 8. Backups

`scripts/ops/backup.sh` creates the nightly backup. It runs with
`set -euo pipefail`, dumps Postgres (`pg_dump` via
`docker compose --profile prod exec -T postgres`) and the storage volume
(`lanomat_app_storage`) into a temp directory, verifies both archives
(the database dump by `gzip -t` and a minimum size of 1 KiB; the storage
archive by `gzip -t` plus a readable `tar tzf` listing, with no minimum size
because an empty uploads volume is valid), then moves them atomically into
`/var/backups/lanomat` (override with `BACKUP_DIR`). Files older than 14 days
are deleted. A failed run exits non-zero (1 database, 2 storage, 3 setup),
publishes nothing and leaves `.last-success` untouched. Output has UTC
timestamps.

Install the cron job once, as root, from the project directory:

```bash
cd /opt/lanomat && sudo scripts/ops/install-backup-cron.sh /opt/lanomat
```

This writes `/etc/cron.d/lanomat-backup` with:

```cron
SHELL=/bin/bash
0 3 * * * root cd /opt/lanomat && ./scripts/ops/backup.sh >> /var/log/lanomat-backup.log 2>&1
```

After a fully successful run the script writes a Unix timestamp to
`/var/backups/lanomat/.last-success`. The `app` service mounts
`/var/backups/lanomat` read-only (compose.yml), and the Preflight check
"Backup" reads the marker (`BACKUP_MARKER_PATH`, default
`/var/backups/lanomat/.last-success`): OK under 26 h, warning above, outage
above 50 h. A missing marker is a warning in production.

Restore, in short:

1. Stop the writers: `docker compose --profile prod stop app queue scheduler reverb-prod` (adjust to your service list).
2. Database: `gunzip -c db-<stamp>.sql.gz | docker compose --profile prod exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'` into an empty database (drop and recreate it first).
3. Uploads: `docker run --rm -v lanomat_app_storage:/data -v /var/backups/lanomat:/backup:ro alpine tar xzf /backup/storage-<stamp>.tgz -C /data`
4. Start the services again and run `php artisan lanomat:preflight`.
5. Test a restore before you need it: `scripts/ops/restore-drill.sh` restores the newest backup into a throwaway Postgres on the server itself (read-only against production, no data leaves the host) and verifies it. The full emergency procedure (rollback, DB restore, uploads, server loss, what is not backed up, `APP_KEY` warning) is in [`docs/ops/restore-runbook.md`](ops/restore-runbook.md).

The backups must be copied off the host regularly. A backup that stays on the
same disk does not count. The repo does not set up an off-host target; choose
one yourself.

## Security headers

Traefik's `secure-headers` middleware (`docker/traefik/dynamic.yml`) sets HSTS,
frame deny, nosniff, `Referrer-Policy: strict-origin-when-cross-origin` and a
restrictive `Permissions-Policy` (camera and microphone only for the same
origin, used by the check-in camera scanner and the web voice chat; geolocation,
payment and USB denied). The image sets `expose_php = 0`, so `X-Powered-By` is not sent.

## Request size limits

A request body is capped per route class, so one logged-in user cannot push
multi-gigabyte bodies at an arbitrary route. Two layers enforce the same numbers
(`config/lanomat.php`, key `limits`):

| Route class | Cap | Paths |
|---|---|---|
| Everything else (default) | 10 MiB | all routes, including team logo updates (2 MB rule) |
| Gallery upload | 100 MiB | `POST /events/{slug}/gallery` (25 MB per photo) |
| Shared files | 210 MiB | `POST /events/{slug}/files` (200 MB file cap plus multipart overhead) |
| Livewire temporary uploads | 1032 MiB | `POST /livewire-<hash>/upload-file` (every Filament FileUpload, e.g. voice installers, 1 GB rule) |

1. Traefik (primary): `buffering` middlewares `body-limit-*` in
   `docker/traefik/dynamic.yml`, attached through the `app` router (default) and
   three higher-priority routers (`app-gallery`, `app-files`, `app-upload`) in
   the `app` labels of `compose.yml`. Bodies are buffered (1 MiB in memory, the
   rest on disk), so an oversized body is refused with 413 even when it declares
   no `Content-Length`. The Reverb router is untouched.
2. App (second layer for dev, tests and any path that bypasses Traefik):
   `LimitRequestBodySize` checks the declared `Content-Length` and answers 413
   with a German error page. It cannot bound a chunked body without a length;
   that is Traefik's job.

`docker/frankenphp/php.ini` keeps `upload_max_filesize`/`post_max_size` at 1G
because PHP cannot vary them per route; they are only the global ceiling for the
largest class. To change a cap, change `config/lanomat.php` and the matching
`maxRequestBodyBytes` in `dynamic.yml` together (a Pest test fails when they
drift). `deploy.sh` recreates Traefik when `docker/traefik` or `compose.yml`
changes since the last deploy (it remembers the deployed commit in
`.git/lanomat-deployed-head`), so a deploy picks the new caps up. Always run
`deploy.sh` itself; do not `git pull` first. A new middleware in `dynamic.yml` that the
running Traefik does not know makes it drop every router using it, and the site
answers 404 until Traefik is recreated
(`docker compose --profile prod up -d --force-recreate --no-deps traefik`).

Team logos are additionally re-encoded without metadata (EXIF/GPS) and capped
to 512 px on the longest edge (`config/teams.php`).

### Content Security Policy (report-only by default)

The Laravel app sends the policy itself (`App\Http\Middleware\AddContentSecurityPolicy`,
built by `App\Support\Security\ContentSecurityPolicy`), controlled by one env
flag:

| `CSP_MODE` | Effect |
|---|---|
| `report-only` (default) | sends `Content-Security-Policy-Report-Only`; nothing is ever blocked |
| `enforce` | sends the enforcing `Content-Security-Policy` header |
| `off` | sends no CSP header |

Any other value behaves like `report-only`. The change takes effect after
`php artisan config:clear` (or a container restart with a cached config).

What the policy allows:

- **Participant, beamer and overlay pages** (Inertia/Vue): `script-src 'self'`
  plus a fresh per-request nonce (Laravel's `Vite::useCspNonce()` stamps `@vite`
  tags, the inline scripts in `app.blade.php` carry it too) and
  `'wasm-unsafe-eval'` for the self-hosted ZXing decoder of the check-in camera
  scanner. No `'unsafe-eval'`, no `'unsafe-inline'` for scripts.
- **Admin panel** (`/admin`, Filament): the same, plus `'unsafe-eval'`.
  Alpine compiles its attribute expressions with `new Function`, and Filament's
  own documentation says `script-src` still needs it there (Livewire's CSP-safe
  build is not supported by Filament). Filament panels do not run the `web`
  middleware group, so the middleware is registered on the panel separately.
- `style-src 'self' 'unsafe-inline'` everywhere (Filament documents that a strict
  `style-src` is unsupported; the Vue UI binds `style` attributes).
- `img-src 'self' data: blob: https:` (Discord avatars), `font-src 'self'`,
  `connect-src 'self'` plus the Reverb `ws(s)://host:port` from
  `REVERB_HOST/PORT/SCHEME`, `object-src 'none'`, `base-uri 'self'`,
  `frame-ancestors 'none'`, `form-action 'self'` plus the Discord, Steam and
  Twitch hosts (`config/security.php`).
- In non-production, the Vite dev server origin from `public/hot` is allowed.

Violations are POSTed by the browser to `POST /csp-report` (no session, no CSRF,
no login, 8 KB limit, 60 requests/minute per IP, both `report-uri` and Reporting
API formats) and logged as one line each to `storage/logs/csp.log` (daily
rotation):

```
csp-violation directive=script-src-elem blocked=https://evil.example/x.js document=/events/spring source=/build/app.js line=12 disposition=report
```

Query strings and fragments are stripped and no IP, user agent or cookie is
logged. What to watch: `docker compose exec app tail -f storage/logs/csp.log`
while clicking through the participant pages, `/screen/{event}`, the OBS overlays
and `/admin`. Expected, ignorable noise: browser extensions
(`blocked=chrome-extension` / `moz-extension` / `inline` from a page you did not
write). Anything with a first-party `source=` or a CDN host is a real finding:
fix the asset (self-host it, add the nonce) or extend the policy deliberately.

**Switching to enforce:** run report-only through at least one full event
rehearsal (all pages above, check-in scanner, Discord login round trip, Steam and
Twitch linking, an upload), confirm the log has no first-party entries, set
`CSP_MODE=enforce`, reload config, and re-test the same paths. To back out, set
`CSP_MODE=report-only` again. Known caveat: the OAuth login redirects
(Discord/Steam/Twitch) are plain navigations or redirects after a form POST; if
Chrome reports `form-action` violations for a redirect target, add that host to
`security.csp.form_action_hosts`.

## 9. Later: switch to the full profile

When the full LANoMAT features are needed (Discord bot ingress and
interactions, Jukebox, Gallery, and so on):

1. Set `MODULES_PROFILE=full` in `.env`, or enable single modules with
   `MODULE_<KEY>=true`.
2. Run `docker compose --profile prod up -d <the same service list as in section 4>`.
   Add `discord-gateway` if the Discord bot is needed. The containers must be
   recreated to pick up the new env.
3. Run `docker compose --profile prod exec app php artisan migrate --force`
   for any new migrations.

Users, registrations and linked accounts stay in the same database.

## Troubleshooting

- **Hairpin NAT:** the `app` and `queue` containers reach Reverb through its
  public name `ws.lan.raute1.org`, via Traefik. The host must therefore resolve
  and reach its own public IP, which many home and LAN routers do not allow. If
  broadcasts fail from the server side, check that first. The fix is a
  split-horizon DNS entry on the host or router, or an internal `REVERB_HOST`
  for server-side use (browsers keep the public name).
- **`LOG_LEVEL=warning`** keeps production logs quiet. `debug` writes request
  payload details and is only for short debugging sessions.

## Operational cautions

- `docker compose --profile prod down -v` deletes the database **and** the
  uploads volume. Do not run it on this host unless you want a clean slate.
- Plain `restart` keeps the old environment. Use `up -d` after changing `.env`.
- All prod services use `restart: unless-stopped`, so they come back after a
  host reboot.

## On-site self check-in

Participants can check in with their phone via one printed QR code per event. How orga prints, rotates and what the rules are: [self-checkin.md](self-checkin.md).
