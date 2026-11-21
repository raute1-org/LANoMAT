//! Resume-safe HTTP downloads with SHA-256 verification.
//!
//! The LAN file host speaks HTTP Range; a dropped connection resumes from the
//! `.part` file. The final file only appears after the hash check passes, so a
//! later launcher start can trust it.
//!
//! Safety rules this module deliberately keeps:
//!
//! * a `.part` is only resumed when the plan pins a SHA-256; without one the
//!   existing bytes are unverifiable (a planted prefix would be trusted) and
//!   the file restarts from zero;
//! * an existing final file is only reused when its SHA-256 matches, never on
//!   the strength of its size alone;
//! * a `.part` is only ever appended to for a `206 Partial Content`; a plain
//!   `200 OK` restarts the file from zero, a `416` is retried once without a
//!   `Range` header;
//! * an idle connection (no chunk for [`DownloadTimeouts::idle`]) fails, but the
//!   `.part` stays for the next attempt;
//! * the body may never grow past the announced size;
//! * the bearer token only travels to the origin declared in
//!   [`DownloadTask::bearer_origin`], never to a redirect target on another
//!   host;
//! * progress callbacks are throttled to one every [`PROGRESS_INTERVAL`].

use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};

use reqwest::header::{CONTENT_LENGTH, CONTENT_RANGE, LOCATION, RANGE};
use reqwest::redirect::Policy;
use reqwest::StatusCode;
use sha2::{Digest, Sha256};
use url::Url;

use crate::archive::is_hostile_windows_component;

/// Minimum time between two progress callbacks.
pub const PROGRESS_INTERVAL: Duration = Duration::from_millis(200);

/// How many redirects a download follows. The bearer token is recomputed per
/// hop, so a redirect to another host is followed *without* the token.
const MAX_REDIRECTS: u8 = 1;

#[derive(Clone)]
pub struct DownloadTask {
    pub url: String,
    pub filename: String,
    pub dest_dir: PathBuf,
    pub sha256: Option<String>,
    pub size_bytes: Option<u64>,
    /// The bearer token for the LANoMAT API download route.
    pub bearer: Option<String>,
    /// Origin (`scheme://host[:port]`) that may receive [`Self::bearer`].
    ///
    /// Set this to the LANoMAT origin, not to a fallback/CDN host: the token is
    /// sent only when the request URL's origin matches. `None` means the token
    /// is never sent, so a caller that forgets the origin leaks nothing.
    pub bearer_origin: Option<String>,
}

/// Hand-written so a `tracing::debug!("{task:?}")` cannot put the bearer token
/// into the log file (the same rule as `state.rs`/`types.rs`).
impl std::fmt::Debug for DownloadTask {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DownloadTask")
            .field("url", &self.url)
            .field("filename", &self.filename)
            .field("dest_dir", &self.dest_dir)
            .field("sha256", &self.sha256)
            .field("size_bytes", &self.size_bytes)
            .field("bearer", &self.bearer.as_ref().map(|_| "<redacted>"))
            .field("bearer_origin", &self.bearer_origin)
            .finish()
    }
}

#[derive(Debug, Clone, Default)]
pub struct DownloadProgress {
    pub filename: String,
    pub received: u64,
    pub total: Option<u64>,
    pub bytes_per_second: u64,
}

/// Connection timings. The default idle window is long enough for a slow LAN
/// host but short enough that a dead server does not hang a launcher start.
#[derive(Debug, Clone, Copy)]
pub struct DownloadTimeouts {
    /// TCP/TLS connect.
    pub connect: Duration,
    /// Waiting for the response headers.
    pub response: Duration,
    /// Waiting for the next body chunk.
    pub idle: Duration,
}

impl Default for DownloadTimeouts {
    fn default() -> Self {
        Self {
            connect: Duration::from_secs(15),
            response: Duration::from_secs(60),
            idle: Duration::from_secs(30),
        }
    }
}

