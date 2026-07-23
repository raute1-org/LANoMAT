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

### Peek into a local clone (no Discord app needed)

Under `APP_ENV=local`, the login page shows **Demo-Login** buttons (Demo-Teilnehmer /
Demo-Orga) — no Discord OAuth app required. They are backed by a local-only
`POST /dev/login/{role}` route that 404s in every other environment. For a populated app,
migrate and seed demo data first:

```bash
php artisan migrate
php artisan db:seed --class=ScreenshotSeeder
```

Then open `/login` and pick a demo role.

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

Committed output (captured against the local production stack —
`docker compose --profile prod up`, see the run book in
`scripts/screenshots/README.md`). Click a thumbnail to open it full size.

<details>
<summary><strong>Screenshots</strong> — 17 screens × light/dark (click to expand)</summary>

<table>
<thead>
<tr><th align="left">Screen</th><th>Light</th><th>Dark</th></tr>
</thead>
<tbody>
<tr>
<td>Homepage (news block)</td>
<td><a href="docs/screenshots/home-light.png"><img src="docs/screenshots/home-light.png" width="380" alt="Homepage, light mode"></a></td>
<td><a href="docs/screenshots/home-dark.png"><img src="docs/screenshots/home-dark.png" width="380" alt="Homepage, dark mode"></a></td>
</tr>
<tr>
<td>Event page</td>
<td><a href="docs/screenshots/event-page-light.png"><img src="docs/screenshots/event-page-light.png" width="380" alt="Event page, light mode"></a></td>
<td><a href="docs/screenshots/event-page-dark.png"><img src="docs/screenshots/event-page-dark.png" width="380" alt="Event page, dark mode"></a></td>
</tr>
<tr>
<td>Pre-LAN countdown</td>
<td><a href="docs/screenshots/countdown-light.png"><img src="docs/screenshots/countdown-light.png" width="380" alt="Pre-LAN countdown, light mode"></a></td>
<td><a href="docs/screenshots/countdown-dark.png"><img src="docs/screenshots/countdown-dark.png" width="380" alt="Pre-LAN countdown, dark mode"></a></td>
</tr>
<tr>
<td>Seating</td>
<td><a href="docs/screenshots/seating-light.png"><img src="docs/screenshots/seating-light.png" width="380" alt="Seating, light mode"></a></td>
<td><a href="docs/screenshots/seating-dark.png"><img src="docs/screenshots/seating-dark.png" width="380" alt="Seating, dark mode"></a></td>
</tr>
<tr>
<td>Live bracket</td>
<td><a href="docs/screenshots/tournaments-light.png"><img src="docs/screenshots/tournaments-light.png" width="380" alt="Live bracket, light mode"></a></td>
<td><a href="docs/screenshots/tournaments-dark.png"><img src="docs/screenshots/tournaments-dark.png" width="380" alt="Live bracket, dark mode"></a></td>
</tr>
<tr>
<td>Schedule</td>
<td><a href="docs/screenshots/schedule-light.png"><img src="docs/screenshots/schedule-light.png" width="380" alt="Schedule, light mode"></a></td>
<td><a href="docs/screenshots/schedule-dark.png"><img src="docs/screenshots/schedule-dark.png" width="380" alt="Schedule, dark mode"></a></td>
</tr>
<tr>
<td>Catering</td>
<td><a href="docs/screenshots/catering-light.png"><img src="docs/screenshots/catering-light.png" width="380" alt="Catering, light mode"></a></td>
<td><a href="docs/screenshots/catering-dark.png"><img src="docs/screenshots/catering-dark.png" width="380" alt="Catering, dark mode"></a></td>
</tr>
<tr>
<td>Voting</td>
<td><a href="docs/screenshots/voting-light.png"><img src="docs/screenshots/voting-light.png" width="380" alt="Voting, light mode"></a></td>
<td><a href="docs/screenshots/voting-dark.png"><img src="docs/screenshots/voting-dark.png" width="380" alt="Voting, dark mode"></a></td>
</tr>
<tr>
<td>LFG</td>
<td><a href="docs/screenshots/lfg-light.png"><img src="docs/screenshots/lfg-light.png" width="380" alt="LFG, light mode"></a></td>
<td><a href="docs/screenshots/lfg-dark.png"><img src="docs/screenshots/lfg-dark.png" width="380" alt="LFG, dark mode"></a></td>
</tr>
<tr>
<td>Files</td>
<td><a href="docs/screenshots/files-light.png"><img src="docs/screenshots/files-light.png" width="380" alt="Files, light mode"></a></td>
<td><a href="docs/screenshots/files-dark.png"><img src="docs/screenshots/files-dark.png" width="380" alt="Files, dark mode"></a></td>
</tr>
<tr>
<td>Presence</td>
<td><a href="docs/screenshots/presence-light.png"><img src="docs/screenshots/presence-light.png" width="380" alt="Presence, light mode"></a></td>
<td><a href="docs/screenshots/presence-dark.png"><img src="docs/screenshots/presence-dark.png" width="380" alt="Presence, dark mode"></a></td>
</tr>
<tr>
<td>Jukebox</td>
<td><a href="docs/screenshots/jukebox-light.png"><img src="docs/screenshots/jukebox-light.png" width="380" alt="Jukebox, light mode"></a></td>
<td><a href="docs/screenshots/jukebox-dark.png"><img src="docs/screenshots/jukebox-dark.png" width="380" alt="Jukebox, dark mode"></a></td>
</tr>
<tr>
<td>Gallery</td>
<td><a href="docs/screenshots/gallery-light.png"><img src="docs/screenshots/gallery-light.png" width="380" alt="Gallery, light mode"></a></td>
<td><a href="docs/screenshots/gallery-dark.png"><img src="docs/screenshots/gallery-dark.png" width="380" alt="Gallery, dark mode"></a></td>
</tr>
<tr>
<td>Recap</td>
<td><a href="docs/screenshots/recap-light.png"><img src="docs/screenshots/recap-light.png" width="380" alt="Recap, light mode"></a></td>
<td><a href="docs/screenshots/recap-dark.png"><img src="docs/screenshots/recap-dark.png" width="380" alt="Recap, dark mode"></a></td>
</tr>
<tr>
<td>Infoscreen (live)</td>
<td><a href="docs/screenshots/infoscreen-light.png"><img src="docs/screenshots/infoscreen-light.png" width="380" alt="Infoscreen live scene, light mode"></a></td>
<td><a href="docs/screenshots/infoscreen-dark.png"><img src="docs/screenshots/infoscreen-dark.png" width="380" alt="Infoscreen live scene, dark mode"></a></td>
</tr>
<tr>
<td>Infoscreen (recap)</td>
<td><a href="docs/screenshots/infoscreen-recap-light.png"><img src="docs/screenshots/infoscreen-recap-light.png" width="380" alt="Infoscreen recap scene, light mode"></a></td>
<td><a href="docs/screenshots/infoscreen-recap-dark.png"><img src="docs/screenshots/infoscreen-recap-dark.png" width="380" alt="Infoscreen recap scene, dark mode"></a></td>
</tr>
<tr>
<td>Admin panel</td>
<td><a href="docs/screenshots/admin-light.png"><img src="docs/screenshots/admin-light.png" width="380" alt="Admin panel, light mode"></a></td>
<td><a href="docs/screenshots/admin-dark.png"><img src="docs/screenshots/admin-dark.png" width="380" alt="Admin panel, dark mode"></a></td>
</tr>
</tbody>
</table>

