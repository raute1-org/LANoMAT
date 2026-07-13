# LANoMAT v2

A modular LAN party management tool: event registration/tickets, seating, tournaments with
brackets, schedule, catering, voting, LFG, infoscreens, Discord integration, Mumble voice,
and game servers via Pelican. A Laravel monolith, rebuilt from the previous Nuxt/NestJS
system.

See [`docs/architecture.md`](docs/architecture.md) for the module convention and data model,
and [`docs/superpowers/specs/2026-07-13-lanomat-v2-rebuild-design.md`](docs/superpowers/specs/2026-07-13-lanomat-v2-rebuild-design.md)
for the full design rationale.

## Requirements

- PHP 8.4 with the `pgsql`, `redis`, `sodium`, and `intl` extensions
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

## Project state

M0 (Fundament) is complete. See [`CLAUDE.md`](CLAUDE.md) for the current implementation
status and [`docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`](docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md)
for the phase roadmap (M1–M6).