#[derive(Debug, thiserror::Error)]
pub enum DownloadError {
    #[error("invalid file name for a download: {0}")]
    InvalidName(String),
    #[error("invalid download URL: {0}")]
    InvalidUrl(String),
    #[error("could not access {path}: {source}")]
    Io {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("network error: {0}")]
    Network(String),
    #[error("server answered HTTP {status}")]
    Http { status: u16 },
    #[error("server redirected more than once, last to {location}")]
    TooManyRedirects { location: String },
    #[error("server sent an unexpected byte range: expected start {expected}, got {actual}")]
    UnexpectedRange { expected: u64, actual: String },
    #[error("connection stalled: no data for {idle:?}")]
    IdleTimeout { idle: Duration },
    #[error("{file}: the server sent more than the announced {announced} bytes")]
    Oversized { file: String, announced: u64 },
    #[error("{file}: the download ended after {received} of {announced} bytes")]
    Incomplete {
        file: String,
        announced: u64,
        received: u64,
    },
    #[error("checksum mismatch for {file}: expected {expected}, got {actual}")]
    Checksum {
        file: String,
        expected: String,
        actual: String,
    },
    #[error("download cancelled")]
    Cancelled,
}

/// Cooperative cancellation shared by the UI and the engine.
#[derive(Debug, Clone, Default)]
pub struct CancelToken(Arc<AtomicBool>);

impl CancelToken {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn cancel(&self) {
        self.0.store(true, Ordering::SeqCst);
    }

    pub fn is_cancelled(&self) -> bool {
        self.0.load(Ordering::SeqCst)
    }
}

/// The URL's origin (`scheme://host[:port]`) in the form [`DownloadTask::bearer_origin`]
/// expects, or `None` when the string is not a URL.
pub fn origin_of(url: &str) -> Option<String> {
    Url::parse(url)
        .ok()
        .map(|url| url.origin().ascii_serialization())
}

/// Keep only the last component and refuse every name Windows would read
/// differently: a reserved character (`<>:"|?*`), a control character, a
/// trailing dot or space, a reserved device name (`CON`, `NUL`, `COM1`, ...) —
/// or a drive prefix, which `Path::join`/`PathBuf::push` treat as a new base
/// (`C:evil.dll` would land next to the launcher binary instead of in the
/// download directory). The rules are cross-platform: the launcher runs on
/// Windows, so a plan approved on Linux must be safe there.
pub fn sanitize_filename(input: &str) -> Result<String, DownloadError> {
    let normalized = input.replace('\\', "/");
    let name = normalized.rsplit('/').next().unwrap_or("");

    let invalid = name.trim().is_empty() || is_hostile_windows_component(name);

    if invalid {
        return Err(DownloadError::InvalidName(input.to_string()));
    }

    Ok(name.to_string())
}

fn io_error(path: &Path, source: std::io::Error) -> DownloadError {
    DownloadError::Io {
        path: path.to_path_buf(),
        source,
    }
}

fn sha256_file(path: &Path) -> Result<String, DownloadError> {
    use std::io::Read;
    let mut file = std::fs::File::open(path).map_err(|source| io_error(path, source))?;
    let mut hash = Sha256::new();
    let mut buffer = [0u8; 64 * 1024];

    loop {
        let read = file
            .read(&mut buffer)
            .map_err(|source| io_error(path, source))?;
        if read == 0 {
            break;
        }
        hash.update(&buffer[..read]);
    }

    Ok(format!("{:x}", hash.finalize()))
}

/// Throttles progress reports so a fast LAN host cannot flood the UI with
/// thousands of events. Every decision is taken against a caller-supplied
/// `Instant`, which keeps the rule unit-testable without sleeping.
#[derive(Debug, Clone)]
struct ProgressThrottle {
    interval: Duration,
    last: Option<Instant>,
}

impl ProgressThrottle {
    fn new(interval: Duration) -> Self {
        Self {
            interval,
            last: None,
        }
    }

    fn allow(&mut self, now: Instant) -> bool {
        if let Some(last) = self.last {
            if now.saturating_duration_since(last) < self.interval {
                return false;
            }
        }

        self.last = Some(now);
        true
    }
}

