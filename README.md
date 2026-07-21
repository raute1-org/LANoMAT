# LANoMAT v2

A modular LAN party management tool: event registration/tickets, seating, tournaments with
brackets, schedule, catering, voting, LFG, infoscreens, Discord integration, Mumble voice,
and game servers via Pelican. A Laravel monolith, rebuilt from the previous Nuxt/NestJS
system.

See [`docs/architecture.md`](docs/architecture.md) for the module convention and data model,
and [`docs/superpowers/specs/2026-07-13-lanomat-v2-rebuild-design.md`](docs/superpowers/specs/2026-07-13-lanomat-v2-rebuild-design.md)
for the full design rationale.

## Requirements

- PHP 8.4 with the `pgsql`, `redis`, `sodium`, `intl`, and `gd` extensions (`gd` is
  required since M12 for the Gallery module's EXIF-strip/thumbnail pipeline; already
  installed in `docker/Dockerfile` — a pre-M12 local app image needs a rebuild)
- Composer 2
- Node 22
- Docker (for the local Postgres/Redis stack)

## Setup

1. **Start the dev stack** (Postgres 16 + Redis 7):

   ```bash
   docker compose up -d
   ```

   This exposes Postgres on host port **5434** and Redis on host port **6380** — deliberate
   non-default ports, since 5432/5433 and 6379 are commonly already in use locally. `.env.example`
   is already configured to match.

2. **Install dependencies:**

   ```bash
   composer install
   npm install
   ```

3. **Configure the environment:**

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Then set up Discord OAuth (the only login method — there is no password login for
   participants):

   1. Go to the [Discord Developer Portal](https://discord.com/developers/applications) and
      create a new application.
   2. Under **OAuth2 → General**, add a redirect URI:
      `http://localhost:8000/auth/discord/callback`
   3. Copy the **Client ID** and **Client Secret** into your `.env`:

      ```
      DISCORD_CLIENT_ID=...
      DISCORD_CLIENT_SECRET=...
      DISCORD_REDIRECT_URI=http://localhost:8000/auth/discord/callback
      ```

   4. **Optional, for Discord announcements/DMs (since M2):** create a bot user under
      **Bot** in the same application, copy its token into `DISCORD_BOT_TOKEN`, and invite
      the bot to your server with permission to view/send messages in the channel you want
      announcements in. Set `DISCORD_ANNOUNCE_CHANNEL_ID` to that channel's ID (right-click
      the channel in Discord with Developer Mode enabled → "Copy Channel ID"). Without a
      bot token/announce channel configured, registration-open announcements and event
      reminders are silently skipped (`AnnounceRegistrationOpen` and
      `lanomat:send-reminders` both no-op when `services.discord.announce_channel_id` is
      blank) — everything else still works.

4. **Run migrations and create the first admin** (replace `<id>` with your Discord user ID;
   log in once via Discord first, or let the command create the user record):

   ```bash
   php artisan lanomat:install --admin-discord-id=<id>
   ```

   This is idempotent — running it again re-promotes the same user without creating
   duplicates.

5. **Start the dev servers** (app, queue worker, and Vite):

   ```bash
   composer run dev
   ```

   The app runs at `http://localhost:8000`. Log in via Discord to get a `participant`
   account, then `/admin` becomes reachable once your role is `orga` or `admin`.

   A seeded local test user (`discord_id 100000000000000001`) is available in seeded dev
   databases — see `database/seeders`.

6. **Scheduler (since M2):** event reminders (`lanomat:send-reminders`, registered in
   `routes/console.php` via `Schedule::command(...)->everyFiveMinutes()`) only fire if
   something is actually running Laravel's scheduler.

   - **Dev:** run `php artisan schedule:work` in a separate terminal — it re-checks the
     schedule every minute for as long as it's running, no crontab needed.
   - **Prod:** the `scheduler` service in `compose.yml`'s `prod` profile runs
     `php artisan schedule:work` continuously — see "Production deployment" below.

## Production deployment

> **Testing it once (with Discord):** [`docs/prod-test.md`](docs/prod-test.md) is a full step-by-step walkthrough for a one-time end-to-end test of the prod stack — including complete Discord setup (OAuth login, bot, guild/channel IDs, interactions endpoint + public key, slash-command registration) and smoke tests for realtime/queue/scheduler.

