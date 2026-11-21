//! End-to-end install-engine proof against a local wiremock file host.
//!
//! No LANoMAT server, no $HOME: the plan paths are overridden and the two
//! archives are served from 127.0.0.1.

use std::io::Write;
use std::path::Path;
use std::sync::{Arc, Mutex};

use launcher_core::download::{origin_of, CancelToken};
use launcher_core::install::{
    verify_install_for, InstallEngine, InstallOptions, InstallPhase, InstallProgress,
};
use launcher_core::types::RecipePlan;
use sha2::{Digest, Sha256};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

fn zip_bytes(entries: &[(&str, &[u8])]) -> Vec<u8> {
    let mut writer = zip::ZipWriter::new(std::io::Cursor::new(Vec::new()));
    let options = zip::write::SimpleFileOptions::default();

    for (name, body) in entries {
        writer.start_file(*name, options).unwrap();
        writer.write_all(body).unwrap();
    }

    writer.finish().unwrap().into_inner()
}

fn sha256(bytes: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    format!("{:x}", hasher.finalize())
}

#[allow(clippy::type_complexity)]
type ProgressRecorder = (
    Arc<Mutex<Vec<InstallPhase>>>,
    Arc<dyn Fn(InstallProgress) + Send + Sync>,
);

fn progress_recorder() -> ProgressRecorder {
    let phases = Arc::new(Mutex::new(Vec::new()));
    let recorder = phases.clone();
    let callback: Arc<dyn Fn(InstallProgress) + Send + Sync> = Arc::new(move |progress| {
        recorder.lock().unwrap().push(progress.phase);
    });

    (phases, callback)
}

fn plan_json(downloads: &Path, files: serde_json::Value) -> RecipePlan {
    serde_json::from_value(serde_json::json!({
        "recipe": {"slug": "unreal-tournament-2004", "title": "Unreal Tournament 2004"},
        "os": "linux",
        // Deliberately home-relative: the InstallOptions target override and
        // the absolute downloads_dir must keep the test away from $HOME.
        "target_dir": "~/Games/ut2004",
        "downloads_dir": downloads.to_string_lossy(),
        "files": files,
    }))
    .unwrap()
}

#[tokio::test]
async fn installs_a_portable_archive_without_a_patch() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");

    let archive = zip_bytes(&[
        ("System/ut2004-bin-amd64", b"#!/bin/sh\nexit 0\n"),
        ("System/Build.ini", b"Label=v3374\n"),
        ("Maps/DM-Rankin.ut2", b"rank in"),
    ]);

    Mock::given(method("GET"))
        .and(path("/files/portable"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(archive.clone()))
        .mount(&server)
        .await;

    // The portable archive is already 3374: the required patch has no URL and
    // must not block this install.
    let plan = plan_json(
        &downloads,
        serde_json::json!([
            {
                "requirement": "archive",
                "status": "matched",
                "download_url": format!("{}/files/portable", server.uri()),
                "name": "ut2004-portable.zip",
                "size_bytes": archive.len(),
                "sha256": sha256(&archive)
            },
            {"requirement": "patch", "status": "missing", "required": true, "label": "OldUnreal Patch"}
        ]),
    );
    let (phases, on_progress) = progress_recorder();

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: None,
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress,
    })
    .await
    .unwrap();

    assert!(outcome.used_archive);
    assert_eq!(
        outcome.verified,
        vec!["System/ut2004-bin-amd64", "System/Build.ini"]
    );
    assert_eq!(
        std::fs::read(target.join("System/Build.ini")).unwrap(),
        b"Label=v3374\n"
    );
    assert!(!downloads.join("OldUnreal Patch").exists());
    assert!(
        !phases.lock().unwrap().contains(&InstallPhase::Patching),
        "the portable archive must not wait for a patch"
    );
}