/// The token to send with a request to `url`, or `None` when the origin is not
/// the declared LANoMAT origin.
fn bearer_for<'a>(url: &Url, task: &'a DownloadTask) -> Option<&'a str> {
    let token = task.bearer.as_deref().filter(|token| !token.is_empty())?;
    let declared = Url::parse(task.bearer_origin.as_deref()?).ok()?;

    (url.origin() == declared.origin()).then_some(token)
}

/// `bytes <start>-<end>/<total>` -> start; `None` for a missing/malformed header.
fn content_range_start(value: &str) -> Option<u64> {
    let value = value.trim().strip_prefix("bytes")?.trim_start();
    let range = value.split('/').next()?;
    range.split('-').next()?.trim().parse().ok()
}

/// `bytes <start>-<end>/<total>` -> total; `None` for `*` or a malformed header.
fn content_range_total(value: &str) -> Option<u64> {
    let value = value.trim().strip_prefix("bytes")?;
    let total = value.split('/').nth(1)?;

    total.trim().parse().ok()
}

fn header_str(response: &reqwest::Response, name: reqwest::header::HeaderName) -> Option<&str> {
    response
        .headers()
        .get(name)
        .and_then(|value| value.to_str().ok())
}

/// An already downloaded file is only reused when its SHA-256 matches the one
/// the plan pinned. Without a planned hash there is nothing to verify — the
/// size alone proves nothing (a planted file of the right length would pass) —
/// so the file is fetched again. LAN files always carry a hash (L31).
fn final_is_complete(task: &DownloadTask, path: &Path) -> Result<bool, DownloadError> {
    let Some(expected) = task.sha256.as_deref() else {
        return Ok(false);
    };

    Ok(sha256_file(path)?.eq_ignore_ascii_case(expected))
}

/// Download one file. Returns the final path.
pub async fn download_file<F>(
    task: &DownloadTask,
    cancel: &CancelToken,
    on_progress: F,
) -> Result<PathBuf, DownloadError>
where
    F: FnMut(DownloadProgress),
{
    download_file_with(task, DownloadTimeouts::default(), cancel, on_progress).await
}

