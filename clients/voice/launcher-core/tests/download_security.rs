//! Security boundaries of the download engine: where the bearer token may
//! travel, how far a redirect is followed, and which bodies are acceptable.

use std::fs;

use launcher_core::download::{download_file, origin_of, CancelToken, DownloadError, DownloadTask};
use launcher_core::install::{base_file, download_task};
use launcher_core::types::RecipePlan;
use sha2::{Digest, Sha256};
use wiremock::matchers::{header, method, path};
use wiremock::{Mock, MockServer, Request, ResponseTemplate};

fn sha256_hex(bytes: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    format!("{:x}", hasher.finalize())
}

fn task(url: String, dir: &tempfile::TempDir, body: &[u8]) -> DownloadTask {
    DownloadTask {
        url,
        filename: "ut2004.iso".into(),
        dest_dir: dir.path().to_path_buf(),
        sha256: Some(sha256_hex(body)),
        size_bytes: Some(body.len() as u64),
        bearer: Some("lan-token".into()),
        bearer_origin: None,
    }
}

fn authorization(request: &Request) -> Option<String> {
    request
        .headers
        .get("authorization")
        .and_then(|value| value.to_str().ok())
        .map(str::to_string)
}

#[tokio::test]
async fn download_sends_the_bearer_to_the_declared_origin() {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let body: &[u8] = b"lan-payload";

    Mock::given(method("GET"))
        .and(path("/file"))
        .and(header("authorization", "Bearer lan-token"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&server)
        .await;

    let mut download = task(format!("{}/file", server.uri()), &dir, body);
    download.bearer_origin = origin_of(&server.uri());

    let path = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    assert_eq!(fs::read(&path).unwrap(), body);
}

#[tokio::test]
async fn download_withholds_the_bearer_from_another_origin() {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let body: &[u8] = b"external-copy";

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&server)
        .await;

    let mut download = task(format!("{}/file", server.uri()), &dir, body);
    // The declaration points at another host: fail closed, send nothing.
    download.bearer_origin = Some("https://lan.example".into());

    download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(authorization(&requests[0]), None);
}

#[tokio::test]
async fn download_redirect_does_not_receive_the_bearer() {
    let lan = MockServer::start().await;
    let cdn = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let body: &[u8] = b"cdn-payload";

    Mock::given(method("GET"))
        .and(path("/file"))
        .and(header("authorization", "Bearer lan-token"))
        .respond_with(
            ResponseTemplate::new(302).insert_header("Location", format!("{}/file", cdn.uri())),
        )
        .mount(&lan)
        .await;

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&cdn)
        .await;

    let mut download = task(format!("{}/file", lan.uri()), &dir, body);
    download.bearer_origin = origin_of(&lan.uri());

    let path = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    assert_eq!(fs::read(&path).unwrap(), body);

    let lan_requests = lan.received_requests().await.unwrap();
    assert_eq!(
        authorization(&lan_requests[0]).as_deref(),
        Some("Bearer lan-token")
    );

    let cdn_requests = cdn.received_requests().await.unwrap();
    assert_eq!(cdn_requests.len(), 1, "the redirect was followed once");
    assert_eq!(
        authorization(&cdn_requests[0]),
        None,
        "the foreign host never sees the token"
    );
}

#[tokio::test]
async fn download_refuses_a_second_redirect() {
    let lan = MockServer::start().await;
    let cdn = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let body: &[u8] = b"never-arrives";

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(
            ResponseTemplate::new(302).insert_header("Location", format!("{}/file", cdn.uri())),
        )
        .mount(&lan)
        .await;

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(
            ResponseTemplate::new(302).insert_header("Location", format!("{}/again", lan.uri())),
        )
        .mount(&cdn)
        .await;

    let mut download = task(format!("{}/file", lan.uri()), &dir, body);
    download.bearer_origin = origin_of(&lan.uri());

    let error = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap_err();

    assert!(
        matches!(error, DownloadError::TooManyRedirects { .. }),
        "{error:?}"
    );
    assert!(!dir.path().join("ut2004.iso").exists());
}

#[tokio::test]
async fn download_rejects_an_oversized_body() {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let announced = b"1234";
    let liar: &[u8] = b"1234567890";

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(liar))
        .mount(&server)
        .await;

    let mut download = task(format!("{}/file", server.uri()), &dir, announced);
    download.size_bytes = Some(announced.len() as u64);
    download.sha256 = None;

    let error = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap_err();

    assert!(
        matches!(error, DownloadError::Oversized { announced: 4, .. }),
        "{error:?}"
    );
    assert!(!dir.path().join("ut2004.iso").exists());
}

