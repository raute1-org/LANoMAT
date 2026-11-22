//! The local store library: which Steam, Epic, GOG and Ubisoft games are installed on this PC,
//! merged with the server's game recipes by explicit store ids (`metadata.store_ids`, never by
//! title). The scan never depends on the pairing: signed out or with the server down, the local
//! games are still listed and the recipe side shows one reason (`recipes_reason`).
//!
//! Starting a game or opening a store page takes a library key and looks it up in the cached
//! scan or the cached recipe list. The UI never hands over a URL or a path. Documented for the UI
//! in `docs/commands.md` ("Library").

use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::time::Duration;

use launcher_core::store_library::gog::galaxy_installed;
use launcher_core::store_library::handoff::{self, Handoff};
use launcher_core::store_library::{
    os_registry, scan, FileSystem, LocalGame, LocalStatus, OsFs, Registry, Scan, SourceReport,
    Store, StoreEnv,
};
use serde::Serialize;
use voice_core::api::StoreIds;

use super::games::now_rfc3339;
use super::{lock, Features, GameView};
use crate::engine::UiError;

/// What a scan reads: the registry, the file system and the environment.
pub(super) struct Sources {
    pub reg: Box<dyn Registry>,
    pub fs: Box<dyn FileSystem>,
    pub env: StoreEnv,
}

/// Opens a validated handoff; the error is the system's text.
pub(super) type Opener = Box<dyn Fn(&Handoff) -> Result<(), String> + Send + Sync>;

/// How long the recipe side may take before the list shows the local games alone.
const RECIPE_TIMEOUT: Duration = Duration::from_secs(3);

/// The seams of the library: where the scan reads from, how a handoff is opened, and how long
/// the server gets. Tests replace them; the defaults are the real machine.
pub(super) struct LibraryEnv {
    pub sources: Box<dyn Fn() -> Sources + Send + Sync>,
    pub opener: Opener,
    pub recipe_timeout: Duration,
}

impl Default for LibraryEnv {
    fn default() -> Self {
        Self {
            sources: Box::new(|| Sources {
                reg: os_registry(),
                fs: Box::new(OsFs),
                env: StoreEnv::from_os(),
            }),
            opener: Box::new(open_handoff),
            recipe_timeout: RECIPE_TIMEOUT,
        }
    }
}

struct CachedScan {
    scan: Scan,
    scanned_at: String,
}

#[derive(Default)]
pub(super) struct LibraryState {
    scan: Option<CachedScan>,
    /// The recipes of the last list, for `recipe:<slug>` keys.
    recipes: Vec<GameView>,
}

impl LibraryState {
    /// Another account (or server) may be next: its recipes are not this one's. The local scan
    /// is the machine's, so it stays.
    pub(super) fn clear_recipes(&mut self) {
        self.recipes.clear();
    }
}

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct StoreGameView {
    pub key: String,
    pub store: Store,
    pub store_id: String,
    pub title: String,
    pub status: LocalStatus,
    pub install_path: Option<String>,
}

impl From<&LocalGame> for StoreGameView {
    fn from(g: &LocalGame) -> Self {
        Self {
            key: g.key(),
            store: g.store,
            store_id: g.store_id.clone(),
            title: g.title.clone(),
            status: g.status,
            install_path: g
                .install_path
                .as_ref()
                .map(|p| p.to_string_lossy().into_owned()),
        }
    }
}

#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct LibraryEntry {
    /// `recipe:<slug>` when a recipe is involved, else the store game's key (`steam:10`).
    pub key: String,
    pub title: String,
    pub recipe: Option<GameView>,
    pub store_games: Vec<StoreGameView>,
    /// `play`, `install_recipe`, `open_store` or `none`.
    pub primary: &'static str,
}

#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct LibraryList {
    pub entries: Vec<LibraryEntry>,
    pub sources: Vec<SourceReport>,
    /// `null` when the recipes loaded, else `not_paired`, `module_off`, `server_too_old`,
    /// `offline` or `server_error`.
    pub recipes_reason: Option<&'static str>,
    pub scanned_at: String,
}

fn store_id_of(ids: &StoreIds, store: Store) -> Option<&str> {
    match store {
        Store::Steam => ids.steam.as_deref(),
        Store::Epic => ids.epic.as_deref(),
        Store::Gog => ids.gog.as_deref(),
        Store::Ubisoft => ids.ubisoft.as_deref(),
    }
}

fn matches_recipe(g: &LocalGame, ids: &StoreIds) -> bool {
    store_id_of(ids, g.store).is_some_and(|id| id == g.store_id)
}

fn primary(recipe: Option<&GameView>, games: &[StoreGameView]) -> &'static str {
    if games.iter().any(|g| g.status == LocalStatus::Ready) {
        return "play";
    }
    let Some(r) = recipe else {
        return "none";
    };
    if r.installable == Some(true) && r.launcher_supported == Some(true) {
        return "install_recipe";
    }
    if r.store_ids.steam.is_some() {
        return "open_store";
    }
    "none"
}

