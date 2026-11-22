//! Local game library: which Steam, Epic, GOG and Ubisoft games are installed
//! on this PC. The readers are pure: they only see the registry and the file
//! system through the [`Registry`] and [`FileSystem`] traits.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

pub mod steam;
pub mod vdf;

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
