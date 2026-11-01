//! Credential persistence. The token is a bearer secret: file mode 0600 from the first byte,
//! atomic replace, and it never appears in `Debug` output or logs.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

use crate::error::{Error, Result};

#[derive(Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct StoredCredentials {
    /// Normalised origin the token belongs to.
    pub base_url: String,
    pub access_token: String,
    #[serde(default)]
    pub device_name: String,
}

impl std::fmt::Debug for StoredCredentials {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("StoredCredentials")
            .field("base_url", &self.base_url)
            .field("access_token", &"<redacted>")
            .field("device_name", &self.device_name)
            .finish()
    }
}

pub trait TokenStore {
    fn load(&self) -> Result<Option<StoredCredentials>>;
    fn save(&self, creds: &StoredCredentials) -> Result<()>;
    /// Idempotent: clearing an empty store is not an error.
    fn clear(&self) -> Result<()>;
}

pub struct FileTokenStore {
    path: PathBuf,
}

impl FileTokenStore {
    pub fn new(path: impl Into<PathBuf>) -> Self {
        Self { path: path.into() }
    }

    /// `<config dir>/lanomat-voice/credentials.json`, e.g. `~/.config/lanomat-voice/` on Linux.
    pub fn default_location() -> Result<Self> {
        let dirs = directories::ProjectDirs::from("", "", "lanomat-voice")
            .ok_or_else(|| Error::Store("cannot determine the config directory".into()))?;
        Ok(Self::new(dirs.config_dir().join("credentials.json")))
    }

    pub fn path(&self) -> &Path {
        &self.path
    }
}

impl TokenStore for FileTokenStore {
    fn load(&self) -> Result<Option<StoredCredentials>> {
        match std::fs::read(&self.path) {
            Ok(bytes) => serde_json::from_slice(&bytes).map(Some).map_err(|e| {
                Error::Store(format!(
                    "{} is not valid ({e}); delete it and log in again",
                    self.path.display()
                ))
            }),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(None),
            Err(e) => Err(Error::io(&self.path, e)),
        }
    }

    fn save(&self, creds: &StoredCredentials) -> Result<()> {
        use std::io::Write;

        let dir = self
            .path
            .parent()
            .filter(|p| !p.as_os_str().is_empty())
            .unwrap_or_else(|| Path::new("."));
        std::fs::create_dir_all(dir).map_err(|e| Error::io(dir, e))?;
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            // Best effort: keep the directory private as well.
            let _ = std::fs::set_permissions(dir, std::fs::Permissions::from_mode(0o700));
        }

        let tmp = self.path.with_extension("json.tmp");
        let mut opts = std::fs::OpenOptions::new();
        opts.write(true).create(true).truncate(true);
        #[cfg(unix)]
        {
            use std::os::unix::fs::OpenOptionsExt;
            opts.mode(0o600);
        }
        let json = serde_json::to_vec_pretty(creds).map_err(|e| Error::Store(e.to_string()))?;
        let mut file = opts.open(&tmp).map_err(|e| Error::io(&tmp, e))?;
        file.write_all(&json).map_err(|e| Error::io(&tmp, e))?;
        file.sync_all().map_err(|e| Error::io(&tmp, e))?;
        drop(file);
        std::fs::rename(&tmp, &self.path).map_err(|e| Error::io(&self.path, e))
    }

    fn clear(&self) -> Result<()> {
        match std::fs::remove_file(&self.path) {
            Ok(()) => Ok(()),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
            Err(e) => Err(Error::io(&self.path, e)),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn creds() -> StoredCredentials {
        StoredCredentials {
            base_url: "https://app.example/".into(),
            access_token: "lvc_unit_test_token".into(),
            device_name: "laptop".into(),
        }
    }

    #[test]
    fn missing_file_loads_as_none() {
        let dir = tempfile::tempdir().unwrap();
        let store = FileTokenStore::new(dir.path().join("nested/credentials.json"));
        assert_eq!(store.load().unwrap(), None);
    }

    #[test]
    fn round_trip_and_clear() {
        let dir = tempfile::tempdir().unwrap();
        let store = FileTokenStore::new(dir.path().join("nested/credentials.json"));
        store.save(&creds()).unwrap();
        assert_eq!(store.load().unwrap(), Some(creds()));
        // Overwrite.
        let mut other = creds();
        other.device_name = "desktop".into();
        store.save(&other).unwrap();
        assert_eq!(store.load().unwrap(), Some(other));
        store.clear().unwrap();
        assert_eq!(store.load().unwrap(), None);
        store.clear().unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn file_is_private() {
        use std::os::unix::fs::PermissionsExt;
        let dir = tempfile::tempdir().unwrap();
        let store = FileTokenStore::new(dir.path().join("credentials.json"));
        store.save(&creds()).unwrap();
        let mode = std::fs::metadata(store.path())
            .unwrap()
            .permissions()
            .mode()
            & 0o777;
        assert_eq!(mode, 0o600);
        // No stale temp file left behind.
        assert!(!dir.path().join("credentials.json.tmp").exists());
    }

    #[test]
    fn corrupt_file_is_a_clear_error() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("credentials.json");
        std::fs::write(&path, b"{nope").unwrap();
        let err = FileTokenStore::new(&path).load().unwrap_err();
        assert!(matches!(err, Error::Store(_)));
    }

    #[test]
    fn debug_never_prints_the_token() {
        assert!(!format!("{:?}", creds()).contains("unit_test_token"));
    }
}
