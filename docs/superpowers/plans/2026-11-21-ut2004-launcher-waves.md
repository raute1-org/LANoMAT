# UT2004 launcher pilot — wave plan (2026-11-21)

**Scope:** only the Unreal Tournament 2004 flat pilot from
[`2026-11-21-one-click-launcher.md`](../specs/2026-11-21-one-click-launcher.md). Every other
recipe, VR, chat/Venice sponsorship, avatars and the ISO hosting pipeline stay backlog.

**Definition of done (user acceptance):** a fresh Linux or Windows PC downloads the LANoMAT
Launcher, pairs once, opens the UT2004 card and installs the game with one button. The launcher
downloads the retail ISO and the OldUnreal 3374 patch from the LANoMAT file host, extracts the game,
verifies the install and starts the flat game. The website has a `/launcher` page and a visible
Launcher entry.

## Architecture decision (recorded)

- New `clients/launcher` Tauri v2 app with a small `launcher-core` crate. It reuses the existing
  agent device flow (`POST /api/mcp/device`, `POST /api/mcp/token`) and `/api/v1/recipes/...`.
- The install engine is Rust, not a bundled Node sidecar. The retail ISO is read in-process with
  `libmkiso` (ISO9660/Joliet), the patch is a zip (Windows) or tar.bz2 (Linux).
- A portable `archive` requirement, when a recipe gains one, wins over the ISO with no further
  launcher change.
- No new auth endpoint in the pilot. A one-time install ticket is deferred; the existing bearer
  token is scoped to the agent API and stored 0600.

## Waves

| Wave | Slots | Deliverable | Writable paths |
|---|---|---|---|
| 0 | coordinator | crate skeleton, types, API/auth/state, archive/download/install baseline, merged spec | `clients/launcher/**`, spec |
| 1 | 6 workers | core hardening, app shell, UI, backend page, build pipeline, docs | disjoint per card |
| 2 | 4 workers | integration fixes, end-to-end core test, UI smoke, release manifest | per card |
| 3 | reviewers | architecture, security, tests, docs; merge captain | read-only |

Each worker owns one worktree and one card; the coordinator merges in dependency order and runs the
gates. A card is done only when its proof command is green.

## Cards

### L1 — launcher-core hardening

**Writable:** `clients/launcher/launcher-core/src/{api,auth,state,types}.rs`,
`clients/launcher/launcher-core/tests/**`

- API: token expiry/`me`, 401 classification, server URL normalization, query strings.
- Auth: poll state machine (pending/slow_down/expired/denied), no sleep inside the core, clock
  injection for tests; token file never logged.
- State: migrate a v0 file (no `version`) in memory, preserve unknown keys, corrupt-file recovery.
- Proof: `cargo test -p launcher-core`.

### L2 — download engine

**Writable:** `clients/launcher/launcher-core/src/download.rs`, `launcher-core/tests/download*.rs`

- Resume from `.part` with Range, 200 fallback, 416 retry, idle timeout, size cap, sha256 verify,
  cancellation, progress throttling.
- Bearer token only on the LANoMAT host; never forward it to an external fallback URL.
- Proof: `cargo test -p launcher-core download`.

### L3 — archive + install engine

**Writable:** `clients/launcher/launcher-core/src/{archive,install}.rs`,
`launcher-core/tests/install*.rs`

- ISO extraction (Joliet names, wrapper folder, skip `System`), zip/tar.bz2 patch, path traversal
  rejection, verification, executable bit, launch.
- Missing-file behavior: a plan with only a portable archive installs without the ISO; a plan with
  neither reports both missing labels.
- Proof: `cargo test -p launcher-core install archive`.

### L4 — Tauri shell

**Writable:** `clients/launcher/app/src/**`, `clients/launcher/app/{Cargo.toml,build.rs,tauri.conf.json,capabilities/**}`

- Commands: status, connect_start/poll, logout, list_games, get_plan, install, cancel, launch,
  open_url. Emits `install-progress`.
- Deep link `lanomat://install/<slug>` and `lanomat://launch/<slug>`; single instance.
- No blocking of the UI thread; one install at a time; clean error strings.
- Proof: `cargo check -p lanomat-launcher-app` in the app image.

