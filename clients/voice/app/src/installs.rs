//! The local install register of the app: which games are on this machine and in which state.
//!
//! A thin owner around `launcher_core::state` (the model, the status automaton, the atomic 0600
//! file, the quarantine of an unreadable file and the import of the old launcher's register). It
//! lives in the app's config directory, next to `credentials.json` and `settings.json`
//! (`<config dir>/lanomat-voice/installs.json`). Port of `clients/launcher/app/src/installs.rs`,
//! which was a bare list; this one has states and survives a corrupt file.

use std::path::{Path, PathBuf};
use std::sync::{Mutex, MutexGuard};

use launcher_core::state::{
    self, ImportReport, InstallRecord, InstallStatus, Ledger, LedgerChangeError, StateError,
};
use serde::Serialize;

/// What the UI needs to know about one game on this machine.
#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct LocalView {
    pub status: &'static str,
    pub path: Option<String>,
    /// RFC 3339.
    pub last_played_at: Option<String>,
}

impl LocalView {
    pub fn not_installed() -> Self {
        Self {
            status: InstallStatus::NotInstalled.as_str(),
            path: None,
            last_played_at: None,
        }
    }
}

#[derive(Debug)]
pub enum InstallsError {
    Change(LedgerChangeError),
    Save(StateError),
    /// The register was written by a newer app: this one reads nothing from it and writes nothing.
    ReadOnly,
}

impl std::fmt::Display for InstallsError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Change(e) => write!(f, "{e}"),
            Self::Save(e) => write!(f, "{e}"),
            Self::ReadOnly => write!(f, "the install register is from a newer version"),
        }
    }
}

impl std::error::Error for InstallsError {}

pub struct Installs {
    dir: PathBuf,
    ledger: Mutex<Ledger>,
    writable: bool,
    /// Where an unreadable register was moved to when the app opened it.
    pub quarantined: Option<PathBuf>,
    /// What the one-time import of the old launcher's register did.
    pub imported: ImportReport,
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

impl Installs {
    /// Opens the register in `dir`. Never fails: an unreadable file is quarantined, a register
    /// from a newer app is kept read-only and empty. On the very first start (no register yet)
    /// the install records of the old launcher in `legacy_launcher_dir` are imported once;
    /// installs are only ever imported, never the launcher's token. Interrupted installs become
    /// `repair_needed` and every record is checked against the disk.
    pub fn open(dir: &Path, legacy_launcher_dir: Option<&Path>) -> Self {
        let (mut ledger, existed, quarantined, writable) = match state::load_ledger(dir) {
            Ok(loaded) => (loaded.ledger, loaded.existed, loaded.quarantined, true),
            Err(_) => (Ledger::default(), true, None, false),
        };

        let mut imported = ImportReport::default();
        if !existed && writable {
            if let Some(legacy) = legacy_launcher_dir {
                imported = state::import_launcher_installs(legacy, &mut ledger);
            }
        }

        let changed = ledger.recover_interrupted() + ledger.reconcile();
        let me = Self {
            dir: dir.to_path_buf(),
            ledger: Mutex::new(ledger),
            writable,
            quarantined,
            imported,
        };
        if writable && (changed > 0 || me.imported.imported > 0 || !existed) {
            let _ = state::save_ledger(&me.dir, &lock(&me.ledger));
        }

        me
    }

    pub fn dir(&self) -> &Path {
        &self.dir
    }

    /// The state of one game; no record means `not_installed`. Re-checks the disk first, so a
    /// drive that came back or a deleted folder shows up without a restart (in memory only).
    pub fn local(&self, slug: &str) -> LocalView {
        let mut ledger = lock(&self.ledger);
        ledger.reconcile();

        ledger
            .find(slug)
            .map_or_else(LocalView::not_installed, |r| LocalView {
                status: r.status.as_str(),
                path: Some(r.path.clone()),
                last_played_at: r.last_played_at.clone(),
            })
    }

    pub fn record(&self, slug: &str) -> Option<InstallRecord> {
        lock(&self.ledger).find(slug).cloned()
    }

