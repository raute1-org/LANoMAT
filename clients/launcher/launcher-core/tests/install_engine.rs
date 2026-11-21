//! Install-engine behaviour that does not need the network: verification,
//! executable bits, launch, missing-file selection and the patch requirement.

use std::path::PathBuf;
use std::sync::Arc;

use launcher_core::download::CancelToken;
use launcher_core::install::{
    base_file, missing_files, required_file_groups, required_files, verify_install,
    verify_install_for, InstallEngine, InstallError, InstallOptions,
};
use launcher_core::types::{Os, RecipePlan};

fn plan(files: serde_json::Value) -> RecipePlan {
    serde_json::from_value(serde_json::json!({
        "recipe": {"slug": "unreal-tournament-2004", "title": "Unreal Tournament 2004"},
        "target_dir": "~/Games/ut2004",
        "files": files,
    }))
    .unwrap()
}

fn options(plan: RecipePlan) -> InstallOptions {
    InstallOptions {
        plan,
        bearer: None,
        bearer_origin: None,
        target_dir: None,
        cancel: CancelToken::new(),
        on_progress: Arc::new(|_| {}),
    }
}

#[test]
fn required_files_cover_both_platforms() {
    assert_eq!(
        required_files(Os::Linux),
        &[
            "System/Build.ini",
            "System/ut2004-bin-amd64",
            "System/UT2004"
        ]
    );
    assert_eq!(
        required_files(Os::Windows),
        &[
            "System/UT2004.exe",
            "System/Build.ini",
            "System/D3D11Drv.dll"
        ]
    );
}

#[test]
fn linux_verification_accepts_either_the_symlink_or_the_elf() {
    assert_eq!(
        required_file_groups(Os::Linux),
        &[
            &["System/ut2004-bin-amd64", "System/UT2004"][..],
            &["System/Build.ini"][..]
        ]
    );

    let symlink = tempfile::tempdir().unwrap();
    write(
        symlink.path(),
        &["System/ut2004-bin-amd64", "System/Build.ini"],
    );
    assert_eq!(
        verify_install_for(symlink.path(), Os::Linux).unwrap(),
        vec!["System/ut2004-bin-amd64", "System/Build.ini"]
    );

    let elf = tempfile::tempdir().unwrap();
    write(elf.path(), &["System/UT2004", "System/Build.ini"]);
    assert_eq!(
        verify_install_for(elf.path(), Os::Linux).unwrap(),
        vec!["System/UT2004", "System/Build.ini"],
        "the real OldUnreal binary must verify without the symlink"
    );

    let neither = tempfile::tempdir().unwrap();
    write(neither.path(), &["System/Build.ini"]);
    let error = verify_install_for(neither.path(), Os::Linux).unwrap_err();
    assert!(
        error.to_string().contains("ut2004-bin-amd64")
            && error.to_string().contains("System/UT2004"),
        "unexpected error: {error}"
    );
}

fn write(root: &std::path::Path, files: &[&str]) {
    for file in files {
        let path = root.join(file);
        std::fs::create_dir_all(path.parent().unwrap()).unwrap();
        std::fs::write(path, b"data").unwrap();
    }
}

#[test]
fn verification_accepts_linux_and_windows_layouts() {
    let linux = tempfile::tempdir().unwrap();
    write(
        linux.path(),
        &[
            "System/ut2004-bin-amd64",
            "System/Build.ini",
            "Maps/DM-Rankin.ut2",
        ],
    );
    assert_eq!(
        verify_install_for(linux.path(), Os::Linux).unwrap(),
        vec!["System/ut2004-bin-amd64", "System/Build.ini"]
    );

    let windows = tempfile::tempdir().unwrap();
    write(
        windows.path(),
        &[
            "System/UT2004.exe",
            "System/Build.ini",
            "System/D3D11Drv.dll",
        ],
    );
    assert_eq!(
        verify_install_for(windows.path(), Os::Windows).unwrap(),
        vec![
            "System/UT2004.exe",
            "System/Build.ini",
            "System/D3D11Drv.dll"
        ]
    );
}

#[test]
fn verification_reports_the_first_missing_file() {
    let dir = tempfile::tempdir().unwrap();
    write(dir.path(), &["System/UT2004.exe", "System/Build.ini"]);

    let error = verify_install_for(dir.path(), Os::Windows).unwrap_err();
    assert!(
        error.to_string().contains("System/D3D11Drv.dll"),
        "unexpected error: {error}"
    );
}

#[cfg(unix)]
#[test]
fn verification_checks_files_without_executing_them() {
    let dir = tempfile::tempdir().unwrap();
    let system = dir.path().join("System");
    std::fs::create_dir_all(&system).unwrap();
    let exe = system.join("ut2004-bin-amd64");
    std::fs::write(&exe, "#!/bin/sh\ntouch \"$(dirname \"$0\")/ran\"\n").unwrap();
    std::fs::write(system.join("Build.ini"), b"Label=v3374\n").unwrap();

    verify_install(dir.path()).unwrap();
    std::thread::sleep(std::time::Duration::from_millis(100));
    assert!(
        !system.join("ran").exists(),
        "verification must not run the game"
    );
}

