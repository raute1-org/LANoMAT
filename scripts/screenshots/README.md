# Screenshot pipeline

Repeatable README screenshots (roadmap 7.6, issue #10): a deterministic seeder
plus a Playwright headless capture script, so the screenshots in the root
`README.md` can be regenerated on demand instead of hand-curated once and
left to rot.

## What's here

- `database/seeders/ScreenshotSeeder.php` — creates three demo events and
  global news, all deterministic (fixed names/slugs) and idempotent
  (`updateOrCreate` on stable keys — safe to run repeatedly):
  - `screenshot-demo` (live): filled seating grid, live tournament with a
    couple of `Ready` matches, open poll, approved shared files, catering
    order, schedule items, gallery photos (with real placeholder JPEG bytes
    on the private disk), and a rotation of enabled beamer scenes.
  - `screenshot-recap` (finished): a crowned tournament (podium), gallery
    highlights, a closed MVP-of-the-night poll with a clear winner, jukebox
    play history, and an enabled `recap` beamer scene — drives the public
    recap page and its beamer scene.
  - `screenshot-upcoming` (registration open): arrival info + a live
    registration count for the pre-LAN countdown/hype on its event page.
  - On the demo event, the 2026 gaming surfaces: three games the orga shared
    (`/games/share`), a draft with its pending archive, one published with an
    approved archive and an open catalogue pull request, and one published
    although its archive is gone; their event servers (one ready, one not
    responding, each with an A2S answer), and a Presence snapshot that places
    the demo participants on two of them (one account hides its location).
    `/games`, a shared game's page, the server pages, the files page's "Als
    Spiel teilen", and the panel's review queue and recipe form are captured
    from those rows.
- `scripts/screenshots/capture.mjs` — a standalone Playwright script (not
  part of the Vite/app bundle) that logs in as the seeded orga, visits the
  core screens, and captures both light and dark mode. It prints every console
  error, page error and failed request as one summary at the end.

## How to run

1. **Start the app** against a database you're fine seeding demo data into
   (a fresh local dev DB, or a disposable staging environment — never
   production):

   ```bash
   docker compose up -d
   composer run dev
   ```

2. **Seed the demo fixtures:**

   ```bash
   php artisan db:seed --class=ScreenshotSeeder
   ```

   Safe to re-run; it will not create duplicate rows.

3. **Install the Playwright browser** (one-time per machine/container):

   ```bash
   npx playwright install --with-deps chromium
   ```

4. **Run the capture:**

   ```bash
   npm run screenshots
   ```

   Or against a different host:

   ```bash
   APP_URL=https://staging.lan.example npm run screenshots
   ```

5. Screenshots land in `docs/screenshots/<name>-<light|dark>.png`. Review
   them, then commit the ones you want to embed in `README.md`.

## Capturing against the production image

The `docker compose --profile prod` image is built with
`composer install --no-dev`, which **omits `fakerphp/faker`** — so the
factory-based seeder cannot run *inside* the prod container (`fake()` is
undefined). Seed from a dev-dependency context instead (the host checkout,
or the dev stack) against the same Postgres, then mirror the generated photo
files into the container (the prod `app` service has no storage volume):

```bash
# 1. Migrate the schema inside the prod container.
docker compose exec app php artisan migrate:fresh --force

# 2. Seed from the host (has faker) against the published Postgres port.
DB_HOST=127.0.0.1 DB_PORT=5434 REDIS_HOST=127.0.0.1 REDIS_PORT=6380 \
  CACHE_STORE=array QUEUE_CONNECTION=sync BROADCAST_CONNECTION=log \
  php artisan db:seed --class=ScreenshotSeeder --force

# 3. Copy the seeded photo bytes into the container's private disk.
docker compose cp storage/app/private/event-1 app:/app/storage/app/private/

# 4. Capture against the prod app.
APP_URL=http://localhost:8000 npm run screenshots
```

## Honest scope

This pipeline (seeder + script + docs) is the tested deliverable. The PNGs in
`docs/screenshots/` were captured against the local production stack
(`docker compose --profile prod up`) using the flow above; re-running the
pipeline overwrites them in place.

## Notes

- **Capture a subset.** `ONLY=games-share,games-share-shares,servers npm run
  screenshots` captures just those routes (the names are the `ROUTES` entries
  in `capture.mjs`); `SCHEMES=light` limits the colour schemes. Leave the rest
  of `docs/screenshots/` untouched.
- **The one download page (`/app`) needs published builds.** It lists the
  voice app and the launcher, each with its own manifest; `/launcher` and
  `/talk/client` only redirect there now. `app-download` is captured with both
  published: `clients-dist/manifest.json` for the voice app (`app_version`,
  `commit`, `api_version` = the server's API version, `built_at`, `files[]`)
  and `clients-dist/launcher/manifest.json` for the launcher (`app_version`,
  `commit`, `built_at`, `files[]`), each `files[]` entry with `name`, `os`,
  `arch`, `kind`, `variant`, `size`, 64-hex `sha256`, `signed`, and one file
  per entry whose size matches. Synthetic manifests are enough — the pages
  validate names and sizes and only hash on a real download. Without them the
  card says "nichts veröffentlicht"; that is a valid picture too.
- **The launcher-link notice lives on a server card** (`launcher-notice`):
  `/servers/all?game=Bunker Bash Deluxe`, a click on "Im Launcher beitreten",
  then the composable's 2.5 s timeout.
- **Presence is a cache read.** The "Hier spielen" line comes from the
  location snapshot, which lives in the cache for five minutes
  (`LocationSnapshotStore::TTL_SECONDS`) and is normally refreshed by the
  scheduler. Run `php artisan db:seed --class=ScreenshotSeeder` again right
  before capturing `servers` and `servers-all`, or those rows are empty.
- **Pull request states are seeded.** The review queue shows an open pull
  request (with an invented `https://github.com/example/lanomat/pull/42`) and
  a refused one with its reason; the edit page's "Katalog (Pull Request)"
  preview only appears while `RECIPES_PR_ENABLED`, `RECIPES_PR_REPOSITORY` and
  `RECIPES_PR_TOKEN` are set (fake values are fine — the pages do no request).
- Login: this app is Discord-OAuth-only in the UI (see
  `resources/js/pages/auth/Login.vue`) — there is no password login form.
  Fortify's underlying `/login` (email+password) route still exists under
  the hood (unaffected by disabling registration/reset-password/email
  verification), so the seeded orga gets a fixed local password
  (`ScreenshotSeeder::ORGA_PASSWORD`) purely so this script can authenticate
  headlessly, without needing a real Discord OAuth handshake. This password
  only ever exists in throwaway seeded demo databases — never wire it into
  any production seeding path.
- Only `/` (the guest login gate) uses a signed-out guest context; every
  other capture runs inside the logged-in orga session, so participant pages
  render as a signed-in user.
- Re-running `npm run screenshots` overwrites the previous PNGs in place.
