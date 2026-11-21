//! Portable base robustness: wrapper folders, installer zips that only wrap an
//! ISO, the payload guard that keeps `setup.exe` from becoming "Ready", and
//! patch precedence over a portable base.

use std::io::Write;
use std::path::Path;
use std::sync::{Arc, Mutex};

use launcher_core::download::{origin_of, CancelToken};
use launcher_core::install::{
    verify_install_for, InstallEngine, InstallError, InstallOptions, InstallPhase, InstallProgress,
};
use launcher_core::types::{Os, RecipePlan};
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

fn tar_bz2_base(entries: &[(&str, &[u8])]) -> Vec<u8> {
    let encoder = bzip2::write::BzEncoder::new(Vec::new(), bzip2::Compression::best());
    let mut builder = tar::Builder::new(encoder);

    for (name, body) in entries {
        let mut header = tar::Header::new_gnu();
        header.set_size(body.len() as u64);
        header.set_mode(if name.ends_with("UT2004") {
            0o755
        } else {
            0o644
        });
        header.set_cksum();
        builder.append_data(&mut header, *name, *body).unwrap();
    }

    builder.into_inner().unwrap().finish().unwrap()
}

fn sha256(bytes: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    format!("{:x}", hasher.finalize())
}

fn plan_json(downloads: &Path, os: &str, files: serde_json::Value) -> RecipePlan {
    serde_json::from_value(serde_json::json!({
        "recipe": {"slug": "unreal-tournament-2004", "title": "Unreal Tournament 2004"},
        "os": os,
        "target_dir": "~/Games/ut2004",
        "downloads_dir": downloads.to_string_lossy(),
        "files": files,
    }))
    .unwrap()
}

fn archive_file(server: &MockServer, route: &str, name: &str, bytes: &[u8]) -> serde_json::Value {
    serde_json::json!({
        "requirement": "archive",
        "status": "matched",
        "download_url": format!("{}{}", server.uri(), route),
        "name": name,
        "size_bytes": bytes.len(),
        "sha256": sha256(bytes)
    })
}

fn patch_file(server: &MockServer, route: &str, name: &str, bytes: &[u8]) -> serde_json::Value {
    serde_json::json!({
        "requirement": "patch",
        "status": "matched",
        "required": true,
        "download_url": format!("{}{}", server.uri(), route),
        "name": name,
        "size_bytes": bytes.len(),
        "sha256": sha256(bytes)
    })
}

async fn mount(server: &MockServer, route: &str, body: &[u8]) {
    Mock::given(method("GET"))
        .and(path(route.to_string()))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body.to_vec()))
        .mount(server)
        .await;
}

fn no_progress() -> Arc<dyn Fn(InstallProgress) + Send + Sync> {
    Arc::new(|_| {})
}

#[tokio::test]
async fn a_wrapped_zip_base_is_flattened_before_verification() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");
    let archive = zip_bytes(&[
        ("UT2004/System/ut2004-bin-amd64", b"#!/bin/sh\nexit 0\n"),
        ("UT2004/System/Build.ini", b"Label=v3374\n"),
        ("UT2004/Maps/DM-Rankin.ut2", b"rank in"),
    ]);
    mount(&server, "/files/base", &archive).await;

    let plan = plan_json(
        &downloads,
        "linux",
        serde_json::json!([archive_file(
            &server,
            "/files/base",
            "ut2004-portable.zip",
            &archive
        )]),
    );

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: origin_of(&server.uri()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress: no_progress(),
    })
    .await
    .unwrap();

    assert!(outcome.used_archive);
    assert_eq!(
        outcome.verified,
        vec!["System/ut2004-bin-amd64", "System/Build.ini"]
    );
    assert!(target.join("System/Build.ini").is_file());
    assert_eq!(
        std::fs::read(target.join("Maps/DM-Rankin.ut2")).unwrap(),
        b"rank in"
    );
    assert!(
        !target.join("UT2004").exists(),
        "the wrapper folder must be stripped"
    );
}

#[tokio::test]
async fn a_wrapped_tar_base_is_flattened_before_verification() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");
    let archive = tar_bz2_base(&[
        ("UT2004/System/UT2004", b"#!/bin/sh\nexit 0\n"),
        ("UT2004/System/Build.ini", b"Label=v3374\n"),
        ("UT2004/Maps/DM-Rankin.ut2", b"rank in"),
    ]);
    mount(&server, "/files/base", &archive).await;

    let plan = plan_json(
        &downloads,
        "linux",
        serde_json::json!([{
            "requirement": "archive",
            "status": "matched",
            "download_url": format!("{}/files/base", server.uri()),
            "name": "ut2004-portable.tar.bz2",
            "size_bytes": archive.len(),
            "sha256": sha256(&archive)
        }]),
    );

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: origin_of(&server.uri()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress: no_progress(),
    })
    .await
    .unwrap();

    assert_eq!(outcome.verified, vec!["System/UT2004", "System/Build.ini"]);
    assert!(target.join("System/UT2004").is_file());
    assert_eq!(
        std::fs::read(target.join("Maps/DM-Rankin.ut2")).unwrap(),
        b"rank in"
    );
    assert!(
        !target.join("UT2004").exists(),
        "the tar wrapper folder must be stripped"
    );
}

