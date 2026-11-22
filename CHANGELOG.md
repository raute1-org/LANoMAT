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

## 2026-11-22

### Added

- Docs: `AGENTS.md` for coding agents and `docs/setup/agent-setup.md`, a guide that lets an agent set LANoMAT up for your own LAN (README section and `CLAUDE.md` import `AGENTS.md`)
- Native client API: `GET /api/voice-client/v1/recipes`, `recipes/{slug}` and `recipes/{slug}/plan` give the voice token the game library and the install plan (published recipes only, exact slug, GameRecipes module gated); the plan carries `plan_version` and signed file-host links minted through the file policy (`InstallPlanUrls` strategy: the agent API keeps `mcp.files.download`); `GET config` reports `features.launcher`. `api_version` stays 1
- Native client (all-in-one, phase 1): game library core in `clients/voice`: `voice-core` recipe/plan client (`api/recipes.rs`, `plan_version` check, `features.launcher`), the install register with status automaton, quarantine and one-time import of the old launcher's `installs.json` (never its token) in `launcher-core`/`app`, and the Tauri commands `games_state`, `games_list`, `game_detail` (availability from `dry_run=1` plans, budgeted and cached)
- Native client UI: the "Spiele" tab, a read-only game library in the Signalpult design (cards with availability on the LAN host and local state, search, genre and ready/installable filters, lineup-first sorting, detail view with plan summary and missing files; install and start are an explained placeholder until phase 2). Shown only when `games_state` says so; pure logic covered by `node --test app/tests/games-model.test.mjs`
- Server browser: "Hier spielen" — the LANoMAT accounts on a server from the Presence read model (`ServerOccupants` contract, adapter in WebVoice, resolved once per request) as up to five faces plus a count on server cards and rows of `/servers`, `/servers/all` and an event's servers; shown to registered participants of a publicly visible event and to orga (`EventPolicy::viewPeople`), and `share_location` and blocks stay Presence's rule, so a hidden person is neither listed nor counted and the viewer always sees themselves
- Server browser: a row's install block carries `lanReady`, and both launcher handoffs say one calm line when not all of the game's files are on the LAN ("eine Neuinstallation kann deshalb scheitern"; the launcher handoff stays)
- Recipe pull requests: the panel shows the catalogue readiness before anything is sent — "Textprüfung für den Katalog" in the section "Katalog (Pull Request)" (what a draft is missing and what would still be missing once published) and one warning line in both publish confirmations; the recipe list shows the first line of the reason under "Nicht gesendet" / "Fehlgeschlagen"
- MCP/REST: `recipe_contribute` takes `skill_markdown` and both contribution answers carry `catalog` (`pull_requests_enabled`, `problems`, `problems_when_published`), so an assistant can finish a contribution until the catalogue would take it; a new resource `lanomat://skills/contribute-recipe` is the how-to
- Install plans and recipe details: `{{server_host}}` and `{{server_port}}` are filled in before the text leaves the server — in step commands, step checks, launch targets and the recipe detail (REST, MCP `recipe_get`, native client) — from a server whose game slug is exactly the recipe's slug, `Ready` or `Antwortet nicht`, never one switched off, failed or being set up (an answering server first, then a permanent one, then the lowest id; host letters/digits/dot/hyphen, port 1–65535, everything else empty)
- Game sharing: the share page selects the archive that was just uploaded on both upload paths, while a file the participant picked by hand stays selected
- Files: "Als Spiel teilen" appears only where the share page would take the archive — the Files-owned `ShareableGameArchive` contract answers per row (`shareableAsGame`) with `PortableRecipeBuilder::isShareableArchive()`
- Native client store library (stage 1, Windows): the "Spiele" tab lists installed Steam, Epic, GOG and Ubisoft games read from the launchers' own data next to the LAN's recipes, starts them through each store's handoff (a GOG game without Galaxy only from its own folder), works signed out and with the server unreachable (the server side, state check and recipe list, is bounded to three seconds; opening the tab never waits on a dead server), rescans when the tab opens and on "Neu einlesen", leaves out Steam tools, Epic DLC and engine plugins, and lets a Steam game that only needs an update still start (#PR)
- Server: optional recipe `metadata.store_ids` (`steam`, `epic`, `gog`, `ubisoft`) matches a recipe to the store games with the same id, validated by the recipe schema and served in the recipes API (#PR)
- Agent: `install` takes `--target`, and the path placeholders are checked before anything is downloaded, created or run
- Recipe form: a requirement's `os`, `manual`, notes, author, licence and tags are shown read-only at review (they have no form field)
- Smoke: `scripts/smoke/game-sharing-smoke.mjs` with `database/seeders/GameSharingSmokeSeeder.php` drives the whole journey in a real browser (headless Chromium via Playwright) — share with a form upload, the refusals, the orga's review and one-step publish, find the game, the server row's actions, the missing-files line, and the launcher link without a handler; per-step screenshots, `SMOKE_STRICT=1` turns findings into failures; never production (`migrate:fresh`, and the seeder refuses production). Documents: `scripts/smoke/README.md`

### Changed

- One download entry for the LANoMAT app: the sidebar, header, Filament navigation/topbar and all links use the label "App herunterladen" and lead to the unified page `/app` (voice app and launcher side by side while both builds exist); `/launcher` and `/talk/client` redirect there, file routes unchanged; the launcher page's live-server list is removed (use `/servers`)
- Docs/spike: all-in-one client phase 0 (decision record, workspace-merge and deep-link spike, download-engine comparison, recipe inventory); spike code in `clients/voice` is not for production
- Game recipes: one lineup rule (`LauncherLineup::allowsSlug()`) for the panel flag and the server install block, so a case-sensitive catalogue query can no longer disagree with `allows()`; the unused `LauncherLineup::DEFAULT` constant is gone, the default lives only in `config/launcher.php`
- Server rows: a launcher handoff that cannot name the server is labelled "Im Launcher starten" with its own hint ("Den Server wählst du dann im Spiel"), "Im Launcher beitreten" only when the link carries `?address=` or `?url=` — on `/servers`, `/servers/all`, an event's server list and the game page
- "Hier spielen" is answered for the audience of the event's people list (registered participant of that event, or orga); a logged-in account alone no longer learns who is on a server
- Missing files on the LAN: the one calm line under the install actions now says a fresh install can fail ("Für dieses Spiel liegen gerade nicht alle Dateien auf dem LAN-Server; eine Neuinstallation kann deshalb scheitern") instead of claiming the join will not work — the check is cautious about the platform
- Sharing: the share page refuses archive names the installing client would store under another name (`:` `"` `<` `>` `|`, a leading dot, two dots in a row) and the archive list names the reason; a finished share ends the "picked by hand" state
- Recipes: a contribution's file row keeps an admin's pin only while the requirement still names the same file (patterns and digest); a renamed or duplicated requirement never inherits one
- MCP: the contribution answer, the tool description and the how-to say that a second `recipe_contribute` call REPLACES the draft — the complete recipe must be sent and an omitted field is cleared
- Public-safety guard: `scripts/ops/check-public-safe.sh` also scans `resources/recipes/`, `config/` and `lang/`; the allowlist entry for the NFSU2 recipe (it no longer carries an address) is gone
- Docs: screenshots of the gaming surfaces the two rounds changed (`docs/screenshots/`: share page, share form and "Deine geteilten Spiele", server rows and the missing-files line, the launcher-link notice, the admin review)
- CI: workflow "Frontend guards" runs the JS unit suites (`npm run test:js`) and the launcher UI contract `clients/launcher/app/ui/check-ui.mjs` on every relevant pull request; both stand in `ci.yml`'s frontend job as well

### Fixed

- Native client: no more flicker between "Verbunden" and the offline banner while the server is unreachable. Only server-backed commands mark the app online, and the setup after connecting runs single-flight with backoff (2 s up to 60 s); the old loop also grew the app by about 100 MB a minute. The header shows the connection state when no name is known (#208)
- Recipes: the NFSU2 recipe no longer carries a test host address in its steps or metadata; the Windows and Linux steps take `{{server_host}}` instead and only rewrite the host lines of `net_u2.ini` when the plan carried one, so a LANoMAT install on another LAN no longer points the client at a machine that may not exist
- Recipes: an install plan wrote the browser twin's public front end into a native game's configuration when no native server existed; a plan (and the recipe detail) now takes the host only from a server of exactly the recipe's game
- Launcher: `NFS_SERVER_HOST` has no default any more (a test machine's private address was baked in), and `LauncherLineupSeeder` registers no native NFSU2 server without it and says so on the console — including that it left an earlier run's server (and its old address) as it is
- Match page: the server join renders outline next to the match card's own primary (report/confirm/go live), not as a second amber button
- Game share: the pull request state is typed as the five `PullRequestState` values, and the quota gauge reads in full contrast (warning dot and one sentence instead of warn-coloured numbers and a low-contrast bar)
- Agent: an install folder whose name would end the quoting of the recipe's commands is refused before the download (quote, `$`, backtick, control character, `;`, `&`, `|`, `<`, `>`; on Windows also the curly quotes PowerShell reads as quotation marks and `%`, `!`, `^`), naming the character and the folder instead of running a broken command; a leading `~/` is resolved for the plan's folder and for `--target` (it was pasted literally into quoted commands on Linux and macOS)
- Files: a multipart upload stored the browser's raw file name while the resumable path stored a cleaned one; both now use `UploadFileName::clean()` (control, invisible and bidirectional formatting characters, path parts and anything past 255 characters removed), a name that cleans to nothing gets its own message on both paths, and a name stored before the cleaning is cleaned again when the download is offered
- MCP: a recipe that fails the schema check on `POST /api/v1/recipes` answers its problems (422) instead of a server error; `SKILL.md` and the skill description keep line breaks and tabs and nothing else from the control range on every contribution path, and the tool schema names the 20–1024 character range of `skill_description`
- Game recipes: editing a contribution deleted and recreated every file row, so a stored `recipe_file_id` (launcher plan references) pointed at a deleted row; rows are updated in place, keyed by role and label, and an admin pin survives while its requirement names the same file
- Game sharing: a plain form upload on the share page did not select the new archive although the page promises it (found by the browser run); the list also called a real but unusable archive "no archive" instead of naming its name as the reason
- Game sharing: a published shared game kept calling itself a draft in its description, its step notes and its `SKILL.md`; publishing replaces those sentences with their published form
- Games pages: they named another event than the shell did and said "● LIVE" for an event that was not live; both now follow `CurrentEvent` and the event's status
- Recipe panel: the publish confirmation read "1 Schrittbefehle und 1 Startbefehle" for a single command, and a requirement's platform now shows by name ("Nur für Windows")
- Launcher: the notice under a launcher button pushed its row's other actions to the middle; the row keeps its first action on the left

## 2026-11-21

### Added

- Mordhau: the community egg (`resources/pelican-eggs/mordhau.json`, MIT, now also read by `RepositoryEggs`), seeded `horde`/`deathmatch`/`frontline` presets (32 slots, 4 GB) and the new preset field `fixed_ports` (fixed node ports instead of pool ports; missing allocations are created, a port held by another server is refused with a readable error; editable in the game form). Docs: `docs/infra/mordhau.md`
- Preflight: the AI assistant probe now knows the `openai` provider (`GET /models` with the bearer key; 401/403 down, unreachable warn, configured model missing from the account warn); docs and `.env.example` list `OPENAI_API_KEY`
- Game sharing: participants share a ready-to-run game on `/games/share` ("Spiel teilen" on `/games`, on an own archive under Dateien, and in the launcher): pick an uploaded `.zip` or `.tar.bz2`, name the start file, and a draft portable recipe with that file pinned is created; "Deine geteilten Spiele" shows file, review and catalogue state. The orga approves the pinned file and publishes in one panel action ("Dateien freigeben und veröffentlichen"), sees every launch command before publishing, and a published shared game joins the players' list through the new `game_recipes.in_lineup` switch ("In der Spieleliste zeigen") without a config change. A participant holds at most `RECIPES_MAX_DRAFTS_PER_USER` drafts (default 20). Guide: `docs-site/pages/guides/share-a-game.mdx`
- Launcher support is decided in one place (`LauncherSupport`): every recipe with `metadata.portable: true` and the portable shape (required base archive, a file check, a launch target) is installable by the launcher, next to the UT2004 pilot, NFSU2 and browser recipes; `metadata.launcher_supported` is the orga's override ("Automatisch entscheiden / Immer anbieten / Nie anbieten" in the panel)
- Server browser to game: every server row on `/servers`, `/servers/all`, Jetzt, `/launcher`, the game page and `GET /api/v1/servers` carries an `install` block with the published recipe of its game: "Im Launcher beitreten" (installs when needed, then joins), "Spiel installieren", "Mitspieler suchen". One matcher (`RecipeGameMatcher`) replaces three
- Recipe pull requests (off by default, `RECIPES_PR_*`): a shared or panel-written recipe is exported as `recipe.yaml`, `SKILL.md` and a catalogue row and proposed to the git repository as one pull request per recipe (`recipes/<slug>`) when the orga publishes (`RECIPES_PR_TRIGGER=contribute` opens it on sharing). A public-safety check and a catalogue readiness check run before anything is sent; limits per contributor and per installation; state in the panel and on the share page; `lanomat:recipes:propose`, hourly `lanomat:recipes:pr-status`, Preflight check `recipe_repository`. Runbook: `docs/infra/recipe-pull-requests.md`
- Launcher links answer: every `lanomat://` action on the website notices when no launcher took over and offers the download, a retry and the manual way ("Kein Launcher-Fenster aufgegangen?")
- Recipes: `lanomat:recipes:export --format=repository` writes the repository layout and every bundled recipe round-trips through it; schema keys `portable`, `launcher_supported`, `multiplayer` and `manual`; the validator checks `_catalog.md` and takes a directory (`node validate.mjs [dir]`); GitHub workflow `recipes.yml` validates recipe pull requests
- REST/MCP: `recipe_contribute` and `POST /api/v1/recipes` forward version, homepage, licence, launch targets, play URL, the portable flag and a pinned LAN file the caller may download; recipe answers carry `launcher_supported`, `portable`, `in_lineup` and, for the contributor and the orga, `pull_request`
- Launcher UI: "Spiel teilen" opens the share page of the paired server; copy and install phase labels fit a catalogue of several games
- Files: nightly `lanomat:files:hash --limit=20`, because resumable uploads arrive without a digest
- AI assistant (orga chat page): `Orga/Assistant` with a history list, a live message stream, compact tool chips and approval cards (Ausführen/Ablehnen, password typed on the card, several cards decided together, old cards turn into "Überholt"), an "Assistent" entry in the Orga sidebar group and the admin navigation (same label), and the read-only "Assistent-Protokoll" in the admin panel (Nutzer group; never shows a stored password)
- AI assistant (backend): the optional `Assistant` module (off in `core`) adds an orga-only chat API at `/orga/assistant` on first-party `laravel/ai` (Claude by default, Ollama as the offline alternative with the full tool set). Seven read tools and ten write tools (tournament create/update/advance, start/configure/map/power/console/restart-round of game servers, admin-only permanent server creation); every write pauses for a preview card and runs only after an approve, logged in `assistant_actions`; passwords are typed on the card and never reach the model; conversations are private to their owner; Preflight probes the provider. New `CreateTournament`/`UpdateTournament` actions (the Filament create page uses the action). See `docs/infra/assistant.md`
- Docs: `catering:import-dish-media` takes the restaurant (slug or id) as its first argument, the old round-id call becomes `- --round=<id>` (docs-site catering module page)
- Catering: set menus ("Menü für 2 Personen"). A dish can carry optional `choices` (up to 5 groups with an exact count of 1 to 10, offered from menu categories or a fixed dish list, repeats optional); participants assemble the menu in a picker dialog (progress, radio/checkbox/stepper by group, search), the server validates the selection completely, orders store the picks as name snapshots, the receipt shows them as sub-lines, the group order and CSV count them under the menu, picked dishes are protected like ordered ones, the menu editor has an **Auswahl** section, `catering_menu_get/preview/import` and the native client API accept and return `choices` (additive, api version 1). Old menus and orders are unchanged; no migration
- Catering: photo previews in the restaurant menu editor. The menu table shows a lazy thumbnail per dish instead of a tick, the dish modal a larger preview and the restaurant form the current logo; served by orga-only routes (`/orga/catering/restaurants/{restaurant}/image/{key}` and `/logo`, `role:orga` plus the `view` policy, private cache) so the public image routes keep their "published round of a visible event" rule
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

- Docs: all-in-one client design and plan (`docs/superpowers/specs/2026-11-22-all-in-one-client-design.md`, `docs/superpowers/plans/2026-11-22-all-in-one-client.md`): merge the Rust launcher into the voice app, one pairing, one release
- Games: the game page offers each server's join in an honest order (launcher, the game's own join options, a join link, else the address to copy) and has loading and error states; the game page's only amber action is "Installieren"
- Install plans: every recipe gets its own folder on macOS (only the UT2004 pilot keeps Application Support), and the Windows folder is built from the title with letters, digits, space, dot, underscore and hyphen only
- Prettier no longer checks `recipe.yaml` and `SKILL.md` under `resources/recipes`; the schema validator is their gate
- Catering admin redesign: the round form is split into sections (Runde, Bestellfenster in Berlin time, Zahlung und Ticket with a PayPal link preview for an example amount); the edit page shows the status badge and a summary line (dishes, orders, sum, paid, open); the round list has status badge, window, order count, sum and open amount, a default filter hiding closed rounds, and row actions for open/close (with confirmation) and the group order; the ordered items are split into the tabs "Nach Gericht" and "Nach Person" with paid/open filter, search by person or dish and sum rows. The menu editor is unchanged
- Catering: clear order feedback (button spinner then check, success toast with price, receipt row highlight, mobile cart bar flash, one screen-reader announcement) and menu filter chips (my selection, up to 10 or 15 euro, N of M count, reset).
- Catering page redesign for long menus: dishes grouped by name prefix with search and a sticky category jump bar, add/remove buttons per dish (identical dishes shown as "2×"), the order as a sticky receipt ("Bon") on large screens and a bottom bar with a sheet on phones, note field moved into the receipt, calm ghost remove buttons
- Onboarding/MVP: the guest landing at `/` is only a login gate (mark, wordmark, tagline/hint, "Mit Discord anmelden"); `/launcher` and its downloads require login, and the chat (Now, event pages, dock) and the sidebar entry "Launcher" appear only after the Discord login
- Docs: the docs site is an optional `docs` compose profile (non-root nginx at `/docs`, read-only, healthcheck) with verified one-hour screenshot caching, `/docs/games` redirects, a prerendered introduction at `/docs/`, real 404s and HSTS matching the app
- Docs: built-output guard against doubled base paths (`/docs/docs/`) in `.md` raw anchors

### Fixed

- AI assistant: the chat showed "Der Assistent antwortet gerade nicht" although the server had answered. The page fed Vue reactive state to `structuredClone`, which throws a DataCloneError on a Proxy, so the first stream part aborted the turn; the reducer now copies safely, the stream reader tolerates unknown parts, CRLF, a missing `[DONE]` and a connection drop after the answer started, and failed runs are logged (masked) in the server log. The page header shows the real provider and model (OpenAI is no longer labelled "Claude"). The Caddyfile excludes the assistant stream endpoints from compression. Answers render a safe Markdown subset (bold, italic, code, lists, paragraphs, http(s)/relative links) instead of raw asterisks; the parser builds typed nodes and Vue elements, never HTML strings.
- Recipes: `lanomat:recipes:sync --only=<slug> --prune` deleted every other recipe of the source, and `--prune` on an empty or unreadable source deleted all of them; a recipe that still has a directory in the source is never pruned now
- Recipes: a recipe title could reach a shell through the install folder, which is substituted into quoted command arguments; found in review, before any release
- Recipes: editing a contributed draft no longer wipes its launch targets and pinned files; a base file is required by default, as in the YAML parser
- Recipes: a recipe with a macOS step could not be saved in the admin panel
- Games: the game page built a `steam://connect/` link for every server, also for games that are not on Steam
- Recipe catalogue: the validator is green again (`uevr`, `god-of-war-iii-remastered`)
- Docs: the OpenAPI file had a duplicated schema key that broke the docs build
- Onboarding: the public team pages keep the guest-safe shell, so no Spiele/Launcher/Server navigation appears before the Discord login; the guest landing and `/launcher` are covered by regression tests.
- Light control page: the "last checked" time no longer crashes the page (500) when the bridge is online; it now uses `DisplayTimezone::local()` instead of calling `setTimezone()` with a string on a raw `DateTimeImmutable`
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
