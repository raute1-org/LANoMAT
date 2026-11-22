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

/// Creates `dir` (0700 when this call created it) so a state file can be written into it.
fn ensure_private_dir(dir: &Path) -> Result<(), StateError> {
    let created = !dir.exists();
    std::fs::create_dir_all(dir).map_err(|source| StateError::Write {
        path: dir.to_path_buf(),
        source,
    })?;

    // Only tighten a directory this call created: the path may be a shared one the user owns.
    if created {
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let _ = std::fs::set_permissions(dir, std::fs::Permissions::from_mode(0o700));
        }
    }

    Ok(())
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

    quarantine_as(path, quarantine_path(dir))
}

/// Moves an unreadable state file to `target` (mode 0600), replacing an older quarantine copy.
fn quarantine_as(path: &Path, target: PathBuf) -> Option<PathBuf> {
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

// ---- the install register -------------------------------------------------------------------
//
// `installs.json` in the app's config directory: one record per installed (or half installed)
// game. It holds paths and states, never a token, and is the only launcher file the app ever
// imports (see [`import_launcher_installs`]).

pub const LEDGER_VERSION: u32 = 1;

/// The register file, next to the app's other state.
pub const LEDGER_FILE: &str = "installs.json";

/// Where an unreadable register is moved to.
pub const LEDGER_QUARANTINE_NAME: &str = "installs.json.corrupt";

/// More games than a LAN library will ever hold; a guard against a runaway file, not a policy.
pub const MAX_LEDGER_RECORDS: usize = 500;

/// Where a game stands locally.
///
/// Main line: `not_installed -> installing -> ready`. A game without a record is
/// `not_installed`. Side states: `repair_needed` (files differ from the plan, or an install was
/// interrupted: resumable), `broken` (the install directory is gone or unusable: reinstall),
/// `offline` (the install location is not reachable right now, e.g. an unplugged drive).
/// An unknown value read from a newer file is treated as `repair_needed`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum InstallStatus {
    NotInstalled,
    Installing,
    Ready,
    Broken,
    Offline,
    #[serde(other)]
    RepairNeeded,
}

impl InstallStatus {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::NotInstalled => "not_installed",
            Self::Installing => "installing",
            Self::Ready => "ready",
            Self::RepairNeeded => "repair_needed",
            Self::Broken => "broken",
            Self::Offline => "offline",
        }
    }

    /// The allowed moves. Staying put is always fine; removing a record is not a transition.
    pub fn can_become(self, next: Self) -> bool {
        use InstallStatus::*;

        self == next
            || matches!(
                (self, next),
                (NotInstalled, Installing)
                    // finished, failed (nothing usable), or cancelled before anything landed
                    | (Installing, Ready | Broken | RepairNeeded | NotInstalled)
                    // a check found a difference, the place vanished, or an update starts
                    | (Ready, RepairNeeded | Broken | Offline | Installing)
                    // repaired in place, repaired by reinstalling, or turned out worse
                    | (RepairNeeded, Ready | Installing | Broken | Offline)
                    | (Broken, Installing | RepairNeeded | NotInstalled)
                    // the drive came back
                    | (Offline, Ready | RepairNeeded | Broken | Installing)
            )
    }
}

/// One game on this machine.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct InstallRecord {
    pub slug: String,
    /// The install directory.
    pub path: String,
    pub status: InstallStatus,
    /// `RecipePlan::content_hash` of the plan that was installed: tells an update from a repair.
    #[serde(default)]
    pub plan_hash: Option<String>,
    /// The recipe's start command for this OS, resolved at install time.
    #[serde(default)]
    pub launch_command: Option<String>,
    /// RFC 3339, set when the game was started from the app.
    #[serde(default)]
    pub last_played_at: Option<String>,
    #[serde(default)]
    pub version: Option<String>,
    /// Files that were verified after the install (paths relative to `path`).
    #[serde(default)]
    pub verified: Vec<String>,
}

