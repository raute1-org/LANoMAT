//! The generic install path: a non-UT2004 recipe is driven entirely by its
//! plan (base archive, optional patch/extras, `steps[].verify`, launch target).
//! The fixture is the NFSU2 shape: one titled wrapper folder, `SPEED.EXE`,
//! `dinput8.dll` and an ASI plugin that arrives as a separate LAN file.

use std::io::Write;
use std::path::Path;
use std::sync::Arc;

use launcher_core::download::CancelToken;
use launcher_core::install::{launch_command_for, verify_plan, InstallEngine, InstallOptions};
use launcher_core::types::{Os, RecipePlan};
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

fn nfsu2_plan(downloads: &Path, files: serde_json::Value) -> RecipePlan {
    serde_json::from_value(serde_json::json!({
        "recipe": {
            "slug": "need-for-speed-underground-2",
            "title": "Need for Speed: Underground 2",
            "version": "1.2 + NFS Online",
            "launch_targets": [
                {
                    "id": "windows-direct",
                    "os": "windows",
                    "command": "Start-Process -FilePath \"{{target}}/speed.exe\" -WorkingDirectory \"{{target}}\""
                },
                {
                    "id": "linux-wine",
                    "os": "linux",
                    "command": "WINEDLLOVERRIDES=\"dinput8=n,b\" wine \"{{target}}/speed.exe\""
                }
            ]
        },
        "os": "linux",
        "target_dir": "~/Games/nfsu2",
        "downloads_dir": downloads.to_string_lossy(),
        "files": files,
        "steps": [
            {
                "id": "install-base",
                "title": "Unpack",
                "os": "linux",
                "optional": false,
                "detail": "fixture",
                "verify": [
                    {"type": "file_exists", "path": "{{target}}/speed.exe"},
                    {"type": "file_exists", "path": "{{target}}/scripts/net_u2.asi"},
                    {"type": "file_exists", "path": "{{target}}/dinput8.dll"}
                ]
            }
        ]
    }))
    .unwrap()
}

#[tokio::test]
async fn installs_a_wrapped_nfsu2_archive_from_the_plan() {
    let server = MockServer::start().await;
    let work = tempfile::tempdir().unwrap();
    let downloads = work.path().join("downloads");
    let target = work.path().join("nfsu2");

    let wrapper = "Need for Speed - Underground 2";
    let speed = format!("{wrapper}/SPEED.EXE");
    let loader = format!("{wrapper}/dinput8.dll");
    let cars = format!("{wrapper}/DATA/cars.bin");
    let base = zip_bytes(&[
        (speed.as_str(), b"MZ fake game"),
        (loader.as_str(), b"ASI loader"),
        (cars.as_str(), b"car data"),
    ]);
    let asi = b"ASI plugin".to_vec();

    Mock::given(method("GET"))
        .and(path("/files/base"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(base.clone()))
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(path("/files/asi"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(asi.clone()))
        .mount(&server)
        .await;

    let plan = nfsu2_plan(
        &downloads,
        serde_json::json!([
            {
                "requirement": "base",
                "os": "linux",
                "status": "matched",
                "download_url": format!("{}/files/base", server.uri()),
                "name": "nfsu2-portable.zip",
                "size_bytes": base.len()
            },
            {
                "requirement": "mod",
                "status": "matched",
                "download_url": format!("{}/files/asi", server.uri()),
                "name": "net_u2.asi",
                "size_bytes": asi.len()
            }
        ]),
    );

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: None,
        bearer_origin: None,
        target_dir: Some(target.clone()),
        cancel: CancelToken::new(),
        on_progress: Arc::new(|_| {}),
    })
    .await
    .unwrap();

    assert!(outcome.used_archive);
    assert_eq!(
        outcome.verified,
        vec![
            "speed.exe".to_string(),
            "scripts/net_u2.asi".to_string(),
            "dinput8.dll".to_string()
        ]
    );
    assert!(target.join("SPEED.EXE").is_file());
    assert!(target.join("dinput8.dll").is_file());
    assert_eq!(
        std::fs::read(target.join("scripts/net_u2.asi")).unwrap(),
        asi
    );
    assert!(
        !target.join(wrapper).exists(),
        "the titled wrapper folder must be hoisted"
    );
}

#[test]
fn the_plan_selects_the_launch_command_per_os() {
    let work = tempfile::tempdir().unwrap();
    let plan = nfsu2_plan(&work.path().join("downloads"), serde_json::json!([]));

    assert_eq!(
        launch_command_for(&plan, Os::Linux).as_deref(),
        Some("WINEDLLOVERRIDES=\"dinput8=n,b\" wine \"{{target}}/speed.exe\"")
    );
    assert!(launch_command_for(&plan, Os::Windows)
        .unwrap()
        .contains("Start-Process"));
}

#[cfg(unix)]
#[test]
fn a_generic_windows_exe_launch_starts_the_executable_directly() {
    use std::os::unix::fs::PermissionsExt;

    let dir = tempfile::tempdir().unwrap();
    let target = dir.path();
    let exe = target.join("speed.exe");
    std::fs::write(&exe, "#!/bin/sh\ntouch \"$(dirname \"$0\")/launched\"\n").unwrap();
    let mut permissions = std::fs::metadata(&exe).unwrap().permissions();
    permissions.set_mode(0o755);
    std::fs::set_permissions(&exe, permissions).unwrap();

    let launched =
        InstallEngine::launch_command(target, "\"{{target}}/speed.exe\"", Os::Windows).unwrap();

    assert_eq!(launched, exe);

    let marker = target.join("launched");
    for _ in 0..100 {
        if marker.is_file() {
            break;
        }
        std::thread::sleep(std::time::Duration::from_millis(20));
    }
    assert!(marker.is_file(), "the generic launch did not start");
}

#[test]
fn a_missing_plan_verify_file_is_reported_by_name() {
    let work = tempfile::tempdir().unwrap();
    let plan = nfsu2_plan(&work.path().join("downloads"), serde_json::json!([]));

    let error = verify_plan(work.path(), &plan).unwrap_err();

    assert!(
        error.to_string().contains("speed.exe"),
        "unexpected error: {error}"
    );
}
