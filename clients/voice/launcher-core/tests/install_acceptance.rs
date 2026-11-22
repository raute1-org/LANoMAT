//! Install-level acceptance tests flagged by the test review.
//!
//! Four behaviours a real install must show, all hermetic:
//! 1. an install produced by [`InstallEngine::install`] can be started with
//!    [`InstallEngine::launch`] and the game process really runs,
//! 2. a cancelled download returns [`InstallError::Cancelled`], keeps its
//!    `.part` file, and a retry with a fresh token completes and verifies,
//! 3. a Windows layout verifies on a Linux machine (and a missing file is
//!    reported), while Linux accepts either `System/ut2004-bin-amd64` or
//!    `System/UT2004`,
//! 4. an `external`-only base archive installs through [`InstallOptions`]
//!    without ever receiving the LANoMAT bearer token.
//!
//! No test touches `$HOME` (the plan's home-relative paths are overridden), no
//! fixed port (wiremock picks one) and none exceeds a five-second budget.

// Some helpers and imports are only used by the `#[cfg(unix)]` tests below: on Windows they would be
// dead code, and `-D warnings` in `ci/build.sh` (cargo xwin clippy) must stay green.
#![cfg_attr(windows, allow(dead_code, unused_imports))]

use std::io::Write;
use std::path::Path;
use std::sync::Arc;
use std::time::{Duration, Instant};

use launcher_core::download::{origin_of, CancelToken};
use launcher_core::install::{verify_install_for, InstallEngine, InstallError, InstallOptions};
use launcher_core::types::{Os, RecipePlan};
use sha2::{Digest, Sha256};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

/// Every test body is wrapped in this budget (R3: bounded, no hangs).
const BUDGET: Duration = Duration::from_secs(5);

/// The launched game script writes this next to itself.
const LAUNCH_MARKER: &str = "System/launch-marker";

fn sha256(bytes: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    format!("{:x}", hasher.finalize())
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

/// A portable archive that already carries a complete, launchable Linux game.
fn launchable_archive() -> Vec<u8> {
    zip_bytes(&[
        (
            "System/ut2004-bin-amd64",
            b"#!/bin/sh\nprintf launched > \"$(dirname \"$0\")/launch-marker\"\n",
        ),
        ("System/Build.ini", b"Label=v3374\n"),
        ("Maps/DM-Rankin.ut2", b"rank in"),
    ])
}

/// A plan whose home-relative target is always overridden and whose downloads
/// always land in a temp dir, so no test writes below `$HOME`.
fn plan_json(downloads: &Path, files: serde_json::Value) -> RecipePlan {
    serde_json::from_value(serde_json::json!({
        "recipe": {"slug": "unreal-tournament-2004", "title": "Unreal Tournament 2004"},
        "os": "linux",
        "target_dir": "~/Games/ut2004",
        "downloads_dir": downloads.to_string_lossy(),
        "files": files,
    }))
    .unwrap()
}

fn options(plan: RecipePlan, target: &Path, cancel: CancelToken) -> InstallOptions {
    InstallOptions {
        plan,
        bearer: None,
        bearer_origin: None,
        target_dir: Some(target.to_path_buf()),
        cancel,
        on_progress: Arc::new(|_| {}),
    }
}

/// Poll for a marker file without ever waiting longer than `BUDGET`.
async fn wait_for_marker(marker: &Path) -> bool {
    let started = Instant::now();

    while started.elapsed() < BUDGET {
        if marker.is_file() {
            return true;
        }

        tokio::time::sleep(Duration::from_millis(20)).await;
    }

    marker.is_file()
}

async fn mount_archive(server: &MockServer, route: &str, body: &[u8]) {
    Mock::given(method("GET"))
        .and(path(route.to_string()))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body.to_vec()))
        .mount(server)
        .await;
}

/// The archive download of a plan with one portable base.
fn portable_plan(server: &MockServer, downloads: &Path, archive: &[u8]) -> RecipePlan {
    plan_json(
        downloads,
        serde_json::json!([{
            "requirement": "archive",
            "status": "matched",
            "required": true,
            "source": "lan",
            "name": "ut2004-portable.zip",
            "size_bytes": archive.len(),
            "sha256": sha256(archive),
            "download_url": format!("{}/files/archive", server.uri())
        }]),
    )
}

