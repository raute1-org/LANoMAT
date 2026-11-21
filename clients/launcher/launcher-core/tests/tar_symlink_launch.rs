//! The real OldUnreal Linux patch stores `System/UT2004` (ELF, 0755) and
//! `System/ut2004-bin-amd64 -> UT2004` (relative symlink). The tar extractor
//! must materialise the link without ever following one out of the install,
//! verification must accept either spelling, and the target must keep its
//! executable bit.

#![cfg(unix)]

use std::path::{Path, PathBuf};
use std::sync::Arc;

use launcher_core::archive::{extract_tar_bz2, ArchiveError};
use launcher_core::download::CancelToken;
use launcher_core::install::{
    verify_install_for, InstallEngine, InstallOptions, InstallPhase, InstallProgress,
};
use launcher_core::types::{Os, RecipePlan};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

fn tar_file(
    builder: &mut tar::Builder<bzip2::write::BzEncoder<Vec<u8>>>,
    name: &str,
    body: &[u8],
    mode: u32,
) {
    let mut header = tar::Header::new_gnu();
    header.set_size(body.len() as u64);
    header.set_mode(mode);
    header.set_cksum();
    builder.append_data(&mut header, name, body).unwrap();
}

fn tar_symlink(
    builder: &mut tar::Builder<bzip2::write::BzEncoder<Vec<u8>>>,
    name: &str,
    target: &str,
) {
    let mut header = tar::Header::new_gnu();
    header.set_entry_type(tar::EntryType::Symlink);
    header.set_size(0);
    header.set_mode(0o777);
    header.set_cksum();
    builder.append_link(&mut header, name, target).unwrap();
}

fn tar_bz2_bytes(
    build: impl FnOnce(&mut tar::Builder<bzip2::write::BzEncoder<Vec<u8>>>),
) -> Vec<u8> {
    let encoder = bzip2::write::BzEncoder::new(Vec::new(), bzip2::Compression::best());
    let mut builder = tar::Builder::new(encoder);
    build(&mut builder);
    builder.into_inner().unwrap().finish().unwrap()
}

fn write_tar_bz2(
    path: &Path,
    build: impl FnOnce(&mut tar::Builder<bzip2::write::BzEncoder<Vec<u8>>>),
) -> PathBuf {
    let bytes = tar_bz2_bytes(build);
    std::fs::write(path, bytes).unwrap();
    path.to_path_buf()
}

fn linux_tar() -> Vec<u8> {
    tar_bz2_bytes(|builder| {
        // The real archive stores the links before the ELF they point at.
        tar_symlink(builder, "System/ut2004-bin-amd64", "UT2004");
        tar_symlink(builder, "System/ut2004-bin", "UT2004");
        tar_file(
            builder,
            "System/UT2004",
            b"#!/bin/sh\ntouch \"$(dirname \"$0\")/launched\"\n",
            0o755,
        );
        tar_file(builder, "System/Build.ini", b"Label=v3374\n", 0o644);
    })
}

#[test]
fn extraction_materialises_the_relative_symlink_and_keeps_the_executable_bit() {
    let dir = tempfile::tempdir().unwrap();
    let archive = write_tar_bz2(&dir.path().join("patch.tar.bz2"), |builder| {
        tar_symlink(builder, "System/ut2004-bin-amd64", "UT2004");
        tar_file(builder, "System/UT2004", b"#!/bin/sh\nexit 0\n", 0o755);
        tar_file(builder, "System/Build.ini", b"Label=v3374\n", 0o644);
    });
    let target = dir.path().join("game");

    assert_eq!(extract_tar_bz2(&archive, &target).unwrap(), 3);

    let link = target.join("System/ut2004-bin-amd64");
    let metadata = std::fs::symlink_metadata(&link).unwrap();
    assert!(
        metadata.file_type().is_symlink(),
        "the tar symlink must be materialised, not skipped"
    );
    assert_eq!(
        std::fs::read_link(&link).unwrap(),
        Path::new("UT2004"),
        "the link stays relative so the install folder can move"
    );

    use std::os::unix::fs::PermissionsExt;
    let mode = std::fs::metadata(target.join("System/UT2004"))
        .unwrap()
        .permissions()
        .mode();
    assert_ne!(
        mode & 0o111,
        0,
        "the ELF must be executable after extraction"
    );

    assert_eq!(
        verify_install_for(&target, Os::Linux).unwrap(),
        vec!["System/ut2004-bin-amd64", "System/Build.ini"]
    );
}

