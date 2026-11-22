# Client Store Library, Stage 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The LANoMAT desktop client lists the Steam, Epic, GOG and Ubisoft games installed on a Windows PC next to the LAN's recipe games, starts them through each store's own handoff, and does so signed out and offline.

**Architecture:** Pure readers in `launcher-core::store_library` (one source per store over a `Registry` and a `FileSystem` trait, fixture-tested on Linux) plus validated handoff builders; the Tauri app caches a scan, merges it with the recipe list into one `LibraryEntry` list (the model all-in-one phase 2 builds on) and exposes four commands; the "Spiele" tab renders that list and becomes reachable without pairing. The server adds an optional `metadata.store_ids` map to recipes so a store game and a recipe can be the same entry.

**Tech Stack:** Rust (Tauri v2, `clients/voice` workspace, Docker-only builds, `cargo-xwin` for Windows), the `windows-registry` crate (Windows only), plain ES-module UI (`node --test`), Laravel 13 / Pest for the recipe change.

**Spec:** [`docs/superpowers/specs/2026-11-22-client-store-library-design.md`](../specs/2026-11-22-client-store-library-design.md). This plan covers stage 1 only.

## Global Constraints

- Rust builds and tests run only in Docker (`clients/voice/Dockerfile.dev`); never install Rust on the host. Linux: `dc` helper from `clients/voice/README.md` (image `--target core`, or `--target app` for the app crate). Windows: image `--target windows`, `cargo xwin clippy --target x86_64-pc-windows-msvc`.
- Readers are pure: no direct `std::fs` or registry calls inside a source; everything goes through the `Registry` and `FileSystem` traits. A malformed entry is skipped and counted, never a panic, never an error for the whole scan.
- No store logins, tokens or credentials; the client never sends the library anywhere in stage 1.
- Store ids: Steam app id = digits (1-10); Epic = the manifest `AppName`, `[A-Za-z0-9._-]{1,128}`; GOG game id = digits (1-20); Ubisoft install id = digits (1-10). Library key = `"<store>:<store_id>"` with store `steam|epic|gog|ubisoft`.
- Handoffs, exactly: Steam `steam://rungameid/<appid>`; Epic `com.epicgames.launcher://apps/<namespace>%3A<catalogItemId>%3A<appName>?action=launch&silent=true`; GOG `goggalaxy://openGameView/<id>` when Galaxy is installed, otherwise the registry `exe` started directly with the install path as working directory; Ubisoft `uplay://launch/<id>/0`. Store page (stage 1, Steam only): `steam://store/<appid>`.
- A direct start runs only an `.exe` that lies inside the game's own install folder (canonicalised); anything else is refused.
- Recipe ↔ store matching only through explicit ids (`metadata.store_ids`, or a recipe launch of kind `steam` with an app id). No matching by title.
- UI: Signalpult (`docs/design.md`), German copy in `app/ui/js/strings.js`, all four states (empty, loading, error, normal), `frontend-design` skill for UI work.
- Frontend gates for client JS: `node --test app/tests/*.test.mjs`, `node app/tests/check-ui.mjs`, `npx prettier --check clients/voice/app/ui/js clients/voice/app/tests` (from the repo root).
- Server gates: `./vendor/bin/pint --test`, phpstan level 8, Pest.
- Public repo: fixtures use made-up paths, ids and names only (no real usernames, no real drive layouts of anyone).
- Conventional Commits, `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` trailer.

## Review Focus

1. A Steam library folder listed in `libraryfolders.vdf` that no longer exists (removed drive): that library is skipped, the other libraries' games are listed (Task 2 test `steam_skips_missing_library`).
2. Windows paths in VDF with escaped backslashes (`"C:\\Games\\Steam"`) and an escaped quote: decoded to the real path (Task 2 test `vdf_unescapes_paths`).
3. Hostile data: an Epic `AppName` with `/`, `?` or `&`, a GOG `exe` of `..\..\Windows\System32\cmd.exe` or an absolute path outside the install folder: refused, nothing opened (Task 4 tests).
4. Signed out or server down: the library still lists local games and shows the recipe reason as one line, not a blocking screen (Task 5 test `library_list_without_pairing_has_local_entries`, Task 6 test).
5. The same app id in two Steam libraries (stale manifest left behind), and the same game installed in two stores matched to one recipe: one Steam entry, and one merged entry holding both store games (Task 2 test `steam_dedupes_app_ids`, Task 5 test `merge_attaches_two_stores_to_one_recipe`).

