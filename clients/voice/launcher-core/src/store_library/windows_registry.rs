//! The real Windows registry behind the [`Registry`] trait.

use windows_registry::{Key, CURRENT_USER, LOCAL_MACHINE};

use super::{Hive, Registry};

pub struct OsRegistry;

fn root(hive: Hive) -> &'static Key {
    match hive {
        Hive::CurrentUser => CURRENT_USER,
        Hive::LocalMachine => LOCAL_MACHINE,
    }
}

impl Registry for OsRegistry {
    fn string(&self, hive: Hive, path: &str, name: &str) -> Option<String> {
        // `get_string` reads REG_SZ and REG_EXPAND_SZ (expanded).
        root(hive).open(path).ok()?.get_string(name).ok()
    }

    fn subkeys(&self, hive: Hive, path: &str) -> Vec<String> {
        let Ok(key) = root(hive).open(path) else {
            return Vec::new();
        };
        key.keys().map(Iterator::collect).unwrap_or_default()
    }
}
