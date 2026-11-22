# Smoke tests

Two headless-browser checks for classes of bug the PHP suite structurally cannot
see: **the browser's own JavaScript** and **the real user journey**.

| Script | What it proves | Fixtures |
| --- | --- | --- |
| `realtime-smoke.mjs` (`npm run smoke`) | realtime pages render at all | `ScreenshotSeeder` |
| `game-sharing-smoke.mjs` | the game-sharing journey works end to end | `GameSharingSmokeSeeder` |

---

## Realtime pages — `realtime-smoke.mjs`

A tiny check that the app's **realtime pages actually render in a browser**.

### Why this exists

Realtime pages (presence, jukebox, the live bracket) open a Reverb channel via
Echo. If the browser's Echo client is misconfigured — e.g. instantiated without
a Reverb app key — those pages throw during Vue setup and render **blank**,
while every server-side Pest test still passes: PHP tests can assert the config
the server *sends*, but not what the browser's JavaScript *does* with it. That
exact gap shipped once (Echo read its config from an `#app` data-page attribute
that isn't reliably present at module-load in Inertia v2 → no key → blank pages
in prod) and was only caught by chance. This script is the guard.

### What it checks

Logs in as the seeded orga and loads each realtime route, failing (exit 1) if a
page:

- logs a **fatal** Echo/Pusher error (`app key` / `Pusher` / `configureEcho`), or
- renders **empty** main content (< 50 chars — realtime setup likely threw).

A mere websocket *connection* failure is fine (realtime is a progressive
enhancement; the page still renders) — only a failed Echo *construction* trips it.

### How to run

Same prerequisites as the screenshot pipeline — the app running with the
`ScreenshotSeeder` fixtures:

```bash
# app running at http://localhost:8000 (dev server or prod stack), then:
php artisan db:seed --class=ScreenshotSeeder
npm run smoke
```

Override the target with `APP_URL=https://… npm run smoke`.

### Not in CI (yet)

This needs a running app + Postgres/Redis + a frontend build in the runner, so
it is a **local pre-merge check** for now: run it before merging any change that
touches realtime pages or the Echo/Reverb wiring. Wiring it into GitHub Actions
(service containers + a background app + `npm run smoke`) is a sensible next
step if realtime regressions recur.

---

## Game sharing — `game-sharing-smoke.mjs`

The real-browser twin of `tests/Feature/GameRecipes/GameSharingStoryTest.php`
and of the "game sharing" card in
`docs/superpowers/plans/2026-11-21-game-sharing-swarm.md` (section 8). The PHP
story test proves what the server sends; this one proves what the accounts
actually get to do in a browser.

### What it drives

Five accounts from `GameSharingSmokeSeeder` (a participant who shares, an orga, a
second participant, one participant who hides their location and one account
that is not registered for the event) walk the journey in one run:

0. **clean slate** — deletes a previous run's recipe in the panel and every
   account's own uploads, so the script can be run again without re-seeding.
