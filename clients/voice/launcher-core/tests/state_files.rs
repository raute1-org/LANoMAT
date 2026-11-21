//! Config files on disk: v0 migration, unknown-key preservation, corrupt-file
//! quarantine and the 0600 guarantee.

use std::path::Path;

use launcher_core::state::{
    config_path, delete, load, quarantine_path, save, LauncherConfig, StateError, CONFIG_VERSION,
};
use serde_json::{json, Value};

fn write_config(dir: &Path, raw: &str) {
    std::fs::create_dir_all(dir).unwrap();
    std::fs::write(config_path(dir), raw).unwrap();
}

fn read_json(dir: &Path) -> Value {
    serde_json::from_str(&std::fs::read_to_string(config_path(dir)).unwrap()).unwrap()
}

#[test]
fn a_missing_file_is_not_an_error() {
    let dir = tempfile::tempdir().unwrap();

    assert!(load(dir.path()).unwrap().is_none());
    assert!(delete(dir.path()).is_ok());
}

#[test]
fn a_v0_file_without_version_is_accepted_and_migrated_in_memory() {
    let dir = tempfile::tempdir().unwrap();
    write_config(
        dir.path(),
        r#"{
            "url": "https://lan.test",
            "token": "old-token",
            "token_expires_at": "2030-01-01T00:00:00Z",
            "user": {"id": 7, "name": "Ada", "role": "participant", "is_orga": false},
            "server_name": "LANoMAT",
            "server_version": "1.0.0"
        }"#,
    );

    let loaded = load(dir.path()).unwrap().expect("a v0 file is readable");
    assert_eq!(loaded.version, CONFIG_VERSION);
    assert_eq!(loaded.url, "https://lan.test");
    assert_eq!(loaded.token, "old-token");
    assert_eq!(
        loaded.user.as_ref().map(|user| user.name.as_str()),
        Some("Ada")
    );
    assert_eq!(loaded.server_name.as_deref(), Some("LANoMAT"));
    // The file on disk is untouched until the next save.
    assert_eq!(read_json(dir.path()).get("version"), None);

    save(dir.path(), &loaded).unwrap();
    assert_eq!(read_json(dir.path())["version"], json!(CONFIG_VERSION));
    assert_eq!(read_json(dir.path())["token"], json!("old-token"));
}

#[test]
fn legacy_agent_field_names_are_read_and_written_in_the_launcher_shape() {
    let dir = tempfile::tempdir().unwrap();
    write_config(
        dir.path(),
        r#"{
            "server_url": "https://lan.test/lanomat",
            "access_token": "legacy-token",
            "expires_at": "2030-01-01T00:00:00Z"
        }"#,
    );

    let loaded = load(dir.path()).unwrap().unwrap();
    assert_eq!(loaded.version, CONFIG_VERSION);
    assert_eq!(loaded.url, "https://lan.test/lanomat");
    assert_eq!(loaded.token, "legacy-token");
    assert_eq!(loaded.token_expires_at, "2030-01-01T00:00:00Z");

    save(dir.path(), &loaded).unwrap();
    let saved = read_json(dir.path());
    assert_eq!(saved["url"], json!("https://lan.test/lanomat"));
    assert_eq!(saved["token"], json!("legacy-token"));
    assert_eq!(saved.get("server_url"), None);
}