---

### Task 1: Recipes carry `metadata.store_ids`, served to the client

**Files:**
- Modify: `resources/recipes/schema/recipe.schema.json` (metadata `properties`), `resources/recipes/schema/README.md` (metadata row)
- Modify: `app/Modules/GameRecipes/Support/RecipeValidator.php`, `app/Modules/GameRecipes/Support/RecipeYamlExporter.php` (`METADATA_KEYS`)
- Modify: `app/Modules/WebVoice/NativeClient/Http/RecipesApiController.php` (`summary()`)
- Add a method `storeIds(): array` to `app/Modules/GameRecipes/Models/GameRecipe.php`
- Test: `tests/Feature/WebVoice/NativeClient/RecipesApiTest.php`, `tests/Unit/GameRecipes/RecipeValidatorTest.php`

**Interfaces:**
- Produces: `GameRecipe::storeIds(): array{steam?: string, epic?: string, gog?: string, ubisoft?: string}`; the recipes list and detail JSON gain `"store_ids": {"steam": "10"}` (object, only known keys, `{}` when none).

- [ ] **Step 1: Write failing tests**
  - Validator rejects `store_ids` values of the wrong shape, one case per store: `steam: "abc"`, `epic: "a/b"`, `gog: "12x"`, `ubisoft: ""`, and an unknown key `origin: "1"`; accepts `{steam: "10", epic: "Fortnite.Test_1", gog: "1207658924", ubisoft: "4"}`.
  - `RecipesApiTest`: a published recipe with `metadata.store_ids.gog = "1207658924"` returns `store_ids == ['gog' => '1207658924']`; a recipe whose `metadata.launch` is `{kind: steam, target: "10"}` and has no `store_ids` returns `store_ids == ['steam' => '10']`; explicit `store_ids.steam` wins over the launch target; a recipe without either returns `store_ids` as an empty JSON object (`assertExactJson` fragment or `->assertJsonPath('data.0.store_ids', [])` plus a raw-body check for `{}`).
  - The existing test comparing `METADATA_KEYS` with the schema still passes after both gain `store_ids`.
- [ ] **Step 2: Run them, expect FAIL** — `DB_DATABASE=<test db> ./vendor/bin/pest --filter='store_ids|StoreIds'`
- [ ] **Step 3: Implement.** Schema: `store_ids` object, `additionalProperties: false`, the four string properties with the patterns from Global Constraints. Validator: same rules with messages in the file's existing style (`"{$origin}: metadata.store_ids.steam must be digits"`). `storeIds()` reads `metadata.store_ids`, then fills `steam` from a `steam`-kind launch target (`metadata.launch` object or the first `launch_targets` entry of kind `steam`) when absent and the target is digits. `summary()` emits `(object) $recipe->storeIds()`.
- [ ] **Step 4: Run tests, expect PASS;** then `./vendor/bin/pint --test` and phpstan on the touched files.
- [ ] **Step 5: Commit** — `feat(recipes): optional metadata.store_ids for matching store games`

### Task 2: `store_library` core types, VDF parser, Steam source

**Files:**
- Create: `clients/voice/launcher-core/src/store_library/mod.rs`, `.../store_library/vdf.rs`, `.../store_library/steam.rs`
- Modify: `clients/voice/launcher-core/src/lib.rs` (`pub mod store_library;`)
- Test: unit tests in the same files (`#[cfg(test)]`), fixtures under `clients/voice/launcher-core/tests/fixtures/store_library/steam/`

