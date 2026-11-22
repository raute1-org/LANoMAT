# Client store library: Steam, Epic, GOG and Ubisoft games in the LANoMAT client

Status: draft for owner approval (2026-11-22).
Related: [`2026-11-22-all-in-one-client-design.md`](2026-11-22-all-in-one-client-design.md),
[ADR](../decisions/2026-11-22-all-in-one-client-adr.md) (this design amends decision 11).

## Intent

The owner wants the LANoMAT desktop client to be the one place where they start games, instead of
opening Steam, the Epic Games Launcher, GOG Galaxy and Ubisoft Connect separately. The client already
has a "Spiele" tab for the LAN's own games (recipes from the server, all-in-one phase 1). This design
adds the games people already own in the four stores to the same tab, and links both worlds: the same
game appears once, and at a LAN the client tells you whether it runs here, belongs to the tournament
lineup, and who else has it.

Owner decisions (2026-11-22):

1. **Both uses matter equally:** a daily launcher at home, which must work without a LANoMAT server
   and without a running event, and a LAN tool.
2. **Windows first**, Linux as a later stage.
3. **Approach A:** the client reads each launcher's own data files directly (one source per store).
4. **Steam ownership included:** games you own on Steam but have not installed appear too, through
   the Steam account you can already link in LANoMAT. Epic, GOG and Ubisoft show installed games only.
5. **Reporting the game list to the server is opt-in:** off by default; with consent the server learns
   which games you have, for LAN features such as "who has this game".
6. **Stage 1 before all-in-one phase 2**, and **the owner tests stage 1 on their own Windows PC**.

## Goals

- One library in the client's "Spiele" tab: installed games from Steam, Epic, GOG and Ubisoft
  (Windows), owned-but-not-installed Steam games, and the server's LAN games (recipes).
- Start any installed store game from LANoMAT in one click, through the store's official handoff.
- The library works offline: no server, no pairing, no event needed for the local part.
- With a server: LAN facts on each game (runs on this LAN, in the lineup, friends playing it, join).
- With consent: the server knows who has which game, so LAN features can count and match.

## Non-goals (first version)

- No store logins in LANoMAT, no store credentials or tokens stored by the client.
- No installing, updating, repairing or uninstalling through the stores; "Installieren" for a store
  game opens the store at that game.
- No Epic, GOG or Ubisoft ownership lists (they need store logins; see Later).
- No Linux sources yet (stage 4), no macOS.
- No playtime tracking or achievements.
- No replacement of the stores' background services: Steam and Ubisoft Connect still run in the
  background when their games start; LANoMAT is the face, not the DRM.

## Anti-goals (failure even if it works)

- The client asks for or keeps a store password or session.
- A game list leaves the machine without the person's consent, or the server shows it to anyone
  but the people the setting allows.
- The library breaks (empty tab, error wall) when the server is down or the client is not paired.
- A malformed or hostile launcher file crashes the client or makes it start something that is not
  the store's own handoff.

## Constraints

- Rust core in the `clients/voice` workspace, Tauri v2 app, plain ES-module UI without a build step,
  Signalpult design (`docs/design.md`), German UI copy in `strings.js`.
- Builds only in Docker (`clients/voice/Dockerfile.dev`), Windows cross-compiled; never a host toolchain.
- Readers are pure functions over file contents or registry values, testable on Linux with fixtures.
- Every new native-client API route goes into `tests/Feature/Security/RouteAuthorizationMatrixTest.php`.
- The repo is public: fixtures contain made-up paths, ids and names only.
- Start handoffs go through one validated URL builder per store (same spirit as `app/joinurl.rs`):
  only the store's scheme, only an id of the expected shape.

## Approaches considered

| | Approach | Trade-off | Decision |
|---|---|---|---|
| A | Read each launcher's own data (Steam VDF/ACF, Epic manifests, GOG and Ubisoft registry) | Offline, no extra software, store ids for launch and recipe matching; a source needs a fix when a store changes its format (rare) | **Chosen** |
| B | Read Playnite's library | Little own code; requires Playnite on every PC, no Linux | Rejected |
| C | Scan Windows uninstall entries | One mechanism; no store ids, so no clean launch and no reliable matching | Rejected |

## Design

### Stages

Each stage ships on its own and is useful without the next.

1. **Local library (Windows)**: four sources, merged list, launch, offline-capable tab.
2. **Steam ownership**: owned-not-installed Steam games via the server.
3. **Opt-in report and LAN features**: game list to the server, "who has it", lineup coverage.
4. **Linux sources**: Steam (native), Heroic (Epic/GOG/Amazon), Lutris.

### Stage 1: the local library

**Sources.** A `StoreSource` trait with one implementation per store; each returns
`Vec<LocalGame>` and never panics on bad input (a broken entry is skipped and counted).

