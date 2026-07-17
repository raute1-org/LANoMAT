# Screenshot pipeline

Repeatable README screenshots (roadmap 7.6, issue #10): a deterministic seeder
plus a Playwright headless capture script, so the screenshots in the root
`README.md` can be regenerated on demand instead of hand-curated once and
left to rot.

## What's here

- `database/seeders/ScreenshotSeeder.php` — creates one demo event
  (`screenshot-demo`) with a filled seating grid, a live tournament with a
  couple of `Ready` matches, an open poll, approved shared files, a catering
  order and schedule items. Deterministic (fixed names/slugs) and idempotent
  (`updateOrCreate` on stable keys — safe to run repeatedly).
- `scripts/screenshots/capture.mjs` — a standalone Playwright script (not
  part of the Vite/app bundle) that logs in as the seeded orga, visits the
  core screens, and captures both light and dark mode.

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

## Honest scope

This pipeline (seeder + script + docs) is the tested, committed deliverable.
The **actual capture run** needs a real running server plus a downloaded
Playwright browser binary, which is environment-dependent (and unreliable in
a throwaway sandbox harness) — so no PNGs are committed by this task.
Running the pipeline for real and committing the resulting images is a
manual follow-up.

## Notes

- Login: this app is Discord-OAuth-only in the UI (see
  `resources/js/pages/auth/Login.vue`) — there is no password login form.
  Fortify's underlying `/login` (email+password) route still exists under
  the hood (unaffected by disabling registration/reset-password/email
  verification), so the seeded orga gets a fixed local password
  (`ScreenshotSeeder::ORGA_PASSWORD`) purely so this script can authenticate
  headlessly, without needing a real Discord OAuth handshake. This password
  only ever exists in throwaway seeded demo databases — never wire it into
  any production seeding path.
- Most routes captured here (event page, seating, bracket, schedule,
  catering, voting, LFG, files, infoscreen) require no authentication at
  all — only `/admin` does.
- Re-running `npm run screenshots` overwrites the previous PNGs in place.
