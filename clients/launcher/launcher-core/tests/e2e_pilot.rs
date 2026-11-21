//! The UT2004 pilot, end to end: pair -> `me` -> `recipes` -> `plan` -> two
//! downloads from the plan origin -> ISO + patch extraction -> verify.
//!
//! Everything is hermetic: one wiremock server on a random port, all files in a
//! `tempfile` directory, no real network and no `$HOME` writes (the plan's
//! home-relative paths are overridden by `InstallOptions::target_dir`).
//!
//! Fixture provenance:
//! * the DVD image is a real ISO9660/Joliet image written at test time by
//!   `libmkiso`'s native writer (dev-dependency `native-writer`), the same
//!   writer the retail disc layout is read with in production. It contains a
//!   one-folder wrapper (`UT2004/`) with a `System/` tree that must be skipped
//!   and a `Maps/DM-Rankin.ut2` payload that must land in the install;
//! * the Linux patch is a real `tar.bz2` built in memory with the `bzip2` and
//!   `tar` crates that the engine reads it with, so no committed binary
//!   fixture is needed.

mod common;

use std::io::Write;
use std::path::Path;
use std::sync::{Arc, Mutex};

use common::{
    bearer_of, device_start_body, me_body, mock_json, recipes_body, token_body, ACCESS_TOKEN,
    DEVICE_CODE,
};
use launcher_core::api::{ApiClient, PollEvent};
use launcher_core::download::{origin_of, CancelToken};
use launcher_core::install::{
    InstallEngine, InstallError, InstallOptions, InstallPhase, InstallProgress,
};
use launcher_core::types::Os;
use sha2::{Digest, Sha256};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

const SLUG: &str = "unreal-tournament-2004";
const PLAN_ROUTE: &str = "/api/v1/recipes/unreal-tournament-2004/plan";

fn sha256(bytes: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    format!("{:x}", hasher.finalize())
}

/// A real ISO9660/Joliet image with the retail disc's shape: one wrapper
/// folder, a `System/` tree plus the payload the patch does not ship.
fn disc_image(work: &Path) -> Vec<u8> {
    let source = work.join("disc");
    std::fs::create_dir_all(source.join("UT2004/System")).unwrap();
    std::fs::create_dir_all(source.join("UT2004/Maps")).unwrap();
    // System/ on the disc is replaced by the patch; this file proves the skip.
    std::fs::write(source.join("UT2004/System/UT2004.exe"), b"disc binary\n").unwrap();
    std::fs::write(source.join("UT2004/System/Build.ini"), b"Label=3369\n").unwrap();
    std::fs::write(source.join("UT2004/Maps/DM-Rankin.ut2"), b"rank in\n").unwrap();

    let iso_path = work.join("ut2004.iso");
    libmkiso::write_iso9660_with_options(
        &source,
        &iso_path,
        &libmkiso::IsoOptions {
            joliet: true,
            filename_policy: libmkiso::FilenamePolicy::Mangle,
            ..libmkiso::IsoOptions::default()
        },
    )
    .unwrap();

    std::fs::read(&iso_path).unwrap()
}

/// A real `tar.bz2` (levels 1..=9 of the bzip2 crate the engine decodes with).
fn tar_bz2(entries: &[(&str, &[u8])]) -> Vec<u8> {
    let encoder = bzip2::write::BzEncoder::new(Vec::new(), bzip2::Compression::best());
    let mut builder = tar::Builder::new(encoder);

    for (name, body) in entries {
        let mut header = tar::Header::new_gnu();
        header.set_size(body.len() as u64);
        header.set_mode(0o644);
        header.set_cksum();
        builder.append_data(&mut header, *name, *body).unwrap();
    }

    let encoder = builder.into_inner().unwrap();
    encoder.finish().unwrap()
}

