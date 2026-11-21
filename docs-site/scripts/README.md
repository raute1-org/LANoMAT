# Docs scripts

Small Node scripts back the docs build and its screenshots. None of them is
part of the Zudoku bundle.

| Script | `npm` | What it does |
|---|---|---|
| `check-links.mjs` | `npm run check:links` | Fails on broken internal links, nav entries and missing `/screens/...` assets |
| `copy-screenshots.mjs` | `npm run screenshots` | Copies the app's `docs/screenshots/` set into `public/screens/` (never deletes; `--prune` drops only stale app shots) |
| `check-screens.mjs` | — (`node scripts/check-screens.mjs`) | Verifies referenced images exist, are PNGs, stay under 250 KB and writes `scripts/screens.json` (width/height) for `<img width height loading="lazy">` |
| `capture-docs.mjs` | — | Screenshots the built docs site itself (the "docs" surface in the tour) |

## Capturing the docs themselves

`capture-docs.mjs` needs a full checkout: Playwright comes from the app's root
`devDependencies`, not from `docs-site/node_modules`. After `npm run build`:

```bash
python3 -m http.server 4013 --directory dist &
# A plain file server has no clean-URL rewrite; the script falls back to
# prerendered .html files, so extensionless paths still work.
DOCS_URL=http://127.0.0.1:4013 node scripts/capture-docs.mjs
```

It writes `public/screens/docs-games-library-{light,dark}.png` and
`docs-mcp-{light,dark}.png`, scrolled to the screenshot tables so the embedded
app screenshots are visible. Those `docs-*.png` files are **not** part of the
app capture set: `copy-screenshots.mjs` leaves them alone, and `--prune` never
removes them.

## Capturing the app screens (1440 × 900)

The app screenshots come from `scripts/screenshots/capture.mjs` in the app
checkout. It talks to a **real, seeded app**, uses a **separate database**, and
writes `docs/screenshots/<name>-<light|dark>.png` (plus `-mobile-` pairs where
the layout is part of the story).

### 1. Isolated database

Never point this at a database you care about; the seeder creates a demo orga
with a public password and rewrites rows.

```bash
docker compose up -d postgres            # host port 5434 (or use the compose network)
docker exec lanomat-postgres-1 createdb -U lanomat lanomat_swarm_w40
```

### 2. App image

The docs set is captured against the **production image**, not `vite dev`, so
the asset build and the shell are the real ones:

```bash
docker build -f docker/Dockerfile -t lanomat-w40:app .
docker run --rm --network lanomat_default \
  -e APP_ENV=local -e APP_KEY="$(openssl rand -base64 32 | sed 's/^/base64:/')" \
  -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
  -e DB_DATABASE=lanomat_swarm_w40 -e DB_USERNAME=lanomat -e DB_PASSWORD=lanomat \
  -e MODULES_PROFILE=full \
  --entrypoint php lanomat-w40:app artisan migrate --force
```

`MODULES_PROFILE=full` matters: `/games`, `/mcp/*` and `/admin/recipes` are
optional modules and 404 under the default `core` profile.

### 3. Seed

The image ships `--no-dev` (no Faker), so the factory-based seeder runs in a
throwaway container with the checkout's dev vendor mounted:

```bash
docker run --rm --network lanomat_default \
  -e APP_ENV=local -e APP_KEY=… -e APP_URL=http://127.0.0.1:8040 \
  -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=lanomat_swarm_w40 \
  -e DB_USERNAME=lanomat -e DB_PASSWORD=lanomat -e MODULES_PROFILE=full \
  -v "$PWD/vendor:/app/vendor" \
  -v "$PWD/storage/app/private:/app/storage/app/private" \
  --entrypoint php lanomat-w40:app artisan db:seed --class=ScreenshotSeeder --force
```

The seeder is deterministic and idempotent: three events (`screenshot-demo`,
`screenshot-recap`, `screenshot-upcoming`), the orga registration/ticket/seat,
a filled seating grid, a **real 8-entry bracket** (two played rounds, a live
match, semis and the final), the bundled recipe catalogue published as a
screenshot-sized slice with LAN copies for UT2004 (disc, patch, avatar shelf)
and Ridge Racer Type 4, one ready UT2004 server, the **DEMO-2345** pending
pairing code, and two connected agents (`pi · demo-laptop`,
`Claude Code · workshop-laptop`) whose timestamps are created-before-used.

If a fixture changes, drop and recreate `lanomat_swarm_w40`; the seeder skips
children of an event that already exists.

### 4. Serve it