/// [`download_file`] with explicit timeouts (tests use a short idle window).
pub async fn download_file_with<F>(
    task: &DownloadTask,
    timeouts: DownloadTimeouts,
    cancel: &CancelToken,
    mut on_progress: F,
) -> Result<PathBuf, DownloadError>
where
    F: FnMut(DownloadProgress),
{
    let filename = sanitize_filename(&task.filename)?;
    std::fs::create_dir_all(&task.dest_dir).map_err(|source| io_error(&task.dest_dir, source))?;

    let final_path = task.dest_dir.join(&filename);
    let part_path = task.dest_dir.join(format!("{filename}.part"));

    if cancel.is_cancelled() {
        return Err(DownloadError::Cancelled);
    }

    if final_path.is_file() {
        if final_is_complete(task, &final_path)? {
            on_progress(DownloadProgress {
                filename: filename.clone(),
                received: std::fs::metadata(&final_path)
                    .map(|meta| meta.len())
                    .unwrap_or(0),
                total: task.size_bytes,
                bytes_per_second: 0,
            });

            return Ok(final_path);
        }

        let _ = std::fs::remove_file(&final_path);
    }

    let base_url =
        Url::parse(&task.url).map_err(|error| DownloadError::InvalidUrl(error.to_string()))?;
    let client = reqwest::Client::builder()
        // Redirects are followed by hand: `reqwest` would replay the
        // `Authorization` header to whatever host the server names.
        .redirect(Policy::none())
        .connect_timeout(timeouts.connect)
        .build()
        .map_err(|error| DownloadError::Network(error.to_string()))?;

    let mut existing = match task.sha256 {
        // A `.part` can only be resumed when the plan pins its hash: the bytes
        // on disk are otherwise unverifiable, so a planted or corrupted prefix
        // would silently become the final file. Restart from zero instead.
        Some(_) => std::fs::metadata(&part_path)
            .map(|meta| meta.len())
            .unwrap_or(0),
        None => 0,
    };

    // A `.part` larger than the announced size cannot be a prefix of it.
    if task.size_bytes.is_some_and(|cap| existing > cap) {
        existing = 0;
    }

    let mut current = base_url;
    let mut redirects = 0u8;

    let response = loop {
        let mut request = client.get(current.clone());

        if let Some(token) = bearer_for(&current, task) {
            request = request.bearer_auth(token);
        }

        if existing > 0 {
            request = request.header(RANGE, format!("bytes={existing}-"));
        }

        let response = tokio::time::timeout(timeouts.response, request.send())
            .await
            .map_err(|_| DownloadError::Network("timed out waiting for the response".into()))?
            .map_err(|error| DownloadError::Network(error.to_string()))?;

        let status = response.status();

        if status == StatusCode::RANGE_NOT_SATISFIABLE && existing > 0 {
            // The `.part` is not a usable prefix (a leftover from another
            // build, or a file the server replaced). Retry exactly once from
            // zero; a second `416` falls through to the HTTP error below.
            existing = 0;
            continue;
        }

        if status.is_redirection() {
            let location = header_str(&response, LOCATION).ok_or(DownloadError::Http {
                status: status.as_u16(),
            })?;
            let next = current
                .join(location)
                .map_err(|error| DownloadError::InvalidUrl(error.to_string()))?;

            if redirects >= MAX_REDIRECTS {
                return Err(DownloadError::TooManyRedirects {
                    location: next.to_string(),
                });
            }

            redirects += 1;
            current = next;
            continue;
        }

        if !status.is_success() {
            return Err(DownloadError::Http {
                status: status.as_u16(),
            });
        }

        break response;
    };

    let status = response.status();
    let append = existing > 0 && status == StatusCode::PARTIAL_CONTENT;

    if status == StatusCode::PARTIAL_CONTENT {
        let start = header_str(&response, CONTENT_RANGE).and_then(content_range_start);

        if start != Some(existing) {
            return Err(DownloadError::UnexpectedRange {
                expected: existing,
                actual: start
                    .map(|start| start.to_string())
                    .unwrap_or_else(|| "no Content-Range header".into()),
            });
        }
    }

    let declared_total = if status == StatusCode::PARTIAL_CONTENT {
        header_str(&response, CONTENT_RANGE)
            .and_then(content_range_total)
            .or_else(|| response.content_length().map(|length| length + existing))
    } else {
        response.content_length()
    };

    // The plan's size is authoritative; the response header is the fallback for
    // recipes that do not announce one.
    let cap = task.size_bytes.or(declared_total);

    let mut file = if append {
        std::fs::OpenOptions::new()
            .append(true)
            .open(&part_path)
            .map_err(|source| io_error(&part_path, source))?
    } else {
        // `200 OK` means "here is the whole file": truncate, never concatenate.
        std::fs::File::create(&part_path).map_err(|source| io_error(&part_path, source))?
    };

    let mut received = if append { existing } else { 0 };
    let mut throttle = ProgressThrottle::new(PROGRESS_INTERVAL);
    let started = Instant::now();
    let mut bytes_per_second = 0u64;
    let mut response = response;

    use std::io::Write;

    loop {
        let chunk = match tokio::time::timeout(timeouts.idle, response.chunk()).await {
            Ok(Ok(Some(chunk))) => chunk,
            Ok(Ok(None)) => break,
            Ok(Err(error)) => {
                let _ = file.flush();
                return Err(DownloadError::Network(error.to_string()));
            }
            Err(_) => {
                // Keep the `.part` so the next attempt resumes from here.
                let _ = file.flush();
                return Err(DownloadError::IdleTimeout {
                    idle: timeouts.idle,
                });
            }
        };

        if cancel.is_cancelled() {
            let _ = file.flush();
            return Err(DownloadError::Cancelled);
        }

        if let Some(cap) = cap {
            if received.saturating_add(chunk.len() as u64) > cap {
                // An oversized body is not resumable: drop the part.
                drop(file);
                let _ = std::fs::remove_file(&part_path);
                return Err(DownloadError::Oversized {
                    file: filename,
                    announced: cap,
                });
            }
        }

        file.write_all(&chunk)
            .map_err(|source| io_error(&part_path, source))?;
        received += chunk.len() as u64;

        if throttle.allow(Instant::now()) {
            let elapsed = started.elapsed().as_secs_f64().max(0.001);
            bytes_per_second = (received.saturating_sub(existing) as f64 / elapsed) as u64;
            on_progress(DownloadProgress {
                filename: filename.clone(),
                received,
                total: cap,
                bytes_per_second,
            });
        }
    }

    file.flush()
        .map_err(|source| io_error(&part_path, source))?;
    drop(file);

    if cancel.is_cancelled() {
        return Err(DownloadError::Cancelled);
    }

    if let Some(cap) = cap {
        if received < cap {
            // Short body: keep the `.part`, the next attempt resumes.
            return Err(DownloadError::Incomplete {
                file: filename,
                announced: cap,
                received,
            });
        }
    }

    if let Some(expected) = task.sha256.as_deref() {
        let actual = sha256_file(&part_path)?;

        if !actual.eq_ignore_ascii_case(expected) {
            let _ = std::fs::remove_file(&part_path);
            return Err(DownloadError::Checksum {
                file: filename,
                expected: expected.to_string(),
                actual,
            });
        }
    }

    std::fs::rename(&part_path, &final_path).map_err(|source| io_error(&final_path, source))?;

    on_progress(DownloadProgress {
        filename,
        received,
        total: cap,
        bytes_per_second,
    });

    Ok(final_path)
}