A production image is built from `docker/Dockerfile` — [FrankenPHP](https://frankenphp.dev/)
(`dunglas/frankenphp`, PHP 8.4) serving the app natively (no Octane/worker mode; see the
Dockerfile's header comment for why). `compose.yml`'s `prod` profile adds five services on
top of the same shared Postgres/Redis/Mumble infrastructure the dev stack uses: `app` (HTTP,
healthchecked on `/up`), `queue` (`php artisan queue:work`), `scheduler`
(`php artisan schedule:work`, replacing the dev-only `schedule:work` terminal),
`reverb-prod` (websockets, replacing the dev-only throwaway `reverb` service — the two never
run at the same time), and `traefik` (TLS-terminating reverse proxy in front of `app`/
`reverb-prod`, see below). `docker compose up -d` (no flags) is unaffected by any of this.

1. **Configure `.env` for production:**

   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://lan.example
   REVERB_SCHEME=https
   REVERB_ALLOWED_ORIGINS=https://lan.example
   ```

   `REVERB_ALLOWED_ORIGINS` locks Reverb's websocket handshake down to the real origin(s)
   the browser client connects from (comma-separated for more than one) — see
   `config/reverb.php`. Leaving it unset defaults to `*` (fine for dev, not for prod).

2. **Bring up the stack:**

   ```bash
   docker compose --profile prod up -d
   ```

3. **Run migrations and create the first admin**, same command as dev, just inside the
   `app` container:

   ```bash
   docker compose --profile prod exec app php artisan lanomat:install --admin-discord-id=<id>
   ```

**TLS / reverse proxy:** `compose.yml`'s `prod` profile includes a Traefik v3 `traefik`
service that TLS-terminates in front of `app` (participant UI + Filament `/admin`) and
`reverb-prod` (websockets), with Let's Encrypt (ACME) for a public domain or a self-signed
fallback for a pure LAN with no public DNS. See
[`docs/traefik-setup.md`](docs/traefik-setup.md) for `APP_DOMAIN`/`ACME_EMAIL` setup, the
routing scheme, and the LAN-without-DNS path.

**Own image registry:** production images are published to a private registry instead of
relying on public Docker Hub/GHCR pulls at deploy time — see
[`docs/registry-setup.md`](docs/registry-setup.md) for the registry itself (an optional
`registry:2` Compose profile, or any existing external registry), the
`.github/workflows/publish-images.yml` CI workflow that builds and pushes the `app` image on
a version tag/release, and how a prod host pulls from it.

**LanCache (separate host):** a [LanCache](https://lancache.net) instance on its own LAN
host transparently caches Steam/Epic/Battle.net downloads at LAN speed after the first pull
— it is *not* a service in this repo's `compose.yml`, but a host registered in LANoMAT's
managed remote-hosts registry (`role=lancache`) and bootstrapped over SSH via
`ApplyLancacheSetup`. See [`docs/lancache-setup.md`](docs/lancache-setup.md) for registering
the host, DNS pointing for the game CDNs, and the pre-caching-before-the-LAN checklist.

## Quality gates

Run these before committing — CI enforces the same checks:

```bash
composer check          # pint --test, phpstan (level 8), pest
npm run lint:check      # eslint
npm run format:check    # prettier --check
npm run types:check     # vue-tsc --noEmit
npm run build           # vite build
```

Auto-fix formatting/lint issues locally with `composer run lint` (pint) and `npm run lint`
(eslint --fix) / `npm run format` (prettier --write).

## Screenshots

A repeatable, deterministic pipeline (`database/seeders/ScreenshotSeeder.php` +
`scripts/screenshots/capture.mjs`, see [`scripts/screenshots/README.md`](scripts/screenshots/README.md))
seeds a demo event and captures the core screens in both light and dark mode with
Playwright:

```bash
php artisan db:seed --class=ScreenshotSeeder
npm run screenshots
```

Committed output files (captured against the local production stack —
`docker compose --profile prod up`, see the run book in
`scripts/screenshots/README.md`):

| Screen | Light | Dark |
| --- | --- | --- |
| Homepage (news block) | `docs/screenshots/home-light.png` | `docs/screenshots/home-dark.png` |
| Event page | `docs/screenshots/event-page-light.png` | `docs/screenshots/event-page-dark.png` |
| Pre-LAN countdown | `docs/screenshots/countdown-light.png` | `docs/screenshots/countdown-dark.png` |
| Seating | `docs/screenshots/seating-light.png` | `docs/screenshots/seating-dark.png` |
| Live bracket | `docs/screenshots/tournaments-light.png` | `docs/screenshots/tournaments-dark.png` |
| Schedule | `docs/screenshots/schedule-light.png` | `docs/screenshots/schedule-dark.png` |
| Catering | `docs/screenshots/catering-light.png` | `docs/screenshots/catering-dark.png` |
| Voting | `docs/screenshots/voting-light.png` | `docs/screenshots/voting-dark.png` |
| LFG | `docs/screenshots/lfg-light.png` | `docs/screenshots/lfg-dark.png` |
| Files | `docs/screenshots/files-light.png` | `docs/screenshots/files-dark.png` |
| Presence | `docs/screenshots/presence-light.png` | `docs/screenshots/presence-dark.png` |
| Jukebox | `docs/screenshots/jukebox-light.png` | `docs/screenshots/jukebox-dark.png` |
| Gallery | `docs/screenshots/gallery-light.png` | `docs/screenshots/gallery-dark.png` |
| Recap | `docs/screenshots/recap-light.png` | `docs/screenshots/recap-dark.png` |
| Infoscreen (live) | `docs/screenshots/infoscreen-light.png` | `docs/screenshots/infoscreen-dark.png` |
| Infoscreen (recap) | `docs/screenshots/infoscreen-recap-light.png` | `docs/screenshots/infoscreen-recap-dark.png` |
| Admin panel | `docs/screenshots/admin-light.png` | `docs/screenshots/admin-dark.png` |

## Project state

M0–M6 are complete and tagged (fundament; events & identity; registration/seating/
notifications/Discord base; teams & tournaments with Reverb realtime and Discord
interactions; production deployment infra; game servers via Pelican + stats). M7
(infra & operations — Traefik ingress, own image registry, moderated LAN file-sharing,
custom Docker game servers, a separate-host LanCache setup, and a repeatable
screenshot pipeline) is in progress. See [`CLAUDE.md`](CLAUDE.md) for the current
implementation status and
[`docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`](docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md)
for the full phase roadmap.