#[tokio::test]
async fn a_zip_that_only_wraps_an_iso_is_extracted_through_the_iso_path() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");

    // A real ISO with the retail wrapper and the payload the patch does not
    // ship. It is then nested inside an installer-shaped zip (`setup.exe`).
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

    let installer = zip_bytes(&[
        ("ut2k4+keygen/setup.exe", b"installer"),
        ("ut2k4+keygen/keygen.exe", b"keygen"),
        ("ut2k4+keygen/ut2004.iso", &iso),
    ]);
    let patch = zip_bytes(&[
        ("System/ut2004-bin-amd64", b"#!/bin/sh\nexit 0\n"),
        ("System/Build.ini", b"Label=v3374\n"),
    ]);

    mount(&server, "/files/base", &installer).await;
    mount(&server, "/files/patch", &patch).await;

    let plan = plan_json(
        &downloads,
        "linux",
        serde_json::json!([
            {
                "requirement": "base",
                "status": "matched",
                "download_url": format!("{}/files/base", server.uri()),
                "name": "ut2k4+keygen.zip",
                "size_bytes": installer.len(),
                "sha256": sha256(&installer)
            },
            patch_file(&server, "/files/patch", "patch.zip", &patch)
        ]),
    );

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: origin_of(&server.uri()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress: no_progress(),
    })
    .await
    .unwrap();

    assert!(outcome.used_archive, "the zip base is the portable path");
    assert_eq!(
        std::fs::read(target.join("Maps/DM-Rankin.ut2")).unwrap(),
        b"rank in",
        "the nested ISO must supply the payload"
    );
    assert_eq!(
        std::fs::read(target.join("System/Build.ini")).unwrap(),
        b"Label=v3374\n",
        "the patch still supplies System/"
    );
    assert!(
        !target.join("setup.exe").exists() && !target.join("ut2k4+keygen").exists(),
        "the installer shell must not be extracted"
    );
    assert!(
        std::fs::read_dir(&downloads)
            .unwrap()
            .flatten()
            .all(|entry| !entry
                .file_name()
                .to_string_lossy()
                .starts_with(".lanomat-nested-")),
        "the temporary ISO must be removed"
    );
}

#[tokio::test]
async fn a_setup_exe_zip_with_a_patch_is_not_ready() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");
    let installer = zip_bytes(&[
        ("setup.exe", b"installer"),
        ("readme.txt", b"just an installer"),
    ]);
    let patch = zip_bytes(&[
        ("System/ut2004-bin-amd64", b"#!/bin/sh\nexit 0\n"),
        ("System/Build.ini", b"Label=v3374\n"),
    ]);
    mount(&server, "/files/base", &installer).await;
    mount(&server, "/files/patch", &patch).await;

    let plan = plan_json(
        &downloads,
        "linux",
        serde_json::json!([
            archive_file(&server, "/files/base", "ut2k4+keygen.zip", &installer),
            patch_file(&server, "/files/patch", "patch.zip", &patch)
        ]),
    );
    let phases = Arc::new(Mutex::new(Vec::new()));
    let recorder = phases.clone();

    let error = InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: origin_of(&server.uri()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress: Arc::new(move |progress| {
            recorder.lock().unwrap().push(progress.phase);
        }),
    })
    .await
    .unwrap_err();

    assert!(
        matches!(error, InstallError::Verification(_)),
        "expected a verification failure, got {error:?}"
    );
    assert!(
        error
            .to_string()
            .contains("Basis-Archiv enthält keine Spieldaten (Maps/Textures)"),
        "the payload failure must be explicit: {error}"
    );
    assert!(
        !phases.lock().unwrap().contains(&InstallPhase::Ready),
        "an installer-only base must never reach Ready"
    );
    assert!(
        !target.join("Maps").exists(),
        "the patch must not run after the base payload check failed"
    );
}