| Store | Where (Windows) | What we take |
|---|---|---|
| Steam | install path from registry `HKCU\Software\Valve\Steam\SteamPath`, falling back to `HKLM\SOFTWARE\WOW6432Node\Valve\Steam\InstallPath` (32-bit view) *(controller's call)*; `steamapps/libraryfolders.vdf`; each library's `appmanifest_<appid>.acf` | app id, name, install dir, state flags (fully installed vs updating) |
| Epic | `%ProgramData%\Epic\EpicGamesLauncher\Data\Manifests\*.item` (JSON) | `AppName`, `CatalogNamespace`, `CatalogItemId`, `DisplayName`, `InstallLocation` |
| GOG | registry `HKLM\SOFTWARE\WOW6432Node\GOG.com\Games\<id>` | game id, name, path, exe |
| Ubisoft | registry `HKLM\SOFTWARE\WOW6432Node\Ubisoft\Launcher\Installs\<id>` | install id, install dir; name from the install folder (the launcher's name cache is not a stable format) |

`LocalGame` carries: `store` (enum `steam|epic|gog|ubisoft`), `store_id` (the store's own id),
`title`, `install_path`, `status` (`ready`, `updating`, `broken`: path missing), and a stable library
key `"<store>:<store_id>"`.

The Steam VDF/ACF parser is a small text parser of our own (the format is a nested key/value text);
registry access sits behind a `Registry` trait so sources are pure in tests. A scan runs on app start,
when the tab opens and on a manual "Neu einlesen", never in a tight loop; results are cached in memory.

**Launch.** One builder per store returns the handoff URL; the app opens it with the existing
`open_url` path (Tauri opener). Ids are validated before building:

| Store | Handoff | Id shape |
|---|---|---|
| Steam | `steam://rungameid/<appid>` | digits |
| Epic | `com.epicgames.launcher://apps/<namespace>%3A<catalogItemId>%3A<appName>?action=launch&silent=true` | `[A-Za-z0-9._-]` parts |
| GOG | `goggalaxy://openGameView/<id>` when Galaxy is installed (registry `HKLM\SOFTWARE\WOW6432Node\GOG.com\GalaxyClient\paths\client` exists, *controller's call*), otherwise the registry `exe` started directly (working dir = install path) | digits |
| Ubisoft | `uplay://launch/<id>/0` | digits |

Direct starts (GOG without Galaxy) only run an `.exe` inside the game's own install folder as found
in the registry; anything else is refused. "Installieren"/"Im Shop öffnen" uses the store's
store-page handoff (e.g. `steam://store/<appid>`), validated the same way.

**Recipe matching.** A LAN recipe and a store game are "the same game" only through an explicit id:
recipes gain an optional `metadata.store_ids` map (`steam`, `epic`, `gog`, `ubisoft`), validated by
`RecipeValidator`, exported by `RecipeYamlExporter`, and served in the recipe list. A recipe whose
launch target is `steam` with an app id counts as `store_ids.steam` without extra data. No matching
by title.

**One list.** The tab shows one list of library entries; an entry is a store game, a recipe, or both
(matched). Merge rules: a matched entry shows the recipe's LAN facts and the store game's launch; the
primary action is "Spielen" (store launch) when installed, otherwise the recipe's own install path
(phase 2 of the all-in-one plan) or "Im Shop öffnen". Filters gain "Quelle" (Steam, Epic, GOG,
Ubisoft, LAN) next to the existing ones; search covers all entries.

**Offline and unpaired.** Acceptance for stage 1 includes: with the client signed out and no network,
the person can open "Spiele", see and start their installed store games. The "Spiele" tab and the
local library no longer require pairing:
`games_state` reports the local part as available on Windows even when signed out; the recipe part
keeps its current reasons (`not_paired`, `module_off`, `server_too_old`) and shows them as one line
inside the tab, not as a blocking screen. The app shell lets a signed-out person reach the tab
(today the connect screen covers everything); exactly how is left to the builder within this rule.

**Where it lives.** A new module `store_library` in `launcher-core` (or a sibling crate if the builder
finds `launcher-core` the wrong home), Tauri commands `library_scan`, `library_list`, `library_launch`,
`library_open_store`, and UI in `games.js`/`games-model.js` (pure logic stays testable with
`node --test`).

### Stage 2: Steam ownership

- Server: a voice-client route `GET /api/voice-client/v1/library/steam-owned` returns
  `{ status, fetched_at, games: [{ app_id, name }] }` for the caller's linked Steam account (the
  account comes from `linked_accounts`, never from the request), from Steam's
  `IPlayerService/GetOwnedGames` with `include_appinfo=1` (extend `SteamConnector`, which already
  calls it per app with `services.steam.client_secret`; the key is set in production). Cached per
  account for 6 h; `?refresh=1` bypasses the cache at most once per 10 minutes per user
  *(controller's calls)*. `status` is `ok`, `not_linked`, `private` (Steam answers
  `{"response": {}}` without a `games` key, see `SteamConnector`) or `unavailable` (API error; the
  last cached list is returned when there is one).
- States: no linked Steam account → a hint "Steam-Konto verknüpfen" (links to the connections page);
  profile/game details private → a hint how to make "Spieldetails" public; Steam API down → last
  cached list or nothing, never an error wall.
- Client: owned-not-installed Steam games join the list with status `not_installed` and the action
  "Installieren" (opens `steam://install/<appid>`). Cached locally so they stay visible offline.
- Route goes into the route authorization matrix (own account only, no user id in the path).

### Stage 3: opt-in report and LAN features

- Setting "Meine Spieleliste für LAN-Funktionen teilen", default off, in the client and on the
  profile page, modelled on the existing `share_location` opt-out but inverted (opt-in).
- With consent the client reports the library keys it has (store + store id + title, installed or
  owned; no paths) to `PUT /api/voice-client/v1/library` after a scan, debounced; switching the
  setting off deletes the server copy at once.
- Server *(controller's calls)*: a new optional module `GameLibrary` (enum case, `MODULE_GAME_LIBRARY`,
  on in `full`, off in `core`), owning one table `user_game_library` (user, store, store_id, title,
  installed bool, owned bool, updated_at; unique per user+store+store_id). The server is the single
  source of truth for the setting (a user column or a module table, builder's choice); client and
  web profile both write it through the API, last write wins. It reaches other modules only through
  existing read models or contracts with null implementations, per the architecture ratchet:
  lineup and LAN servers through GameRecipes' `LauncherLineup` and the recipe/server mapping,
  friends and blocks through the Friends module's existing contract. Read models:
  - per game: how many **checked-in** participants of the current event have it; visible to
    participants and orga, counts only;
  - per person: "hat es" visible to that person's **friends only**; a block hides it both ways.
- **Matching on the server goes through recipes only** *(controller's call)*: a library key matches
  a LAN game when a recipe carries that store id (`metadata.store_ids` or a `steam` launch target).
  Lineup, "läuft auf dieser LAN" and per-event counts therefore cover games that have a recipe; the
  Games and Tournaments models get no store ids in this design.
- Client LAN facts on an entry: "Läuft auf dieser LAN" (a server of that game exists),
  "Im Turnier-Lineup", "N Freunde haben es", "Beitreten" (existing join path, `steam://connect` rule).
- Retention: the list is deleted when the setting goes off or the account is deleted.

### Stage 4: Linux

Sources for Steam on Linux (`~/.local/share/Steam`, Flatpak path), Heroic (its `legendaryConfig`
and `gog_store` JSON, launching via `heroic://launch/...`) and Lutris (its SQLite DB, `lutris:rungameid/<id>`).
Same trait, same validated builders.

### ADR amendment

Decision 11 ("Steam/Epic: no automation; only `steam://connect`") becomes: *no automation of store
installs or accounts; reading local launcher data and starting or opening games through the stores'
own handoff URLs (validated per store) is allowed, and so is `steam://connect`.* The ADR row is updated
in the same pull request as stage 1.

### Coordination

The "Spiele" tab and `launcher-core` are also where all-in-one phase 2 (recipe install and start)
will be built. **Owner decision: stage 1 comes first** and defines the merged-list model (an entry is a
store game, a recipe, or both); phase 2 builds on it. Stage 1's plan starts from the then-current
`main`.

### Verification

Fixture tests for every source and URL builder run on Linux in CI. **The owner verifies stage 1 on
their own Windows PC** with Steam, Epic, GOG and Ubisoft installed: the build ships a Windows test
build and a short checklist (each store's games listed, start works, broken path shown, signed-out
and offline use).

## Error and edge cases (the builder must cover)

- Steam with several library folders, one of them on a removed drive.
- A manifest or registry entry pointing to a folder that no longer exists → `broken`, shown, not hidden.
- Malformed VDF/JSON/registry data → entry skipped, the rest of the library still loads.
- A store not installed at all → that source is silent, no error.
- The same game installed in two stores → two entries (different store ids), both may match one recipe.
- Very large libraries (thousands of owned Steam games) → list stays responsive.

## Left to the builder

Crate placement, exact command names, caching details, the UI layout of the merged list and its four
states (within `docs/design.md`), how the shell exposes the tab while signed out, the table design of
stage 3, and the Steam ownership cache duration.

## Later (not planned here)

Epic/GOG ownership through Heroic or Legendary, store installs and updates, playtime, macOS.