#[cfg(unix)]
#[tokio::test]
async fn a_finished_install_launches_the_flat_game_from_the_expected_path() {
    tokio::time::timeout(BUDGET, async {
        let server = MockServer::start().await;
        let work = tempfile::tempdir().unwrap();
        let downloads = work.path().join("downloads");
        let target = work.path().join("game");
        let archive = launchable_archive();
        mount_archive(&server, "/files/archive", &archive).await;

        let outcome = InstallEngine::install(options(
            portable_plan(&server, &downloads, &archive),
            &target,
            CancelToken::new(),
        ))
        .await
        .unwrap();

        assert_eq!(outcome.target_dir, target.to_string_lossy());
        assert!(
            outcome
                .verified
                .contains(&"System/ut2004-bin-amd64".to_string()),
            "verification must name the executable: {:?}",
            outcome.verified
        );

        let marker = target.join(LAUNCH_MARKER);
        assert!(
            !marker.exists(),
            "an install must not start the game just to verify it"
        );

        let launched = InstallEngine::launch(&target).unwrap();
        assert_eq!(
            launched,
            target.join("System/ut2004-bin-amd64"),
            "launch must start the install's own executable"
        );

        assert!(
            wait_for_marker(&marker).await,
            "the launched game did not write {}",
            marker.display()
        );
        assert!(process_is_executable(&launched));
    })
    .await
    .expect("install -> launch exceeded the five-second budget");
}

#[cfg(unix)]
fn process_is_executable(path: &Path) -> bool {
    use std::os::unix::fs::PermissionsExt;

    std::fs::metadata(path)
        .map(|metadata| metadata.permissions().mode() & 0o111 != 0)
        .unwrap_or(false)
}

#[tokio::test]
async fn cancelling_a_download_keeps_the_part_and_a_fresh_retry_completes() {
    tokio::time::timeout(BUDGET, async {
        let server = MockServer::start().await;
        let work = tempfile::tempdir().unwrap();
        let downloads = work.path().join("downloads");
        let target = work.path().join("game");
        let archive = launchable_archive();
        let plan = portable_plan(&server, &downloads, &archive);

        // A slow body: the response only starts after the cancel has fired, so
        // the engine observes the cancellation while the request is in flight.
        Mock::given(method("GET"))
            .and(path("/files/archive"))
            .respond_with(
                ResponseTemplate::new(200)
                    .set_body_bytes(archive.clone())
                    .set_delay(Duration::from_millis(300)),
            )
            .mount(&server)
            .await;

        let cancel = CancelToken::new();
        let canceller = cancel.clone();
        let handle = tokio::spawn(async move {
            tokio::time::sleep(Duration::from_millis(50)).await;
            canceller.cancel();
        });

        let error = InstallEngine::install(options(plan.clone(), &target, cancel))
            .await
            .unwrap_err();
        handle.await.unwrap();

        assert!(
            matches!(error, InstallError::Cancelled),
            "expected Cancelled, got {error:?}"
        );

        let part = downloads.join("ut2004-portable.zip.part");
        let final_file = downloads.join("ut2004-portable.zip");
        assert!(part.is_file(), "the .part file must be kept for a resume");
        assert!(
            !final_file.exists(),
            "a cancelled download must not look complete"
        );
        assert!(
            !target.join("System/Build.ini").exists(),
            "a cancelled install must not extract into the target"
        );

        // A fresh token retries the same plan and must finish normally.
        let outcome = InstallEngine::install(options(plan, &target, CancelToken::new()))
            .await
            .unwrap();

        assert_eq!(outcome.target_dir, target.to_string_lossy());
        assert!(final_file.is_file(), "the final archive must exist");
        assert!(!part.exists(), "a completed download has no .part left");
        assert_eq!(
            std::fs::read(target.join("System/Build.ini")).unwrap(),
            b"Label=v3374\n"
        );
        assert!(target.join("System/ut2004-bin-amd64").is_file());

        let requests = server.received_requests().await.unwrap();
        assert_eq!(requests.len(), 2, "one request per attempt");
    })
    .await
    .expect("cancel/retry exceeded the five-second budget");
}

