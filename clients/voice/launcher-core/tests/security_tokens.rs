//! Where the bearer token may travel, and what a download may trust.
//!
//! Invariants that must hold (and do today):
//!
//! * the token is bound to one exact origin — no subdomain, no host alias, no
//!   port change, no scheme downgrade, no redirect target, no plan URL;
//! * an `external` plan file never carries an origin at all;
//! * no `Debug` output of a credential-carrying value prints the token;
//! * a download only trusts bytes the plan pins with a SHA-256.
//!
//! L9 (security review) raised S2 and S3; the tests are their regression lock.

use std::path::PathBuf;
use std::sync::Arc;

use launcher_core::auth::LoginPoll;
use launcher_core::download::{
    download_file, origin_of, sanitize_filename, CancelToken, DownloadTask,
};
use launcher_core::install::{download_task, InstallOptions, InstallProgress};
use launcher_core::state::LauncherConfig;
use launcher_core::types::{PlanFile, RecipePlan};
use sha2::{Digest, Sha256};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, Request, ResponseTemplate};

fn sha256_hex(bytes: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    format!("{:x}", hasher.finalize())
}

fn authorization(request: &Request) -> Option<String> {
    request
        .headers
        .get("authorization")
        .and_then(|value| value.to_str().ok())
        .map(str::to_string)
}

fn range_header(request: &Request) -> Option<String> {
    request
        .headers
        .get("range")
        .and_then(|value| value.to_str().ok())
        .map(str::to_string)
}

fn task(url: String, dir: &tempfile::TempDir, body: &[u8], origin: Option<String>) -> DownloadTask {
    DownloadTask {
        url,
        filename: "ut2004.iso".into(),
        dest_dir: dir.path().to_path_buf(),
        sha256: Some(sha256_hex(body)),
        size_bytes: Some(body.len() as u64),
        bearer: Some("lan-token".into()),
        bearer_origin: origin,
    }
}

async fn body_served_by(server: &MockServer, body: &'static [u8]) {
    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(server)
        .await;
}

/// The declared origin and the request origin must match exactly. A host
/// alias (`localhost` vs `127.0.0.1`), another port or a downgrade to http are
/// all "another host" and must fail closed.
#[tokio::test]
async fn only_the_exact_declared_origin_receives_the_bearer() {
    let server = MockServer::start().await;
    let port = server.address().port();
    let body: &[u8] = b"payload";
    body_served_by(&server, body).await;

    let declared = [
        // host alias
        format!("http://localhost:{port}"),
        // different port
        format!("http://127.0.0.1:{}", port + 1),
        // scheme downgrade / upgrade
        format!("https://127.0.0.1:{port}"),
        // not a URL at all
        "127.0.0.1".to_string(),
    ];

    for origin in declared {
        let dir = tempfile::tempdir().unwrap();
        let download = task(server.uri() + "/file", &dir, body, Some(origin.clone()));

        download_file(&download, &CancelToken::new(), |_| {})
            .await
            .unwrap_or_else(|error| panic!("{origin}: {error:?}"));
    }

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 4);
    for request in &requests {
        assert_eq!(
            authorization(request),
            None,
            "an origin that is not byte-for-byte the declared one must not see the token"
        );
    }

    // Control: the exact origin does receive the token (so the test above is
    // not passing for the wrong reason).
    let dir = tempfile::tempdir().unwrap();
    let exact = task(server.uri() + "/file", &dir, body, origin_of(&server.uri()));
    download_file(&exact, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    let requests = server.received_requests().await.unwrap();
    assert_eq!(
        authorization(requests.last().unwrap()).as_deref(),
        Some("Bearer lan-token")
    );
}

/// A plan may name a download host of its own. The origin comes from the
/// paired server (`InstallOptions::bearer_origin`), never from the plan file.
#[tokio::test]
async fn a_plan_file_on_another_origin_never_receives_the_bearer() {
    let file_host = MockServer::start().await;
    let body: &[u8] = b"iso-from-a-file-host";
    body_served_by(&file_host, body).await;

    let file: PlanFile = serde_json::from_value(serde_json::json!({
        "requirement": "iso",
        "status": "matched",
        "source": "lan",
        "name": "ut2004.iso",
        "size_bytes": body.len(),
        "sha256": sha256_hex(body),
        "download_url": format!("{}/file", file_host.uri()),
    }))
    .unwrap();

    let dir = tempfile::tempdir().unwrap();
    let download = download_task(
        &file,
        dir.path(),
        Some("lan-token".into()),
        Some("https://lan.example".into()),
    )
    .unwrap();

    download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    let requests = file_host.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(
        authorization(&requests[0]),
        None,
        "the token belongs to the paired server, not to a host the plan names"
    );
}

#[test]
fn an_external_plan_file_has_no_bearer_origin_at_all() {
    let file: PlanFile = serde_json::from_value(serde_json::json!({
        "requirement": "patch",
        "status": "external",
        "source": "external",
        "name": "patch.zip",
        "download_url": "https://downloads.example/patch.zip",
    }))
    .unwrap();

    let task = download_task(
        &file,
        std::path::Path::new("/tmp"),
        Some("lan-token".into()),
        Some("https://lan.example".into()),
    )
    .unwrap();

    assert_eq!(task.bearer_origin, None);
}