#[cfg(unix)]
#[test]
fn launch_starts_the_flat_game_from_system() {
    use std::os::unix::fs::PermissionsExt;

    let dir = tempfile::tempdir().unwrap();
    let system = dir.path().join("System");
    std::fs::create_dir_all(&system).unwrap();
    let exe = system.join("ut2004-bin-amd64");
    std::fs::write(&exe, "#!/bin/sh\ntouch \"$(dirname \"$0\")/launched\"\n").unwrap();
    let mut permissions = std::fs::metadata(&exe).unwrap().permissions();
    permissions.set_mode(0o755);
    std::fs::set_permissions(&exe, permissions).unwrap();

    let launched = InstallEngine::launch(dir.path()).unwrap();
    assert_eq!(launched, exe);

    let marker = system.join("launched");
    for _ in 0..100 {
        if marker.is_file() {
            break;
        }
        std::thread::sleep(std::time::Duration::from_millis(20));
    }
    assert!(marker.is_file(), "the game process did not start");
}

#[test]
fn launch_reports_a_missing_executable() {
    let dir = tempfile::tempdir().unwrap();
    std::fs::create_dir_all(dir.path().join("System")).unwrap();

    let error = InstallEngine::launch(dir.path()).unwrap_err();
    assert!(
        error.to_string().contains("ut2004-bin-amd64"),
        "unexpected error: {error}"
    );
}

#[tokio::test]
async fn install_reports_both_missing_labels_without_network() {
    let plan = plan(serde_json::json!([
        {"requirement": "iso", "status": "missing", "required": true, "label": "UT2004 DVD"},
        {"requirement": "patch", "status": "missing", "required": true, "label": "OldUnreal Patch"}
    ]));

    let error = InstallEngine::install(options(plan)).await.unwrap_err();
    match error {
        InstallError::MissingFiles(labels) => {
            assert!(labels.contains("UT2004 DVD"), "{labels}");
            assert!(labels.contains("OldUnreal Patch"), "{labels}");
        }
        other => panic!("expected MissingFiles, got {other}"),
    }
}

#[tokio::test]
async fn a_plan_without_a_base_file_is_rejected() {
    let plan = plan(serde_json::json!([
        {"requirement": "mod", "status": "matched", "download_url": "https://lan/mod.zip", "name": "mod.zip"}
    ]));

    assert!(matches!(
        InstallEngine::install(options(plan)).await,
        Err(InstallError::NoBaseFile)
    ));
}

#[tokio::test]
async fn a_declared_patch_without_a_url_fails_the_iso_install() {
    let plan = plan(serde_json::json!([
        {"requirement": "iso", "status": "matched", "download_url": "http://127.0.0.1:9/dvd.iso", "name": "dvd.iso"},
        {"requirement": "patch", "status": "matched", "required": true, "label": "OldUnreal Patch"}
    ]));

    let error = InstallEngine::install(options(plan)).await.unwrap_err();
    assert!(
        error.to_string().contains("OldUnreal Patch"),
        "unexpected error: {error}"
    );
}

#[tokio::test]
async fn a_portable_archive_does_not_need_the_patch() {
    let plan = plan(serde_json::json!([
        {"requirement": "archive", "status": "matched", "download_url": "http://127.0.0.1:9/portable.zip", "name": "portable.zip"},
        {"requirement": "patch", "status": "missing", "required": true, "label": "OldUnreal Patch"}
    ]));

    // The plan is accepted; the failure is the unreachable archive, not the patch.
    let error = InstallEngine::install(options(plan)).await.unwrap_err();
    assert!(
        matches!(error, InstallError::Download(_)),
        "unexpected error: {error}"
    );
}

#[test]
fn one_present_base_satisfies_the_missing_iso_label() {
    let both = plan(serde_json::json!([
        {"requirement": "iso", "status": "missing", "required": true, "label": "UT2004 DVD"},
        {"requirement": "patch", "status": "missing", "required": true, "label": "OldUnreal Patch"}
    ]));
    assert_eq!(missing_files(&both), vec!["UT2004 DVD", "OldUnreal Patch"]);
    assert!(base_file(&both).is_none());

    let archive_only = plan(serde_json::json!([
        {"requirement": "iso", "status": "missing", "required": true, "label": "UT2004 DVD"},
        {"requirement": "archive", "status": "matched", "download_url": "https://lan/portable.zip", "name": "portable.zip"},
        {"requirement": "patch", "status": "matched", "download_url": "https://lan/patch.zip", "name": "patch.zip"}
    ]));
    assert!(missing_files(&archive_only).is_empty());
    assert_eq!(base_file(&archive_only).unwrap().requirement, "archive");

    let iso_only = plan(serde_json::json!([
        {"requirement": "iso", "status": "matched", "download_url": "https://lan/dvd.iso", "name": "dvd.iso"},
        {"requirement": "archive", "status": "missing", "required": false, "label": "Portable"},
        {"requirement": "patch", "status": "matched", "download_url": "https://lan/patch.zip", "name": "patch.zip"}
    ]));
    assert!(missing_files(&iso_only).is_empty());
    assert_eq!(base_file(&iso_only).unwrap().requirement, "iso");
}

#[test]
fn an_install_directory_can_be_a_plain_path_override() {
    let options = InstallOptions {
        plan: plan(serde_json::json!([])),
        bearer: None,
        bearer_origin: None,
        target_dir: Some(PathBuf::from("/tmp/opencode/ut2004")),
        cancel: CancelToken::new(),
        on_progress: Arc::new(|_| {}),
    };

    assert_eq!(
        options.target_dir,
        Some(PathBuf::from("/tmp/opencode/ut2004"))
    );
}