/// Download a list sequentially; the caller decides which files are needed.
pub async fn download_many<F>(
    tasks: &[DownloadTask],
    cancel: &CancelToken,
    mut on_progress: F,
) -> Result<Vec<PathBuf>, DownloadError>
where
    F: FnMut(usize, DownloadProgress),
{
    let mut paths = Vec::with_capacity(tasks.len());

    for (index, task) in tasks.iter().enumerate() {
        let path = download_file(task, cancel, |progress| on_progress(index, progress)).await?;
        paths.push(path);
    }

    Ok(paths)
}

/// The expected `Content-Length` header is not trusted for the size cap; this
/// is only a helper for the UI.
pub fn announced_size(response: &reqwest::Response) -> Option<u64> {
    response
        .headers()
        .get(CONTENT_LENGTH)
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.parse().ok())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn sanitizes_file_names() {
        assert_eq!(sanitize_filename("ut2004.iso").unwrap(), "ut2004.iso");
        assert_eq!(
            sanitize_filename("C:\\Games\\ut2004.iso").unwrap(),
            "ut2004.iso"
        );
        assert_eq!(sanitize_filename("/tmp/ut2004.iso").unwrap(), "ut2004.iso");
        assert_eq!(sanitize_filename("../../etc/passwd").unwrap(), "passwd");
        assert!(sanitize_filename("..").is_err());
        assert!(sanitize_filename("").is_err());
        assert!(sanitize_filename("   ").is_err());
        assert!(sanitize_filename("a\0b.iso").is_err());
        assert!(sanitize_filename("bad\nname.iso").is_err());
        assert!(sanitize_filename("ends.with.dot.").is_err());
        assert!(sanitize_filename("CON").is_err());
        assert!(sanitize_filename("lpt1.iso").is_err());
        assert!(sanitize_filename("setup").is_ok());
    }

    #[test]
    fn refuses_names_windows_would_resolve_elsewhere() {
        // A drive prefix would make `dest_dir.join(name)` a new base.
        assert!(sanitize_filename("C:").is_err());
        assert!(sanitize_filename("C:ut2004.iso").is_err());
        assert!(sanitize_filename("z:WebView2Loader.dll").is_err());

        for hostile in [
            "ut2004:evil.iso",
            "ut2004*.iso",
            "ut2004?.iso",
            "ut2004\".iso",
            "ut2004<.iso",
            "ut2004>.iso",
            "ut2004|.iso",
            "UT2004.EXE:stream",
            "ut2004.iso ",
            "ut2004.iso.",
            "nul.txt",
            "COM9",
        ] {
            assert!(sanitize_filename(hostile).is_err(), "{hostile:?}");
        }
    }

    #[test]
    fn the_debug_output_redacts_the_bearer() {
        let task = DownloadTask {
            url: "https://lan.example/api/v1/files/1/download".into(),
            filename: "ut2004.iso".into(),
            dest_dir: PathBuf::from("/tmp"),
            sha256: None,
            size_bytes: None,
            bearer: Some("super-secret-token".into()),
            bearer_origin: Some("https://lan.example".into()),
        };
        let debug = format!("{task:?}");

        assert!(!debug.contains("super-secret-token"));
        assert!(debug.contains("<redacted>"), "{debug}");
        assert!(debug.contains("ut2004.iso"), "{debug}");
    }

    #[test]
    fn the_progress_throttle_keeps_one_report_per_window() {
        let start = Instant::now();
        let mut throttle = ProgressThrottle::new(PROGRESS_INTERVAL);

        assert!(throttle.allow(start));
        assert!(!throttle.allow(start + Duration::from_millis(50)));
        assert!(!throttle.allow(start + Duration::from_millis(199)));
        assert!(throttle.allow(start + Duration::from_millis(200)));
        assert!(!throttle.allow(start + Duration::from_millis(399)));
        assert!(throttle.allow(start + Duration::from_millis(400)));
    }

    #[test]
    fn parses_content_range_headers() {
        assert_eq!(content_range_start("bytes 100-199/200"), Some(100));
        assert_eq!(content_range_total("bytes 100-199/200"), Some(200));
        assert_eq!(content_range_total("bytes 100-199/*"), None);
        assert_eq!(content_range_start("nonsense"), None);
        assert_eq!(content_range_total(""), None);
    }

    #[test]
    fn keeps_the_bearer_only_for_the_declared_origin() {
        let task = DownloadTask {
            url: "https://lan.example/api/v1/files/1/download".into(),
            filename: "a.iso".into(),
            dest_dir: PathBuf::from("/tmp"),
            sha256: None,
            size_bytes: None,
            bearer: Some("token".into()),
            bearer_origin: Some("https://lan.example".into()),
        };
        let lan = Url::parse("https://lan.example/api/v1/files/1/download").unwrap();
        let other = Url::parse("https://cdn.example/1.iso").unwrap();

        assert_eq!(bearer_for(&lan, &task), Some("token"));
        assert_eq!(bearer_for(&other, &task), None);

        let undeclared = DownloadTask {
            bearer_origin: None,
            ..task.clone()
        };
        assert_eq!(bearer_for(&lan, &undeclared), None);
    }

    #[test]
    fn the_bearer_origin_helper_normalises_a_url() {
        assert_eq!(
            origin_of("https://LAN.example:443/api/v1/files/1").as_deref(),
            Some("https://lan.example")
        );
        assert_eq!(
            origin_of("http://192.168.1.5:8080/x").as_deref(),
            Some("http://192.168.1.5:8080")
        );
        assert_eq!(origin_of("not a url"), None);
    }

    #[tokio::test]
    async fn downloads_and_verifies_a_file_with_a_retryable_part() {
        use wiremock::matchers::{method, path};
        use wiremock::{Mock, MockServer, ResponseTemplate};

        let server = MockServer::start().await;
        let body = b"iso-bytes";
        let hash = {
            let mut hasher = Sha256::new();
            hasher.update(body);
            format!("{:x}", hasher.finalize())
        };

        Mock::given(method("GET"))
            .and(path("/file"))
            .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
            .mount(&server)
            .await;

        let dir = tempfile::tempdir().unwrap();
        let task = DownloadTask {
            url: format!("{}/file", server.uri()),
            filename: "ut2004.iso".into(),
            dest_dir: dir.path().to_path_buf(),
            sha256: Some(hash),
            size_bytes: Some(body.len() as u64),
            bearer: None,
            bearer_origin: None,
        };
        let path = download_file(&task, &CancelToken::new(), |_| {})
            .await
            .unwrap();
        assert_eq!(std::fs::read(&path).unwrap(), body);
        assert!(!dir.path().join("ut2004.iso.part").exists());
    }
}