/// Recipes first, in the server's order, each with every installed store game its ids name;
/// then the store games no recipe claims, in scan order.
fn merge(scan: &Scan, recipes: &[GameView]) -> Vec<LibraryEntry> {
    let mut claimed = vec![false; scan.games.len()];
    let mut entries = Vec::new();
    for r in recipes {
        let mut games = Vec::new();
        for (i, g) in scan.games.iter().enumerate() {
            if matches_recipe(g, &r.store_ids) {
                claimed[i] = true;
                games.push(StoreGameView::from(g));
            }
        }
        let primary = primary(Some(r), &games);
        entries.push(LibraryEntry {
            key: format!("recipe:{}", r.slug),
            title: r.title.clone(),
            recipe: Some(r.clone()),
            store_games: games,
            primary,
        });
    }
    for (g, claimed) in scan.games.iter().zip(claimed) {
        if claimed {
            continue;
        }
        let games = vec![StoreGameView::from(g)];
        let primary = primary(None, &games);
        entries.push(LibraryEntry {
            key: g.key(),
            title: g.title.clone(),
            recipe: None,
            store_games: games,
            primary,
        });
    }
    entries
}

fn usage(message: &str) -> UiError {
    UiError::new("usage", message)
}

fn unknown_game() -> UiError {
    usage("Dieses Spiel ist nicht (mehr) in der Bibliothek. Suche erneut.")
}

/// `\\?\C:\x` to `C:\x` and `\\?\UNC\srv\x` to `\\srv\x`: `std::fs::canonicalize` on Windows
/// returns the verbatim form, which not every game launcher copes with. Only plain drive and UNC
/// paths are stripped, and not when the plain form would be over the 259 characters the classic
/// API takes (the rule of the `dunce` crate): then the verbatim form is the one that works.
pub(super) fn strip_verbatim(p: &Path) -> PathBuf {
    const MAX_PLAIN: usize = 259;
    let s = p.to_string_lossy();
    let plain = if let Some(rest) = s.strip_prefix(r"\\?\UNC\") {
        format!(r"\\{rest}")
    } else if let Some(rest) = s.strip_prefix(r"\\?\") {
        let b = rest.as_bytes();
        let drive = b.len() >= 3 && b[0].is_ascii_alphabetic() && b[1] == b':' && b[2] == b'\\';
        if !drive {
            return p.to_path_buf();
        }
        rest.to_string()
    } else {
        return p.to_path_buf();
    };
    if plain.chars().count() > MAX_PLAIN {
        return p.to_path_buf();
    }
    PathBuf::from(plain)
}

/// Opens a validated handoff: a URL through the system's handler, an `.exe` as a plain process
/// (no shell, no stdio) in the game's folder.
fn open_handoff(h: &Handoff) -> Result<(), String> {
    match h {
        Handoff::Url(url) => crate::open_in_browser(url).map_err(|e| e.to_string()),
        Handoff::Exe { program, cwd } => std::process::Command::new(strip_verbatim(program))
            .current_dir(strip_verbatim(cwd))
            .stdin(std::process::Stdio::null())
            .stdout(std::process::Stdio::null())
            .stderr(std::process::Stdio::null())
            .spawn()
            .map(|_| ())
            .map_err(|e| e.to_string()),
    }
}

/// Debug builds only: `LANOMAT_STORE_LIBRARY_FIXTURE=<dir>` replaces the scan with the `Scan`
/// JSON in `<dir>/scan.json` (made-up games for screenshots). Release builds have no such hook.
#[cfg(debug_assertions)]
pub(super) fn fixture_scan() -> Option<Scan> {
    let dir = std::env::var_os("LANOMAT_STORE_LIBRARY_FIXTURE")?;
    let text = std::fs::read_to_string(Path::new(&dir).join("scan.json")).ok()?;
    serde_json::from_str(&text).ok()
}

#[cfg(not(debug_assertions))]
pub(super) fn fixture_scan() -> Option<Scan> {
    None
}

impl Features {
    /// Test seam: replaces where the scan reads from and how handoffs are opened.
    #[cfg(test)]
    pub(super) fn set_library_env(&self, env: LibraryEnv) {
        *lock(&self.library_env) = Arc::new(env);
    }

    fn library_env(&self) -> Arc<LibraryEnv> {
        lock(&self.library_env).clone()
    }

    /// Scans on a blocking thread (registry and disk) and caches the result.
    async fn library_rescan(&self) -> Result<(), UiError> {
        let env = self.library_env();
        let scanned = tokio::task::spawn_blocking(move || {
            if let Some(fixture) = fixture_scan() {
                return fixture;
            }
            let s = (env.sources)();
            scan(&*s.reg, &*s.fs, &s.env)
        })
        .await
        .map_err(|_| UiError::new("local", "Die Spiele konnten nicht gesucht werden."))?;
        lock(&self.library).scan = Some(CachedScan {
            scan: scanned,
            scanned_at: now_rfc3339(),
        });
        Ok(())
    }

