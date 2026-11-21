//! macOS install layout, macOS launch and the DMG fallback.
//!
//! The bundle layout is checked without running macOS: verification is a pure
//! file check. `launch_for(.., Os::Macos)` is exercised with a fake `open`
//! command so both the LaunchServices path and the direct-binary fallback run
//! on Linux CI.

use std::path::{Path, PathBuf};
use std::sync::Mutex;

use launcher_core::archive::{detect_dmg, extract_dmg, ArchiveError};
use launcher_core::install::{
    required_file_groups, required_files, verify_install_for, InstallEngine,
};
use launcher_core::types::Os;

/// Launch tests mutate the process `PATH`; keep them from overlapping.
static ENV_LOCK: Mutex<()> = Mutex::new(());

fn write(root: &Path, files: &[&str]) {
    for file in files {
        let path = root.join(file);
        std::fs::create_dir_all(path.parent().unwrap()).unwrap();
        std::fs::write(path, b"data").unwrap();
    }
}

#[cfg(unix)]
fn make_executable(path: &Path) {
    use std::os::unix::fs::PermissionsExt;

    let mut permissions = std::fs::metadata(path).unwrap().permissions();
    permissions.set_mode(0o755);
    std::fs::set_permissions(path, permissions).unwrap();
}

fn fake_open(directory: &Path, body: &str) -> PathBuf {
    let path = directory.join("open");
    std::fs::write(&path, body).unwrap();
    #[cfg(unix)]
    make_executable(&path);
    path
}

fn wait_for(path: &Path) -> bool {
    for _ in 0..100 {
        if path.is_file() {
            return true;
        }
        std::thread::sleep(std::time::Duration::from_millis(20));
    }

    path.is_file()
}

#[test]
fn macos_verification_accepts_the_app_bundle_layout() {
    let mac = tempfile::tempdir().unwrap();
    write(
        mac.path(),
        &[
            "UT2004.app/Contents/MacOS/UT2004",
            "UT2004.app/Contents/Resources/System/Packages.md5",
            "Maps/DM-Rankin.ut2",
            "Textures/Arborea.utx",
        ],
    );

    let verified = verify_install_for(mac.path(), Os::Macos).unwrap();
    assert!(
        verified
            .iter()
            .any(|file| file == "UT2004.app/Contents/MacOS/UT2004"),
        "the bundle executable must satisfy verification: {verified:?}"
    );
    assert!(
        verified
            .iter()
            .any(|file| file == "UT2004.app/Contents/Resources/System/Packages.md5"),
        "the bundle's system data must satisfy verification: {verified:?}"
    );

    // The same tree is not a Linux install: macOS has its own branch now.
    assert!(
        verify_install_for(mac.path(), Os::Linux).is_err(),
        "a macOS bundle must not verify as Linux"
    );
}

#[test]
fn macos_verification_rejects_a_flat_linux_layout() {
    let linux = tempfile::tempdir().unwrap();
    write(
        linux.path(),
        &[
            "System/ut2004-bin-amd64",
            "System/Build.ini",
            "Maps/DM-Rankin.ut2",
        ],
    );

    assert!(verify_install_for(linux.path(), Os::Linux).is_ok());
    let error = verify_install_for(linux.path(), Os::Macos).unwrap_err();
    assert!(
        error
            .to_string()
            .contains("UT2004.app/Contents/MacOS/UT2004"),
        "the mac branch must name its own executable: {error}"
    );
}

#[test]
fn macos_required_files_are_not_the_linux_ones() {
    let mac = required_files(Os::Macos);
    assert!(mac.contains(&"UT2004.app/Contents/MacOS/UT2004"));
    assert!(mac.contains(&"UT2004.app/Contents/Resources/System/Packages.md5"));
    assert!(!mac.contains(&"System/ut2004-bin-amd64"));
    assert!(!mac.contains(&"System/UT2004"));

    let groups = required_file_groups(Os::Macos);
    assert_eq!(groups[0], &["UT2004.app/Contents/MacOS/UT2004"][..]);
    assert!(groups.iter().any(|group| group
        .iter()
        .any(|file| *file == "UT2004.app/Contents/Resources/System/Packages.md5")));

    // Linux and Windows keep their explicit branches.
    assert_eq!(
        required_file_groups(Os::Linux)[0],
        &["System/ut2004-bin-amd64", "System/UT2004"][..]
    );
    assert_eq!(
        required_file_groups(Os::Windows)[0],
        &["System/UT2004.exe"][..]
    );
}