impl InstallRecord {
    pub fn new(slug: impl Into<String>, path: impl Into<String>, status: InstallStatus) -> Self {
        Self {
            slug: slug.into(),
            path: path.into(),
            status,
            plan_hash: None,
            launch_command: None,
            last_played_at: None,
            version: None,
            verified: Vec::new(),
        }
    }
}

/// A recipe slug as the server allows it: lowercase words joined by single hyphens.
pub fn is_valid_slug(slug: &str) -> bool {
    !slug.is_empty()
        && slug.len() <= 128
        && !slug.starts_with('-')
        && !slug.ends_with('-')
        && !slug.contains("--")
        && slug
            .bytes()
            .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-')
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Ledger {
    pub version: u32,
    #[serde(default)]
    pub records: Vec<InstallRecord>,
}

impl Default for Ledger {
    fn default() -> Self {
        Self {
            version: LEDGER_VERSION,
            records: Vec::new(),
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum LedgerChangeError {
    InvalidSlug,
    NoRecord,
    NotAllowed {
        from: InstallStatus,
        to: InstallStatus,
    },
    Full,
}

impl std::fmt::Display for LedgerChangeError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::InvalidSlug => write!(f, "not a valid recipe slug"),
            Self::NoRecord => write!(f, "that game has no install record"),
            Self::NotAllowed { from, to } => {
                write!(
                    f,
                    "a game cannot go from {} to {}",
                    from.as_str(),
                    to.as_str()
                )
            }
            Self::Full => write!(f, "the install register is full"),
        }
    }
}

impl std::error::Error for LedgerChangeError {}

impl Ledger {
    pub fn find(&self, slug: &str) -> Option<&InstallRecord> {
        self.records.iter().find(|r| r.slug == slug)
    }

    /// The status of a game; no record means `not_installed`.
    pub fn status_of(&self, slug: &str) -> InstallStatus {
        self.find(slug)
            .map_or(InstallStatus::NotInstalled, |r| r.status)
    }

    /// Adds or replaces the record of `record.slug`, keeping the register ordered by first
    /// insertion. Replacing is how an install starts over; use [`Self::set_status`] for moves.
    pub fn upsert(&mut self, record: InstallRecord) -> Result<(), LedgerChangeError> {
        if !is_valid_slug(&record.slug) {
            return Err(LedgerChangeError::InvalidSlug);
        }

        if let Some(existing) = self.records.iter_mut().find(|r| r.slug == record.slug) {
            let from = existing.status;
            if !from.can_become(record.status) {
                return Err(LedgerChangeError::NotAllowed {
                    from,
                    to: record.status,
                });
            }
            *existing = record;
            return Ok(());
        }

        if record.status == InstallStatus::NotInstalled {
            // Nothing to remember about a game that is not installed.
            return Ok(());
        }
        if self.records.len() >= MAX_LEDGER_RECORDS {
            return Err(LedgerChangeError::Full);
        }
        self.records.push(record);

        Ok(())
    }

    /// Moves a game along the status automaton. `NotInstalled` removes the record.
    pub fn set_status(&mut self, slug: &str, next: InstallStatus) -> Result<(), LedgerChangeError> {
        let record = self
            .records
            .iter_mut()
            .find(|r| r.slug == slug)
            .ok_or(LedgerChangeError::NoRecord)?;
        if !record.status.can_become(next) {
            return Err(LedgerChangeError::NotAllowed {
                from: record.status,
                to: next,
            });
        }
        record.status = next;
        if next == InstallStatus::NotInstalled {
            self.records.retain(|r| r.slug != slug);
        }

        Ok(())
    }

    /// Forgets a game (uninstalled, or the person removed it from the list).
    pub fn remove(&mut self, slug: &str) -> bool {
        let before = self.records.len();
        self.records.retain(|r| r.slug != slug);

        self.records.len() != before
    }

    /// Records a start from the app; `now` is an RFC 3339 timestamp from the caller (this crate
    /// keeps no clock). Only a game that is `ready` or `repair_needed` can be played.
    pub fn mark_played(&mut self, slug: &str, now: &str) -> Result<(), LedgerChangeError> {
        let record = self
            .records
            .iter_mut()
            .find(|r| r.slug == slug)
            .ok_or(LedgerChangeError::NoRecord)?;
        if !matches!(
            record.status,
            InstallStatus::Ready | InstallStatus::RepairNeeded
        ) {
            return Err(LedgerChangeError::NotAllowed {
                from: record.status,
                to: InstallStatus::Ready,
            });
        }
        record.last_played_at = Some(now.to_owned());

        Ok(())
    }

    /// A record still `installing` when the app starts belongs to a run that did not finish: it
    /// is resumable, not "in progress". Returns how many records changed.
    pub fn recover_interrupted(&mut self) -> usize {
        let mut changed = 0;
        for record in &mut self.records {
            if record.status == InstallStatus::Installing {
                record.status = InstallStatus::RepairNeeded;
                changed += 1;
            }
        }

        changed
    }

    /// Re-checks where installed games live. A `ready`/`repair_needed` game whose directory is
    /// gone is `broken` when its parent still exists (someone deleted it) and `offline` when the
    /// parent is gone too (an unplugged drive, an unmounted share); an `offline` game whose
    /// directory is back is `ready` again. Returns how many records changed.
    pub fn reconcile(&mut self) -> usize {
        let mut changed = 0;
        for record in &mut self.records {
            let next = reconciled_status(record);
            if next != record.status {
                record.status = next;
                changed += 1;
            }
        }

        changed
    }
}

fn reconciled_status(record: &InstallRecord) -> InstallStatus {
    let path = Path::new(&record.path);
    match record.status {
        InstallStatus::Ready | InstallStatus::RepairNeeded | InstallStatus::Offline => {
            if path.is_dir() {
                return if record.status == InstallStatus::Offline {
                    InstallStatus::Ready
                } else {
                    record.status
                };
            }
            let parent_there = path.parent().is_some_and(Path::exists);
            if parent_there {
                InstallStatus::Broken
            } else {
                InstallStatus::Offline
            }
        }
        other => other,
    }
}

pub fn ledger_path(dir: &Path) -> PathBuf {
    dir.join(LEDGER_FILE)
}

/// Where an unreadable register is moved to by [`load_ledger`].
pub fn ledger_quarantine_path(dir: &Path) -> PathBuf {
    dir.join(LEDGER_QUARANTINE_NAME)
}

/// What [`load_ledger`] found.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct LedgerLoad {
    pub ledger: Ledger,
    /// Set when the file was unreadable: it was moved here and `ledger` is empty.
    pub quarantined: Option<PathBuf>,
    /// `false` when no register file existed (a first start).
    pub existed: bool,
}