#[tokio::test]
async fn a_portable_base_without_system_uses_the_patch_for_system() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");
    let archive = zip_bytes(&[
        ("Maps/DM-Rankin.ut2", b"rank in"),
        ("Textures/Arborea.utx", b"texture"),
    ]);
    let patch = zip_bytes(&[
        ("System/ut2004-bin-amd64", b"#!/bin/sh\nexit 0\n"),
        ("System/Build.ini", b"Label=v3374\n"),
    ]);
    mount(&server, "/files/base", &archive).await;
    mount(&server, "/files/patch", &patch).await;

    let plan = plan_json(
        &downloads,
        "linux",
        serde_json::json!([
            archive_file(&server, "/files/base", "ut2004-portable.zip", &archive),
            patch_file(&server, "/files/patch", "patch.zip", &patch)
        ]),
    );

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: origin_of(&server.uri()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress: no_progress(),
    })
    .await
    .unwrap();

    assert_eq!(
        outcome.verified,
        vec!["System/ut2004-bin-amd64", "System/Build.ini"]
    );
    assert!(target.join("Maps/DM-Rankin.ut2").is_file());
    assert!(target.join("Textures/Arborea.utx").is_file());
}

#[tokio::test]
async fn a_windows_portable_base_keeps_its_system_tree() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");
    let archive = zip_bytes(&[
        ("UT2004/System/UT2004.exe", b"MZ"),
        ("UT2004/System/Build.ini", b"Label=v3374\n"),
        ("UT2004/System/D3D11Drv.dll", b"dll"),
        ("UT2004/Maps/DM-Rankin.ut2", b"rank in"),
    ]);
    mount(&server, "/files/base", &archive).await;

    let plan = plan_json(
        &downloads,
        "windows",
        serde_json::json!([archive_file(
            &server,
            "/files/base",
            "ut2004-portable-win.zip",
            &archive
        )]),
    );

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: origin_of(&server.uri()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress: no_progress(),
    })
    .await
    .unwrap();

    assert_eq!(
        outcome.verified,
        vec![
            "System/UT2004.exe",
            "System/Build.ini",
            "System/D3D11Drv.dll"
        ]
    );
    assert!(target.join("System/UT2004.exe").is_file());
    assert!(verify_install_for(&target, Os::Windows).is_ok());
    assert!(
        !target.join("UT2004").exists(),
        "the wrapper folder is stripped on Windows too"
    );
}

#[tokio::test]
async fn a_portable_macos_base_installs_into_the_application_support_target() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("Application Support/UT2004");
    let archive = zip_bytes(&[
        ("UT2004.app/Contents/MacOS/UT2004", b"#!/bin/sh\nexit 0\n"),
        (
            "UT2004.app/Contents/Resources/System/Packages.md5",
            b"packages",
        ),
        (
            "UT2004.app/Contents/Resources/Maps/DM-Rankin.ut2",
            b"rank in",
        ),
    ]);
    mount(&server, "/files/base", &archive).await;

    let plan = plan_json(
        &downloads,
        "macos",
        serde_json::json!([archive_file(
            &server,
            "/files/base",
            "ut2004-portable-mac.zip",
            &archive
        )]),
    );

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: origin_of(&server.uri()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress: no_progress(),
    })
    .await
    .unwrap();

    assert!(outcome.used_archive);
    assert_eq!(
        outcome.verified,
        vec![
            "UT2004.app/Contents/MacOS/UT2004",
            "UT2004.app/Contents/Resources/System/Packages.md5"
        ]
    );
    assert!(
        target
            .join("UT2004.app/Contents/Resources/Maps/DM-Rankin.ut2")
            .is_file(),
        "the app bundle wrapper is kept and its payload found recursively"
    );
    assert!(verify_install_for(&target, Os::Macos).is_ok());

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;

        let mode = std::fs::metadata(target.join("UT2004.app/Contents/MacOS/UT2004"))
            .unwrap()
            .permissions()
            .mode();
        assert_ne!(mode & 0o111, 0, "the bundle executable must be runnable");
    }
}

#[tokio::test]
async fn a_patch_wins_over_a_portable_wrapped_base() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("game");
    let archive = zip_bytes(&[
        ("UT2004/System/ut2004-bin-amd64", b"#!/bin/sh\nexit 0\n"),
        ("UT2004/System/Build.ini", b"Label=3369\n"),
        ("UT2004/Maps/DM-Rankin.ut2", b"rank in"),
    ]);
    let patch = zip_bytes(&[
        ("System/Build.ini", b"Label=v3374\n"),
        ("System/UT2004", b"#!/bin/sh\nexit 0\n"),
    ]);
    mount(&server, "/files/base", &archive).await;
    mount(&server, "/files/patch", &patch).await;

    let plan = plan_json(
        &downloads,
        "linux",
        serde_json::json!([
            archive_file(&server, "/files/base", "ut2004-portable.zip", &archive),
            patch_file(&server, "/files/patch", "patch.zip", &patch)
        ]),
    );

    InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: origin_of(&server.uri()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress: no_progress(),
    })
    .await
    .unwrap();

    assert_eq!(
        std::fs::read(target.join("System/Build.ini")).unwrap(),
        b"Label=v3374\n",
        "the patch must overwrite the portable base"
    );
    assert!(target.join("System/UT2004").is_file());
}
