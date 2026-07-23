# Realtime smoke test

`npm run smoke` — a tiny headless-browser check that the app's **realtime
pages actually render in a browser**, catching a class of bug the PHP test
suite structurally cannot.

## Why this exists

Realtime pages (presence, jukebox, the live bracket) open a Reverb channel via
Echo. If the browser's Echo client is misconfigured — e.g. instantiated without
a Reverb app key — those pages throw during Vue setup and render **blank**,
while every server-side Pest test still passes: PHP tests can assert the config
the server *sends*, but not what the browser's JavaScript *does* with it. That
exact gap shipped once (Echo read its config from an `#app` data-page attribute
that isn't reliably present at module-load in Inertia v2 → no key → blank pages
in prod) and was only caught by chance. This script is the guard.

## What it checks

Logs in as the seeded orga and loads each realtime route, failing (exit 1) if a
page:

- logs a **fatal** Echo/Pusher error (`app key` / `Pusher` / `configureEcho`), or
- renders **empty** main content (< 50 chars — realtime setup likely threw).

A mere websocket *connection* failure is fine (realtime is a progressive
enhancement; the page still renders) — only a failed Echo *construction* trips it.

## How to run

Same prerequisites as the screenshot pipeline — the app running with the
`ScreenshotSeeder` fixtures:

```bash
# app running at http://localhost:8000 (dev server or prod stack), then:
php artisan db:seed --class=ScreenshotSeeder
npm run smoke
```

Override the target with `APP_URL=https://… npm run smoke`.

## Not in CI (yet)

This needs a running app + Postgres/Redis + a frontend build in the runner, so
it is a **local pre-merge check** for now: run it before merging any change that
touches realtime pages or the Echo/Reverb wiring. Wiring it into GitHub Actions
(service containers + a background app + `npm run smoke`) is a sensible next
step if realtime regressions recur.