    pub fn records(&self) -> Vec<InstallRecord> {
        lock(&self.ledger).records.clone()
    }

    /// Starts (or restarts) an install: `not_installed`, `ready`, `repair_needed`, `broken` or
    /// `offline` become `installing`.
    pub fn begin_install(
        &self,
        slug: &str,
        path: &str,
        plan_hash: Option<String>,
        launch_command: Option<String>,
        version: Option<String>,
    ) -> Result<(), InstallsError> {
        let mut record = InstallRecord::new(slug, path, InstallStatus::Installing);
        record.plan_hash = plan_hash;
        record.launch_command = launch_command;
        record.version = version;

        self.commit(|ledger| {
            if let Some(old) = ledger.find(slug) {
                record.last_played_at = old.last_played_at.clone();
            }
            ledger.upsert(record)
        })
    }

    /// Moves a game along the status automaton.
    pub fn set_status(&self, slug: &str, status: InstallStatus) -> Result<(), InstallsError> {
        self.commit(|ledger| ledger.set_status(slug, status))
    }

    /// Records a start from the app (`now`: RFC 3339).
    pub fn mark_played(&self, slug: &str, now: &str) -> Result<(), InstallsError> {
        self.commit(|ledger| ledger.mark_played(slug, now))
    }

    /// Forgets a game; the files are not touched.
    pub fn forget(&self, slug: &str) -> Result<bool, InstallsError> {
        let mut removed = false;
        self.commit(|ledger| {
            removed = ledger.remove(slug);
            Ok(())
        })?;

        Ok(removed)
    }