fn zip_bytes(entries: &[(&str, &[u8])]) -> Vec<u8> {
    let mut writer = zip::ZipWriter::new(std::io::Cursor::new(Vec::new()));
    let options = zip::write::SimpleFileOptions::default();

    for (name, body) in entries {
        writer.start_file(*name, options).unwrap();
        writer.write_all(body).unwrap();
    }

    writer.finish().unwrap().into_inner()
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

/// The documented plan response shape, with the launcher's files filled in.
fn plan_body(files: serde_json::Value, missing: serde_json::Value) -> serde_json::Value {
    serde_json::json!({
        "recipe": {
            "slug": SLUG,
            "title": "Unreal Tournament 2004",
            "version": "3374",
            "skill": SLUG,
            "launch_target": "System/ut2004-bin-amd64",
            "launch_targets": [
                {
                    "id": "flat-linux",
                    "label": "Flat",
                    "os": "linux",
                    "command": "./ut2004-bin-amd64",
                    "target": "System/ut2004-bin-amd64",
                    "shell": "bash"
                }
            ],
            "play_url": "https://lan.test/games/unreal-tournament-2004"
        },
        "os": "linux",
        // Home-relative on purpose: the target override must keep $HOME clean.
        "target_dir": "~/Games/unreal-tournament-2004",
        "steps": [],
        "files": files,
        "downloads": [],
        "avatars": [],
        "missing": missing,
        "generated_at": "2026-11-21T12:00:00+00:00"
    })
}

async fn mock_plan(server: &MockServer, body: serde_json::Value) {
    mock_json(server, "POST", PLAN_ROUTE, 200, body).await;
}

/// Drive the real device flow against the mock server and return the
/// authenticated client, as the shell does before it opens the library.
async fn pair(server: &MockServer) -> ApiClient {
    mock_json(server, "POST", "/api/mcp/device", 200, device_start_body()).await;
    mock_json(server, "POST", "/api/mcp/token", 200, token_body()).await;
    mock_json(server, "GET", "/api/v1/me", 200, me_body()).await;
    mock_json(server, "GET", "/api/v1/recipes", 200, recipes_body()).await;

    let anonymous = ApiClient::new(&server.uri()).unwrap();
    let device = anonymous
        .start_device("LANoMAT Launcher on lan-pc")
        .await
        .unwrap();
    assert_eq!(device.device_code, DEVICE_CODE);

    let grant = match anonymous.poll_token(&device.device_code).await.unwrap() {
        PollEvent::Token(grant) => grant,
        other => panic!("expected a granted token, got {other:?}"),
    };
    assert_eq!(grant.access_token, ACCESS_TOKEN);

    let api = anonymous.with_token(grant.access_token.clone());

    let me = api.me().await.unwrap();
    assert_eq!(me.user.name, "Ada");
    assert!(!me.user.is_orga);

    let recipes = api.recipes().await.unwrap();
    assert!(
        recipes.iter().any(|recipe| recipe.slug == SLUG),
        "the UT2004 recipe must be listed: {recipes:?}"
    );

    api
}

/// The two download requests the plan asked for, with their bearer check.
fn download_requests(requests: &[wiremock::Request]) -> Vec<&wiremock::Request> {
    requests
        .iter()
        .filter(|request| {
            request.method.as_str() == "GET" && request.url.path().starts_with("/api/v1/files/")
        })
        .collect()
}

#[tokio::test]
async fn the_ut2004_pilot_installs_from_the_dvd_iso_and_the_linux_patch() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let target = work.path().join("game");
    let downloads = target.join(".lanomat-downloads");

    let iso = disc_image(work.path());
    let patch = tar_bz2(&[
        ("System/ut2004-bin-amd64", b"#!/bin/sh\nexit 0\n"),
        ("System/Build.ini", b"Label=v3374\n"),
    ]);

    Mock::given(method("GET"))
        .and(path("/api/v1/files/42/download"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(iso.clone()))
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(path("/api/v1/files/43/download"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(patch.clone()))
        .mount(&server)
        .await;

    let api = pair(&server).await;
    let plan_json = plan_body(
        serde_json::json!([
            {
                "requirement": "iso",
                "label": "Retail ISO",
                "status": "matched",
                "required": true,
                "source": "lan",
                "name": "ut2004.iso",
                "size_bytes": iso.len(),
                "sha256": sha256(&iso),
                "download_url": format!("{}/api/v1/files/42/download", server.uri()),
                "fallback_url": null
            },
            {
                "requirement": "patch",
                "label": "OldUnreal 3374 patch",
                "status": "matched",
                "required": true,
                "source": "lan",
                "name": "ut2004-linux-patch.tar.bz2",
                "size_bytes": patch.len(),
                "sha256": sha256(&patch),
                "download_url": format!("{}/api/v1/files/43/download", server.uri()),
                "fallback_url": null
            },
            {
                // Optional and missing on the LAN: must not block the install.
                "requirement": "mod",
                "label": "Community Bonus Pack",
                "status": "missing",
                "required": false,
                "source": null
            }
        ]),
        serde_json::json!(["Community Bonus Pack"]),
    );
    mock_plan(&server, plan_json).await;

    let plan = api.plan(SLUG, Os::Linux).await.unwrap();
    assert_eq!(plan.recipe.slug, SLUG);
    assert_eq!(plan.target_dir, "~/Games/unreal-tournament-2004");
    assert!(plan.downloads_dir.is_none(), "the default must be used");

    let (phases, on_progress) = progress_recorder();
    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: api.token().map(str::to_string),
        bearer_origin: origin_of(api.base_url()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress,
    })
    .await
    .unwrap();

    // Two downloads, both on the LANoMAT (plan) origin and both authenticated.
    let requests = server.received_requests().await.unwrap();
    let downloads_seen = download_requests(&requests);
    assert_eq!(
        downloads_seen.len(),
        2,
        "the ISO and the patch were fetched: {downloads_seen:#?}"
    );
    for request in &downloads_seen {
        assert_eq!(
            bearer_of(request).as_deref(),
            Some(format!("Bearer {ACCESS_TOKEN}").as_str()),
            "the bearer must reach the plan origin for {}",
            request.url.path()
        );
    }
    assert!(downloads_seen
        .iter()
        .any(|request| request.url.path() == "/api/v1/files/42/download"));
    assert!(downloads_seen
        .iter()
        .any(|request| request.url.path() == "/api/v1/files/43/download"));

    // The plan request itself was authenticated (no token, no plan).
    assert!(requests.iter().any(|request| {
        request.method.as_str() == "POST"
            && request.url.path() == PLAN_ROUTE
            && bearer_of(request).as_deref() == Some(format!("Bearer {ACCESS_TOKEN}").as_str())
    }));

    // Both files are kept next to the install for a later repair/resume.
    assert!(downloads.join("ut2004.iso").is_file());
    assert!(downloads.join("ut2004-linux-patch.tar.bz2").is_file());
    assert!(!downloads.join("ut2004.iso.part").exists());
    assert!(!downloads.join("ut2004-linux-patch.tar.bz2.part").exists());

    // The retail path: the ISO supplied the payload, not a portable archive.
    assert!(!outcome.used_archive);
    assert_eq!(outcome.target_dir, target.to_string_lossy());
    assert!(
        outcome.files_extracted > 0,
        "nothing was extracted: {outcome:?}"
    );

    // The ISO payload landed, the wrapper folder was stripped.
    assert_eq!(
        std::fs::read(target.join("Maps/DM-Rankin.ut2")).unwrap(),
        b"rank in\n"
    );
    assert!(!target.join("UT2004").exists(), "the wrapper is stripped");

    // System/ from the disc is skipped; the patch supplies the whole tree.
    assert!(
        !target.join("System/UT2004.exe").exists(),
        "the disc's System/ tree must not be extracted"
    );
    assert_eq!(
        std::fs::read(target.join("System/Build.ini")).unwrap(),
        b"Label=v3374\n",
        "the patch must win over the disc"
    );

    // Verification passed for the flat Linux layout.
    assert_eq!(
        outcome.verified,
        vec![
            "System/ut2004-bin-amd64".to_string(),
            "System/Build.ini".to_string()
        ]
    );
    assert!(target.join("System/ut2004-bin-amd64").is_file());

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
    assert_eq!(phases.last(), Some(&InstallPhase::Ready));
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

#[tokio::test]
async fn a_portable_archive_installs_without_the_dvd() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let target = work.path().join("game");
    let downloads = target.join(".lanomat-downloads");

    let portable = zip_bytes(&[
        ("System/ut2004-bin-amd64", b"#!/bin/sh\nexit 0\n"),
        ("System/Build.ini", b"Label=v3374\n"),
        ("Maps/DM-Rankin.ut2", b"rank in\n"),
    ]);

    Mock::given(method("GET"))
        .and(path("/api/v1/files/7/download"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(portable.clone()))
        .mount(&server)
        .await;

    let api = pair(&server).await;
    mock_plan(
        &server,
        plan_body(
            serde_json::json!([
                // Required and missing: the matched portable archive wins.
                {
                    "requirement": "iso",
                    "label": "UT2004 DVD",
                    "status": "missing",
                    "required": true,
                    "source": null
                },
                {
                    "requirement": "archive",
                    "label": "Portable build",
                    "status": "matched",
                    "required": false,
                    "source": "lan",
                    "name": "ut2004-portable-3374.zip",
                    "size_bytes": portable.len(),
                    "sha256": sha256(&portable),
                    "download_url": format!("{}/api/v1/files/7/download", server.uri())
                }
            ]),
            serde_json::json!(["UT2004 DVD"]),
        ),
    )
    .await;

    let plan = api.plan(SLUG, Os::Linux).await.unwrap();
    let (_, on_progress) = progress_recorder();
    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: api.token().map(str::to_string),
        bearer_origin: origin_of(api.base_url()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress,
    })
    .await
    .unwrap();

    // Exactly one download: no DVD was needed.
    let requests = server.received_requests().await.unwrap();
    let downloads_seen = download_requests(&requests);
    assert_eq!(
        downloads_seen.len(),
        1,
        "only the portable archive is fetched: {downloads_seen:#?}"
    );
    assert_eq!(downloads_seen[0].url.path(), "/api/v1/files/7/download");
    assert_eq!(
        bearer_of(downloads_seen[0]).as_deref(),
        Some(format!("Bearer {ACCESS_TOKEN}").as_str())
    );
    assert!(downloads.join("ut2004-portable-3374.zip").is_file());
    assert!(!downloads.join("ut2004.iso").exists());

    assert!(outcome.used_archive, "the portable path was used");
    assert!(outcome.files_extracted >= 3, "{outcome:?}");
    assert_eq!(
        outcome.verified,
        vec![
            "System/ut2004-bin-amd64".to_string(),
            "System/Build.ini".to_string()
        ]
    );
    assert_eq!(
        std::fs::read(target.join("System/Build.ini")).unwrap(),
        b"Label=v3374\n"
    );
    assert_eq!(
        std::fs::read(target.join("Maps/DM-Rankin.ut2")).unwrap(),
        b"rank in\n"
    );

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        let mode = std::fs::metadata(target.join("System/ut2004-bin-amd64"))
            .unwrap()
            .permissions()
            .mode();
        assert_ne!(mode & 0o111, 0, "the executable bit must be set");
    }
}