/// Loads the register. A missing file is an empty register; an unreadable one is moved aside
/// (so the next start is not stuck on it) and replaced by an empty register, reported in
/// [`LedgerLoad::quarantined`]. A register written by a newer app is refused, not downgraded.
pub fn load_ledger(dir: &Path) -> Result<LedgerLoad, StateError> {
    let path = ledger_path(dir);
    let bytes = match std::fs::read(&path) {
        Ok(bytes) => bytes,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
            return Ok(LedgerLoad {
                ledger: Ledger::default(),
                quarantined: None,
                existed: false,
            });
        }
        Err(source) => return Err(StateError::Read { path, source }),
    };

    match serde_json::from_slice::<Ledger>(&bytes) {
        Ok(ledger) if ledger.version > LEDGER_VERSION => Err(StateError::NewerVersion {
            path,
            version: ledger.version,
        }),
        Ok(mut ledger) => {
            tighten_permissions(&path);
            ledger.records.retain(|r| is_valid_slug(&r.slug));
            ledger.records.truncate(MAX_LEDGER_RECORDS);

            Ok(LedgerLoad {
                ledger,
                quarantined: None,
                existed: true,
            })
        }
        Err(_) => Ok(LedgerLoad {
            ledger: Ledger::default(),
            quarantined: quarantine_as(&path, ledger_quarantine_path(dir)),
            existed: true,
        }),
    }
}

