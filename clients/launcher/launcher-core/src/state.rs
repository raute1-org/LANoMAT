//! Persistent launcher settings: server URL, bearer token and the last install state.
//!
//! The token lives in `~/.config/lanomat-launcher/config.json` (0600, directory
//! 0700 when the launcher created it). Writes are atomic (private temp file +
//! rename) and preserve unknown keys in [`LauncherConfig::extra`], so a newer
//! launcher's fields are not silently dropped by an older one.
//!
//! Recovery: a file that does not parse is moved to `config.json.corrupt`
//! (mode 0600, like the config itself) and reported as [`StateError::Corrupt`],
//! whose message names the quarantine file. The next load then simply has no
//! config instead of failing forever. [`delete`] (logout) removes the config
//! *and* every `config.json.corrupt*` file, so no token survives the logout.
//!
//! Migration: a file without `version` (a v0 file) is accepted and migrated to
//! [`CONFIG_VERSION`] in memory; the next [`save`] persists the new version.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Deserializer, Serialize};

use crate::auth::parse_rfc3339;
use crate::types::MeUser;

pub const CONFIG_VERSION: u32 = 1;

/// The name a broken config is moved to, next to `config.json`.
pub const QUARANTINE_NAME: &str = "config.json.corrupt";

#[derive(Clone, Serialize)]
pub struct LauncherConfig {
    pub version: u32,
    pub url: String,
    pub token: String,
    #[serde(default)]
    pub token_expires_at: String,
    #[serde(default)]
    pub user: Option<MeUser>,
    #[serde(default)]
    pub server_name: Option<String>,
    #[serde(default)]
    pub server_version: Option<String>,
    /// Keys written by a newer launcher (or by a dev build): kept verbatim.
    #[serde(flatten)]
    pub extra: serde_json::Map<String, serde_json::Value>,
}

impl LauncherConfig {
    pub fn new(url: String, token: String, token_expires_at: String) -> Self {
        Self {
            version: CONFIG_VERSION,
            url,
            token,
            token_expires_at,
            user: None,
            server_name: None,
            server_version: None,
            extra: serde_json::Map::new(),
        }
    }

    /// The stored token expiry as Unix seconds, when the file has a parseable
    /// timestamp (a legacy file may carry `""`).
    pub fn token_expiry_unix(&self) -> Option<u64> {
        parse_rfc3339(&self.token_expires_at)
    }

    /// True when the stored expiry is known and not in the future. An unknown
    /// expiry is not treated as expired: the server's 401 is the authority.
    pub fn token_expired(&self, now_unix: u64) -> bool {
        self.token_expiry_unix()
            .is_some_and(|expires_at| now_unix >= expires_at)
    }
}

/// Hand-written so a `tracing::debug!("{config:?}")` cannot leak the bearer
/// token (the whole point of the 0600 file).
impl std::fmt::Debug for LauncherConfig {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("LauncherConfig")
            .field("version", &self.version)
            .field("url", &self.url)
            .field("token", &"<redacted>")
            .field("token_expires_at", &self.token_expires_at)
            .field("user", &self.user)
            .field("server_name", &self.server_name)
            .field("server_version", &self.server_version)
            .field("extra", &self.extra.keys().collect::<Vec<_>>())
            .finish()
    }
}

/// The on-disk shape before migration. `version` is optional so a v0 file is
/// readable instead of "corrupt"; the aliases cover configs written by earlier
/// agent/launcher builds. `extra` catches every key this version does not know.
#[derive(Deserialize)]
struct RawConfig {
    #[serde(default)]
    version: Option<u32>,
    #[serde(default, alias = "server_url", alias = "base_url")]
    url: String,
    #[serde(default, alias = "access_token")]
    token: String,
    #[serde(default, alias = "expires_at")]
    token_expires_at: String,
    #[serde(default)]
    user: Option<MeUser>,
    #[serde(default)]
    server_name: Option<String>,
    #[serde(default)]
    server_version: Option<String>,
    #[serde(flatten)]
    extra: serde_json::Map<String, serde_json::Value>,
}