#[test]
fn unknown_keys_survive_a_save_load_round_trip() {
    let dir = tempfile::tempdir().unwrap();
    write_config(
        dir.path(),
        r#"{
            "version": 1,
            "url": "https://lan.test",
            "token": "t",
            "token_expires_at": "2030-01-01T00:00:00Z",
            "theme": "signalpult",
            "installs": [{"slug": "unreal-tournament-2004", "verified": true}],
            "future": {"nested": {"depth": 3}},
            "null_key": null
        }"#,
    );

    let loaded = load(dir.path()).unwrap().unwrap();
    assert_eq!(loaded.extra.get("theme"), Some(&json!("signalpult")));

    save(dir.path(), &loaded).unwrap();

    let saved = read_json(dir.path());
    assert_eq!(saved["theme"], json!("signalpult"));
    assert_eq!(
        saved["installs"],
        json!([{"slug": "unreal-tournament-2004", "verified": true}])
    );
    assert_eq!(saved["future"], json!({"nested": {"depth": 3}}));
    assert_eq!(saved["null_key"], Value::Null);
    assert_eq!(saved["url"], json!("https://lan.test"));
    assert_eq!(saved["version"], json!(CONFIG_VERSION));

    // No key is written twice: a duplicate `version` would make the file
    // ambiguous for any JSON parser.
    let raw = std::fs::read_to_string(config_path(dir.path())).unwrap();
    for key in ["\"version\"", "\"url\"", "\"token\"", "\"theme\""] {
        assert_eq!(
            raw.matches(key).count(),
            1,
            "{key} appears more than once in:\n{raw}"
        );
    }

    // And a second load is idempotent.
    let again = load(dir.path()).unwrap().unwrap();
    assert_eq!(again.extra.len(), loaded.extra.len());
    assert_eq!(again.token, "t");
}

#[test]
fn a_corrupt_file_is_quarantined_and_the_error_names_it() {
    let dir = tempfile::tempdir().unwrap();
    let broken = "{ this is not json";
    write_config(dir.path(), broken);

    let error = load(dir.path()).unwrap_err();
    let message = error.to_string();

    assert!(matches!(error, StateError::Corrupt { .. }));
    assert!(message.contains("is corrupt"), "{message}");
    assert!(message.contains("config.json.corrupt"), "{message}");

    // The original bytes are preserved for support, and the launcher starts
    // clean next time instead of failing forever.
    let quarantined = quarantine_path(dir.path());
    assert_eq!(std::fs::read_to_string(&quarantined).unwrap(), broken);
    assert!(!config_path(dir.path()).exists());
    assert!(load(dir.path()).unwrap().is_none());
}

#[test]
fn binary_garbage_is_quarantined_too() {
    let dir = tempfile::tempdir().unwrap();
    std::fs::create_dir_all(dir.path()).unwrap();
    std::fs::write(config_path(dir.path()), [0xff, 0xfe, 0x00, 0x7b]).unwrap();

    let error = load(dir.path()).unwrap_err();
    assert!(matches!(error, StateError::Corrupt { .. }));
    assert!(quarantine_path(dir.path()).exists());
    assert!(load(dir.path()).unwrap().is_none());
}

#[test]
fn a_non_object_config_is_corrupt_and_quarantined() {
    let dir = tempfile::tempdir().unwrap();
    write_config(dir.path(), "[]");

    assert!(matches!(load(dir.path()), Err(StateError::Corrupt { .. })));
    assert!(load(dir.path()).unwrap().is_none());
}

#[test]
fn a_newer_version_is_refused_without_quarantine() {
    let dir = tempfile::tempdir().unwrap();
    write_config(
        dir.path(),
        r#"{"version":99,"url":"https://lan.test","token":"t","token_expires_at":"x"}"#,
    );

    match load(dir.path()) {
        Err(StateError::NewerVersion { version, .. }) => assert_eq!(version, 99),
        other => panic!("expected NewerVersion, got {other:?}"),
    }

    // A newer launcher may still be installed: keep its file.
    assert!(config_path(dir.path()).exists());
    assert!(!quarantine_path(dir.path()).exists());
}