**Interfaces:**
- Produces (in `store_library`):
  - `enum Store { Steam, Epic, Gog, Ubisoft }` — `Serialize`/`Deserialize` lowercase, `fn as_str(self) -> &'static str`.
  - `enum LocalStatus { Ready, Updating, Broken }` — snake_case.
  - `struct EpicIds { namespace: String, catalog_item_id: String, app_name: String }`
  - `struct LocalGame { store: Store, store_id: String, title: String, install_path: Option<PathBuf>, status: LocalStatus, exe: Option<PathBuf>, epic: Option<EpicIds> }` with `fn key(&self) -> String` (`"steam:10"`). `exe` is set by GOG only, `epic` by Epic only.
  - `struct SourceReport { store: Store, present: bool, found: usize, skipped: usize }` (`present`: the launcher itself was detected).
  - `struct Scan { games: Vec<LocalGame>, sources: Vec<SourceReport> }`
  - `enum Hive { CurrentUser, LocalMachine }`; `trait Registry { fn string(&self, hive: Hive, path: &str, name: &str) -> Option<String>; fn subkeys(&self, hive: Hive, path: &str) -> Vec<String>; }`
  - `trait FileSystem { fn read_to_string(&self, p: &Path) -> Option<String>; fn list_dir(&self, p: &Path) -> Vec<PathBuf>; fn is_dir(&self, p: &Path) -> bool; fn is_file(&self, p: &Path) -> bool; fn canonicalize(&self, p: &Path) -> Option<PathBuf>; }`
  - `struct NoRegistry;` (always `None`/empty, used on non-Windows) and `struct OsFs;` (real `std::fs`), plus test doubles `MapRegistry` and `MapFs` (in-memory, `#[cfg(any(test, feature = "test-doubles"))]` or a `pub mod testing`) so the app crate's tests can use them too.
  - `vdf::parse(text: &str) -> Option<vdf::Node>` with `Node::get(&self, key) -> Option<&Node>`, `Node::str(&self) -> Option<&str>`, `Node::children(&self) -> impl Iterator<Item=(&str,&Node)>`; keys compared case-insensitively.
  - `steam::read(reg: &dyn Registry, fs: &dyn FileSystem) -> (Vec<LocalGame>, SourceReport)`

- [ ] **Step 1: Write failing tests**
  - `vdf_parses_nested_blocks` (a two-level `libraryfolders` sample), `vdf_unescapes_paths` (`"path"  "C:\\Games\\Steam"` → `C:\Games\Steam`; `\"` → `"`), `vdf_rejects_unbalanced_braces` (returns `None`).
  - `steam_finds_install_from_hkcu` (registry `HKCU\Software\Valve\Steam` `SteamPath` = `C:/Program Files (x86)/Steam`, forward slashes as Steam writes them), `steam_falls_back_to_hklm_32bit` (`HKLM\SOFTWARE\WOW6432Node\Valve\Steam` `InstallPath`).
  - `steam_reads_two_libraries`: libraries `C:\Steam` and `D:\SteamLibrary`, manifests `appmanifest_10.acf` (`name` "Counter-Strike", `installdir` "Half-Life", `StateFlags` "4") and `appmanifest_620.acf` (`StateFlags` "6") → two games, keys `steam:10` (`Ready`, `install_path` `C:\Steam\steamapps\common\Half-Life`) and `steam:620` (`Updating`).
  - `steam_marks_missing_install_dir_broken`, `steam_skips_missing_library` (Review Focus 1), `steam_dedupes_app_ids` (same `appmanifest_10.acf` in two libraries → one entry, the one whose install dir exists), `steam_skips_malformed_manifest` (`skipped == 1`, the rest found), `steam_absent_is_silent` (no registry value → `present: false`, no games, no panic).