### L5 — launcher UI

**Writable:** `clients/launcher/app/ui/**`

- Signalpult tokens, Space Grotesk/JetBrains Mono, light/dark, all four states, German copy.
- Screens: connect (URL + code), library (UT2004 card), install (phase, file, bytes, speed, ETA,
  cancel), ready (launch), error/offline. No raw hex.
- Keyboard focus, `prefers-reduced-motion`, no unlabeled buttons.
- Proof: static HTML/CSS review + shell smoke (L11).

### L6 — website launcher page

**Writable:** `app/Modules/GameRecipes/Http/LauncherPageController.php`,
`app/Modules/GameRecipes/Support/LauncherBuilds.php`, `routes/web.php` (one line),
`resources/js/pages/Launcher/Index.vue`, `resources/js/components/AppSidebar.vue` (one entry),
`resources/js/lib/pageLayouts.ts` (one entry), `resources/js/pages/Welcome.vue` (one tile),
`tests/Feature/GameRecipes/LauncherPageTest.php`, `tests/Architecture/PageLayoutsTest` fixture if needed.

- `/launcher` page: one primary download button per OS from `clients-dist/launcher/manifest.json`,
  SHA256, install steps, current server address, "mit LANoMAT verbinden".
- Download route validates the manifest and file name (no traversal), 404 when no build.
- Entry in sidebar and homepage above the fold; `/launcher` in `pageLayouts.ts`.
- Proof: targeted Pest + `npm run types:check`.

### L7 — build, release, docs

**Writable:** `clients/launcher/{README.md,ci/**,scripts/**}`, `scripts/ops/build-launcher.sh`,
`.github/workflows/release-launcher.yml`, `docs-site/pages/guides/launcher.md`,
`docs-site/pages/start/quickstart.md`, `CHANGELOG.md`, `.env.example` if needed.

- Build script compiles Linux app + core, packs a reproducible tar.gz, writes
  `clients-dist/launcher/manifest.json` + `SHA256SUMS`.
- Workflow mirrors the voice release: Linux build/test, Windows cross-build, draft release.
- Docs: one page, one screenshot (placeholder if needed), links checked.
- Proof: `scripts/ops/build-launcher.sh --allow-dirty` + `npm run build` in docs-site.

### L8 — end-to-end proof

**Writable:** `clients/launcher/launcher-core/tests/e2e_pilot.rs`,
`clients/launcher/app/tests/**`, `docs/superpowers/plans/2026-11-21-ut2004-launcher-waves.md`

- A fixture ISO (tiny, generated with `libmkiso` or committed) + patch zip, a wiremock API serving
  device/token/recipes/plan/download, then run `InstallEngine` end to end in a temp dir and assert
  `System/UT2004.exe`/`Build.ini` exist.
- Optional Tauri headless smoke (Xvfb) for connect screen and install button.
- Proof: `cargo test -p launcher-core --test e2e_pilot`.

### L9 — security + review

- Path traversal, token leakage to external download, URL redirect to a foreign host with bearer,
  huge archive entries, zip bombs, ISO names with `..`, deep-link slug injection, throttle.
- Proof: `cargo clippy --all-targets -- -D warnings` + targeted tests.

## Gates

- `docker run ... lanomat-launcher-dev cargo fmt --check`
- `docker run ... lanomat-launcher-dev cargo clippy --all-targets --features '' -- -D warnings`
- `docker run ... lanomat-launcher-dev cargo test -p launcher-core`
- `cargo check -p lanomat-launcher-app`
- `php artisan test tests/Feature/GameRecipes tests/Architecture`
- `npm run lint:check`, `npm run format:check`, `npm run types:check`, `npm run build`
- `docs-site`: `npm run build`, `node scripts/check-links.mjs`
- No secret committed; gitleaks clean.

## Slot layout

Wave 1: L1, L2, L3, L4, L5, L6. Wave 2: L7, L8, L9, integration. Reviewers R1 (architecture/Rust),
R2 (security), R3 (tests), R4 (docs/UI). Max 32 child sessions; unused slots stay free.