impl<'de> Deserialize<'de> for LauncherConfig {
    fn deserialize<D>(deserializer: D) -> Result<Self, D::Error>
    where
        D: Deserializer<'de>,
    {
        let raw = RawConfig::deserialize(deserializer)?;

        Ok(Self {
            // Missing `version` is v0; `load` upgrades it to CONFIG_VERSION.
            version: raw.version.unwrap_or(0),
            url: raw.url,
            token: raw.token,
            token_expires_at: raw.token_expires_at,
            user: raw.user,
            server_name: raw.server_name,
            server_version: raw.server_version,
            extra: raw.extra,
        })
    }
}

#[derive(Debug, thiserror::Error)]
pub enum StateError {
    #[error("could not read {path}: {source}")]
    Read {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("could not write {path}: {source}")]
    Write {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("config {path} was written by a newer launcher (version {version})")]
    NewerVersion { path: PathBuf, version: u32 },
    #[error("config {path} is corrupt: {message}")]
    Corrupt { path: PathBuf, message: String },
}

/// The config directory, overridable for tests (`LANOMAT_LAUNCHER_CONFIG_DIR`).
pub fn config_dir() -> PathBuf {
    if let Some(dir) = std::env::var_os("LANOMAT_LAUNCHER_CONFIG_DIR") {
        return PathBuf::from(dir);
    }

    directories::ProjectDirs::from("app", "LANoMAT", "lanomat-launcher")
        .map(|dirs| dirs.config_dir().to_path_buf())
        .unwrap_or_else(|| PathBuf::from(".lanomat-launcher"))
}

pub fn config_path(dir: &Path) -> PathBuf {
    dir.join("config.json")
}

/// Where a corrupt `config.json` is moved to by [`load`].
pub fn quarantine_path(dir: &Path) -> PathBuf {
    dir.join(QUARANTINE_NAME)
}

pub fn load(dir: &Path) -> Result<Option<LauncherConfig>, StateError> {
    let path = config_path(dir);
    let bytes = match std::fs::read(&path) {
        Ok(bytes) => bytes,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(None),
        Err(source) => return Err(StateError::Read { path, source }),
    };

    let mut config = match serde_json::from_slice::<LauncherConfig>(&bytes) {
        Ok(config) => config,
        Err(error) => {
            // Move the unreadable file aside so the next start is not stuck on
            // the same error, and name it in the message the UI shows.
            let message = match quarantine(&path) {
                Some(target) => format!("{error} (moved aside to {})", target.display()),
                None => format!("{error} (could not be moved aside)"),
            };

            return Err(StateError::Corrupt { path, message });
        }
    };

    if config.version > CONFIG_VERSION {
        return Err(StateError::NewerVersion {
            path,
            version: config.version,
        });
    }

    if config.version < CONFIG_VERSION {
        // v0 file (no `version`) or an older shape: migrate in memory only.
        config.version = CONFIG_VERSION;
    }

    // A file copied from elsewhere may be world-readable; the token inside is
    // not. Loading is the cheapest place to repair that.
    tighten_permissions(&path);

    Ok(Some(config))
}

pub fn save(dir: &Path, config: &LauncherConfig) -> Result<(), StateError> {
    let created = !dir.exists();
    std::fs::create_dir_all(dir).map_err(|source| StateError::Write {
        path: dir.to_path_buf(),
        source,
    })?;

    // Only tighten a directory the launcher created: `LANOMAT_LAUNCHER_CONFIG_DIR`
    // may point at a shared directory the user owns.
    if created {
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let _ = std::fs::set_permissions(dir, std::fs::Permissions::from_mode(0o700));
        }
    }

    let path = config_path(dir);
    let temp = dir.join(format!("config.json.{}.tmp", std::process::id()));
    let raw = serde_json::to_vec_pretty(config).map_err(|error| StateError::Corrupt {
        path: path.clone(),
        message: error.to_string(),
    })?;

    write_private(&temp, &raw).map_err(|source| StateError::Write {
        path: temp.clone(),
        source,
    })?;

    std::fs::rename(&temp, &path).map_err(|source| {
        let _ = std::fs::remove_file(&temp);

        StateError::Write { path, source }
    })
}

