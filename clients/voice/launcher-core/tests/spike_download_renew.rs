//! SPIKE, not for production (all-in-one client, phase 0, task 0.3).
//!
//! Question: can the launcher engine (`download_file`, SHA-256, origin-bound bearer) take over
//! the signed-link renewal the voice transfer manager does on every resume? The engine keeps the
//! `.part` file by file name, not by URL, so a renewed link continues where the old one stopped.
//! `download_with_renewal` below is the wrapper a unified engine would own; it lives in the test
//! on purpose so the production code stays untouched.

use std::fs;
use std::future::Future;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;

use launcher_core::download::{download_file, CancelToken, DownloadError, DownloadTask};
use sha2::{Digest, Sha256};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, Request, ResponseTemplate};

fn sha256_hex(bytes: &[u8]) -> String {
    format!("{:x}", Sha256::digest(bytes))
}

fn task(url: String, dest: &Path, body: &[u8]) -> DownloadTask {
    DownloadTask {
        url,
        filename: "game.zip".into(),
        dest_dir: PathBuf::from(dest),
        sha256: Some(sha256_hex(body)),
        size_bytes: Some(body.len() as u64),
        bearer: None,
        bearer_origin: None,
    }
}

fn is_expired(error: &DownloadError) -> bool {
    matches!(
        error,
        DownloadError::Http {
            status: 401 | 403 | 404 | 410
        }
    )
}

/// Runs the engine; when the signed link is dead (401/403/404/410) it asks `renew` for a fresh
/// one and continues from the same `.part`, at most `max_renewals` times.
async fn download_with_renewal<F, Fut>(
    mut task: DownloadTask,
    cancel: &CancelToken,
    max_renewals: usize,
    mut renew: F,
) -> Result<PathBuf, DownloadError>
where
    F: FnMut() -> Fut,
    Fut: Future<Output = Option<String>>,
{
    let mut renewals = 0;
    loop {
        match download_file(&task, cancel, |_| {}).await {
            Err(error) if is_expired(&error) && renewals < max_renewals => {
                renewals += 1;
                task.url = renew().await.ok_or(error)?;
            }
            other => return other,
        }
    }
}

#[tokio::test]
async fn an_expired_link_is_renewed_and_the_part_file_continues() {
    let server = MockServer::start().await;
    let body: &[u8] = b"hello|world";
    let dir = tempfile::tempdir().unwrap();
    fs::write(dir.path().join("game.zip.part"), b"hello").unwrap();

    Mock::given(method("GET"))
        .and(path("/expired"))
        .respond_with(ResponseTemplate::new(403))
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(path("/fresh"))
        .respond_with(|request: &Request| {
            match request.headers.get("range").and_then(|v| v.to_str().ok()) {
                Some("bytes=5-") => ResponseTemplate::new(206)
                    .insert_header("Content-Range", "bytes 5-10/11")
                    .set_body_bytes(&body[5..]),
                _ => ResponseTemplate::new(500),
            }
        })
        .mount(&server)
        .await;

    let renewed = Arc::new(AtomicUsize::new(0));
    let fresh = format!("{}/fresh", server.uri());
    let counter = renewed.clone();
    let done = download_with_renewal(
        task(format!("{}/expired", server.uri()), dir.path(), body),
        &CancelToken::new(),
        2,
        move || {
            counter.fetch_add(1, Ordering::SeqCst);
            let fresh = fresh.clone();
            async move { Some(fresh) }
        },
    )
    .await
    .unwrap();

    assert_eq!(fs::read(&done).unwrap(), body);
    assert_eq!(renewed.load(Ordering::SeqCst), 1);
    assert!(!dir.path().join("game.zip.part").exists());
}

#[tokio::test]
async fn renewal_gives_up_after_the_limit_and_keeps_the_part() {
    let server = MockServer::start().await;
    let body: &[u8] = b"hello|world";
    let dir = tempfile::tempdir().unwrap();
    fs::write(dir.path().join("game.zip.part"), b"hello").unwrap();

    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(410))
        .mount(&server)
        .await;

    let url = format!("{}/dead", server.uri());
    let again = url.clone();
    let error = download_with_renewal(
        task(url, dir.path(), body),
        &CancelToken::new(),
        2,
        move || {
            let again = again.clone();
            async move { Some(again) }
        },
    )
    .await
    .unwrap_err();

    assert!(matches!(error, DownloadError::Http { status: 410 }));
    // Initial try plus two renewals, then stop; the prefix stays for the next manual resume.
    assert_eq!(server.received_requests().await.unwrap().len(), 3);
    assert_eq!(
        fs::read(dir.path().join("game.zip.part")).unwrap(),
        b"hello"
    );
}

#[tokio::test]
async fn a_part_without_a_pinned_hash_is_not_resumed_by_this_engine() {
    // Documents the one behavioural difference to the voice engine: without a SHA-256 the
    // launcher engine restarts from zero (the voice engine would send `Range: bytes=5-`).
    let server = MockServer::start().await;
    let body: &[u8] = b"hello|world";
    let dir = tempfile::tempdir().unwrap();
    fs::write(dir.path().join("game.zip.part"), b"hello").unwrap();

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&server)
        .await;

    let mut unpinned = task(format!("{}/file", server.uri()), dir.path(), body);
    unpinned.sha256 = None;
    let done = download_file(&unpinned, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    assert_eq!(fs::read(done).unwrap(), body);
    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert!(requests[0].headers.get("range").is_none());
}