#[tokio::test]
async fn without_a_base_file_both_missing_labels_are_reported() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let target = work.path().join("game");

    let api = pair(&server).await;
    mock_plan(
        &server,
        plan_body(
            serde_json::json!([
                {
                    "requirement": "iso",
                    "label": "UT2004 DVD",
                    "status": "missing",
                    "required": true,
                    "source": null
                },
                {
                    "requirement": "patch",
                    "label": "OldUnreal Patch",
                    "status": "missing",
                    "required": true,
                    "source": null
                }
            ]),
            serde_json::json!(["UT2004 DVD", "OldUnreal Patch"]),
        ),
    )
    .await;

    let plan = api.plan(SLUG, Os::Linux).await.unwrap();
    let (phases, on_progress) = progress_recorder();
    let error = InstallEngine::install(InstallOptions {
        plan,
        bearer: api.token().map(str::to_string),
        bearer_origin: origin_of(api.base_url()),
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress,
    })
    .await
    .unwrap_err();

    match error {
        InstallError::MissingFiles(labels) => {
            assert!(labels.contains("UT2004 DVD"), "{labels}");
            assert!(labels.contains("OldUnreal Patch"), "{labels}");
        }
        other => panic!("expected MissingFiles, got {other}"),
    }

    // Fail fast: the plan was fetched, but nothing was downloaded or written.
    let requests = server.received_requests().await.unwrap();
    assert!(download_requests(&requests).is_empty(), "{requests:#?}");
    assert!(!target.join(".lanomat-downloads").exists());
    assert_eq!(phases.lock().unwrap().as_slice(), &[InstallPhase::Planning]);
}