pub fn delete(dir: &Path) -> Result<(), StateError> {
    remove_if_present(&config_path(dir))?;

    // A quarantined copy of a corrupt config holds the same token: a
    // logged-out launcher must not leave it on disk either. The scan tolerates
    // a missing directory (nothing to log out from).
    let entries = match std::fs::read_dir(dir) {
        Ok(entries) => entries,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(()),
        Err(source) => {
            return Err(StateError::Read {
                path: dir.to_path_buf(),
                source,
            });
        }
    };

    for entry in entries {
        let entry = entry.map_err(|source| StateError::Read {
            path: dir.to_path_buf(),
            source,
        })?;
        let name = entry.file_name();

        if !name.to_string_lossy().starts_with(QUARANTINE_NAME) {
            continue;
        }

        let path = entry.path();
        let metadata = match std::fs::symlink_metadata(&path) {
            Ok(metadata) => metadata,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => continue,
            Err(source) => return Err(StateError::Read { path, source }),
        };

        if metadata.is_dir() {
            continue;
        }

        remove_if_present(&path)?;
    }

    Ok(())
}

fn remove_if_present(path: &Path) -> Result<(), StateError> {
    match std::fs::remove_file(path) {
        Ok(()) => Ok(()),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(source) => Err(StateError::Read {
            path: path.to_path_buf(),
            source,
        }),
    }
}

/// Create `path` with 0600 from the first byte (no world-readable window
/// between `write` and `chmod`).
fn write_private(path: &Path, bytes: &[u8]) -> std::io::Result<()> {
    use std::io::Write;

    #[cfg(unix)]
    use std::os::unix::fs::OpenOptionsExt;

    let mut options = std::fs::OpenOptions::new();
    options.write(true).create(true).truncate(true);
    #[cfg(unix)]
    options.mode(0o600);

    let mut file = options.open(path)?;
    file.write_all(bytes)?;
    file.sync_all()
}

fn quarantine(path: &Path) -> Option<PathBuf> {
    let dir = path.parent().unwrap_or_else(|| Path::new("."));
    let target = quarantine_path(dir);

    if target.exists() {
        let _ = std::fs::remove_file(&target);
    }

    if std::fs::rename(path, &target).is_ok() {
        // `rename` keeps the old mode, so a file that was world-readable before
        // it broke would leave the token readable at `config.json.corrupt`.
        tighten_permissions(&target);
        return Some(target);
    }

    // A rename can fail on an exotic filesystem; copying is still better than
    // leaving the launcher stuck on the same file.
    std::fs::copy(path, &target).ok()?;
    tighten_permissions(&target);
    std::fs::remove_file(path).ok()?;

    Some(target)
}

#[cfg(unix)]
fn tighten_permissions(path: &Path) {
    use std::os::unix::fs::PermissionsExt;

    if let Ok(metadata) = std::fs::metadata(path) {
        if metadata.permissions().mode() & 0o077 != 0 {
            let _ = std::fs::set_permissions(path, std::fs::Permissions::from_mode(0o600));
        }
    }
}

#[cfg(not(unix))]
fn tighten_permissions(_path: &Path) {}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn saves_atomically_with_private_permissions_and_round_trips() {
        let dir = tempfile::tempdir().unwrap();
        let config = LauncherConfig::new(
            "https://lan.test".into(),
            "tok".into(),
            "2030-01-01T00:00:00Z".into(),
        );

        save(dir.path(), &config).unwrap();
        let loaded = load(dir.path()).unwrap().unwrap();
        assert_eq!(loaded.url, "https://lan.test");
        assert_eq!(loaded.token, "tok");

        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let mode = std::fs::metadata(config_path(dir.path()))
                .unwrap()
                .permissions()
                .mode()
                & 0o777;
            assert_eq!(mode, 0o600);
        }
    }

    #[test]
    fn a_newer_version_is_refused_instead_of_downgraded() {
        let dir = tempfile::tempdir().unwrap();
        std::fs::write(
            config_path(dir.path()),
            r#"{"version":99,"url":"https://lan.test","token":"t","token_expires_at":"x"}"#,
        )
        .unwrap();

        assert!(matches!(
            load(dir.path()),
            Err(StateError::NewerVersion { version: 99, .. })
        ));
    }

    #[test]
    fn the_token_never_appears_in_debug_output() {
        let config = LauncherConfig::new(
            "https://lan.test".into(),
            "super-secret-token".into(),
            "2030-01-01T00:00:00Z".into(),
        );
        let debug = format!("{config:?}");

        assert!(!debug.contains("super-secret-token"));
        assert!(debug.contains("<redacted>"));
    }
}