    /// The server's recipes, or the one reason they are not there. Never fails, and never takes
    /// longer than `recipe_timeout`: the local games must not wait for a dead server.
    async fn library_recipes(&self) -> (Option<Vec<GameView>>, Option<&'static str>) {
        let bound = self.library_env().recipe_timeout;
        match tokio::time::timeout(bound, self.library_recipes_unbounded()).await {
            Ok(answer) => answer,
            Err(_) => (None, Some("offline")),
        }
    }

    async fn library_recipes_unbounded(&self) -> (Option<Vec<GameView>>, Option<&'static str>) {
        let reason = |e: UiError| match e.code.as_deref() {
            Some("not_paired") => "not_paired",
            Some("offline") => "offline",
            Some("module_off") => "module_off",
            _ => "server_error",
        };
        let state = match self.games_state().await {
            Ok(s) => s,
            Err(e) => return (None, Some(reason(e))),
        };
        if !state.enabled {
            return (None, state.reason.or(Some("server_error")));
        }
        match self.games_list_with(false).await {
            Ok(list) => (Some(list.recipes), None),
            Err(e) => (None, Some(reason(e))),
        }
    }

    async fn library_view(&self) -> Result<LibraryList, UiError> {
        let (loaded, recipes_reason) = self.library_recipes().await;
        let mut state = lock(&self.library);
        match loaded {
            Some(recipes) => state.recipes = recipes,
            // The server is only unreachable or in trouble: the last good list stays (its keys
            // keep resolving). Anything else (signed out, module off) means there is none.
            None if matches!(recipes_reason, Some("offline" | "server_error")) => {}
            None => state.recipes.clear(),
        }
        let cached = state.scan.as_ref().ok_or_else(|| {
            UiError::new(
                "local",
                "Die Spiele wurden noch nicht gesucht. Suche erneut.",
            )
        })?;
        Ok(LibraryList {
            entries: merge(&cached.scan, &state.recipes),
            sources: cached.scan.sources.clone(),
            recipes_reason,
            scanned_at: cached.scanned_at.clone(),
        })
    }

    /// The merged library. Scans once when there is no cached scan.
    pub async fn library_list(&self) -> Result<LibraryList, UiError> {
        if lock(&self.library).scan.is_none() {
            self.library_rescan().await?;
        }
        self.library_view().await
    }

    /// Scans again, then lists.
    pub async fn library_scan(&self) -> Result<LibraryList, UiError> {
        self.library_rescan().await?;
        self.library_view().await
    }

    /// The store game a key stands for: a store key (`steam:10`) from the cached scan, or
    /// `recipe:<slug>` resolved to its first ready store game.
    fn library_resolve(&self, key: &str) -> Option<LocalGame> {
        let state = lock(&self.library);
        let scan = &state.scan.as_ref()?.scan;
        if let Some(g) = scan.games.iter().find(|g| g.key() == key) {
            return Some(g.clone());
        }
        let slug = key.strip_prefix("recipe:")?;
        let recipe = state.recipes.iter().find(|r| r.slug == slug)?;
        scan.games
            .iter()
            .find(|g| g.status == LocalStatus::Ready && matches_recipe(g, &recipe.store_ids))
            .cloned()
    }

    /// Starts an installed store game through its store (or the validated `.exe`).
    pub async fn library_launch(&self, key: &str) -> Result<(), UiError> {
        let game = self.library_resolve(key).ok_or_else(unknown_game)?;
        let env = self.library_env();
        let handoff = tokio::task::spawn_blocking(move || {
            let s = (env.sources)();
            handoff::launch(&game, galaxy_installed(&*s.reg), &*s.fs)
        })
        .await
        .map_err(|_| UiError::new("local", "Das Spiel konnte nicht gestartet werden."))?
        .map_err(|e| usage(&e.to_string()))?;
        self.library_open(&handoff, "Das Spiel konnte nicht geöffnet werden.")
    }

    /// Opens the store page of a game: of an installed store game, or of a recipe's Steam id.
    pub async fn library_open_store(&self, key: &str) -> Result<(), UiError> {
        let (store, id) = {
            let state = lock(&self.library);
            let from_scan = state
                .scan
                .as_ref()
                .and_then(|s| s.scan.games.iter().find(|g| g.key() == key))
                .map(|g| (g.store, g.store_id.clone()));
            let from_recipe = key
                .strip_prefix("recipe:")
                .and_then(|slug| state.recipes.iter().find(|r| r.slug == slug))
                .and_then(|r| r.store_ids.steam.clone())
                .map(|id| (Store::Steam, id));
            from_scan.or(from_recipe).ok_or_else(unknown_game)?
        };
        let url = handoff::store_page(store, &id).map_err(|e| usage(&e.to_string()))?;
        self.library_open(
            &Handoff::Url(url),
            "Die Shop-Seite konnte nicht geöffnet werden.",
        )
    }

    /// The system's own error text is English more often than not and says little to a player:
    /// it goes to the log, the window gets `message`.
    fn library_open(&self, h: &Handoff, message: &str) -> Result<(), UiError> {
        (self.library_env().opener)(h).map_err(|e| {
            tracing::warn!(target: "lanomat::library", error = %e, "handoff could not be opened");
            UiError::new("local", message)
        })
    }
}