/// Every credential-carrying value that implements `Debug` must redact. This
/// is the regression lock for the two hand-written impls plus the folder
/// struct around them.
#[test]
fn no_debug_output_prints_the_token() {
    let config = LauncherConfig::new(
        "https://lan.example".into(),
        "super-secret-token".into(),
        "2030-01-01T00:00:00Z".into(),
    );
    assert!(!format!("{config:?}").contains("super-secret-token"));

    let login = LoginPoll::Done {
        token: "super-secret-token".into(),
        expires_at: "2030-01-01T00:00:00Z".into(),
        expires_in: 3600,
    };
    assert!(!format!("{login:?}").contains("super-secret-token"));

    let plan: RecipePlan = serde_json::from_value(serde_json::json!({
        "recipe": {"slug": "unreal-tournament-2004", "title": "UT2004"},
        "target_dir": "/tmp/games/ut2004",
        "files": [],
    }))
    .unwrap();
    let options = InstallOptions {
        plan,
        bearer: Some("super-secret-token".into()),
        bearer_origin: Some("https://lan.example".into()),
        target_dir: None,
        cancel: CancelToken::new(),
        on_progress: Arc::new(|_: InstallProgress| {}),
    };
    assert!(!format!("{options:?}").contains("super-secret-token"));
}

/// S2: `DownloadTask` has a hand-written `Debug`, so any
/// `tracing::debug!("{task:?}")` prints `<redacted>` instead of the bearer
/// token — the exact leak the hand-written impls in `state.rs`/`types.rs`
/// prevent.
#[test]
fn a_download_task_debug_output_does_not_print_the_bearer() {
    let task = DownloadTask {
        url: "https://lan.example/api/v1/files/1/download".into(),
        filename: "ut2004.iso".into(),
        dest_dir: PathBuf::from("/tmp"),
        sha256: None,
        size_bytes: None,
        bearer: Some("super-secret-token".into()),
        bearer_origin: Some("https://lan.example".into()),
    };

    assert!(
        !format!("{task:?}").contains("super-secret-token"),
        "DownloadTask::Debug prints the bearer token"
    );
}

/// S3: a `.part` left behind by an earlier run is only resumed when the plan
/// pins a SHA-256. Without one its bytes cannot be verified, so a planted or
/// corrupted `.part` would install arbitrary content as the final file. The
/// rule: no planned hash => never resume, restart the file from zero.
#[tokio::test]
async fn a_part_without_a_planned_hash_is_not_trusted() {
    let server = MockServer::start().await;
    let tail: &[u8] = b"tail";

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(
            ResponseTemplate::new(206)
                .insert_header("Content-Range", "bytes 4-7/8")
                .set_body_bytes(tail),
        )
        .mount(&server)
        .await;

    let dir = tempfile::tempdir().unwrap();
    std::fs::write(dir.path().join("ut2004.iso.part"), b"EVIL").unwrap();

    let download = DownloadTask {
        url: format!("{}/file", server.uri()),
        filename: "ut2004.iso".into(),
        dest_dir: dir.path().to_path_buf(),
        sha256: None,
        size_bytes: None,
        bearer: None,
        bearer_origin: None,
    };

    let outcome = download_file(&download, &CancelToken::new(), |_| {}).await;

    assert!(
        outcome.is_err(),
        "a .part with no planned hash must not be resumed: {outcome:?}"
    );
    assert!(!dir.path().join("ut2004.iso").exists());

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(
        range_header(&requests[0]),
        None,
        "the unverifiable .part must not be offered as a range"
    );
}

/// S3, second half: an existing *final* file without a planned hash is fetched
/// again instead of being accepted as complete. The rule: no planned hash =>
/// always re-download, because the size alone is not a proof (a planted file of
/// the right length would pass). LAN files carry a SHA-256 (L31).
#[tokio::test]
async fn a_final_file_without_a_planned_hash_is_verified_not_assumed() {
    let dir = tempfile::tempdir().unwrap();
    let planted = dir.path().join("ut2004.iso");
    std::fs::write(&planted, b"planted").unwrap();

    let download = DownloadTask {
        url: "http://127.0.0.1:1/file".into(),
        filename: "ut2004.iso".into(),
        dest_dir: dir.path().to_path_buf(),
        sha256: None,
        size_bytes: None,
        bearer: None,
        bearer_origin: None,
    };

    let outcome = download_file(&download, &CancelToken::new(), |_| {}).await;

    assert!(
        outcome.is_err(),
        "a file the plan does not vouch for must not be reused: {outcome:?}"
    );
    assert!(
        !planted.exists(),
        "the unverified file is discarded and the download is attempted"
    );
}

/// The download name is the last line of defence for the write path, so it is
/// asserted here as well as in the path tests.
#[test]
fn a_download_name_is_reduced_to_one_plain_component() {
    for (input, expected) in [
        ("../../etc/passwd", "passwd"),
        ("C:\\Games\\ut2004.iso", "ut2004.iso"),
        ("/tmp/patch.zip", "patch.zip"),
    ] {
        assert_eq!(sanitize_filename(input).unwrap(), expected);
    }

    for refused in ["", "..", ".", "CON", "lpt1.iso", "a\0b.iso", "ends.dot."] {
        assert!(sanitize_filename(refused).is_err(), "{refused:?}");
    }
}
