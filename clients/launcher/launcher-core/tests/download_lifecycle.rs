//! Lifecycle behaviour: cancellation keeps the `.part`, a stalled connection
//! fails without losing progress, and progress reports stay throttled.

use std::fs;
use std::io::{Read, Write};
use std::net::TcpListener;
use std::time::Duration;

use launcher_core::download::{
    download_file, download_file_with, CancelToken, DownloadError, DownloadTask, DownloadTimeouts,
};
use sha2::{Digest, Sha256};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, Request, ResponseTemplate};

fn sha256_hex(bytes: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    format!("{:x}", hasher.finalize())
}

fn range_header(request: &Request) -> Option<String> {
    request
        .headers
        .get("range")
        .and_then(|value| value.to_str().ok())
        .map(str::to_string)
}

/// A server that answers the headers plus `body`, then goes silent — the only
/// way to exercise the idle timeout.
fn stalling_server(body: &'static [u8], announced: u64) -> String {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let address = listener.local_addr().unwrap();

    std::thread::spawn(move || {
        if let Ok((mut socket, _)) = listener.accept() {
            let mut buffer = [0u8; 4096];
            let _ = socket.read(&mut buffer);
            let head = format!(
                "HTTP/1.1 200 OK\r\nContent-Length: {announced}\r\nConnection: close\r\n\r\n"
            );
            let _ = socket.write_all(head.as_bytes());
            let _ = socket.write_all(body);
            let _ = socket.flush();
            std::thread::sleep(Duration::from_secs(10));
        }
    });

    format!("http://{address}/file")
}

#[tokio::test]
async fn a_cancelled_download_leaves_a_part_that_resumes() {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let full: &[u8] = b"0123456789-abcdefgh";
    fs::write(dir.path().join("ut2004.iso.part"), &full[..10]).unwrap();

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(
            move |request: &Request| match range_header(request).as_deref() {
                Some("bytes=10-") => ResponseTemplate::new(206)
                    .insert_header("Content-Range", "bytes 10-18/19")
                    .set_body_bytes(&full[10..]),
                Some("bytes=19-") => ResponseTemplate::new(416),
                _ => ResponseTemplate::new(200).set_body_bytes(full),
            },
        )
        .mount(&server)
        .await;

    let download = DownloadTask {
        url: format!("{}/file", server.uri()),
        filename: "ut2004.iso".into(),
        dest_dir: dir.path().to_path_buf(),
        sha256: Some(sha256_hex(full)),
        size_bytes: Some(full.len() as u64),
        bearer: None,
        bearer_origin: None,
    };

    let cancel = CancelToken::new();
    let trigger = cancel.clone();
    let error = download_file(&download, &cancel, move |_progress| trigger.cancel())
        .await
        .unwrap_err();

    assert!(matches!(error, DownloadError::Cancelled), "{error:?}");
    let part = dir.path().join("ut2004.iso.part");
    assert_eq!(
        fs::read(&part).unwrap(),
        full,
        "the part survived the cancel"
    );
    assert!(!dir.path().join("ut2004.iso").exists());

    // The next attempt has no cancel token and finishes the job.
    let path = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    assert_eq!(fs::read(&path).unwrap(), full);
    assert!(!part.exists());
}

#[tokio::test]
async fn download_does_not_start_after_a_cancel() {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(b"never"))
        .mount(&server)
        .await;

    let download = DownloadTask {
        url: format!("{}/file", server.uri()),
        filename: "ut2004.iso".into(),
        dest_dir: dir.path().to_path_buf(),
        sha256: None,
        size_bytes: None,
        bearer: None,
        bearer_origin: None,
    };
    let cancel = CancelToken::new();
    cancel.cancel();

    let error = download_file(&download, &cancel, |_| {}).await.unwrap_err();

    assert!(matches!(error, DownloadError::Cancelled), "{error:?}");
    assert!(server.received_requests().await.unwrap().is_empty());
}

#[tokio::test]
async fn download_times_out_on_an_idle_connection() {
    let dir = tempfile::tempdir().unwrap();
    let download = DownloadTask {
        url: stalling_server(b"abc", 1000),
        filename: "ut2004.iso".into(),
        dest_dir: dir.path().to_path_buf(),
        sha256: None,
        size_bytes: Some(1000),
        bearer: None,
        bearer_origin: None,
    };
    let timeouts = DownloadTimeouts {
        connect: Duration::from_secs(2),
        response: Duration::from_secs(2),
        idle: Duration::from_millis(300),
    };

    let error = download_file_with(&download, timeouts, &CancelToken::new(), |_| {})
        .await
        .unwrap_err();

    assert!(
        matches!(error, DownloadError::IdleTimeout { .. }),
        "{error:?}"
    );
    let part = dir.path().join("ut2004.iso.part");
    assert_eq!(
        fs::read(&part).unwrap(),
        b"abc",
        "the bytes received so far stay on disk"
    );
    assert!(!dir.path().join("ut2004.iso").exists());
}

#[tokio::test]
async fn download_throttles_progress_reports() {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let body = vec![b'x'; 8 * 1024 * 1024];

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body.clone()))
        .mount(&server)
        .await;

    let download = DownloadTask {
        url: format!("{}/file", server.uri()),
        filename: "ut2004.iso".into(),
        dest_dir: dir.path().to_path_buf(),
        sha256: Some(sha256_hex(&body)),
        size_bytes: Some(body.len() as u64),
        bearer: None,
        bearer_origin: None,
    };

    let mut reports = Vec::new();
    let path = download_file(&download, &CancelToken::new(), |progress| {
        reports.push(progress);
    })
    .await
    .unwrap();

    assert_eq!(fs::read(&path).unwrap(), body);

    let total = body.len() as u64;
    assert!(!reports.is_empty());
    assert!(
        reports.len() <= 4,
        "8 MiB delivered as one event burst must not produce hundreds of reports, got {}",
        reports.len()
    );
    assert!(reports.iter().all(|report| report.filename == "ut2004.iso"));
    assert!(reports.iter().all(|report| report.total == Some(total)));
    assert!(reports
        .windows(2)
        .all(|pair| pair[1].received >= pair[0].received));

    let last = reports.last().unwrap();
    assert_eq!(last.received, total);
}