#[test]
fn launch_accepts_the_symlink_and_falls_back_to_the_elf() {
    let dir = tempfile::tempdir().unwrap();
    let archive = write_tar_bz2(&dir.path().join("patch.tar.bz2"), |builder| {
        tar_symlink(builder, "System/ut2004-bin-amd64", "UT2004");
        tar_file(
            builder,
            "System/UT2004",
            b"#!/bin/sh\ntouch \"$(dirname \"$0\")/launched\"\n",
            0o755,
        );
        tar_file(builder, "System/Build.ini", b"Label=v3374\n", 0o644);
    });
    let target = dir.path().join("game");
    extract_tar_bz2(&archive, &target).unwrap();

    let launched = InstallEngine::launch(&target).unwrap();
    assert_eq!(launched, target.join("System/ut2004-bin-amd64"));
    let marker = target.join("System/launched");
    for _ in 0..100 {
        if marker.is_file() {
            break;
        }
        std::thread::sleep(std::time::Duration::from_millis(20));
    }
    assert!(marker.is_file(), "the symlinked game did not start");

    // A layout that could not carry the symlink (or an archive with only the
    // ELF) still launches the real binary.
    std::fs::remove_file(target.join("System/ut2004-bin-amd64")).unwrap();
    std::fs::remove_file(&marker).unwrap();
    let launched = InstallEngine::launch(&target).unwrap();
    assert_eq!(launched, target.join("System/UT2004"));
    for _ in 0..100 {
        if marker.is_file() {
            break;
        }
        std::thread::sleep(std::time::Duration::from_millis(20));
    }
    assert!(marker.is_file(), "the UT2004 fallback did not start");
}

#[test]
fn a_symlink_that_leaves_the_target_is_rejected() {
    for target in ["../../outside", "/etc/passwd", "C:\\Windows\\x"] {
        let dir = tempfile::tempdir().unwrap();
        let outside = tempfile::tempdir().unwrap();
        let archive = write_tar_bz2(&dir.path().join("hostile.tar.bz2"), |builder| {
            tar_file(builder, "System/Build.ini", b"Label=v3374\n", 0o644);
            tar_symlink(builder, "System/escape", target);
        });
        let game = dir.path().join("game");

        assert!(
            matches!(
                extract_tar_bz2(&archive, &game),
                Err(ArchiveError::UnsafeEntry { .. })
            ),
            "{target} must be rejected"
        );
        assert!(!game.join("System/escape").exists());
        assert!(!outside.path().join("escape").exists());
    }
}

fn tar_plan(downloads: &Path) -> RecipePlan {
    serde_json::from_value(serde_json::json!({
        "recipe": {"slug": "unreal-tournament-2004", "title": "Unreal Tournament 2004"},
        "os": "linux",
        "target_dir": "~/Games/ut2004",
        "downloads_dir": downloads.to_string_lossy(),
        "files": [
            {
                "requirement": "archive",
                "status": "matched",
                "download_url": "https://lan.invalid/files/linux-portable.tar.bz2",
                "name": "linux-portable.tar.bz2"
            },
            // A portable archive is already 3374: this required patch must not block.
            {"requirement": "patch", "status": "missing", "required": true, "label": "OldUnreal Patch"}
        ]
    }))
    .unwrap()
}

#[tokio::test]
async fn installs_a_tar_base_and_verifies_the_oldunreal_layout() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");
    let archive = linux_tar();

    Mock::given(method("GET"))
        .and(path("/files/linux-portable.tar.bz2"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(archive))
        .mount(&server)
        .await;

    let mut plan = tar_plan(&downloads);
    plan.files[0].download_url = Some(format!("{}/files/linux-portable.tar.bz2", server.uri()));

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: None,
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress: Arc::new(|progress: InstallProgress| {
            assert_ne!(progress.phase, InstallPhase::Error);
        }),
    })
    .await
    .unwrap();

    assert!(outcome.used_archive);
    assert!(
        outcome
            .verified
            .contains(&"System/ut2004-bin-amd64".to_string())
            || outcome.verified.contains(&"System/UT2004".to_string())
    );
    assert!(
        std::fs::symlink_metadata(target.join("System/ut2004-bin-amd64"))
            .unwrap()
            .file_type()
            .is_symlink()
    );

    use std::os::unix::fs::PermissionsExt;
    for file in ["System/UT2004", "System/ut2004-bin-amd64"] {
        let mode = std::fs::metadata(target.join(file))
            .unwrap()
            .permissions()
            .mode();
        assert_ne!(mode & 0o111, 0, "{file} must be executable after install");
    }

    let launched = InstallEngine::launch(&target).unwrap();
    assert!(launched.ends_with("System/ut2004-bin-amd64") || launched.ends_with("System/UT2004"));
}