#[cfg(unix)]
#[test]
fn macos_launch_opens_the_app_bundle() {
    let _guard = ENV_LOCK.lock().unwrap();
    let dir = tempfile::tempdir().unwrap();
    let target = dir.path().join("game");
    write(&target, &["UT2004.app/Contents/MacOS/UT2004"]);
    make_executable(&target.join("UT2004.app/Contents/MacOS/UT2004"));

    let tools = dir.path().join("tools");
    std::fs::create_dir_all(&tools).unwrap();
    fake_open(
        &tools,
        "#!/bin/sh\nprintf '%s' \"$1\" > \"$FAKE_OPEN_LOG\"\n",
    );
    let log = dir.path().join("open.log");

    let old_path = std::env::var_os("PATH");
    std::env::set_var("PATH", &tools);
    std::env::set_var("FAKE_OPEN_LOG", &log);

    let launched = InstallEngine::launch_for(&target, Os::Macos);

    if let Some(old_path) = old_path {
        std::env::set_var("PATH", old_path);
    }
    std::env::remove_var("FAKE_OPEN_LOG");

    let launched = launched.unwrap();
    assert_eq!(launched, target.join("UT2004.app"));
    assert_eq!(
        std::fs::read_to_string(&log).unwrap(),
        launched.to_string_lossy(),
        "LaunchServices must receive the app bundle, not the ELF"
    );
}

#[cfg(unix)]
#[test]
fn macos_launch_falls_back_to_the_bundle_executable() {
    let _guard = ENV_LOCK.lock().unwrap();
    let dir = tempfile::tempdir().unwrap();
    let target = dir.path().join("game");
    let binary = target.join("UT2004.app/Contents/MacOS/UT2004");
    write(&target, &["UT2004.app/Contents/MacOS/UT2004"]);
    let marker = target.join("UT2004.app/Contents/MacOS/launched");
    std::fs::write(
        &binary,
        format!("#!/bin/sh\nprintf launched > \"{}\"\n", marker.display()),
    )
    .unwrap();
    make_executable(&binary);

    let tools = dir.path().join("tools");
    std::fs::create_dir_all(&tools).unwrap();
    fake_open(&tools, "#!/bin/sh\nexit 1\n");

    let old_path = std::env::var_os("PATH");
    std::env::set_var("PATH", &tools);

    let launched = InstallEngine::launch_for(&target, Os::Macos);

    if let Some(old_path) = old_path {
        std::env::set_var("PATH", old_path);
    }

    assert_eq!(launched.unwrap(), binary);
    assert!(
        wait_for(&target.join("UT2004.app/Contents/MacOS/launched")),
        "the bundle executable must start when open fails"
    );
}

#[test]
fn macos_launch_reports_a_missing_bundle() {
    let dir = tempfile::tempdir().unwrap();
    let error = InstallEngine::launch_for(dir.path(), Os::Macos).unwrap_err();

    assert!(
        error.to_string().contains("UT2004.app"),
        "the error must name the missing bundle: {error}"
    );
}

#[test]
fn dmg_detection_uses_the_extension_and_the_udif_trailer() {
    let dir = tempfile::tempdir().unwrap();
    let named = dir.path().join("ut2004.dmg");
    std::fs::write(&named, b"not really a disk image").unwrap();
    assert!(detect_dmg(&named));

    // No extension, but the UDIF `koly` trailer of a real image.
    let renamed = dir.path().join("ut2004-image");
    let mut body = vec![0u8; 512];
    body[..4].copy_from_slice(b"koly");
    std::fs::write(&renamed, body).unwrap();
    assert!(detect_dmg(&renamed));

    let plain = dir.path().join("plain.zip");
    std::fs::write(&plain, b"PK\x03\x04").unwrap();
    assert!(!detect_dmg(&plain));
}

#[cfg(not(target_os = "macos"))]
#[test]
fn dmg_extraction_is_a_clear_platform_error_off_macos() {
    let dir = tempfile::tempdir().unwrap();
    let dmg = dir.path().join("ut2004.dmg");
    std::fs::write(&dmg, b"not really a disk image").unwrap();

    let error = extract_dmg(&dmg, &dir.path().join("game")).unwrap_err();
    assert!(matches!(error, ArchiveError::DmgUnsupported));
    assert!(
        error.to_string().contains("macOS"),
        "the error must say why: {error}"
    );
}
