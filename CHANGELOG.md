# Changelog

All notable changes to LANoMAT v2 since the first production deployment. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); entries are grouped by merge date
(newest first) and then by Added, Changed and Fixed.

**How this file is maintained.** The project has no versioned releases after the `m13` tag, so
there is no `Unreleased` heading: every merged pull request gets one line under its merge date
and section (`feat` is Added, `fix` is Fixed, everything else is Changed; docs-only changes carry a
"Docs:" prefix). Take the title and number from `gh pr list --state merged`, keep it to one line,
and add the entry in the same pull request when possible or in the next docs sync. Earlier history
lives in the git log, the roadmap
([`docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`](docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md))
and the phase tags `m0` to `m13`. This file starts at the pre-LAN state of 2026-10-30.

## 2026-11-21

### Added

- Catering: restaurants. Menus now belong to an event-spanning **restaurant** (`Restaurants` in the admin) that food rounds point at; a restaurant has master data, contact, a default payment that pre-fills new rounds, notes and a compact menu editor (paged and grouped table with search, one modal per dish, ordered dishes cannot be deleted or re-keyed, optional repricing of unpaid positions). Participants see name, logo and description in the round head only. New orders snapshot the dish name. MCP gains `restaurants_list` and `catering_round_create` (29 tools); `catering_menu_get/preview/import` address a restaurant (`event_slug` + `order_id` deprecated). Images: `/catering/restaurant-image/{restaurant}/{key}` and `/catering/restaurant-logo/{restaurant}`; `catering:import-dish-media` targets a restaurant. Migration backfills one restaurant per existing round (see `docs/restaurant-migration.md`); `food_orders.menu` stays as the legacy column
- Maintenance page: an always-on `maintenance` nginx service (`docker/maintenance/`) shows a German, offline-safe "LANoMAT wird aktualisiert" page (503, `Retry-After`, self-reloading via `/up`) while the app restarts or answers 502-504; Traefik `maintenance-errors` middleware plus a lowest-priority fallback router, deploy script keeps the service running, optional `DEPLOY_MAINTENANCE_MODE=1` for `artisan down` around migrations
- Catering: group order (Sammelbestellung) for a food round. Orga page `/admin/food-orders/{id}/group-order` with the kitchen list (per dish quantity, unit price, line sum, grouped notes such as "2× Chicken Curry (1× mild, 1× scharf)", grouped by menu category, no guest names), the person list for cashing up (dishes, sum, paid/open/included in ticket), a copy-text box with a "Kopiert" confirmation, a print view without navigation and the CSV export (formula cells already neutralised); pure `GroupOrder` support class; entry points on the round list and the edit page
- Catering: optional description and photo per dish. Descriptions (max 600 characters) and photos show on the catering page (thumbnail, expandable text, enlarged photo, search in descriptions), are maintained in the food round editor and imported with `catering_menu_import` (descriptions only); photos are re-encoded to 800 px WebP, stored by content hash on the private disk and served by a public cacheable route for published rounds; `catering:import-dish-media` assigns descriptions and photos in bulk from a JSON list and an image folder. The native client API gains additive `description` and `image_url` menu fields.
- Game recipes: draft recipe for Minecraft: Java Edition — portable Prism Launcher 11.1.1 (Windows/Linux/macOS), Minecraft 26.3 with Fabric 0.19.5, Sodium/Lithium/Fabric API, Microsoft sign-in and the LAN server in the multiplayer list; researched online on 2026-11-21 and awaiting venue verification
- Game recipes: draft `hookshot-standalone` recipe for the prebuilt Ocarina of Time (Switch 2) Hookshot package — `hookshot.exe`, built `game/` and mods, no dump/builder/compile; Hookshot builder recipe updated to stable v3.0.1 (2026-11-21)
- Launcher as the first interface: a prominent top-right "Launcher herunterladen" button in the app shell and the Filament topbar (`/launcher/download-latest`, best build for the visitor's system), and a **Jetzt live** list on `/launcher` plus the launcher's own start page: every running server with one-click `Beitreten` — the install wizard and launch on that server via `lanomat://join/<slug>?address=…`, or the game's own join URL
- MCP: menu import for orga/admin. New tools `catering_menu_get`, `catering_menu_preview` (dry run) and `catering_menu_import` (`mode` create, replace or merge) let an agent that read a PDF, web page or photo fill a food round with structured data (name, price in cents or euros, max 300 dishes); strict validation with errors and warnings, atomic and idempotent for create, refused for closed rounds and for replace once participants ordered, audit log line without dish text. Plus the `import-menu` prompt and the `lanomat://skills/import-menu` how-to. LANoMAT does not fetch URLs or parse PDFs. Fixed: `catering_menu` listed draft rounds to every participant
- Start-page chat is now an always-available agent surface: the Jetzt page shows live-only answers ("Welche Spiele laufen?" lists only games with a running server; the catalogue is a fallback), the same chat opens as a dock on every participant page (`GET /chat`, fetched once), answers show `LANoMAT denkt …` / `LANoMAT schreibt …` with a streamed caret, and three quick actions (Spiele installieren, Server ansehen, Launcher herunterladen) sit above the transcript and on Jetzt
- Launcher build and release: `scripts/ops/build-launcher.sh` builds the Linux app in the launcher image and publishes a reproducible tarball with `LIESMICH.txt`, `.desktop` entry and icon, `SHA256SUMS` and `clients-dist/launcher/manifest.json` (the shape `LauncherBuilds` and `/launcher` read, Windows portable exe included when present); `clients/launcher/ci/build.sh` runs fmt/clippy/core tests/release build in CI, and `.github/workflows/release-launcher.yml` cross-builds the Windows portable exe with cargo-xwin and uploads artifacts without publishing a release. New docs page `docs-site/pages/guides/launcher.mdx` (download, pairing, ISO fallback, missing-file behaviour)
- Docs: the pilot onboarding path — start-page chat with typewriter suggestions and inline game/server/install cards (`docs/design.md`, new "Start-page chat" section), the ordered flow chat → Games → Zuletzt gespielt → Installieren → launcher download/pair → the same Games UX → percent/speed/ETA → Starten with a first-visit-to-Now demo conversation and the new `launcher-*.png` screenshots (`docs-site/pages/guides/launcher.mdx`), a paragraph on `/games` sharing the same list and action words, the multi-terminal LAN model (one pairing, token and local install library per terminal, shared catalogue, offline-startable installs, revocation under **Verbundene Geräte**) and the arcade QR sign-in (kiosk/fullscreen, `/mcp/connect?code=…` scanned with a phone, **Abmelden / Nächstes Gerät** handover, per-terminal online/current game/last seen) in the launcher guide, design docs, deploy runbook and pre-LAN checklist, and the one-click spec's UX section marked implemented (pilot)
- Payment comfort: priced ticket types per event with a price snapshot, payment link (paypal.me/pool) with open amount, copyable reference and a payment card on **Jetzt**, "paid by" note and bulk re-pricing for the orga, food rounds paid in cash or PayPal and included per ticket type, deduplicated payment reminders (`registration:payment-reminders`, send window 09-21 Berlin) (#171)
- Participant app: new home page **Jetzt** at `/` for logged-in users (replaces `/dashboard`, which redirects): what needs me now, what is being played with one-click join, one-click "Bock auf" want-to-play signals (expiring LFG posts), the open food round, what comes next and my seat/check-in in one line; separate states before a LAN and without one; slim sidebar with seven main items, live badges and collapsible groups Mehr, Voice and Orga; denser Catering, Tournaments, LFG, Presence and Schedule pages with a shared `Panel` (#167)
- Lighting: optional `Lighting` module (full profile, `MODULE_LIGHTING`) for the venue's DMX light bridge. Admin page "Licht" (live state per reported light, colour, brightness, white, alternating pattern, blackout, Game Mode), scenes (`lighting_scenes`, five defaults) and off-by-default event rules with a cooldown, the `LightingClient` contract with HTTP, fake and null implementations, Preflight check `lighting`, `lighting:status` and `lighting:scene`. The light count and channel plan are read from the bridge. Strobe is locked by default and enforced in one safety decorator. See `docs/infra/lighting.md`
- Game servers: a server that runs as a Docker container on a managed remote host can be started, stopped, restarted, sent console (RCON) commands and have its logs read from LANoMAT (server settings page and an Admin block on `/servers`, orga and admin). New `ControlDriver` (derived: none, pelican, docker, remote_docker), nullable `server_links.remote_host_id`/`remote_container`/`console_kind`, `RemoteDockerControl` contract with SSH and fake implementations, `gameservers:link-remote-docker`, routes `POST servers/{serverLink}/remote/power|command` and `GET servers/{serverLink}/remote/logs` (throttled 30 per minute). Console input is validated and passed as one escaped shell argument; `RemoteExecutor::run` gained an optional timeout. See `docs/infra/remote-docker-control.md`
- Game servers: the global `/servers` overview for match, tournament, event and permanent servers (`?event=` for another LAN), the cross-event browser at `/servers/all`, and the permanent servers at `/servers/permanent`; `/events/{slug}/servers` redirects to the overview (#164)
- Native client download: a published client build is offered when it speaks the server's native-client API version; the server version and commit no longer have to match exactly (Preflight and the download page say "compatible" instead of warning)
- Game servers: new query protocol `ut2004` (UDP, query port = game port + 1, info and player list) so the UT2004 server shows `players/max`; permanent servers list "Wer ist drauf" (in-game nicknames, score, play time where the server reports it) for logged-in users on `/servers` and the event server page, also for Source servers (A2S_PLAYER) and Minecraft's status sample. See `docs/infra/game-server-queries.md`
- Game servers: join options with a "Beitreten" button for UT2004 servers, choosing Steam (`steam://run/13230//<address>:<port>`) or a native install (`ut2004://<address>:<port>`); options come from a per-game template registry (`JoinLinkTemplates`), the choice is remembered in the browser, and a client-side dialog builds a `.reg`/`.desktop` file for the `ut2004://` handler. New optional UT2004 recipe steps register the handler. Untested against a real UT2004 installation; see `docs/infra/game-server-join-options.md`
- Game servers: "Runde neu starten" (`mp_restartgame 1`, `gg_restart` on GunGame servers) and "Karte neu laden" on the settings page, plus an Admin block (restart round, reload map, map picker with workshop id, confirmation dialogs) on the participant page `/servers` for orga and admin; new routes `POST servers/{serverLink}/restart-round|reload-map|map`. Console commands now require a running server
- Game servers: settings page `Server-Einstellungen` (admin panel, orga and admin) for permanent CS2 servers: name, map (allowlist or workshop id), mode, max players and password written to the Pelican startup variables, save and restart, live map change and start/stop/restart, plus the GunGame plugin form (settings and weapon order, merged into the server files). External servers are adopted into Pelican management with `php artisan gameservers:link-pelican` (stay `source=External`, new `PelicanClient` methods `updateStartupVariables`, `sendCommand`, `readFile`, `writeFile`); see `docs/infra/permanent-server-settings.md`
- Admin: admins can change another user's role from the user page ("Rolle ändern"); an admin cannot change their own role and the last admin cannot be demoted; changes are logged
- Files: uploads by an admin are approved on arrival (the admin is recorded as reviewer); uploads of everyone else still wait for approval
- Files: admins can set an upload quota per user (user page, "Dateikontingent"); empty keeps the default `FILES_PER_USER_QUOTA_MB`. New nullable `users.files_quota_mb`, not mass assignable; the quota check, the page readout and the native client API use it

### Changed

- Catering admin redesign: the round form is split into sections (Runde, Bestellfenster in Berlin time, Zahlung und Ticket with a PayPal link preview for an example amount); the edit page shows the status badge and a summary line (dishes, orders, sum, paid, open); the round list has status badge, window, order count, sum and open amount, a default filter hiding closed rounds, and row actions for open/close (with confirmation) and the group order; the ordered items are split into the tabs "Nach Gericht" and "Nach Person" with paid/open filter, search by person or dish and sum rows. The menu editor is unchanged
- Catering: clear order feedback (button spinner then check, success toast with price, receipt row highlight, mobile cart bar flash, one screen-reader announcement) and menu filter chips (my selection, up to 10 or 15 euro, N of M count, reset).
- Catering page redesign for long menus: dishes grouped by name prefix with search and a sticky category jump bar, add/remove buttons per dish (identical dishes shown as "2×"), the order as a sticky receipt ("Bon") on large screens and a bottom bar with a sheet on phones, note field moved into the receipt, calm ghost remove buttons
- Onboarding/MVP: the guest landing at `/` is only a login gate (mark, wordmark, tagline/hint, "Mit Discord anmelden"); `/launcher` and its downloads require login, and the chat (Now, event pages, dock) and the sidebar entry "Launcher" appear only after the Discord login
- Docs: the docs site is an optional `docs` compose profile (non-root nginx at `/docs`, read-only, healthcheck) with verified one-hour screenshot caching, `/docs/games` redirects, a prerendered introduction at `/docs/`, real 404s and HSTS matching the app
- Docs: built-output guard against doubled base paths (`/docs/docs/`) in `.md` raw anchors

### Fixed
- Onboarding: the public team pages keep the guest-safe shell, so no Spiele/Launcher/Server navigation appears before the Discord login; the guest landing and `/launcher` are covered by regression tests.
- MCP: `tools/list` omits `nextCursor` on the last page instead of sending `null` (invalid per the MCP schema; Claude Code rejected the whole tool list).
- Presence: a voice server that does not answer is skipped for two minutes (circuit breaker) instead of blocking the presence refresh and the queue worker for the sidecar timeout.
- Voice: the TeamSpeak sidecar stays on the ServerQuery allowlist (`scripts/ops/teamspeak-allow-sidecar.sh`, run by `deploy.sh`); a flood ban made every presence refresh time out and stalled the queue worker (docs/teamspeak-setup.md 4a).

- Fixed: the UT2004 query parser used a wrong byte layout (server info began one byte too early; the player list has no count byte). It now matches a real UT2004 server and the tests use captured answers

## 2026-11-20

### Added

- MCP server (`/mcp`, bearer tokens + device pairing at `/mcp/connect`) with tools for events, teams, tournaments, matches, files, servers, schedule, polls, catering, LFG and game recipes, plus the `lanomat://recipes/{slug}/skill` resource
- `lanomat-agent` (pi SDK) in `clients/agent/`: device login, MCP/skill setup, Venice AI model setup, LAN-first installer with a VRM avatar multi-select and parallel downloads
- Game recipes module: YAML + `SKILL.md` import (bundled/SMB/git), LAN file matching, install plans, contributor drafts and an admin review resource — the UT2004 + optional UT2004VR recipe, Ridge Racer Type 4 and 19 prepared drafts
- Zudoku docs site (`docs-site/`) with the Signalpult brand, screenshots, MCP/agent/recipe guides and an OpenAPI reference, deployable at `/docs`

### Fixed

- `gameservers:import-eggs` works in the production image: `symfony/yaml` is now a runtime dependency (#150)
- Venue entry: the TCP `HostSNI` rule no longer uses a wildcard (Traefik rejects it and disabled the router); it lists the app and the `ws.` name (#149)

### Changed

- Docs: venue network runbook for the CRS354 (flat LAN, LACP server link, LanCache DNS, WireGuard on the switch, measured routing limits and traps) and a verified cut-over section in the app-at-the-venue runbook (#149)

## 2026-11-01

### Added

- Desktop client: a Beitreten button on every place (voice room, game, server) of a person in Wo ist wer
- File host preflight check and certificate renewal (#109)
- Update watch for pinned third-party images (#110)
- Retract own gallery photos (uploader or orga) (#111)
- Dedicated MatchStatus::Live (#112)
- Native voice client API with device authorization pairing (#113)
- Rust core and CLI for native web voice (clients/voice) (#116)
- Tauri v2 desktop app on voice-core (#117)
- Chat, realtime auth and notifications on the native bearer API (#120)
- Event features in the native client API (#121)
- Native client play API (now feed, tournaments, matches, game servers) (#122)
- Profile, linked accounts and friends for the native client (#123)
- Core features: API clients, realtime chat, notifications, file transfers, command contract (#124)
- Profile, linked accounts and friends (Rust core + commands) (#125)
- Participant screens of the desktop client (#126)
- Where is everybody, and follow them (voice and game) (#127)
- Windows port and client release workflow (#128)
- Download the native client inside LANoMAT, always the server's version (#129)
- Freunde, Profil and Wo ist wer with Hinterherjoinen (#130)
- Desktop app entry points (nav, dashboard hint, settings, /app alias) (#131)
- Keep Discord avatars in LANoMAT and serve them from there (#132)
- Room audio: Denon AV receiver control for the orga (`RoomAudio` module, telnet sidecar, Raumsteuerung page, Preflight check) (#139)

### Changed

- Untrack stray .pyc and ignore bytecode (#114)
- Docs: Sync roadmap and README with the finished backlog (#115)
- Docs: UX concept for participants (#119)

### Fixed

- Paper preset needs USER_AGENT (#118)

## 2026-10-31

### Added

- LANoMAT GMod egg, server presets, port pool and A2S readiness (#60)
- Hypervisor registration, dry-run plans and queued runs (module off in core) (#61)
- Give event-level game servers their own voice channel (#74)
- Voice channels next to the per-match text channel (#79)
- Cloud entry point that passes TLS through to an app at the venue (#83)
- Show the Mumble server password on the voice setup page (#90)
- Delete voice rooms only once nobody is in them (#91)
- Remove empty voice rooms at once, keep the ones with people (#92)
- Category per event, room per team, archive for match text channels (#93)
- Voice join links carry the user's own name (#97)
- Permanent game servers across events (Pelican, Docker, external) (#99)
- Browser voice and text chat with self-hosted LiveKit (#100)
- Trusted TLS for Music Assistant, the Pelican panel and Mumble (#102)
- Allow all file types, serve downloads from the LanCache file host (#103)
- Resumable big-file uploads straight to the file host (tus) (#104)
- Latency diagnostics, self-test and headset mode (#107)

### Changed

- Request body caps, discord-gateway lockfile, team logo metadata stripping (#62)
- Docs: Record the dependency decision before the first LAN (#64)
- Docs: Record the local failure tests for Redis, Postgres and the worker (#65)
- Docs: Round 2 reports for tournaments, seating/schedule/catering and infoscreen (#66)
- Docs: Record rulings AD-AS from the round 2 fixes (#71)
- Docs: Runbook for wildcard TLS certificates on venue-only names (#75)
- Docs: Resolve the venue TLS name through the LanCache DNS and the MikroTik (#76)
- Docs: Variant with the WireGuard tunnel on the venue router (#77)
- Docs: Checklist for switching on the Discord bot features (#80)
- Docs: No load test, the event size does not need one (#81)
- Docs: Plan for moving the app to the venue behind a Cloudflare Tunnel (#82)
- Docs: Record the verified Cloudflare DNS-01 run (#84)
- Docs: Test suite on the VM, planned venue hardware (#88)

### Fixed

- Decide the Traefik recreate from the last deployed commit (#63)
- Beamer bracket fit, live scene list, once-only tombola draws, bounded inputs (#67)
- Confirmed registration gate, item limit, price bounds, per-option counts and CSV export (#68)
- Phone seat map, confirmed-registration gate, stable ICS, reminders and change alarms (#69)
- Orga decision on any match, race guards, roster rules, round-robin ties (#70)
- Make the TeamSpeak sidecar work against a real server (#72)
- Make the Music Assistant client match the real API (#73)
- Keep match channels private and the bot inside them (#78)
- Venue Traefik settings in a static file, drop ignored flag (#85)
- Venue entry as socat forwarder, tunnel test recorded (#86)
- Default locale de, APP_LOCALE in the runbook (#87)
- TeamSpeak sidecar spun on a closed connection (#89)
- Retry a failed channel creation at once; bot rights in team rooms (#94)
- Store each provider's rooms at once, take back half-built ones (#95)
- Match card action buttons wrap instead of overflowing (#96)
- Self-host the fonts so the frontend build needs no network (#98)
- 404 for missing stored files; pages no longer claim a failed load after a rejected form (#101)
- Mumble admin sidecar for Mumble 1.5, server moved to 1.5.939 (#105)
- Certificate group for Mumble 1.5, exporter corrects mode and group (#106)
- Name match rooms like the Discord ones (#108)

## 2026-10-30

### Added

- Harden prod deploy for lan.raute1.org (#24)
- Add production deploy script (#26)
- Link to admin panel from user menu (#27)
- Require login for all event pages (#29)
- Restrict Discord login to members of one guild (#31)
- Unified app shell, useful dashboard, page layout rules (#33)
- New LANoMAT logo mark (L of nodes) (#34)
- Admin marker, back link and shell parity guard (#40)
- Self check-in via on-site QR and walk-in registration (#41)
- Arrival planning and orga forecast overview (#42)
- Restore drill script, restore runbook (drill passed against real backups) (#55)

### Changed

- Bump actions/setup-node from 7.0.0 to 7.1.0 in the github-actions group across 1 directory (#21)
- Docs: Document LANoMAT brand identity and adopt qcode standard (#32)
- Track newest PHP/Node patches, split CI into parallel jobs (#35)
- Guard the public repo (gitleaks, public-safety checker, gitignore) (#46)
- Docs: Public-safe recipe for the Proxmox test VM with LanCache (#48)
- Docs: WireGuard tunnel, Pelican panel/Wings and Garry's Mod TTT (public-safe) (#52)
- Docs: Roadmap, README and CLAUDE.md up to date (pre-LAN checklist), brand docs reflect the new logo (#54)
- Docs: Repeatable, public-safe QA handbook (plan, inventory, findings, runs, seed) (#56)
- Patch composer/npm advisories, route authorization matrix, hardening (#57)

### Fixed

- Bump Traefik to v3.6 for Docker 29 (#25)
- Redis numeric markers and Filament-native widget (#28)
- Steam link 500 on null approvedScopes (#30)
- Constrain panel logo, align navigation with the app (#39)
- QA round 1 (robustness, ops, tournament/registration rules, timezone) (#43)
- Accept tiny valid storage archives in backup verification (#45)
- Allowlist RFC1918 range constants in the infra recipe (#49)
- QA round 4 (queued notifications, Mehr nav, overlays, a11y, copy) (#50)
- Rebuild PelicanClient against the real Pelican API (#51)
- Recreate Traefik when its bind-mounted config changed (#53)