#[test]
fn saving_writes_private_permissions_atomically() {
    let root = tempfile::tempdir().unwrap();
    // A directory the launcher creates itself (not an existing one).
    let dir = root.path().join("lanomat-launcher");
    let config = LauncherConfig::new(
        "https://lan.test".into(),
        "tok".into(),
        "2030-01-01T00:00:00Z".into(),
    );

    save(&dir, &config).unwrap();
    save(&dir, &config).unwrap();

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;

        let file_mode = std::fs::metadata(config_path(&dir))
            .unwrap()
            .permissions()
            .mode()
            & 0o777;
        assert_eq!(
            file_mode, 0o600,
            "the token file must not be group/world readable"
        );

        let dir_mode = std::fs::metadata(&dir).unwrap().permissions().mode() & 0o777;
        assert_eq!(
            dir_mode, 0o700,
            "a directory the launcher created is private"
        );

        // No temp file is left behind.
        let leftovers: Vec<_> = std::fs::read_dir(&dir)
            .unwrap()
            .map(|entry| entry.unwrap().file_name().to_string_lossy().to_string())
            .filter(|name| name.ends_with(".tmp"))
            .collect();
        assert!(leftovers.is_empty(), "temp files left: {leftovers:?}");
    }

    let loaded = load(&dir).unwrap().unwrap();
    assert_eq!(loaded.url, "https://lan.test");
    assert_eq!(loaded.token, "tok");
}

#[cfg(unix)]
#[test]
fn saving_does_not_chmod_a_pre_existing_directory() {
    use std::os::unix::fs::PermissionsExt;

    let parent = tempfile::tempdir().unwrap();
    let shared = parent.path().join("user-config");
    std::fs::create_dir_all(&shared).unwrap();
    std::fs::set_permissions(&shared, std::fs::Permissions::from_mode(0o755)).unwrap();

    save(
        &shared,
        &LauncherConfig::new("https://lan.test".into(), "t".into(), String::new()),
    )
    .unwrap();

    let mode = std::fs::metadata(&shared).unwrap().permissions().mode() & 0o777;
    assert_eq!(
        mode, 0o755,
        "a directory the launcher did not create keeps its mode"
    );
}

#[cfg(unix)]
#[test]
fn loading_repairs_a_world_readable_config() {
    use std::os::unix::fs::PermissionsExt;

    let dir = tempfile::tempdir().unwrap();
    write_config(
        dir.path(),
        r#"{"version":1,"url":"https://lan.test","token":"t","token_expires_at":"2030-01-01T00:00:00Z"}"#,
    );
    std::fs::set_permissions(
        config_path(dir.path()),
        std::fs::Permissions::from_mode(0o644),
    )
    .unwrap();

    load(dir.path()).unwrap().unwrap();

    let mode = std::fs::metadata(config_path(dir.path()))
        .unwrap()
        .permissions()
        .mode()
        & 0o777;
    assert_eq!(mode, 0o600);
}

#[test]
fn the_stored_expiry_is_readable_and_checkable() {
    let fresh = LauncherConfig::new(
        "https://lan.test".into(),
        "t".into(),
        "2023-11-14T23:13:20Z".into(),
    );

    assert_eq!(fresh.token_expiry_unix(), Some(1_700_003_600));
    assert!(fresh.token_expired(1_700_003_600));
    assert!(fresh.token_expired(1_800_000_000));
    assert!(!fresh.token_expired(1_700_003_599));

    // A legacy file without an expiry is not treated as expired: the server's
    // 401 is the authority.
    let legacy = LauncherConfig::new("https://lan.test".into(), "t".into(), String::new());
    assert_eq!(legacy.token_expiry_unix(), None);
    assert!(!legacy.token_expired(u64::MAX));
}

#[test]
fn delete_removes_the_token_file() {
    let dir = tempfile::tempdir().unwrap();
    save(
        dir.path(),
        &LauncherConfig::new("https://lan.test".into(), "t".into(), String::new()),
    )
    .unwrap();

    delete(dir.path()).unwrap();

    assert!(!config_path(dir.path()).exists());
    assert!(load(dir.path()).unwrap().is_none());
}

#[test]
fn the_token_is_redacted_in_debug_output() {
    let config = LauncherConfig::new(
        "https://lan.test".into(),
        "super-secret-token".into(),
        "2030-01-01T00:00:00Z".into(),
    );
    let debug = format!("{config:?}");

    assert!(!debug.contains("super-secret-token"));
    assert!(debug.contains("<redacted>"));
    assert!(debug.contains("https://lan.test"));
}