</details>

## Project state

**All roadmap phases (M0–M12) are complete and tagged**, plus the cross-cutting
Friends system:

- **M0–M3** — fundament; events & identity; registration, QR-ticket check-in,
  seating, in-app notifications and the Discord base; cross-event teams &
  tournaments (pure bracket engine, Reverb realtime, Discord interactions,
  Mumble voice).
- **M4–M6** — catering, voting/polls and LFG; infoscreen scenes for the venue
  projector; production deployment infra; game servers via Pelican + live stats.
- **M7–M8** — infra & operations (Traefik ingress, own image registry, moderated
  LAN file-sharing, custom Docker game servers, separate-host LanCache, screenshot
  pipeline); multi-provider voice (Mumble + TeamSpeak).
- **M9–M11** — account linking (Steam/Twitch) & the Friends system; presence +
  casting/OBS overlays; the LAN-radio jukebox (Music Assistant).
- **M12** — post-/pre-LAN content: moderated photo gallery (EXIF-strip, zip,
  beamer slideshow), public recap page & scene, news block, pre-LAN countdown, and
  the MVP-of-the-night vote.

**Post-roadmap robustness** (hardening toward the real LAN, merged to `main`,
untagged): a consolidated [pre-LAN acceptance checklist](docs/pre-lan-acceptance-checklist.md),
the **Preflight Ampel** (`php artisan lanomat:preflight` traffic-light health
check + Filament dashboard tile + scheduled orga bell), and the **Discord Gateway
bot** (a thin discord.js sidecar for online presence + inbound Gateway events,
bridged to Laravel).

**What remains** is real-infra verification (working through the acceptance
checklist against real Discord/Pelican/Mumble/TeamSpeak/Music-Assistant/hosts) and
a small robustness backlog (backup + rehearsed restore, offline login-QR, a release
watcher). See [`CLAUDE.md`](CLAUDE.md) for the current implementation status and
[`docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`](docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md)
for the full phase roadmap.

## Acknowledgements

Big shoutout to **[JB (@schn4ppi)](https://github.com/schn4ppi)** — a driving
source of feature ideas across the whole roadmap, not just one corner of it.
Several rounds of his input made it into the product:

- **Features woven into the phases** — the schedule favourites + triggers (M5.7),
  the tombola & orga-ping beamer moments (M5.8), Warmup & Go and CS2 live stats
  (M6), the separate-host **LanCache** (M7.5), moderated user file-sharing (M7.3),
  the live-occupancy voice refinements (M8), "presence first" (M10), and the
  crowd-controlled **LAN-radio jukebox** (M11) all started as his requests. The
  helper role, an offline (server-less) tournament type, and payment-comfort
  touches came from the same rounds.
- **The robustness push toward the real LAN** — the pre-LAN acceptance checklist,
  the Preflight Ampel, and the Discord Gateway bot all trace back to his review.

Thanks for keeping the "will this actually survive the LAN day?" question front
and centre. 🙌