```bash
docker run -d --name lanomat-w40-app --network lanomat_default \
  -p 127.0.0.1:8040:80 \
  -e APP_ENV=local -e APP_DEBUG=false -e APP_NAME=LANoMAT -e APP_VERSION=2026.11.0 \
  -e APP_KEY=… -e APP_URL=http://127.0.0.1:8040 \
  -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=lanomat_swarm_w40 \
  -e DB_USERNAME=lanomat -e DB_PASSWORD=lanomat \
  -e MODULES_PROFILE=full -e CACHE_STORE=array -e SESSION_DRIVER=file \
  -e QUEUE_CONNECTION=sync -e BROADCAST_CONNECTION=log \
  -e REVERB_APP_KEY=demo -e REVERB_APP_SECRET=demo -e REVERB_APP_ID=1 \
  -e REVERB_HOST=127.0.0.1 -e REVERB_PORT=8081 -e REVERB_SCHEME=http \
  -v "$PWD/storage/app/private:/app/storage/app/private" lanomat-w40:app
```

`REVERB_*` must be set even though no Reverb server runs here: the bracket page
subscribes to `tournament.{id}` and the Echo client throws without an app key,
which leaves the page blank. The socket failing is fine.

### 5. Run the capture

Playwright comes from the app root (`npm install` there, `npx playwright install
chromium`). ImageMagick (`magick`) is required for the palette compression.

```bash
APP_URL=http://127.0.0.1:8040 npm run screenshots
# or a batch:
APP_URL=http://127.0.0.1:8040 ONLY=dashboard,files-upload npm run screenshots
```

| Knob | Effect |
|---|---|
| `ONLY=a,b` | capture only these route names |
| `SCHEMES=light` | one colour scheme (default `light,dark`) |
| `MOBILE=0` | skip the 375 px pairs |
| `COMPRESS=0` | keep Playwright's raw PNGs |
| `AGENT_CLI=…` | path to `clients/agent/dist/cli.js` for the terminal shots |
| `NO_TERMINAL=1` | skip the two terminal shots |
| `SCREENSHOT_DIR=…` | write elsewhere (scratch run) |

What the routes need from the seed:

| Shot | Route | Fixture |
|---|---|---|
| `home` | `/` (signed out) | none — the guest landing, deliberately not the event page |
| `dashboard` | `/dashboard` | orga registration + seat |
| `event-page` | `/events/screenshot-demo` | live event, news, ticket |
| `files-upload` | `/events/screenshot-demo/files` | approved files + quota |
| `games-filter` | `/games`, "Nur LAN-Spiele" clicked | published slice, two LAN-ready titles |
| `game-ut2004` | `/games/unreal-tournament-2004` | recipe + disc/patch/avatars + ready server |
| `mcp-connect-pending` | `/mcp/connect?code=DEMO-2345` | pending device code + two tokens |
| `tournament-bracket` | `/tournaments/{id}` (resolved) | the seeded bracket |
| `admin-recipes` / `admin-recipe-edit` | `/admin/recipes`, first published row (resolved) | published UT2004 recipe |
| `agent-install-terminal` / `agent-doctor-terminal` | real CLI output | the demo agent token |

The terminal shots run the **real CLI** (`lanomat-agent install
unreal-tournament-2004 --dry-run -y --os linux --event screenshot-demo` and
`lanomat-agent doctor`) with `LANOMAT_CONFIG_DIR` pointing at a temp directory
that holds a config with the seeder's demo token. Build it first:

```bash
(cd clients/agent && npm ci && npm run build)
```

Token, operator home path and `reneil-thinkpad` are sanitised in the rendered
output; the install shot is content-sized (taller than 900 px), the doctor shot
is 1440 × 900.

### 6. Publish and verify

```bash
cd docs-site
npm run screenshots          # copies docs/screenshots -> public/screens
node scripts/check-screens.mjs
npm run build
npm run check:links
```

The capture freezes the browser clock (2026-11-21 19:30 +01:00), emulates
`prefers-reduced-motion: reduce` and disables CSS animation, so two runs against
the **same seeded database** produce the same bytes. Re-seeding first changes
only the recorded timestamps (`Hochgeladen am …`, `Verbunden am …`) — that is
the documented animation/time delta, not image drift. Every PNG is re-encoded as
a 256-colour palette with `-dither None` (deterministic) and checked against the
250 KB budget.

### Notes and known limits

- `home` is the signed-out landing; the logged-in `/` is the event page. The
  tour page's "Dashboard" caption should point at `dashboard-*.png` (the docs
  owner updates the page; this pipeline cannot edit `pages/`).
- `settings-connections-*.png` is captured for the settings surface but no page
  references it yet; `check-screens.mjs` lists it as unreferenced.
- Width/height/lazy: Markdown images cannot carry attributes. `check-screens.mjs`
  writes `scripts/screens.json` with `width`, `height` and `bytes` per file so a
  page that needs them can emit
  `<img src="/screens/…" width="…" height="…" loading="lazy" alt="…">`.
- The native voice app's `voice-app/` screenshots are not produced by this
  pipeline; the blank, unreferenced `v01-connect.png` was removed (the real
  connect screen is `01-connect.png`).
