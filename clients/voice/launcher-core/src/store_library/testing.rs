//! In-memory [`Registry`] and [`FileSystem`] doubles. Fixture paths are
//! Windows-style strings; they are compared as plain `PathBuf`s, so build
//! expected paths the same way the code under test does (`join`).

use std::collections::{BTreeSet, HashMap};
use std::path::{Path, PathBuf};

use super::{FileSystem, Hive, Registry};

#[derive(Default)]
pub struct MapRegistry {
    values: HashMap<(Hive, String, String), String>,
    subkeys: HashMap<(Hive, String), Vec<String>>,
}

impl MapRegistry {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    #[must_use]
    pub fn with_value(mut self, hive: Hive, path: &str, name: &str, value: &str) -> Self {
        self.values.insert(
            (hive, path.to_lowercase(), name.to_lowercase()),
            value.to_string(),
        );
        self
    }

    #[must_use]
    pub fn with_subkeys(mut self, hive: Hive, path: &str, names: &[&str]) -> Self {
        self.subkeys.insert(
            (hive, path.to_lowercase()),
            names.iter().map(|n| (*n).to_string()).collect(),
        );
        self
    }
}

impl Registry for MapRegistry {
    fn string(&self, hive: Hive, path: &str, name: &str) -> Option<String> {
        self.values
            .get(&(hive, path.to_lowercase(), name.to_lowercase()))
            .cloned()
    }

    fn subkeys(&self, hive: Hive, path: &str) -> Vec<String> {
        self.subkeys
            .get(&(hive, path.to_lowercase()))
            .cloned()
            .unwrap_or_default()
    }
}

#[derive(Default)]
pub struct MapFs {
    files: HashMap<PathBuf, String>,
    dirs: BTreeSet<PathBuf>,
    canon: HashMap<PathBuf, PathBuf>,
}

impl MapFs {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    fn register_ancestors(&mut self, p: &Path) {
        for a in p.ancestors().skip(1) {
            if a.as_os_str().is_empty() {
                break;
            }
            self.dirs.insert(a.to_path_buf());
        }
    }

    /// Add a file (its parent directories exist implicitly).
    #[must_use]
    pub fn with_file(mut self, p: impl Into<PathBuf>, content: &str) -> Self {
        let p = p.into();
        self.register_ancestors(&p);
        self.files.insert(p, content.to_string());
        self
    }

    /// Add a directory (and its parents).
    #[must_use]
    pub fn with_dir(mut self, p: impl Into<PathBuf>) -> Self {
        let p = p.into();
        self.register_ancestors(&p);
        self.dirs.insert(p);
        self
    }

    /// Declare what `canonicalize(from)` returns; unlisted paths yield `None`.
    #[must_use]
    pub fn with_canonical(mut self, from: impl Into<PathBuf>, to: impl Into<PathBuf>) -> Self {
        self.canon.insert(from.into(), to.into());
        self
    }
}

impl FileSystem for MapFs {
    fn read_to_string(&self, p: &Path) -> Option<String> {
        self.files.get(p).cloned()
    }

    fn list_dir(&self, p: &Path) -> Vec<PathBuf> {
        let mut out: BTreeSet<PathBuf> = BTreeSet::new();
        for e in self.files.keys().chain(self.dirs.iter()) {
            if e.parent() == Some(p) {
                out.insert(e.clone());
            }
        }
        out.into_iter().collect()
    }

    fn is_dir(&self, p: &Path) -> bool {
        self.dirs.contains(p)
    }

    fn is_file(&self, p: &Path) -> bool {
        self.files.contains_key(p)
    }

    fn canonicalize(&self, p: &Path) -> Option<PathBuf> {
        self.canon.get(p).cloned()
    }
}
