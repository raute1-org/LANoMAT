//! Resume behaviour of the download engine: the `.part` file is only ever a
//! prefix of the final file, and the final file only appears after the hash
//! check.

use std::fs;
use std::path::{Path, PathBuf};

use launcher_core::download::{download_file, CancelToken, DownloadError, DownloadTask};
use sha2::{Digest, Sha256};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, Request, ResponseTemplate};

fn sha256_hex(bytes: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    format!("{:x}", hasher.finalize())
}

fn task(url: String, dest: &Path, body: &[u8]) -> DownloadTask {
    DownloadTask {
        url,
        filename: "ut2004.iso".into(),
        dest_dir: PathBuf::from(dest),
        sha256: Some(sha256_hex(body)),
        size_bytes: Some(body.len() as u64),
        bearer: None,
        bearer_origin: None,
    }
}

fn range_header(request: &Request) -> Option<String> {
    request
        .headers
        .get("range")
        .and_then(|value| value.to_str().ok())
        .map(str::to_string)
}

#[tokio::test]
async fn download_resumes_a_part_file_with_a_range_request() {
    let server = MockServer::start().await;
    let body: &[u8] = b"hello|world";
    let dir = tempfile::tempdir().unwrap();
    fs::write(dir.path().join("ut2004.iso.part"), b"hello").unwrap();

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(|request: &Request| match range_header(request).as_deref() {
            Some("bytes=5-") => ResponseTemplate::new(206)
                .insert_header("Content-Range", "bytes 5-10/11")
                .set_body_bytes(&body[5..]),
            _ => ResponseTemplate::new(500),
        })
        .mount(&server)
        .await;

    let download = task(format!("{}/file", server.uri()), dir.path(), body);
    let path = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    assert_eq!(fs::read(&path).unwrap(), body);
    assert!(!dir.path().join("ut2004.iso.part").exists());

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(range_header(&requests[0]).as_deref(), Some("bytes=5-"));
}

#[tokio::test]
async fn download_restarts_the_part_from_zero_on_a_200() {
    let server = MockServer::start().await;
    let body: &[u8] = b"hello|world";
    let dir = tempfile::tempdir().unwrap();
    fs::write(dir.path().join("ut2004.iso.part"), b"junk!").unwrap();

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&server)
        .await;

    let download = task(format!("{}/file", server.uri()), dir.path(), body);
    let path = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    assert_eq!(fs::read(&path).unwrap(), body);
    assert!(!dir.path().join("ut2004.iso.part").exists());

    let requests = server.received_requests().await.unwrap();
    assert_eq!(
        range_header(&requests[0]).as_deref(),
        Some("bytes=5-"),
        "the part was offered as a range first"
    );
}

#[tokio::test]
async fn download_retries_a_416_once_without_a_range() {
    let server = MockServer::start().await;
    let body: &[u8] = b"0123456789";
    let dir = tempfile::tempdir().unwrap();
    fs::write(dir.path().join("ut2004.iso.part"), b"0123456789").unwrap();

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(move |request: &Request| {
            if range_header(request).is_some() {
                // The part is not a prefix the server knows about.
                ResponseTemplate::new(416)
            } else {
                ResponseTemplate::new(200).set_body_bytes(body)
            }
        })
        .mount(&server)
        .await;

    let download = task(format!("{}/file", server.uri()), dir.path(), body);
    let path = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    assert_eq!(fs::read(&path).unwrap(), body);

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 2, "exactly one retry");
    assert_eq!(range_header(&requests[0]).as_deref(), Some("bytes=10-"));
    assert_eq!(range_header(&requests[1]), None);
}

#[tokio::test]
async fn download_rejects_a_206_with_the_wrong_range() {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    fs::write(dir.path().join("ut2004.iso.part"), b"hello").unwrap();

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(
            ResponseTemplate::new(206)
                .insert_header("Content-Range", "bytes 0-4/9")
                .set_body_bytes(b"xxxxx"),
        )
        .mount(&server)
        .await;

    let download = task(format!("{}/file", server.uri()), dir.path(), b"helloxxxx");
    let error = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap_err();

    assert!(
        matches!(error, DownloadError::UnexpectedRange { expected: 5, .. }),
        "{error:?}"
    );
    assert_eq!(
        fs::read(dir.path().join("ut2004.iso.part")).unwrap(),
        b"hello",
        "nothing was appended"
    );
}

#[tokio::test]
async fn download_skips_a_verified_final_file() {
    let server = MockServer::start().await;
    let body: &[u8] = b"iso-bytes";
    let dir = tempfile::tempdir().unwrap();
    fs::write(dir.path().join("ut2004.iso"), body).unwrap();

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(500))
        .mount(&server)
        .await;

    let download = task(format!("{}/file", server.uri()), dir.path(), body);
    let mut reports = Vec::new();
    let path = download_file(&download, &CancelToken::new(), |progress| {
        reports.push(progress);
    })
    .await
    .unwrap();

    assert_eq!(fs::read(&path).unwrap(), body);
    assert!(server.received_requests().await.unwrap().is_empty());
    assert_eq!(reports.len(), 1);
    assert_eq!(reports[0].received, body.len() as u64);
}

#[tokio::test]
async fn download_replaces_a_final_file_with_the_wrong_hash() {
    let server = MockServer::start().await;
    let body: &[u8] = b"the-real-iso";
    let dir = tempfile::tempdir().unwrap();
    fs::write(dir.path().join("ut2004.iso"), b"leftover-from-an-old-build").unwrap();

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&server)
        .await;

    let download = task(format!("{}/file", server.uri()), dir.path(), body);
    let path = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    assert_eq!(fs::read(&path).unwrap(), body);
    assert_eq!(server.received_requests().await.unwrap().len(), 1);
}

#[tokio::test]
async fn download_keeps_the_final_file_until_the_hash_matches() {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let promised: &[u8] = b"the bytes the plan promised";
    let corrupted = vec![b'z'; promised.len()];

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(corrupted.clone()))
        .mount(&server)
        .await;

    let download = task(format!("{}/file", server.uri()), dir.path(), promised);
    let error = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap_err();

    assert!(matches!(error, DownloadError::Checksum { .. }), "{error:?}");
    assert!(!dir.path().join("ut2004.iso").exists());
    assert!(!dir.path().join("ut2004.iso.part").exists());
}

#[tokio::test]
async fn download_keeps_the_part_after_a_short_body() {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(b"abc"))
        .mount(&server)
        .await;

    let download = DownloadTask {
        url: format!("{}/file", server.uri()),
        filename: "ut2004.iso".into(),
        dest_dir: dir.path().to_path_buf(),
        sha256: None,
        size_bytes: Some(10),
        bearer: None,
        bearer_origin: None,
    };
    let error = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap_err();

    assert!(
        matches!(
            error,
            DownloadError::Incomplete {
                received: 3,
                announced: 10,
                ..
            }
        ),
        "{error:?}"
    );
    assert_eq!(
        fs::read(dir.path().join("ut2004.iso.part")).unwrap(),
        b"abc",
        "the partial body is kept"
    );
    assert!(!dir.path().join("ut2004.iso").exists());
}