#[tokio::test]
async fn installs_an_external_only_archive_and_patch_without_the_bearer() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");

    let archive = zip_bytes(&[
        ("System/ut2004-bin-amd64", b"#!/bin/sh\nexit 0\n"),
        ("System/Build.ini", b"Label=3369\n"),
        ("Maps/DM-Rankin.ut2", b"rank in"),
    ]);
    let patch = zip_bytes(&[
        ("System/UT2004", b"#!/bin/sh\nexit 0\n"),
        ("System/Build.ini", b"Label=v3374\n"),
    ]);

    Mock::given(method("GET"))
        .and(path("/external/portable.zip"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(archive.clone()))
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(path("/external/patch.zip"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(patch.clone()))
        .mount(&server)
        .await;

    let plan = plan_json(
        &downloads,
        serde_json::json!([
            {
                "requirement": "archive",
                "status": "external",
                "source": "external",
                "download_url": format!("{}/external/portable.zip", server.uri()),
                "name": "portable.zip",
                "size_bytes": archive.len(),
                "sha256": sha256(&archive)
            },
            {
                "requirement": "patch",
                "status": "external",
                "source": "external",
                "required": true,
                "download_url": format!("{}/external/patch.zip", server.uri()),
                "name": "patch.zip",
                "size_bytes": patch.len(),
                "sha256": sha256(&patch)
            }
        ]),
    );
    let (_, on_progress) = progress_recorder();

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        // The paired origin is the same as the external file host: only the
        // `external` status keeps the token away, not an origin mismatch.
        bearer: Some("test-token".into()),
        bearer_origin: origin_of(&server.uri()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress,
    })
    .await
    .unwrap();

    assert!(outcome.used_archive);
    assert!(
        verify_install_for(&target, launcher_core::types::Os::Linux).is_ok(),
        "the external-only install must verify"
    );
    assert_eq!(
        std::fs::read(target.join("System/Build.ini")).unwrap(),
        b"Label=v3374\n"
    );

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 2);
    for request in requests {
        assert_eq!(
            request.headers.get("authorization"),
            None,
            "an external plan file must never receive the LANoMAT bearer"
        );
    }
}

#[tokio::test]
async fn installs_a_portable_archive_with_its_patch_and_keeps_the_downloads() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");

    let archive = zip_bytes(&[
        ("System/ut2004-bin-amd64", b"#!/bin/sh\nexit 0\n"),
        ("System/Build.ini", b"Label=3369\n"),
        ("Maps/DM-Rankin.ut2", b"rank in"),
    ]);
    let patch = zip_bytes(&[
        ("System/Build.ini", b"Label=v3374\n"),
        ("System/ut2004", b"launcher wrapper\n"),
        ("Textures/Patch.utx", b"patched texture"),
    ]);

    Mock::given(method("GET"))
        .and(path("/files/archive"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(archive.clone()))
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(path("/files/patch"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(patch.clone()))
        .mount(&server)
        .await;

    let plan = plan_json(
        &downloads,
        serde_json::json!([
            // The ISO is required but missing: the matched portable archive wins.
            {"requirement": "iso", "status": "missing", "required": true, "label": "UT2004 DVD"},
            {
                "requirement": "archive",
                "status": "matched",
                "download_url": format!("{}/files/archive", server.uri()),
                "name": "ut2004-portable.zip",
                "size_bytes": archive.len(),
                "sha256": sha256(&archive)
            },
            {
                "requirement": "patch",
                "status": "matched",
                "required": true,
                "download_url": format!("{}/files/patch", server.uri()),
                "name": "patch.zip",
                "size_bytes": patch.len(),
                "sha256": sha256(&patch)
            }
        ]),
    );
    let (phases, on_progress) = progress_recorder();

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: Some("test-token".into()),
        bearer_origin: origin_of(&server.uri()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress,
    })
    .await
    .unwrap();

    assert!(outcome.used_archive);
    assert_eq!(
        outcome.verified,
        vec!["System/ut2004-bin-amd64", "System/Build.ini"]
    );
    assert_eq!(outcome.target_dir, target.to_string_lossy());
    assert_eq!(
        std::fs::read(target.join("System/Build.ini")).unwrap(),
        b"Label=v3374\n",
        "the patch must win over the portable archive"
    );
    assert!(target.join("System/ut2004-bin-amd64").is_file());
    assert!(target.join("System/ut2004").is_file());
    assert_eq!(
        std::fs::read(target.join("Maps/DM-Rankin.ut2")).unwrap(),
        b"rank in"
    );
    assert!(target.join("Textures/Patch.utx").is_file());

    // Both archives stay behind so a later start can resume or repair.
    assert!(downloads.join("ut2004-portable.zip").is_file());
    assert!(downloads.join("patch.zip").is_file());

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        let mode = std::fs::metadata(target.join("System/ut2004-bin-amd64"))
            .unwrap()
            .permissions()
            .mode();
        assert_ne!(mode & 0o111, 0, "the executable bit must be set");
    }

    let phases = phases.lock().unwrap().clone();
    assert_eq!(phases.first(), Some(&InstallPhase::Planning));
    for expected in [
        InstallPhase::Downloading,
        InstallPhase::Extracting,
        InstallPhase::Patching,
        InstallPhase::Verifying,
        InstallPhase::Ready,
    ] {
        assert!(
            phases.contains(&expected),
            "missing phase {expected:?} in {phases:?}"
        );
    }
}