#[test]
fn windows_layout_verifies_on_linux_and_linux_accepts_either_executable() {
    let windows = tempfile::tempdir().unwrap();
    for file in [
        "System/UT2004.exe",
        "System/Build.ini",
        "System/D3D11Drv.dll",
    ] {
        write_file(windows.path(), file, b"data");
    }

    assert_eq!(
        verify_install_for(windows.path(), Os::Windows).unwrap(),
        vec![
            "System/UT2004.exe",
            "System/Build.ini",
            "System/D3D11Drv.dll"
        ]
    );

    // The same directory is not a Linux install: the ELF is missing.
    assert!(
        verify_install_for(windows.path(), Os::Linux).is_err(),
        "a Windows-only layout must not verify as Linux"
    );

    std::fs::remove_file(windows.path().join("System/D3D11Drv.dll")).unwrap();
    let error = verify_install_for(windows.path(), Os::Windows).unwrap_err();
    assert!(
        error.to_string().contains("System/D3D11Drv.dll"),
        "the missing file must be named: {error}"
    );

    // The OldUnreal patch ships `ut2004-bin-amd64` (a symlink) plus the ELF it
    // points at. Either one is a complete Linux install.
    for executable in ["System/ut2004-bin-amd64", "System/UT2004"] {
        let linux = tempfile::tempdir().unwrap();
        write_file(linux.path(), "System/Build.ini", b"Label=v3374\n");
        write_file(linux.path(), executable, b"#!/bin/sh\nexit 0\n");

        let verified = verify_install_for(linux.path(), Os::Linux).unwrap();
        assert!(
            verified.iter().any(|file| file == executable),
            "{executable} must satisfy verification, got {verified:?}"
        );
        assert!(verified.iter().any(|file| file == "System/Build.ini"));
    }

    // Build.ini alone is not a game, and neither is the Windows binary.
    let incomplete = tempfile::tempdir().unwrap();
    write_file(incomplete.path(), "System/Build.ini", b"Label=v3374\n");
    assert!(verify_install_for(incomplete.path(), Os::Linux).is_err());
}

#[tokio::test]
async fn an_external_only_archive_installs_without_receiving_the_token() {
    tokio::time::timeout(BUDGET, async {
        let server = MockServer::start().await;
        let work = tempfile::tempdir().unwrap();
        let downloads = work.path().join("downloads");
        let target = work.path().join("game");
        let archive = launchable_archive();
        mount_archive(&server, "/external/portable.zip", &archive).await;

        // The plan has no LAN file at all: only a public fallback URL. The
        // launcher must still install it, and it must not leak the token there.
        let plan = plan_json(
            &downloads,
            serde_json::json!([{
                "requirement": "archive",
                "status": "external",
                "source": "external",
                "required": false,
                "label": "Portable build",
                "name": "ut2004-portable.zip",
                "size_bytes": archive.len(),
                "sha256": sha256(&archive),
                "fallback_url": format!("{}/external/portable.zip", server.uri())
            }]),
        );

        let mut request_options = options(plan, &target, CancelToken::new());
        request_options.bearer = Some("launcher-secret-token".into());
        request_options.bearer_origin = origin_of(&server.uri());

        let outcome = InstallEngine::install(request_options).await.unwrap();

        assert_eq!(outcome.target_dir, target.to_string_lossy());
        assert!(outcome.used_archive);
        assert!(target.join("System/ut2004-bin-amd64").is_file());
        assert!(target.join("System/Build.ini").is_file());
        assert!(downloads.join("ut2004-portable.zip").is_file());

        let requests = server.received_requests().await.unwrap();
        assert_eq!(requests.len(), 1);
        assert!(
            !requests[0].headers.contains_key("authorization"),
            "an external download must never carry the LANoMAT bearer token"
        );
    })
    .await
    .expect("external install exceeded the five-second budget");
}

fn write_file(root: &Path, relative: &str, body: &[u8]) {
    let path = root.join(relative);
    std::fs::create_dir_all(path.parent().unwrap()).unwrap();
    std::fs::write(path, body).unwrap();
}