1. **share** — `/games` → "Spiel teilen", a real `.zip` built on the fly. The
   upload is selected automatically (the page's promise), a hand-picked archive
   wins over a later upload, and after a successful share the next upload is
   selected again. Keyboard-only operation (Tab to every control, the file list
   is a radio group, submit with Enter), the success toast, the share row with
   "Die Orga prüft gerade", the 375 px layout.
2. **refusals** — three real archives with names a recipe cannot quote
   (`Tiny Racer*.zip`, `Game: Deluxe.zip`, `.hidden.zip`): listed, disabled, with
   the reason from `game_share.errors.file_name`, and none of them selected. A
   title that already exists (the German message), a line break in the title.
3. **review** — the panel's navigation badge, the "Geteilte Spiele" filter, the
   "Start und Launcher" section with the launch command, "Dateien freigeben und
   veröffentlichen" with its confirmation ("Schrittbefehle: N · Startbefehle: M"),
   and the published recipe whose game no longer calls itself a draft.
4. **find** — the event line on `/games`, the one "App herunterladen" entry in
   the shell header and the sidebar (both to `/app`), the game on `/games` and on
   its page ("Installieren", the file state, the published description).
5. **join** — both launcher shapes on `/servers/all` and `/servers` (expanded):
   "Im Launcher starten" for the plain `address:port` server (the link names no
   server), "Im Launcher beitreten" for the server with a `steam://connect/…`
   join URL (the link carries `?url=…`), each with its own hint.
6. **the launcher link** — what a `lanomat://` click does in a browser with no
   handler: no navigation, no dialog, the "Öffne den Launcher …" line, the
   timeout notice with "App herunterladen" (to `/app`) / "Nochmal versuchen" /
   dismiss, the `role="status"` text at each stage, retry, dismissing,
   Ctrl/middle click, the notice's place in its row on `/servers/all` and in the
   expanded row on `/servers`, and a simulated takeover (window blur,
   `visibilitychange`) showing "Launcher geöffnet. …" and clearing again.
   Then the download path: `/launcher` and `/talk/client` redirect to `/app`,
   which lists the seeded launcher build (from `/launcher/download/…`) and the
   way back to the games library, and the game page's "Installieren" leads to
   `/app` as well.
7. **missing files** — the shared archive is deleted; both server rows say a
   fresh install may fail while their launcher action stays.
8. **presence** — a registered participant sees "Hier spielen" with the other
   participants on that server (the viewer included), a participant with
   "Standort teilen" off is not listed, and an account that is not registered for
   the event sees no line at all.

Every step takes a screenshot, and console errors, unhandled page errors and
4xx/5xx responses are collected per step and printed at the end.

### How to run

```bash
# in the app container / on a dev stack, once:
php artisan migrate:fresh --force
php artisan storage:link
php artisan db:seed --class=GameSharingSmokeSeeder

# from the host, with the app running:
APP_URL=http://127.0.0.1:8000 node scripts/smoke/game-sharing-smoke.mjs
```

The seeder is opt-in (never part of `DatabaseSeeder`), refuses to run in
`production`, and resets everything the previous run created (the recipe, the
uploads, the game and its two servers) — it is safe to run again. It also writes
a placeholder launcher build into `clients-dist/launcher`
(`config('launcher.dist_path')`) when no real build is published, so the game
page shows "Im Launcher öffnen" as well as the deep-link "Installieren"; an
existing manifest is left untouched.

The presence step needs the seeder's location snapshot, so seed with the same
cache store the app runs with (`CACHE_STORE=file` for a `php artisan serve`
instance). The fixture keeps it for a day; the production store's TTL is five
minutes and the scheduled refresh knows nothing about seeded people.

Form uploads are used (`FILES_STORE=local`, the default). The resumable tus path
is not driven — it needs a file host.

### Knobs

| Variable | Meaning |
| --- | --- |
| `APP_URL` | the running app (default `http://localhost:8000`) |
| `SMOKE_BROWSER` | `chromium` (default), `firefox` or `webkit` |
| `CHROMIUM_PATH` | a specific Chromium binary |
| `SMOKE_SHOT_DIR` | where the per-step screenshots go (default: a temp dir) |
| `SMOKE_STRICT=1` | findings (known application defects) fail the run too |

### Exit code and findings

Exit 1 when a step fails. Exit 0 when the journey completes, even if it
**finds** something: a *finding* is an application defect the script works
around and reports (it prints "! FINDING: …", and `SMOKE_STRICT=1` makes it
fail). The findings of the first run (2026-11-22: the upload selection, the
unusable-archive reason, the publish-confirmation counts and the launcher-link
naming) were fixed and are hard assertions now — a regression fails the run.
What is still reported as a finding:

- a real second tab is not a takeover in headless Chromium (the background page
  keeps `visibilityState === 'visible'` and `document.hasFocus() === true`), so
  the attempt ends in the notice; the listeners themselves work.

### What it does not cover

- **A real launcher.** No `lanomat://` handler is installed; the takeover is
  simulated with a window `blur` and a `visibilitychange`. Whether the launcher
  window really takes the focus on Linux/Windows is a hardware check.
- **Real hardware and the real GitHub API.** Pull requests stay off (the
  default); the recipe never leaves the database.
- **Firefox and WebKit.** The script can run in them
  (`SMOKE_BROWSER=firefox`), but they were not installed on the machine this was
  developed on (`ls ~/.cache/ms-playwright` → Chromium only) and are not
  downloaded automatically.
- **The resumable (tus) upload path** and the launcher build itself (the seeded
  build is a placeholder that is never downloaded).

### Not in CI

Like the realtime smoke, it needs a running app, the database, a frontend build
and a seeded fixture set, so it is a **local pre-merge check**. Run it before
merging changes to the share page, the panel review flow, the server rows or
`LauncherLink`.