#[tokio::test]
async fn download_keeps_a_traversal_name_in_the_destination() {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let downloads = dir.path().join("downloads");
    let body: &[u8] = b"iso";

    Mock::given(method("GET"))
        .and(path("/file"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&server)
        .await;

    let download = DownloadTask {
        url: format!("{}/file", server.uri()),
        filename: "../../../etc/ut2004.iso".into(),
        dest_dir: downloads.clone(),
        sha256: Some(sha256_hex(body)),
        size_bytes: Some(body.len() as u64),
        bearer: None,
        bearer_origin: None,
    };
    let path = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    assert_eq!(path, downloads.join("ut2004.iso"));
    assert_eq!(fs::read(&path).unwrap(), body);
    assert!(!dir.path().join("etc").exists());
}

#[tokio::test]
async fn download_rejects_an_invalid_name_before_a_request() {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();

    for bad in ["..", "", "   ", "a\0b.iso", "con"] {
        let download = DownloadTask {
            url: format!("{}/file", server.uri()),
            filename: bad.into(),
            dest_dir: dir.path().to_path_buf(),
            sha256: None,
            size_bytes: None,
            bearer: None,
            bearer_origin: None,
        };
        let error = download_file(&download, &CancelToken::new(), |_| {})
            .await
            .unwrap_err();
        assert!(
            matches!(error, DownloadError::InvalidName(_)),
            "{bad:?}: {error:?}"
        );
    }

    assert!(server.received_requests().await.unwrap().is_empty());
}

/// A one-file plan whose base download lives at `url`.
fn plan_with_base(url: &str, source: Option<&str>) -> RecipePlan {
    let mut file = serde_json::json!({
        "requirement": "archive",
        "status": "matched",
        "name": "ut2004.zip",
        "download_url": url,
    });
    if let Some(source) = source {
        file["source"] = serde_json::json!(source);
    }

    serde_json::from_value(serde_json::json!({
        "recipe": {"slug": "unreal-tournament-2004", "title": "Unreal Tournament 2004"},
        "target_dir": "~/Games/ut2004",
        "files": [file],
    }))
    .unwrap()
}

/// F2: the token belongs to the paired server, not to whatever origin the
/// plan's `download_url` names. A plan file served from host A that points its
/// download at host B must not hand B the bearer.
#[tokio::test]
async fn the_plan_download_origin_never_decides_where_the_bearer_goes() {
    let paired = MockServer::start().await; // host A: where the launcher paired
    let file_host = MockServer::start().await; // host B: named by the plan
    let dir = tempfile::tempdir().unwrap();
    let body: &[u8] = b"foreign-file-host";

    Mock::given(method("GET"))
        .and(path("/files/1/download"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&file_host)
        .await;

    let plan = plan_with_base(&format!("{}/files/1/download", file_host.uri()), None);
    let file = base_file(&plan).unwrap();
    let download = download_task(
        file,
        dir.path(),
        Some("lan-token".into()),
        origin_of(&paired.uri()),
    )
    .unwrap();

    assert_eq!(
        download.bearer_origin,
        origin_of(&paired.uri()),
        "the caller's paired origin wins over the plan's download_url"
    );
    assert_ne!(download.bearer_origin, origin_of(&file_host.uri()));

    let path = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();
    assert_eq!(fs::read(&path).unwrap(), body);

    let requests = file_host.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(
        authorization(&requests[0]),
        None,
        "the foreign file host never sees the token"
    );
    assert!(paired.received_requests().await.unwrap().is_empty());
}

#[tokio::test]
async fn a_plan_download_on_the_paired_origin_still_gets_the_bearer() {
    let paired = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let body: &[u8] = b"paired-payload";

    Mock::given(method("GET"))
        .and(path("/files/1/download"))
        .and(header("authorization", "Bearer lan-token"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&paired)
        .await;

    let plan = plan_with_base(&format!("{}/files/1/download", paired.uri()), None);
    let file = base_file(&plan).unwrap();
    let download = download_task(
        file,
        dir.path(),
        Some("lan-token".into()),
        origin_of(&paired.uri()),
    )
    .unwrap();

    let path = download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();
    assert_eq!(fs::read(&path).unwrap(), body);
}

#[tokio::test]
async fn an_external_status_without_a_source_field_never_gets_the_bearer() {
    let paired = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let body: &[u8] = b"external-only-payload";

    Mock::given(method("GET"))
        .and(path("/mirror/ut2004.iso"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&paired)
        .await;

    let plan: RecipePlan = serde_json::from_value(serde_json::json!({
        "recipe": {"slug": "unreal-tournament-2004", "title": "Unreal Tournament 2004"},
        "os": "linux",
        "target_dir": "~/Games/ut2004",
        "files": [{
            "requirement": "iso",
            "status": "external",
            "name": "ut2004.iso",
            "download_url": format!("{}/mirror/ut2004.iso", paired.uri())
        }]
    }))
    .unwrap();

    let file = base_file(&plan).expect("an external-only ISO is usable");
    let download = download_task(
        file,
        dir.path(),
        Some("lan-token".into()),
        origin_of(&paired.uri()),
    )
    .unwrap();

    assert_eq!(
        download.bearer_origin, None,
        "the `external` status alone keeps the token away"
    );

    download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    let requests = paired.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(authorization(&requests[0]), None);
}

#[tokio::test]
async fn an_external_plan_file_never_gets_the_bearer_even_on_the_paired_origin() {
    let paired = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let body: &[u8] = b"external-payload";

    Mock::given(method("GET"))
        .and(path("/mirror/ut2004.zip"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&paired)
        .await;

    let plan = plan_with_base(
        &format!("{}/mirror/ut2004.zip", paired.uri()),
        Some("external"),
    );
    let file = base_file(&plan).unwrap();
    let download = download_task(
        file,
        dir.path(),
        Some("lan-token".into()),
        origin_of(&paired.uri()),
    )
    .unwrap();

    assert_eq!(download.bearer_origin, None);

    download_file(&download, &CancelToken::new(), |_| {})
        .await
        .unwrap();

    let requests = paired.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(authorization(&requests[0]), None);
}