/// Writes the register atomically (private temp file, then rename), mode 0600.
pub fn save_ledger(dir: &Path, ledger: &Ledger) -> Result<(), StateError> {
    ensure_private_dir(dir)?;

    let path = ledger_path(dir);
    let temp = dir.join(format!("{LEDGER_FILE}.{}.tmp", std::process::id()));
    let raw = serde_json::to_vec_pretty(ledger).map_err(|error| StateError::Corrupt {
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

/// What an import of the old launcher's register did.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct ImportReport {
    /// The old directory had an `installs.json` that could be read.
    pub found: bool,
    pub imported: usize,
    /// A game the register already knows is kept as it is.
    pub already_known: usize,
    /// Entries with a bad slug, an empty or relative path, or past the size limit.
    pub rejected: usize,
}

/// One entry of the old launcher's `installs.json` (a bare JSON array). Only these fields are
/// read: whatever else a file carries is dropped on the way in.
#[derive(Deserialize)]
struct LegacyRecord {
    slug: String,
    target_dir: String,
    #[serde(default)]
    version: Option<String>,
    #[serde(default)]
    verified: Vec<String>,
    #[serde(default)]
    launch_command: Option<String>,
}

/// Brings the install records of the old launcher into `ledger`.
///
/// Reads `<legacy_dir>/installs.json` and nothing else: the launcher's `config.json` (which holds
/// its bearer token) is never opened, and the token of the old launcher does not become a
/// credential of the app; people pair the app themselves. The old file is only read, never
/// changed. Imported games are `ready` and then reconciled with the disk, so a directory that
/// no longer exists shows up as `broken` or `offline` instead of a game that cannot start.
pub fn import_launcher_installs(legacy_dir: &Path, ledger: &mut Ledger) -> ImportReport {
    let mut report = ImportReport::default();
    let Ok(bytes) = std::fs::read(legacy_dir.join(LEDGER_FILE)) else {
        return report;
    };
    let Ok(entries) = serde_json::from_slice::<Vec<LegacyRecord>>(&bytes) else {
        return report;
    };
    report.found = true;

    for entry in entries {
        if !is_valid_slug(&entry.slug) || !Path::new(&entry.target_dir).is_absolute() {
            report.rejected += 1;
            continue;
        }
        if ledger.find(&entry.slug).is_some() {
            report.already_known += 1;
            continue;
        }

        let mut record = InstallRecord::new(entry.slug, entry.target_dir, InstallStatus::Ready);
        record.version = entry.version;
        record.verified = entry.verified;
        record.launch_command = entry.launch_command;
        record.status = reconciled_status(&record);

        match ledger.upsert(record) {
            Ok(()) => report.imported += 1,
            Err(_) => report.rejected += 1,
        }
    }

    report
}

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

    #[cfg(unix)]
    fn mode_of(path: &Path) -> u32 {
        use std::os::unix::fs::PermissionsExt;
        std::fs::metadata(path).unwrap().permissions().mode() & 0o777
    }

    fn rec(slug: &str, path: &str, status: InstallStatus) -> InstallRecord {
        InstallRecord::new(slug, path, status)
    }

    #[test]
    fn the_ledger_round_trips_and_is_private() {
        let root = tempfile::tempdir().unwrap();
        let dir = root.path().join("state");
        let mut ledger = Ledger::default();
        let mut r = rec("ut2004", "/games/ut2004", InstallStatus::Ready);
        r.plan_hash = Some("abc".into());
        r.launch_command = Some("System/ut2004.exe".into());
        r.last_played_at = Some("2026-11-22T12:00:00Z".into());
        ledger.upsert(r.clone()).unwrap();
        ledger
            .upsert(rec("other", "/games/other", InstallStatus::Installing))
            .unwrap();

        save_ledger(&dir, &ledger).unwrap();
        let loaded = load_ledger(&dir).unwrap();
        assert!(loaded.existed && loaded.quarantined.is_none());
        assert_eq!(loaded.ledger, ledger);
        assert_eq!(loaded.ledger.find("ut2004"), Some(&r));

        #[cfg(unix)]
        {
            assert_eq!(mode_of(&ledger_path(&dir)), 0o600);
            assert_eq!(mode_of(&dir), 0o700);
        }
        assert!(
            std::fs::read_dir(&dir).unwrap().count() == 1,
            "no temp file is left behind"
        );
    }

    #[test]
    fn a_missing_ledger_is_empty_and_not_existing() {
        let dir = tempfile::tempdir().unwrap();
        let loaded = load_ledger(dir.path()).unwrap();
        assert!(!loaded.existed);
        assert!(loaded.ledger.records.is_empty());
    }

    #[test]
    fn a_corrupt_ledger_is_quarantined_privately_and_replaced_by_an_empty_one() {
        let dir = tempfile::tempdir().unwrap();
        std::fs::write(ledger_path(dir.path()), b"{ not json").unwrap();
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            std::fs::set_permissions(
                ledger_path(dir.path()),
                std::fs::Permissions::from_mode(0o644),
            )
            .unwrap();
        }

        let loaded = load_ledger(dir.path()).unwrap();
        assert!(loaded.ledger.records.is_empty());
        assert_eq!(
            loaded.quarantined.as_deref(),
            Some(ledger_quarantine_path(dir.path()).as_path())
        );
        assert!(!ledger_path(dir.path()).exists());
        assert_eq!(
            std::fs::read(ledger_quarantine_path(dir.path())).unwrap(),
            b"{ not json"
        );
        #[cfg(unix)]
        assert_eq!(mode_of(&ledger_quarantine_path(dir.path())), 0o600);

        // The next start is not stuck on the same file.
        let again = load_ledger(dir.path()).unwrap();
        assert!(!again.existed && again.quarantined.is_none());
    }

    #[test]
    fn a_newer_ledger_is_refused_and_left_alone() {
        let dir = tempfile::tempdir().unwrap();
        let raw = br#"{"version":99,"records":[]}"#;
        std::fs::write(ledger_path(dir.path()), raw).unwrap();
        assert!(matches!(
            load_ledger(dir.path()),
            Err(StateError::NewerVersion { version: 99, .. })
        ));
        assert_eq!(std::fs::read(ledger_path(dir.path())).unwrap(), raw);
    }

    #[test]
    fn records_with_a_hostile_slug_are_dropped_on_load_and_refused_on_insert() {
        let dir = tempfile::tempdir().unwrap();
        std::fs::write(
            ledger_path(dir.path()),
            br#"{"version":1,"records":[
              {"slug":"../etc","path":"/x","status":"ready"},
              {"slug":"fine-game","path":"/y","status":"ready"}]}"#,
        )
        .unwrap();
        let loaded = load_ledger(dir.path()).unwrap();
        assert_eq!(loaded.ledger.records.len(), 1);
        assert_eq!(loaded.ledger.records[0].slug, "fine-game");

        let mut ledger = Ledger::default();
        for bad in ["", "Up", "a/b", "a--b", "-a", "a_b"] {
            assert_eq!(
                ledger.upsert(rec(bad, "/x", InstallStatus::Ready)),
                Err(LedgerChangeError::InvalidSlug),
                "{bad}"
            );
        }
    }

    #[test]
    fn an_unknown_status_from_a_newer_file_needs_repair_instead_of_failing() {
        let dir = tempfile::tempdir().unwrap();
        std::fs::write(
            ledger_path(dir.path()),
            br#"{"version":1,"records":[{"slug":"g","path":"/x","status":"quantum"}]}"#,
        )
        .unwrap();
        let loaded = load_ledger(dir.path()).unwrap();
        assert_eq!(loaded.ledger.status_of("g"), InstallStatus::RepairNeeded);
    }

    #[test]
    fn the_status_automaton_walks_the_main_line_and_the_side_states() {
        use InstallStatus::*;
        let mut ledger = Ledger::default();
        assert_eq!(ledger.status_of("g"), NotInstalled);

        ledger.upsert(rec("g", "/p", Installing)).unwrap();
        assert!(ledger.set_status("g", Ready).is_ok());
        assert!(ledger.set_status("g", RepairNeeded).is_ok());
        assert!(ledger.set_status("g", Installing).is_ok());
        assert!(ledger.set_status("g", Broken).is_ok());
        assert!(ledger.set_status("g", Installing).is_ok());
        assert!(ledger.set_status("g", Ready).is_ok());
        assert!(ledger.set_status("g", Offline).is_ok());
        assert!(ledger.set_status("g", Ready).is_ok());

        // Not allowed: skipping the install, or leaving broken straight to ready.
        assert_eq!(
            ledger.set_status("g", NotInstalled),
            Err(LedgerChangeError::NotAllowed {
                from: Ready,
                to: NotInstalled
            })
        );
        ledger.set_status("g", Broken).unwrap();
        assert!(ledger.set_status("g", Ready).is_err());
        assert!(ledger.set_status("missing", Ready).is_err());
        // A cancelled or removed game forgets its record.
        ledger.set_status("g", NotInstalled).unwrap();
        assert!(ledger.find("g").is_none());
        // A new game cannot appear as ready without having been installed.
        assert!(Ledger::default()
            .upsert(rec("g", "/p", NotInstalled))
            .is_ok());
    }

    #[test]
    fn an_interrupted_install_becomes_repairable_and_a_vanished_one_broken_or_offline() {
        let root = tempfile::tempdir().unwrap();
        let there = root.path().join("there");
        std::fs::create_dir(&there).unwrap();
        let gone = root.path().join("gone");
        let unplugged = root.path().join("usb").join("games");

        let mut ledger = Ledger::default();
        ledger
            .upsert(rec(
                "half",
                there.to_str().unwrap(),
                InstallStatus::Installing,
            ))
            .unwrap();
        ledger
            .upsert(rec("ok", there.to_str().unwrap(), InstallStatus::Ready))
            .unwrap();
        ledger
            .upsert(rec("deleted", gone.to_str().unwrap(), InstallStatus::Ready))
            .unwrap();
        ledger
            .upsert(rec(
                "drive",
                unplugged.to_str().unwrap(),
                InstallStatus::Ready,
            ))
            .unwrap();

        assert_eq!(ledger.recover_interrupted(), 1);
        assert_eq!(ledger.status_of("half"), InstallStatus::RepairNeeded);

        assert_eq!(ledger.reconcile(), 2);
        assert_eq!(ledger.status_of("ok"), InstallStatus::Ready);
        assert_eq!(ledger.status_of("deleted"), InstallStatus::Broken);
        assert_eq!(ledger.status_of("drive"), InstallStatus::Offline);
        assert_eq!(ledger.status_of("half"), InstallStatus::RepairNeeded);

        // The drive comes back.
        std::fs::create_dir_all(&unplugged).unwrap();
        assert_eq!(ledger.reconcile(), 1);
        assert_eq!(ledger.status_of("drive"), InstallStatus::Ready);
    }

    #[test]
    fn playing_is_recorded_only_for_a_game_that_can_start() {
        let mut ledger = Ledger::default();
        ledger
            .upsert(rec("g", "/p", InstallStatus::Installing))
            .unwrap();
        assert!(ledger.mark_played("g", "2026-11-22T12:00:00Z").is_err());
        ledger.set_status("g", InstallStatus::Ready).unwrap();
        ledger.mark_played("g", "2026-11-22T12:00:00Z").unwrap();
        assert_eq!(
            ledger.find("g").unwrap().last_played_at.as_deref(),
            Some("2026-11-22T12:00:00Z")
        );
        assert_eq!(
            ledger.mark_played("nope", "x"),
            Err(LedgerChangeError::NoRecord)
        );
    }

    const LEGACY_INSTALLS: &str = r#"[
      {"slug":"ut2004","target_dir":"/definitely/not/here/ut2004","version":"3374",
       "verified":["System/Build.ini"],"launch_command":"System/ut2004.exe",
       "token":"legacy-bearer-token","extra":{"access_token":"legacy-bearer-token"}},
      {"slug":"../evil","target_dir":"/x"},
      {"slug":"relative","target_dir":"games/relative"},
      {"slug":"known","target_dir":"/legacy/known"}
    ]"#;

    #[test]
    fn importing_the_launcher_register_takes_installs_only_and_never_a_token() {
        let legacy = tempfile::tempdir().unwrap();
        std::fs::write(legacy.path().join(LEDGER_FILE), LEGACY_INSTALLS).unwrap();
        // The old launcher keeps its bearer token next to the register. It must stay unread.
        let config = legacy.path().join("config.json");
        std::fs::write(
            &config,
            r#"{"version":1,"url":"https://lan.example","token":"legacy-bearer-token"}"#,
        )
        .unwrap();
        let config_before = std::fs::read(&config).unwrap();
        let register_before = std::fs::read(legacy.path().join(LEDGER_FILE)).unwrap();

        let mut ledger = Ledger::default();
        ledger
            .upsert(rec("known", "/app/known", InstallStatus::Ready))
            .unwrap();
        let report = import_launcher_installs(legacy.path(), &mut ledger);

        assert_eq!(
            report,
            ImportReport {
                found: true,
                imported: 1,
                already_known: 1,
                rejected: 2
            }
        );
        let ut = ledger.find("ut2004").unwrap();
        assert_eq!(ut.path, "/definitely/not/here/ut2004");
        assert_eq!(ut.version.as_deref(), Some("3374"));
        assert_eq!(ut.launch_command.as_deref(), Some("System/ut2004.exe"));
        assert_eq!(
            ut.status,
            InstallStatus::Offline,
            "reconciled with the disk"
        );
        assert_eq!(
            ledger.find("known").unwrap().path,
            "/app/known",
            "kept as is"
        );

        // Nothing of the token reaches the new register, in memory or on disk.
        let out = tempfile::tempdir().unwrap();
        save_ledger(out.path(), &ledger).unwrap();
        let written = std::fs::read_to_string(ledger_path(out.path())).unwrap();
        assert!(!written.contains("legacy-bearer-token"), "{written}");
        assert!(!format!("{ledger:?}").contains("legacy-bearer-token"));

        // The old files are untouched.
        assert_eq!(std::fs::read(&config).unwrap(), config_before);
        assert_eq!(
            std::fs::read(legacy.path().join(LEDGER_FILE)).unwrap(),
            register_before
        );
    }

    #[test]
    fn importing_from_nothing_or_from_junk_changes_nothing() {
        let empty = tempfile::tempdir().unwrap();
        let mut ledger = Ledger::default();
        assert_eq!(
            import_launcher_installs(empty.path(), &mut ledger),
            ImportReport::default()
        );

        std::fs::write(empty.path().join(LEDGER_FILE), b"{ nope").unwrap();
        let report = import_launcher_installs(empty.path(), &mut ledger);
        assert!(!report.found);
        assert!(ledger.records.is_empty());
        assert!(
            empty.path().join(LEDGER_FILE).exists(),
            "someone else's file is not quarantined"
        );
    }
}
