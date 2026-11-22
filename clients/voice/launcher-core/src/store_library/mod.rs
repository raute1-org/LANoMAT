//! Local game library: which Steam, Epic, GOG and Ubisoft games are installed
//! on this PC. The readers are pure: they only see the registry and the file
//! system through the [`Registry`] and [`FileSystem`] traits.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

pub mod epic;
pub mod gog;
pub mod steam;
pub mod ubisoft;
pub mod vdf;
#[cfg(windows)]
pub mod windows_registry;

#[cfg(any(test, feature = "test-doubles"))]
pub mod testing;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum Store {
    Steam,
    Epic,
    Gog,
    Ubisoft,
}

impl Store {
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            Store::Steam => "steam",
            Store::Epic => "epic",
            Store::Gog => "gog",
            Store::Ubisoft => "ubisoft",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum LocalStatus {
    Ready,
    Updating,
    Broken,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct EpicIds {
    pub namespace: String,
    pub catalog_item_id: String,
    pub app_name: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct LocalGame {
    pub store: Store,
    pub store_id: String,
    pub title: String,
    pub install_path: Option<PathBuf>,
    pub status: LocalStatus,
    /// Set by GOG only.
    pub exe: Option<PathBuf>,
    /// Set by Epic only.
    pub epic: Option<EpicIds>,
}

impl LocalGame {
    /// Library key, e.g. `steam:10`.
    #[must_use]
    pub fn key(&self) -> String {
        format!("{}:{}", self.store.as_str(), self.store_id)
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct SourceReport {
    pub store: Store,
    /// The launcher itself was detected.
    pub present: bool,
    pub found: usize,
    pub skipped: usize,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Scan {
    pub games: Vec<LocalGame>,
    pub sources: Vec<SourceReport>,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Hive {
    CurrentUser,
    LocalMachine,
}

pub trait Registry {
    fn string(&self, hive: Hive, path: &str, name: &str) -> Option<String>;
    fn subkeys(&self, hive: Hive, path: &str) -> Vec<String>;
}

pub trait FileSystem {
    fn read_to_string(&self, p: &Path) -> Option<String>;
    fn list_dir(&self, p: &Path) -> Vec<PathBuf>;
    fn is_dir(&self, p: &Path) -> bool;
    fn is_file(&self, p: &Path) -> bool;
    fn canonicalize(&self, p: &Path) -> Option<PathBuf>;
}

/// Registry stand-in for platforms without one: always empty.
pub struct NoRegistry;

impl Registry for NoRegistry {
    fn string(&self, _: Hive, _: &str, _: &str) -> Option<String> {
        None
    }
    fn subkeys(&self, _: Hive, _: &str) -> Vec<String> {
        Vec::new()
    }
}

/// The real file system.
pub struct OsFs;

impl FileSystem for OsFs {
    fn read_to_string(&self, p: &Path) -> Option<String> {
        std::fs::read_to_string(p).ok()
    }
    fn list_dir(&self, p: &Path) -> Vec<PathBuf> {
        std::fs::read_dir(p)
            .map(|rd| rd.filter_map(|e| e.ok().map(|e| e.path())).collect())
            .unwrap_or_default()
    }
    fn is_dir(&self, p: &Path) -> bool {
        p.is_dir()
    }
    fn is_file(&self, p: &Path) -> bool {
        p.is_file()
    }
    fn canonicalize(&self, p: &Path) -> Option<PathBuf> {
        std::fs::canonicalize(p).ok()
    }
}

/// Environment the Epic reader needs.
#[derive(Debug, Clone, Default)]
pub struct StoreEnv {
    pub program_data: Option<PathBuf>,
}

impl StoreEnv {
    #[must_use]
    pub fn from_os() -> Self {
        Self {
            program_data: std::env::var_os("ProgramData")
                .filter(|v| !v.is_empty())
                .map(PathBuf::from),
        }
    }
}

/// The registry for this platform.
#[must_use]
pub fn os_registry() -> Box<dyn Registry> {
    #[cfg(windows)]
    {
        Box::new(windows_registry::OsRegistry)
    }
    #[cfg(not(windows))]
    {
        Box::new(NoRegistry)
    }
}

/// Scan all four stores. Games are sorted by title (case-insensitive), sources
/// come in the order steam, epic, gog, ubisoft.
pub fn scan(reg: &dyn Registry, fs: &dyn FileSystem, env: &StoreEnv) -> Scan {
    let (mut games, steam) = steam::read(reg, fs);
    let (epic_games, epic) = match &env.program_data {
        Some(pd) => epic::read(fs, pd),
        None => (
            Vec::new(),
            SourceReport {
                store: Store::Epic,
                present: false,
                found: 0,
                skipped: 0,
            },
        ),
    };
    let (gog_games, gog) = gog::read(reg, fs);
    let (ubi_games, ubisoft) = ubisoft::read(reg, fs);
    games.extend(epic_games);
    games.extend(gog_games);
    games.extend(ubi_games);
    games.sort_by_cached_key(|g| (g.title.to_lowercase(), g.key()));
    Scan {
        games,
        sources: vec![steam, epic, gog, ubisoft],
    }
}

#[cfg(test)]
mod scan_tests {
    use super::testing::{MapFs, MapRegistry};
    use super::*;

    #[test]
    fn scan_combines_sources_and_sorts() {
        let steamapps = Path::new("C:/Steam").join("steamapps");
        let manifests = Path::new("C:/PD")
            .join("Epic")
            .join("EpicGamesLauncher")
            .join("Data")
            .join("Manifests");
        let reg = MapRegistry::new()
            .with_value(
                Hive::CurrentUser,
                "Software\\Valve\\Steam",
                "SteamPath",
                "C:/Steam",
            )
            .with_subkeys(
                Hive::LocalMachine,
                "SOFTWARE\\WOW6432Node\\GOG.com\\Games",
                &["5"],
            )
            .with_value(
                Hive::LocalMachine,
                "SOFTWARE\\WOW6432Node\\GOG.com\\Games\\5",
                "gameID",
                "5",
            )
            .with_value(
                Hive::LocalMachine,
                "SOFTWARE\\WOW6432Node\\GOG.com\\Games\\5",
                "gameName",
                "charlie",
            )
            .with_subkeys(
                Hive::LocalMachine,
                "SOFTWARE\\WOW6432Node\\Ubisoft\\Launcher\\Installs",
                &["4"],
            )
            .with_value(
                Hive::LocalMachine,
                "SOFTWARE\\WOW6432Node\\Ubisoft\\Launcher\\Installs\\4",
                "InstallDir",
                "C:/U/Delta",
            );
        let fs = MapFs::new()
            .with_dir("C:/Steam")
            .with_file(
                steamapps.join("appmanifest_10.acf"),
                "\"AppState\"{\"name\"\"Bravo\"\"StateFlags\"\"4\"\"installdir\"\"B\"}",
            )
            .with_file(
                manifests.join("a.item"),
                r#"{"AppName":"Kinglet","DisplayName":"Alpha","CatalogNamespace":"n","CatalogItemId":"i","InstallLocation":"D:/A"}"#,
            );
        let env = StoreEnv {
            program_data: Some(PathBuf::from("C:/PD")),
        };
        let scan = scan(&reg, &fs, &env);
        let titles: Vec<_> = scan.games.iter().map(|g| g.title.as_str()).collect();
        assert_eq!(titles, ["Alpha", "Bravo", "charlie", "Delta"]);
        let stores: Vec<_> = scan.sources.iter().map(|s| s.store).collect();
        assert_eq!(
            stores,
            [Store::Steam, Store::Epic, Store::Gog, Store::Ubisoft]
        );
    }
}
