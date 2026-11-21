//! Persistent launcher settings: server URL, bearer token and the last install state.
//!
//! The token lives in `~/.config/lanomat-launcher/config.json` (0600, directory
//! 0700). Writes are atomic (temp file + rename) and preserve unknown keys.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

use crate::types::MeUser;

pub const CONFIG_VERSION: u32 = 1;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct LauncherConfig {
    pub version: u32,
    pub url: String,
    pub token: String,
    pub token_expires_at: String,
    #[serde(default)]
    pub user: Option<MeUser>,
    #[serde(default)]
    pub server_name: Option<String>,
    #[serde(default)]
    pub server_version: Option<String>,
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
        }
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

pub fn load(dir: &Path) -> Result<Option<LauncherConfig>, StateError> {
    let path = config_path(dir);
    let raw = match std::fs::read_to_string(&path) {
        Ok(raw) => raw,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(None),
        Err(source) => return Err(StateError::Read { path, source }),
    };

    let config: LauncherConfig = serde_json::from_str(&raw).map_err(|error| StateError::Corrupt {
        path: path.clone(),
        message: error.to_string(),
    })?;

    if config.version > CONFIG_VERSION {
        return Err(StateError::NewerVersion {
            path,
            version: config.version,
        });
    }

    Ok(Some(config))
}

pub fn save(dir: &Path, config: &LauncherConfig) -> Result<(), StateError> {
    std::fs::create_dir_all(dir).map_err(|source| StateError::Write {
        path: dir.to_path_buf(),
        source,
    })?;
    let path = config_path(dir);
    let temp = dir.join("config.json.tmp");
    let raw = serde_json::to_vec_pretty(config).map_err(|error| StateError::Corrupt {
        path: path.clone(),
        message: error.to_string(),
    })?;

    std::fs::write(&temp, raw).map_err(|source| StateError::Write {
        path: temp.clone(),
        source,
    })?;

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        let _ = std::fs::set_permissions(dir, std::fs::Permissions::from_mode(0o700));
        let _ = std::fs::set_permissions(&temp, std::fs::Permissions::from_mode(0o600));
    }

    std::fs::rename(&temp, &path).map_err(|source| StateError::Write { path, source })
}

pub fn delete(dir: &Path) -> Result<(), StateError> {
    let path = config_path(dir);
    match std::fs::remove_file(&path) {
        Ok(()) => Ok(()),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(source) => Err(StateError::Read { path, source }),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn saves_atomically_with_private_permissions_and_round_trips() {
        let dir = tempfile::tempdir().unwrap();
        let config = LauncherConfig::new("https://lan.test".into(), "tok".into(), "2030-01-01T00:00:00Z".into());

        save(dir.path(), &config).unwrap();
        let loaded = load(dir.path()).unwrap().unwrap();
        assert_eq!(loaded.url, "https://lan.test");
        assert_eq!(loaded.token, "tok");

        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let mode = std::fs::metadata(config_path(dir.path())).unwrap().permissions().mode() & 0o777;
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

        assert!(matches!(load(dir.path()), Err(StateError::NewerVersion { version: 99, .. })));
    }
}