- [ ] **Step 2: Run, expect FAIL** — `dc cargo test -p launcher-core store_library`
- [ ] **Step 3: Implement.** Library list from `<steam>/steamapps/libraryfolders.vdf` (`libraryfolders` → each child's `path`); the Steam root itself is always a library. Status: `Broken` when the install dir is not a directory; else `Ready` when `StateFlags & 4 != 0` and `StateFlags & (2 | 1024) == 0`; else `Updating`. Paths are built with `Path::join` on the strings as read (Windows separators survive on Linux as part of the string; tests compare `PathBuf::from` of the expected string built the same way).
- [ ] **Step 4: Run, expect PASS;** `dc cargo clippy -p launcher-core --all-targets -- -D warnings`
- [ ] **Step 5: Commit** — `feat(client): store library core and Steam source`

### Task 3: Epic, GOG and Ubisoft sources, Windows registry, `scan()`

**Files:**
- Create: `clients/voice/launcher-core/src/store_library/{epic.rs,gog.rs,ubisoft.rs,windows_registry.rs}`
- Modify: `clients/voice/launcher-core/src/store_library/mod.rs` (`pub fn scan`), `clients/voice/launcher-core/Cargo.toml` (`[target.'cfg(windows)'.dependencies] windows-registry = "<current stable>"` — check crates.io for the current version first)
- Test: unit tests per file, fixtures under `clients/voice/launcher-core/tests/fixtures/store_library/epic/`

**Interfaces:**
- Consumes: Task 2 types and traits.
- Produces:
  - `epic::read(fs: &dyn FileSystem, program_data: &Path) -> (Vec<LocalGame>, SourceReport)` reading `<program_data>\Epic\EpicGamesLauncher\Data\Manifests\*.item`.
  - `gog::read(reg: &dyn Registry, fs: &dyn FileSystem) -> (Vec<LocalGame>, SourceReport)` from `HKLM\SOFTWARE\WOW6432Node\GOG.com\Games\<id>` values `gameID`, `gameName`, `path`, `exe`.
  - `gog::galaxy_installed(reg: &dyn Registry) -> bool` — `HKLM\SOFTWARE\WOW6432Node\GOG.com\GalaxyClient\paths` value `client` present.
  - `ubisoft::read(reg: &dyn Registry, fs: &dyn FileSystem) -> (Vec<LocalGame>, SourceReport)` from `HKLM\SOFTWARE\WOW6432Node\Ubisoft\Launcher\Installs\<id>` value `InstallDir`; title = last path component of `InstallDir`.
  - `struct StoreEnv { program_data: Option<PathBuf> }` with `StoreEnv::from_os()` (`%ProgramData%`).
  - `pub fn scan(reg: &dyn Registry, fs: &dyn FileSystem, env: &StoreEnv) -> Scan` — all four sources, games sorted by title (case-insensitive), sources in the order steam, epic, gog, ubisoft.
  - `windows_registry::OsRegistry` (`#[cfg(windows)]`) implementing `Registry`; `pub fn os_registry() -> Box<dyn Registry>` returning `OsRegistry` on Windows and `NoRegistry` elsewhere.

- [ ] **Step 1: Write failing tests**
  - Epic: `epic_reads_manifest` (`AppName` "Kinglet", `DisplayName` "Example Game", `CatalogNamespace` "ns1", `CatalogItemId` "item1", `InstallLocation` an existing dir → `epic:Kinglet`, `Ready`, `epic` ids set); `epic_incomplete_install_is_updating` (`bIsIncompleteInstall: true`); `epic_missing_location_is_broken`; `epic_skips_invalid_json_and_bad_appname` (`AppName` "a/b" skipped, counted); `epic_absent_is_silent`.
  - GOG: `gog_reads_registry_game` (`gameID` "1207658924", `gameName` "Example Classic", `path` existing dir, `exe` inside it → `gog:1207658924`, `exe` set); `gog_missing_path_is_broken`; `gog_galaxy_detection` (present / absent).
  - Ubisoft: `ubisoft_reads_install` (`Installs\4` `InstallDir` `C:/Games/Ubisoft/Example Siege/` → `ubisoft:4`, title "Example Siege", trailing slash ignored); `ubisoft_skips_non_numeric_key`.
  - `scan_combines_sources_and_sorts` (one game per store → four games sorted by title, four reports).
- [ ] **Step 2: Run, expect FAIL** — `dc cargo test -p launcher-core store_library`
- [ ] **Step 3: Implement** the three readers and `scan`; `OsRegistry` reads `REG_SZ`/`REG_EXPAND_SZ` values with the 64-bit view as the paths above already name `WOW6432Node` explicitly.
- [ ] **Step 4: Run, expect PASS;** Linux clippy as in Task 2; Windows: in the `--target windows` image run `cargo xwin clippy --target x86_64-pc-windows-msvc -p launcher-core --all-targets -- -D warnings` (proves `OsRegistry` compiles).
- [ ] **Step 5: Commit** — `feat(client): Epic, GOG and Ubisoft library sources`

### Task 4: Validated handoffs

**Files:**
- Create: `clients/voice/launcher-core/src/store_library/handoff.rs`
- Test: unit tests in the same file

**Interfaces:**
- Consumes: `LocalGame`, `Store`, `FileSystem`.
- Produces:
  - `enum Handoff { Url(String), Exe { program: PathBuf, cwd: PathBuf } }`
  - `enum HandoffError { InvalidId, NotLaunchable, ExeOutsideInstall, Unsupported }` (`thiserror`, German `Display` texts for the UI: "Dieses Spiel lässt sich nicht starten.", "Die Startdatei liegt außerhalb des Spielordners.", "Für diesen Shop gibt es das noch nicht.")
  - `fn launch(game: &LocalGame, galaxy_installed: bool, fs: &dyn FileSystem) -> Result<Handoff, HandoffError>`
  - `fn store_page(store: Store, store_id: &str) -> Result<String, HandoffError>` (Steam only; others `Unsupported`)

- [ ] **Step 1: Write failing tests** — exact expected strings:
  - `launch(steam:10)` → `Url("steam://rungameid/10")`; `launch` of a steam game with `store_id` "10;calc" → `InvalidId`.
  - Epic ids `ns1`/`item1`/`Kinglet` → `Url("com.epicgames.launcher://apps/ns1%3Aitem1%3AKinglet?action=launch&silent=true")`; `app_name` "a&b", "a/b", "a?b" → `InvalidId` (Review Focus 3).
  - GOG with Galaxy → `Url("goggalaxy://openGameView/1207658924")`; without Galaxy and `exe` = `<path>\game.exe` inside the install dir → `Exe { program, cwd: install_path }`; `exe` = `<path>\..\..\Windows\System32\cmd.exe` → `ExeOutsideInstall`; absolute `exe` outside → `ExeOutsideInstall`; `exe` not ending in `.exe` → `NotLaunchable`; missing `exe` without Galaxy → `NotLaunchable`.
  - Ubisoft 4 → `Url("uplay://launch/4/0")`.
  - A `Broken` game of any store → `NotLaunchable`.
  - `store_page(Steam, "620")` → `"steam://store/620"`; `store_page(Epic, "Kinglet")` → `Unsupported`.
- [ ] **Step 2: Run, expect FAIL** — `dc cargo test -p launcher-core handoff`
- [ ] **Step 3: Implement.** Id validation by the Global Constraints patterns; containment via `fs.canonicalize` of both paths and `starts_with` (tests use `MapFs` with a canonicalize map).
- [ ] **Step 4: Run, expect PASS;** clippy.
- [ ] **Step 5: Commit** — `feat(client): validated store handoffs`

### Task 5: App — scan cache, merged library list, commands

**Files:**
- Create: `clients/voice/app/src/features/library.rs`
- Modify: `clients/voice/app/src/features.rs` (module, `Features` field for the scan cache, `games_state` gains `local`), `clients/voice/app/src/features/games.rs` (`GamesState.local`), `clients/voice/app/src/lib.rs` (four commands in `command_set!`, one helper to open a `Handoff`), `clients/voice/voice-core/src/api/recipes.rs` (`RecipeSummary.store_ids: StoreIds`, `#[serde(default)]`), `clients/voice/docs/commands.md` ("Library" section)
- Test: `clients/voice/app/src/features/tests.rs` (or a `library` test module), `voice-core` recipe parsing test

**Interfaces:**
- Consumes: `store_library::{scan, Scan, LocalGame, Store, StoreEnv, os_registry, OsFs, handoff::*}`, `gog::galaxy_installed`; existing `GamesList`/`GameView` from `games.rs`.
- Produces (serialised to the UI, snake_case):
  - `GamesState { enabled, reason, local: bool }` — `local` is `true` on Windows builds, `false` elsewhere (stage 1).
  - `StoreIds { steam, epic, gog, ubisoft: Option<String> }` on `RecipeSummary` and `GameView` (`store_ids`).
  - `StoreGameView { key, store, store_id, title, status, install_path: Option<String> }`
  - `LibraryEntry { key: String, title: String, recipe: Option<GameView>, store_games: Vec<StoreGameView>, primary: &'static str }` — `key` is `"recipe:<slug>"` when a recipe is involved, else the store game's key; `primary` ∈ `play` (a store game is `ready`), `install_recipe` (recipe `installable && launcher_supported`, phase 2 placeholder), `open_store` (a Steam id and no ready store game), `none`.
  - `LibraryList { entries: Vec<LibraryEntry>, sources: Vec<SourceReport>, recipes_reason: Option<&'static str>, scanned_at: String }` — `recipes_reason` is `null` when recipes loaded, else `not_paired | module_off | server_too_old | offline | server_error`.
  - Commands: `library_list() -> LibraryList` (scans once if no cached scan), `library_scan() -> LibraryList` (rescans), `library_launch(key: String) -> ()`, `library_open_store(key: String) -> ()`. Launch and store page look the key up in the cached scan or the cached recipe list — never accept a URL or path from the UI.

- [ ] **Step 1: Write failing tests** (scan injected through a constructor that takes `&dyn Registry`, `&dyn FileSystem`; recipes through the existing test server double in `features/tests.rs`):
  - `library_list_without_pairing_has_local_entries` (Review Focus 4): no credentials, a scan with one Steam game → one entry `steam:10`, `primary == "play"`, `recipes_reason == Some("not_paired")`.
  - `merge_matches_recipe_by_store_id`: recipe `cs16` with `store_ids.steam = "10"` + local `steam:10` → one entry `recipe:cs16` with one store game, `primary == "play"`.
  - `merge_attaches_two_stores_to_one_recipe` (Review Focus 5): recipe with `steam: "10"` and `gog: "1207658924"`, both installed → one entry with two store games.
  - `merge_keeps_unmatched_both_sides`: an unmatched recipe and an unmatched store game → two entries.
  - `primary_open_store_for_unowned_steam_recipe`: recipe `steam: "620"`, nothing local, not installable → `open_store`.
  - `launch_unknown_key_is_usage_error` and `launch_uses_cached_scan` (the handoff for `steam:10` is `steam://rungameid/10`; assert through a recording opener injected in tests).
  - `games_state_reports_local` (`local == cfg!(windows)`).
  - voice-core: `recipe_summary_parses_store_ids` and `recipe_summary_without_store_ids_defaults_empty`.
- [ ] **Step 2: Run, expect FAIL** — `dc cargo test -p lanomat-voice-app library` and `dc cargo test -p voice-core recipes` (app crate in the `--target app` image)
- [ ] **Step 3: Implement.** The scan runs on a blocking thread (`spawn_blocking`); cache it in `Features` with the time of the scan. `library_list` loads recipes through the existing `games_list` path when `games_state` is enabled and maps a failure to `recipes_reason` instead of failing the command. Opening a `Handoff::Url` uses the existing `open_in_browser`; `Handoff::Exe` spawns the program with `current_dir(cwd)`, no shell, stdio null. Document the four commands and the payloads in `docs/commands.md`.
- [ ] **Step 4: Run, expect PASS;** `dc cargo clippy --workspace --all-targets --all-features -- -D warnings`; Windows clippy for `-p lanomat-voice-app`.
- [ ] **Step 5: Commit** — `feat(client): merged game library and store launch commands`

### Task 6: UI — one library in "Spiele", reachable signed out

Use the `frontend-design` skill and `docs/design.md` before writing UI.

**Files:**
- Modify: `clients/voice/app/ui/js/games-model.js`, `clients/voice/app/ui/js/games.js`, `clients/voice/app/ui/js/strings.js`, `clients/voice/app/ui/js/optional.js` (gate open when `enabled || local`), `clients/voice/app/ui/js/link.js` (`library_list`, `library_scan`, `library_launch`, `library_open_store` in `LOCAL_COMMANDS`), `clients/voice/app/ui/js/connect.js` + `nav.js` (signed-out entry), `clients/voice/app/ui/index.html` / `screens.css` only if needed
- Test: `clients/voice/app/tests/games-model.test.mjs`

**Interfaces:**
- Consumes: Task 5 payloads (`LibraryList`, `LibraryEntry`, `StoreGameView`, `GamesState.local`).
- Produces (games-model.js): `normalizeLibrary(v) -> {entries, sources, recipesReason, scannedAt}`, `entryCardModel(entry)`, `SOURCES = ['all','steam','epic','gog','ubisoft','lan']`, `filterEntries(entries, {query, source, genre, filter})`, `sortEntries(entries)` (lineup first, then title), `loadLibrary(call)` now returning `{phase: 'ready'|'empty'|'disabled'|'error', entries, sources, recipesNote}` via `games_state` then `library_list`.

- [ ] **Step 1: Write failing tests** in `games-model.test.mjs`:
  - `loadLibrary` with `games_state {enabled:false, reason:'not_paired', local:true}` and a `library_list` of one Steam entry → `phase: 'ready'`, one entry, `recipesNote` = the German "not paired" line from `S.games.recipesNote.not_paired`.
  - `loadLibrary` with `enabled:false, local:false` → `phase: 'disabled'` (today's behaviour on Linux).
  - `filterEntries` by source: `'steam'` keeps entries with a Steam store game; `'lan'` keeps entries with a recipe.
  - `entryCardModel` labels: store badges in the order steam, epic, gog, ubisoft; primary action label `S.games.actions.play` for `primary: 'play'`, `S.games.actions.openStore` for `open_store`.
  - `normalizeLibrary` drops entries without `key` and unknown `primary` values become `'none'`.
- [ ] **Step 2: Run, expect FAIL** — `cd clients/voice && node --test app/tests/games-model.test.mjs`
- [ ] **Step 3: Implement.**
  - Card: title, store badges, status (`ready`/`updating`/`broken` with the existing tone mapping), LAN facts from the recipe part as today, one primary button: "Spielen" → `library_launch` with the first ready store game's key; "Im Shop öffnen" → `library_open_store`; `install_recipe` keeps today's explained placeholder. A "Neu einlesen" control calls `library_scan`. Source filter chips next to the existing filters. `recipesNote` renders as one muted line above the list, never as the disabled screen.
  - Signed out: when `games_state.local` is true, the connect screen shows a secondary action "Meine Spiele ohne Anmeldung"; it shows the `spiele` screen with only the Spiele tab visible and a header button "Anmelden" back to the connect screen. After pairing, the normal shell returns.
  - All four states for the list (skeleton while scanning, empty: "Keine Spiele gefunden" with a hint which launchers are read, error with retry, normal).
- [ ] **Step 4: Run, expect PASS;** `node app/tests/check-ui.mjs`; from the repo root `npx prettier --check clients/voice/app/ui/js clients/voice/app/tests`; build the app (`--target app` image, `cargo build -p lanomat-voice-app`) and take one headless screenshot of the tab signed out with a fixture scan (env `LANOMAT_STORE_LIBRARY_FIXTURE=<dir>` read only in debug builds, pointing `OsFs`/`MapRegistry` at fixture data — add this hook in this task, `#[cfg(debug_assertions)]`).
- [ ] **Step 5: Commit** — `feat(client-ui): store games in the Spiele tab, signed out too`

### Task 7: Docs, ADR amendment, Windows checklist

**Files:**
- Modify: `docs/superpowers/decisions/2026-11-22-all-in-one-client-adr.md` (row 11), `clients/voice/README.md` (library section), `CHANGELOG.md`, `README.md` (`## Project state`, one line), `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (add the stage to the native-client section)
- Create: `clients/voice/docs/store-library-windows-check.md` (owner's Windows checklist)

- [ ] **Step 1:** ADR row 11 text: "Keine Automatisierung von Shop-Installationen oder -Konten. Erlaubt: lokale Launcher-Daten lesen und Spiele über die eigenen Handoff-URLs der Shops starten oder öffnen (pro Shop validiert), dazu `steam://connect`." with a pointer to the store-library spec.
- [ ] **Step 2:** Windows checklist: for each store one installed game listed with the right title; "Spielen" starts it through the store; a game whose folder was renamed shows "defekt"; GOG game starts without Galaxy (direct exe) and with Galaxy (handoff); signed out and with network off the tab opens and starts a game; "Neu einlesen" picks up a newly installed game; a recipe with matching `store_ids` shows as one entry.
- [ ] **Step 3:** CHANGELOG lines (Added: native client store library; server: `metadata.store_ids`), README and roadmap lines; client README section with the four sources, where they read, and the handoffs.
- [ ] **Step 4:** `bash scripts/ops/check-public-safe.sh` passes; gitleaks on the branch range passes.
- [ ] **Step 5: Commit** — `docs(client): store library stage 1, ADR 11 amendment, Windows checklist`
