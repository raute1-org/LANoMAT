//! The token file on disk: 0600, the directory 0700, an atomic write, and the
//! quarantine of an unreadable file (0600, removed on logout).
//!
//! L9 (security review) raised S4; the tests are its regression lock.

// Some helpers and imports are only used by the `#[cfg(unix)]` tests below: on Windows they would be
// dead code, and `-D warnings` in `ci/build.sh` (cargo xwin clippy) must stay green.
#![cfg_attr(windows, allow(dead_code, unused_imports))]

use std::path::Path;

use launcher_core::state::{
    config_path, delete, load, quarantine_path, save, LauncherConfig, CONFIG_VERSION,
    QUARANTINE_NAME,
};

fn token_config() -> LauncherConfig {
    LauncherConfig::new(
        "https://lan.example".into(),
        "super-secret-token".into(),
        "2030-01-01T00:00:00Z".into(),
    )
}

#[cfg(unix)]
fn mode(path: &Path) -> u32 {
    use std::os::unix::fs::PermissionsExt;

    std::fs::metadata(path).unwrap().permissions().mode() & 0o777
}

#[cfg(unix)]
#[test]
fn a_created_config_dir_is_private_and_the_token_file_is_0600() {
    let parent = tempfile::tempdir().unwrap();
    let dir = parent.path().join("lanomat-launcher");

    save(&dir, &token_config()).unwrap();

    assert_eq!(mode(&dir), 0o700, "the directory the launcher created");
    assert_eq!(mode(&config_path(&dir)), 0o600, "the token file");
}

#[test]
fn saving_leaves_no_temporary_file_behind() {
    let dir = tempfile::tempdir().unwrap();
    save(dir.path(), &token_config()).unwrap();

    let leftovers: Vec<_> = std::fs::read_dir(dir.path())
        .unwrap()
        .filter_map(Result::ok)
        .map(|entry| entry.file_name().to_string_lossy().to_string())
        .filter(|name| name != "config.json")
        .collect();

    assert!(leftovers.is_empty(), "{leftovers:?}");
}

#[test]
fn a_corrupt_file_is_quarantined_once_and_the_good_file_survives() {
    let dir = tempfile::tempdir().unwrap();
    std::fs::write(config_path(dir.path()), b"{ truncated").unwrap();

    let error = load(dir.path()).unwrap_err();
    assert!(error.to_string().contains(QUARANTINE_NAME), "{error}");
    assert!(quarantine_path(dir.path()).is_file());
    assert!(!config_path(dir.path()).exists());

    // A second start is not stuck on the same file.
    assert!(load(dir.path()).unwrap().is_none());

    save(dir.path(), &token_config()).unwrap();
    let loaded = load(dir.path()).unwrap().unwrap();
    assert_eq!(loaded.version, CONFIG_VERSION);
    assert_eq!(loaded.token, "super-secret-token");
}

/// S4: a corrupt `config.json` is moved aside with `rename`, which keeps the
/// *old* mode. A file that was world-readable (copied from another machine,
/// synced, restored from a backup) would leave the token readable at
/// `config.json.corrupt`, so the quarantine path tightens the mode to 0600.
#[cfg(unix)]
#[test]
fn a_quarantined_token_file_is_not_world_readable() {
    use std::os::unix::fs::PermissionsExt;

    let dir = tempfile::tempdir().unwrap();
    std::fs::write(
        config_path(dir.path()),
        b"{ \"token\": \"super-secret-token\" ",
    )
    .unwrap();
    std::fs::set_permissions(
        config_path(dir.path()),
        std::fs::Permissions::from_mode(0o644),
    )
    .unwrap();

    assert!(load(dir.path()).is_err());

    assert_eq!(
        mode(&quarantine_path(dir.path())) & 0o077,
        0,
        "the quarantine file still holds the token"
    );
}

/// S4, second half: `logout` (`delete`) removes `config.json` *and* every
/// quarantined copy, so a token that an earlier start moved to
/// `config.json.corrupt` does not survive the logout.
#[test]
fn logout_removes_the_quarantined_token_too() {
    let dir = tempfile::tempdir().unwrap();

    std::fs::write(
        config_path(dir.path()),
        b"{ \"token\": \"super-secret-token\" ",
    )
    .unwrap();
    assert!(load(dir.path()).is_err());
    assert!(quarantine_path(dir.path()).exists());

    save(dir.path(), &token_config()).unwrap();
    delete(dir.path()).unwrap();

    assert!(!config_path(dir.path()).exists());
    assert!(
        !quarantine_path(dir.path()).exists(),
        "a logged-out launcher must not keep the old token on disk"
    );
}