    fn commit(
        &self,
        change: impl FnOnce(&mut Ledger) -> Result<(), LedgerChangeError>,
    ) -> Result<(), InstallsError> {
        if !self.writable {
            return Err(InstallsError::ReadOnly);
        }
        let mut ledger = lock(&self.ledger);
        let mut next = ledger.clone();
        change(&mut next).map_err(InstallsError::Change)?;
        state::save_ledger(&self.dir, &next).map_err(InstallsError::Save)?;
        *ledger = next;

        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_game_walks_from_nothing_to_ready_and_survives_a_restart() {
        let root = tempfile::tempdir().unwrap();
        let cfg = root.path().join("cfg");
        let game = root.path().join("games").join("ut");
        std::fs::create_dir_all(&game).unwrap();
        let game = game.to_str().unwrap();

        let installs = Installs::open(&cfg, None);
        assert_eq!(installs.local("ut-test"), LocalView::not_installed());

        installs
            .begin_install("ut-test", game, Some("hash".into()), None, Some("1".into()))
            .unwrap();
        assert_eq!(installs.local("ut-test").status, "installing");
        installs
            .set_status("ut-test", InstallStatus::Ready)
            .unwrap();
        installs
            .mark_played("ut-test", "2026-11-22T12:00:00Z")
            .unwrap();

        let again = Installs::open(&cfg, None);
        let local = again.local("ut-test");
        assert_eq!(local.status, "ready");
        assert_eq!(local.path.as_deref(), Some(game));
        assert_eq!(
            local.last_played_at.as_deref(),
            Some("2026-11-22T12:00:00Z")
        );
        assert_eq!(
            again.record("ut-test").unwrap().plan_hash.as_deref(),
            Some("hash")
        );
    }

    #[test]
    fn a_restart_during_an_install_leaves_a_repairable_game() {
        let root = tempfile::tempdir().unwrap();
        let cfg = root.path().join("cfg");
        let game = root.path().join("half");
        std::fs::create_dir_all(&game).unwrap();

        let installs = Installs::open(&cfg, None);
        installs
            .begin_install("half-way", game.to_str().unwrap(), None, None, None)
            .unwrap();
        drop(installs);

        assert_eq!(
            Installs::open(&cfg, None).local("half-way").status,
            "repair_needed"
        );
    }

    #[test]
    fn illegal_moves_and_hostile_slugs_change_nothing_on_disk() {
        let root = tempfile::tempdir().unwrap();
        let cfg = root.path().join("cfg");
        let installs = Installs::open(&cfg, None);
        installs.begin_install("g", "/p", None, None, None).unwrap();
        let before = std::fs::read(state::ledger_path(&cfg)).unwrap();

        assert!(matches!(
            installs.set_status("g", InstallStatus::Offline),
            Err(InstallsError::Change(_))
        ));
        assert!(installs
            .begin_install("../x", "/p", None, None, None)
            .is_err());
        assert_eq!(std::fs::read(state::ledger_path(&cfg)).unwrap(), before);
        assert_eq!(installs.local("g").status, "installing");
    }

    #[test]
    fn a_corrupt_register_is_quarantined_and_the_app_starts_with_an_empty_library() {
        let cfg = tempfile::tempdir().unwrap();
        std::fs::write(state::ledger_path(cfg.path()), b"\x00garbage").unwrap();

        let installs = Installs::open(cfg.path(), None);
        assert_eq!(
            installs.quarantined.as_deref(),
            Some(state::ledger_quarantine_path(cfg.path()).as_path())
        );
        assert!(installs.records().is_empty());
        installs.begin_install("g", "/p", None, None, None).unwrap();
        assert!(state::ledger_quarantine_path(cfg.path()).exists());
    }

    #[test]
    fn a_register_from_a_newer_app_is_read_only_and_untouched() {
        let cfg = tempfile::tempdir().unwrap();
        let raw = br#"{"version":99,"records":[]}"#;
        std::fs::write(state::ledger_path(cfg.path()), raw).unwrap();

        let installs = Installs::open(cfg.path(), None);
        assert!(matches!(
            installs.begin_install("g", "/p", None, None, None),
            Err(InstallsError::ReadOnly)
        ));
        assert_eq!(std::fs::read(state::ledger_path(cfg.path())).unwrap(), raw);
    }

    #[test]
    fn the_launcher_register_is_imported_once_and_only_on_a_first_start() {
        let root = tempfile::tempdir().unwrap();
        let legacy = root.path().join("legacy");
        let cfg = root.path().join("cfg");
        let game = root.path().join("ut2004");
        std::fs::create_dir_all(&game).unwrap();
        std::fs::create_dir_all(&legacy).unwrap();
        std::fs::write(
            legacy.join("installs.json"),
            serde_json::json!([{"slug": "unreal-tournament-2004",
                "target_dir": game.to_str().unwrap(), "version": "3374"}])
            .to_string(),
        )
        .unwrap();
        std::fs::write(
            legacy.join("config.json"),
            r#"{"token":"old-launcher-token"}"#,
        )
        .unwrap();

        let installs = Installs::open(&cfg, Some(&legacy));
        assert_eq!(installs.imported.imported, 1);
        assert_eq!(installs.local("unreal-tournament-2004").status, "ready");

        // The game was forgotten on purpose: a restart must not bring it back.
        installs.forget("unreal-tournament-2004").unwrap();
        let again = Installs::open(&cfg, Some(&legacy));
        assert_eq!(again.imported, ImportReport::default());
        assert_eq!(
            again.local("unreal-tournament-2004").status,
            "not_installed"
        );

        for entry in std::fs::read_dir(&cfg).unwrap() {
            let text = std::fs::read_to_string(entry.unwrap().path()).unwrap();
            assert!(!text.contains("old-launcher-token"));
        }
    }

    #[cfg(unix)]
    #[test]
    fn the_state_directory_and_file_are_private() {
        use std::os::unix::fs::PermissionsExt;
        let root = tempfile::tempdir().unwrap();
        let cfg = root.path().join("lanomat-voice");
        let _ = Installs::open(&cfg, None);
        let mode = |p: &Path| std::fs::metadata(p).unwrap().permissions().mode() & 0o777;
        assert_eq!(mode(&cfg), 0o700);
        assert_eq!(mode(&state::ledger_path(&cfg)), 0o600);
    }
}