/// The retail path: a real (tiny) ISO written by libmkiso plus the patch that
/// supplies `System/`. Asserts that the wrapper folder is stripped, `System/`
/// from the ISO is skipped and the patch lands flat.
#[tokio::test]
async fn installs_from_an_iso_and_the_patch_when_no_archive_exists() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");

    let disc = work.path().join("disc");
    std::fs::create_dir_all(disc.join("UT2004/System")).unwrap();
    std::fs::create_dir_all(disc.join("UT2004/Maps")).unwrap();
    std::fs::write(disc.join("UT2004/System/Build.ini"), b"Label=3369\n").unwrap();
    std::fs::write(disc.join("UT2004/Maps/DM-Rankin.ut2"), b"rank in").unwrap();
    let iso_path = work.path().join("ut2004.iso");
    libmkiso::write_iso9660_with_options(
        &disc,
        &iso_path,
        &libmkiso::IsoOptions {
            joliet: true,
            filename_policy: libmkiso::FilenamePolicy::Mangle,
            ..libmkiso::IsoOptions::default()
        },
    )
    .unwrap();
    let iso = std::fs::read(&iso_path).unwrap();

    let patch = zip_bytes(&[
        ("System/ut2004-bin-amd64", b"#!/bin/sh\nexit 0\n"),
        ("System/Build.ini", b"Label=v3374\n"),
    ]);

    Mock::given(method("GET"))
        .and(path("/files/dvd"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(iso.clone()))
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(path("/files/patch"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(patch.clone()))
        .mount(&server)
        .await;

    let plan = plan_json(
        &downloads,
        serde_json::json!([
            {
                "requirement": "iso",
                "status": "matched",
                "required": true,
                "download_url": format!("{}/files/dvd", server.uri()),
                "name": "ut2004.iso",
                "size_bytes": iso.len(),
                "sha256": sha256(&iso)
            },
            {
                "requirement": "patch",
                "status": "matched",
                "required": true,
                "download_url": format!("{}/files/patch", server.uri()),
                "name": "patch.zip",
                "size_bytes": patch.len(),
                "sha256": sha256(&patch)
            }
        ]),
    );
    let (_, on_progress) = progress_recorder();

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: None,
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress,
    })
    .await
    .unwrap();

    assert!(!outcome.used_archive, "the ISO path was used");
    assert_eq!(
        std::fs::read(target.join("System/Build.ini")).unwrap(),
        b"Label=v3374\n",
        "the patch supplies the whole System tree"
    );
    assert_eq!(
        std::fs::read(target.join("Maps/DM-Rankin.ut2")).unwrap(),
        b"rank in"
    );
    assert!(
        !target.join("UT2004").exists(),
        "the wrapper folder is stripped"
    );
    assert!(!target.join("System/UT2004.exe").exists());
    assert!(downloads.join("ut2004.iso").is_file());
    assert!(downloads.join("patch.zip").is_file());
}
